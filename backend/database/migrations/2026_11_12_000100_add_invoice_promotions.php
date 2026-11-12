<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A bill can carry several offers at once; this records each one and what it saved. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_promotions', function (Blueprint $t) {
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('promotion_id')->constrained();
            $t->decimal('discount', 12, 2)->default(0);
            $t->primary(['invoice_id', 'promotion_id']);
        });

        DB::table('invoice_promotions')->insertUsing(
            ['invoice_id', 'promotion_id', 'discount'],
            DB::table('invoices')->whereNotNull('promotion_id')->select('id', 'promotion_id', 'promotion_discount'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_promotions');
    }
};
