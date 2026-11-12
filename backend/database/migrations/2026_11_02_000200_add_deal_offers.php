<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $t) {
            $t->unsignedSmallInteger('buy_qty')->nullable()->after('discount_value');
            $t->unsignedSmallInteger('get_qty')->nullable()->after('buy_qty');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $t) {
            $t->dropColumn(['buy_qty', 'get_qty']);
        });
    }
};
