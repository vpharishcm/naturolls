<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\BackupLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Encrypted database dumps kept outside the public web root, with a log of every run. */
class BackupService
{
    public function directory(): string
    {
        $dir = storage_path('app/private/backups');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public function run(string $trigger = 'schedule', ?User $user = null): BackupLog
    {
        $log = BackupLog::create(['status' => 'running', 'trigger' => $trigger, 'user_id' => $user?->id, 'started_at' => now()]);
        $sqlPath = $this->directory().DIRECTORY_SEPARATOR.'tmp_'.uniqid().'.sql';
        try {
            $c = config('database.connections.'.config('database.default'));
            if ($this->useProgram(config('backup.mysqldump'))) {
                $this->withCredentials($c, function ($credentialsFile) use ($c, $sqlPath) {
                    $result = Process::timeout(600)->env($this->processEnv())->run([
                        config('backup.mysqldump'), '--defaults-extra-file='.$credentialsFile,
                        '--single-transaction', '--routines', '--default-character-set=utf8mb4', '--result-file='.$sqlPath, $c['database'],
                    ]);
                    if ($result->failed()) {
                        throw new \RuntimeException(trim($result->errorOutput()) ?: 'mysqldump failed');
                    }
                });
            } else {
                $this->dumpWithPhp($sqlPath);
            }
            if (! is_file($sqlPath) || filesize($sqlPath) === 0) {
                throw new \RuntimeException('The dump file is empty.');
            }
            $name = 'naturolls_'.now()->format('Ymd_His').'.sql.gz.enc';
            $path = $this->directory().DIRECTORY_SEPARATOR.$name;
            File::put($path, $this->encrypt(gzencode(File::get($sqlPath), 6)));
            $log->update([
                'status' => 'success',
                'file_name' => $name,
                'size_bytes' => filesize($path),
                'checksum' => hash_file('sha256', $path),
                'finished_at' => now(),
            ]);
            $this->prune();
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $this->notifyFailure($e->getMessage());
        } finally {
            File::delete($sqlPath);
        }

        return $log->fresh();
    }

    /** Replaces the live database with a backup, after checking its checksum. */
    public function restore(string $fileName): void
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.basename($fileName);
        if (! is_file($path)) {
            throw new BusinessException('Backup file not found.', 'backup_missing', 404);
        }
        $known = BackupLog::where('file_name', basename($fileName))->value('checksum');
        if ($known && $known !== hash_file('sha256', $path)) {
            throw new BusinessException('Backup checksum does not match. The file may be damaged.', 'backup_corrupt');
        }
        $sql = gzdecode($this->decrypt(File::get($path)));
        if ($sql === false) {
            throw new BusinessException('Backup could not be read.', 'backup_corrupt');
        }
        if (! $this->useProgram(config('backup.mysql'))) {
            $this->restoreWithPhp($sql);

            return;
        }
        $c = config('database.connections.'.config('database.default'));
        $this->withCredentials($c, function ($credentialsFile) use ($c, $sql) {
            $result = Process::timeout(600)->env($this->processEnv())->input($sql)->run([
                config('backup.mysql'), '--defaults-extra-file='.$credentialsFile, '--default-character-set=utf8mb4', $c['database'],
            ]);
            if ($result->failed()) {
                throw new \RuntimeException(trim($result->errorOutput()) ?: 'Restore failed');
            }
        });
    }

    public function lastFailureUnresolved(): ?BackupLog
    {
        $last = BackupLog::orderByDesc('id')->first();

        return $last && $last->status === 'failed' ? $last : null;
    }

    /** Shared hosts often block running programs or lack the MySQL tools, so PHP does the work there. */
    private function useProgram(string $program): bool
    {
        $driver = config('backup.driver');
        if ($driver !== 'auto') {
            return $driver === 'native';
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (! function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return false;
        }
        try {
            return Process::timeout(10)->env($this->processEnv())->run([$program, '--version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** One statement per line for data, so a restore can read the file line by line. */
    private function dumpWithPhp(string $path): void
    {
        $out = fopen($path, 'wb');
        $pdo = DB::connection()->getPdo();
        fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        DB::beginTransaction();
        try {
            foreach (DB::select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'') as $row) {
                $table = array_values((array) $row)[0];
                $create = array_values((array) DB::selectOne("SHOW CREATE TABLE `{$table}`"))[1];
                fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
                $values = [];
                foreach (DB::table($table)->cursor() as $record) {
                    $values[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), (array) $record)).')';
                    if (count($values) === 200) {
                        fwrite($out, "INSERT INTO `{$table}` VALUES ".implode(',', $values).";\n");
                        $values = [];
                    }
                }
                if ($values) {
                    fwrite($out, "INSERT INTO `{$table}` VALUES ".implode(',', $values).";\n");
                }
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($out);
        }
    }

    private function restoreWithPhp(string $sql): void
    {
        $statement = '';
        foreach (preg_split('/\r?\n/', $sql) as $line) {
            if ($statement === '' && (trim($line) === '' || str_starts_with($line, '--'))) {
                continue;
            }
            $statement .= $line."\n";
            if (str_ends_with(rtrim($line), ';')) {
                DB::unprepared($statement);
                $statement = '';
            }
        }
    }

    private function encrypt(string $data): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($data, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        return 'NBK1'.$iv.$tag.$cipher;
    }

    private function decrypt(string $blob): string
    {
        if (! str_starts_with($blob, 'NBK1')) {
            throw new BusinessException('This is not a Naturolls backup file.', 'backup_corrupt');
        }
        $plain = openssl_decrypt(substr($blob, 32), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($blob, 4, 12), substr($blob, 16, 16));
        if ($plain === false) {
            throw new BusinessException('Backup could not be decrypted. Check APP_KEY or BACKUP_KEY.', 'backup_corrupt');
        }

        return $plain;
    }

    private function key(): string
    {
        return hash('sha256', (string) (config('backup.key') ?: config('app.key')), true);
    }

    /** Passes the password in a short-lived file, so it never shows in the process list. */
    private function withCredentials(array $c, callable $callback): void
    {
        $file = $this->directory().DIRECTORY_SEPARATOR.'cred_'.uniqid().'.cnf';
        File::put($file, "[client]\nhost={$c['host']}\nport={$c['port']}\nuser={$c['username']}\npassword=\"".addslashes((string) $c['password'])."\"\n");
        try {
            $callback($file);
        } finally {
            File::delete($file);
        }
    }

    /** Windows needs SystemRoot to open sockets, and web server workers may not inherit it. */
    private function processEnv(): array
    {
        return PHP_OS_FAMILY === 'Windows' ? ['SystemRoot' => getenv('SystemRoot') ?: 'C:\Windows'] : [];
    }

    private function prune(): void
    {
        $cutoff = now()->subDays(config('backup.retention_days'))->getTimestamp();
        foreach (File::files($this->directory()) as $f) {
            if ($f->getExtension() === 'enc' && $f->getMTime() < $cutoff) {
                File::delete($f->getPathname());
            }
        }
    }

    private function notifyFailure(string $error): void
    {
        Log::error('Naturolls backup failed: '.$error);
        $to = config('mail.from.address');
        if ($to && config('mail.default') !== 'log') {
            try {
                Mail::raw("The scheduled Naturolls backup failed:\n\n{$error}", fn ($m) => $m->to($to)->subject('Naturolls backup failed'));
            } catch (Throwable $e) {
                Log::error('Backup failure mail not sent: '.$e->getMessage());
            }
        }
    }
}
