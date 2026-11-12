<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('mobile', 20)->nullable()->unique();
            $t->date('birthday')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('name');
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('categories');
            $t->string('name', 80);
            $t->unsignedInteger('display_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['parent_id', 'display_order']);
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->constrained();
            $t->string('name', 120);
            $t->string('sku', 40)->nullable()->unique();
            $t->string('code', 40)->nullable()->unique();
            $t->text('description')->nullable();
            $t->string('image_path')->nullable();
            $t->string('art', 20)->nullable();
            $t->string('art_color', 9)->nullable();
            $t->string('unit', 20)->default('pc');
            $t->decimal('price', 10, 2);
            $t->decimal('cost', 10, 2)->nullable();
            $t->decimal('gst_rate', 5, 2)->default(0);
            $t->boolean('track_inventory')->default(true);
            $t->string('inventory_method', 20)->default('on_serve');
            $t->decimal('min_stock', 12, 3)->default(0);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_favourite')->default(false);
            $t->unsignedInteger('display_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['category_id', 'is_active', 'display_order']);
            $t->index('name');
        });

        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->string('name', 80);
            $t->string('sku', 40)->nullable()->unique();
            $t->decimal('price', 10, 2);
            $t->decimal('cost', 10, 2)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('inventory', function (Blueprint $t) {
            $t->id();
            $t->string('stock_key', 40)->unique();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('product_variant_id')->nullable()->constrained();
            $t->decimal('opening_qty', 12, 3)->default(0);
            $t->decimal('physical_qty', 12, 3)->default(0);
            $t->decimal('reserved_qty', 12, 3)->default(0);
            $t->timestamps();
            $t->index('updated_at');
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('customers');
    }
};
