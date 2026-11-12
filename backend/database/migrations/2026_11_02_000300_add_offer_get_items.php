<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $t) {
            $t->string('get_scope', 12)->nullable()->after('get_qty');
        });
        Schema::create('promotion_get_products', function (Blueprint $t) {
            $t->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->primary(['promotion_id', 'product_id']);
        });
        Schema::create('promotion_get_categories', function (Blueprint $t) {
            $t->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->constrained()->cascadeOnDelete();
            $t->primary(['promotion_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_get_categories');
        Schema::dropIfExists('promotion_get_products');
        Schema::table('promotions', function (Blueprint $t) {
            $t->dropColumn('get_scope');
        });
    }
};
