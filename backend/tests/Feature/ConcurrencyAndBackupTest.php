<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\Order;
use App\Models\Product;
use App\Services\BackupService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** These tests commit for real, because they exercise row locks and mysqldump across processes. */
class ConcurrencyAndBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh');
        $this->seedAccess();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_many_terminals_racing_for_scarce_stock_never_oversell(): void
    {
        $product = $this->product(stock: 2);
        $orders = app(OrderService::class);
        $ids = collect(range(1, 6))->map(fn () => $orders->create([], $this->staff)->id);
        $startAt = microtime(true) + 2.5;
        $worker = base_path('tests/Support/reserve_worker.php');

        $results = Process::pool(function (Pool $pool) use ($ids, $product, $startAt, $worker) {
            foreach ($ids as $id) {
                $pool->path(base_path())->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'naturolls_test', 'DB_CONNECTION' => 'mysql'])
                    ->command([PHP_BINARY, $worker, (string) $id, (string) $product->id, (string) $startAt]);
            }
        })->start()->wait();

        $outputs = collect($results->collect())->map(fn ($r) => trim($r->output()).trim($r->errorOutput()));
        $this->assertSame(2, $outputs->filter(fn ($o) => $o === 'ok')->count(), $outputs->implode(' | '));
        $this->assertSame(4, $outputs->filter(fn ($o) => $o === 'insufficient_stock')->count(), $outputs->implode(' | '));
        $this->assertSame([2.0, 2.0, 0.0], $this->stock($product));
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_backup_is_encrypted_logged_and_restores(): void
    {
        $product = $this->product(stock: 5);
        $service = app(BackupService::class);
        $log = $service->run('test');
        $this->assertSame('success', $log->status, (string) $log->error);
        $path = $service->directory().DIRECTORY_SEPARATOR.$log->file_name;
        $this->assertStringNotContainsString('CREATE TABLE', File::get($path), 'The file on disk must be encrypted');

        $product->update(['name' => 'Changed after backup']);
        $service->restore($log->file_name);
        DB::purge();
        $this->assertNotSame('Changed after backup', Product::find($product->id)->name);

        // The restore rewound backup_logs to before this backup finished, so record it again.
        BackupLog::create(['status' => 'success', 'file_name' => $log->file_name, 'checksum' => $log->checksum]);
        File::append($path, 'x');
        $this->expectExceptionMessage('checksum');
        try {
            $service->restore($log->file_name);
        } finally {
            File::delete($path);
        }
    }

    public function test_backup_failure_is_recorded_and_surfaced(): void
    {
        Config::set('backup.driver', 'native');
        Config::set('backup.mysqldump', 'definitely-not-a-program');
        $log = app(BackupService::class)->run('test');
        $this->assertSame('failed', $log->status);
        $this->assertNotEmpty($log->error);
        $this->assertNotNull(app(BackupService::class)->lastFailureUnresolved());
    }

    public function test_backup_and_restore_work_with_php_alone_for_shared_hosting(): void
    {
        Config::set('backup.driver', 'php');
        $product = $this->product(stock: 5, extra: ['name' => "Mango's \"best\";\nline two ₹"]);
        $name = $product->name;
        $service = app(BackupService::class);
        $log = $service->run('test');
        $this->assertSame('success', $log->status, (string) $log->error);

        $product->update(['name' => 'Changed after backup']);
        $added = $this->product(stock: 1);
        $service->restore($log->file_name);
        DB::purge();
        File::delete($service->directory().DIRECTORY_SEPARATOR.$log->file_name);

        $this->assertSame($name, Product::find($product->id)->name);
        $this->assertNull(Product::find($added->id));
        $this->assertSame([5.0, 0.0, 5.0], $this->stock($product));
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_auto_falls_back_to_php_when_the_program_is_missing(): void
    {
        Config::set('backup.mysqldump', 'definitely-not-a-program');
        $service = app(BackupService::class);
        $log = $service->run('test');
        $this->assertSame('success', $log->status, (string) $log->error);
        File::delete($service->directory().DIRECTORY_SEPARATOR.$log->file_name);
    }
}
