<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_number', 32)->unique();
            $t->char('client_uuid', 36)->nullable()->unique();
            $t->string('status', 12)->default('unbilled');
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->string('customer_name', 120)->nullable();
            $t->string('customer_mobile', 20)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('billed_by')->nullable()->constrained('users');
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->foreignId('terminal_id')->nullable()->constrained();
            $t->decimal('item_count', 12, 3)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('created_offline')->default(false);
            $t->string('cancel_reason')->nullable();
            $t->timestamp('billed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'created_at']);
            $t->index('customer_name');
            $t->index('customer_mobile');
            $t->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('product_variant_id')->nullable()->constrained();
            $t->string('stock_key', 40);
            $t->string('product_name', 160);
            $t->string('category_name', 80)->nullable();
            $t->string('sku', 40)->nullable();
            $t->decimal('unit_price', 10, 2);
            $t->decimal('unit_cost', 10, 2)->nullable();
            $t->decimal('gst_rate', 5, 2)->default(0);
            $t->decimal('quantity', 12, 3);
            $t->decimal('served_qty', 12, 3)->default(0);
            $t->decimal('stock_shortfall', 12, 3)->default(0);
            $t->decimal('line_total', 12, 2);
            $t->string('conflict_note')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['order_id', 'stock_key']);
            $t->index(['product_id', 'created_at']);
        });

        Schema::create('inventory_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_id')->constrained('inventory');
            $t->foreignId('order_item_id')->constrained();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->timestamps();
            $t->unique('order_item_id');
            $t->index(['inventory_id', 'quantity']);
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_id')->constrained('inventory');
            $t->string('type', 24);
            $t->decimal('physical_delta', 12, 3)->default(0);
            $t->decimal('reserved_delta', 12, 3)->default(0);
            $t->decimal('physical_after', 12, 3);
            $t->decimal('reserved_after', 12, 3);
            $t->string('reference_type', 40)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reason')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['inventory_id', 'created_at']);
            $t->index(['type', 'created_at']);
            $t->index(['reference_type', 'reference_id']);
        });

        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('discount_type', 12);
            $t->decimal('discount_value', 10, 2);
            $t->string('scope', 12)->default('order');
            $t->decimal('min_purchase', 10, 2)->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('requires_authorization')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_products', function (Blueprint $t) {
            $t->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->primary(['promotion_id', 'product_id']);
        });

        Schema::create('promotion_categories', function (Blueprint $t) {
            $t->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->constrained();
            $t->primary(['promotion_id', 'category_id']);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_number', 32)->unique();
            $t->foreignId('order_id')->unique()->constrained();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('billed_by')->constrained('users');
            $t->decimal('subtotal', 12, 2);
            $t->decimal('promotion_discount', 12, 2)->default(0);
            $t->decimal('manual_discount', 12, 2)->default(0);
            $t->decimal('loyalty_discount', 12, 2)->default(0);
            $t->decimal('taxable_amount', 12, 2);
            $t->decimal('tax_amount', 12, 2);
            $t->decimal('total', 12, 2);
            $t->decimal('refunded_amount', 12, 2)->default(0);
            $t->decimal('cost_total', 12, 2)->default(0);
            $t->foreignId('promotion_id')->nullable()->constrained();
            $t->foreignId('discount_authorized_by')->nullable()->constrained('users');
            $t->string('discount_reason')->nullable();
            $t->json('tax_breakup');
            $t->json('snapshot');
            $t->unsignedInteger('print_count')->default(0);
            $t->timestamp('issued_at');
            $t->timestamps();
            $t->index('issued_at');
            $t->index('customer_id');
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->string('type', 10)->default('payment');
            $t->string('method', 10);
            $t->decimal('amount', 12, 2);
            $t->decimal('tendered', 12, 2)->nullable();
            $t->decimal('change_given', 12, 2)->default(0);
            $t->string('reference')->nullable();
            $t->string('gateway_txn_id', 100)->nullable()->unique();
            $t->string('status', 12)->default('success');
            $t->foreignId('parent_payment_id')->nullable()->constrained('payments');
            $t->string('reason')->nullable();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('authorized_by')->nullable()->constrained('users');
            $t->timestamp('reconciled_at')->nullable();
            $t->foreignId('reconciled_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['method', 'created_at']);
            $t->index(['type', 'created_at']);
            $t->index('reference');
        });

        Schema::create('cash_closings', function (Blueprint $t) {
            $t->id();
            $t->date('business_date')->unique();
            $t->decimal('opening_balance', 12, 2)->default(0);
            $t->decimal('cash_sales', 12, 2)->default(0);
            $t->decimal('cash_refunds', 12, 2)->default(0);
            $t->decimal('expected_balance', 12, 2)->default(0);
            $t->decimal('counted_balance', 12, 2)->default(0);
            $t->decimal('variance', 12, 2)->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });

        Schema::create('printer_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('terminal_id')->nullable()->constrained();
            $t->string('driver', 16);
            $t->string('status', 12);
            $t->boolean('is_duplicate')->default(false);
            $t->text('error')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['invoice_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });

        Schema::create('production_batches', function (Blueprint $t) {
            $t->id();
            $t->date('production_date');
            $t->time('production_time');
            $t->foreignId('user_id')->constrained();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('production_date');
        });

        Schema::create('production_batch_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_batch_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('product_variant_id')->nullable()->constrained();
            $t->decimal('quantity', 12, 3);
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users');
            $t->string('reversal_reason')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'reversed_at']);
        });

        Schema::create('loyalty_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->unique()->constrained();
            $t->integer('points_balance')->default(0);
            $t->integer('lifetime_earned')->default(0);
            $t->integer('lifetime_redeemed')->default(0);
            $t->timestamps();
        });

        Schema::create('loyalty_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loyalty_account_id')->constrained();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->string('type', 12);
            $t->integer('points');
            $t->integer('balance_after');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['loyalty_account_id', 'created_at']);
            $t->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['loyalty_transactions', 'loyalty_accounts', 'production_batch_items', 'production_batches', 'printer_logs', 'cash_closings', 'payments', 'invoices', 'promotion_categories', 'promotion_products', 'promotions', 'inventory_movements', 'inventory_reservations', 'order_items', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
