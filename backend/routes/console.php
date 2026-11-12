<?php

use App\Services\BackupService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('backup:run', function (BackupService $backups) {
    $log = $backups->run('command');
    $this->{$log->status === 'success' ? 'info' : 'error'}($log->status.': '.($log->file_name ?? $log->error));

    return $log->status === 'success' ? 0 : 1;
})->purpose('Create an encrypted database backup');

Artisan::command('backup:restore {file} {--force}', function (BackupService $backups) {
    if (! $this->option('force') && ! $this->confirm('This replaces the current database with the backup. Continue?')) {
        return 1;
    }
    $backups->restore($this->argument('file'));
    $this->info('Restored '.$this->argument('file'));

    return 0;
})->purpose('Restore the database from an encrypted backup');

Artisan::command('inventory:reconcile {--repair}', function (InventoryService $inventory) {
    $issues = $inventory->reconcile((bool) $this->option('repair'));
    $this->info(count($issues).' stock rows differ from the ledger.');
    foreach ($issues as $issue) {
        $this->line(json_encode($issue));
    }

    return 0;
})->purpose('Compare stock totals with the movement ledger');

Artisan::command('idempotency:prune', function () {
    $this->info(DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(7))->delete().' keys removed');
})->purpose('Remove idempotency keys older than a week');

Schedule::command('backup:run')->dailyAt('23:30')->withoutOverlapping();
Schedule::command('inventory:reconcile --repair')->dailyAt('23:45')->withoutOverlapping();
Schedule::command('idempotency:prune')->daily();
