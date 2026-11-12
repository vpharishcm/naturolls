<?php

/**
 * Turns a freshly seeded database into a clean start for a real shop: no demo stock,
 * customer or offer, and new random logins. Run by build-hosting-package.ps1.
 * Prints the new logins as JSON for the script to store.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$base = $argv[1];
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach (['inventory_movements', 'inventory_reservations', 'loyalty_transactions', 'loyalty_accounts', 'customers', 'promotion_products', 'promotion_categories', 'promotions', 'audit_logs', 'staff_activities', 'backup_logs', 'personal_access_tokens'] as $table) {
    DB::table($table)->truncate();
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
DB::table('inventory')->update(['opening_qty' => 0, 'physical_qty' => 0, 'reserved_qty' => 0]);

$logins = ['admin_password' => Str::password(14, symbols: false), 'admin_pin' => (string) random_int(100000, 999999), 'staff_password' => Str::password(14, symbols: false)];
User::where('username', 'admin')->update(['password' => Hash::make($logins['admin_password']), 'pin' => Hash::make($logins['admin_pin'])]);
User::where('username', 'staff')->update(['password' => Hash::make($logins['staff_password'])]);

echo json_encode($logins);
