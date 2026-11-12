<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedInteger('edit_count')->default(0)->after('print_count');
            $t->timestamp('edited_at')->nullable()->after('issued_at');
            $t->foreignId('edited_by')->nullable()->after('edited_at')->constrained('users');
        });

        // A fresh install gets this from the seeder; an existing one has roles already.
        $admin = Role::where('name', 'admin')->first();
        if ($admin) {
            $permission = Permission::firstOrCreate(['name' => 'invoice.edit_anytime'], ['label' => 'Edit bills at any time']);
            $admin->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'invoice.edit_anytime')->delete();
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('edited_by');
            $t->dropColumn(['edit_count', 'edited_at']);
        });
    }
};
