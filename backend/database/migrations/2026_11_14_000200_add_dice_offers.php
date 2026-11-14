<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Roll the dice": on a bill over a set amount the customer rolls a real dice, and the
 * number decides a prize. Each offer has six faces; the bill records what was rolled and won.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dice_offers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->decimal('min_bill', 12, 2)->default(0);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            // Six entries, one per face: {type: discount|free_item|reroll|none, label, amount?, product_ids?, category_ids?}
            $t->json('faces');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignId('dice_offer_id')->nullable()->after('promotion_id')->constrained('dice_offers');
            // Every number rolled, in order; only the last one won anything.
            $t->json('dice_rolls')->nullable()->after('dice_offer_id');
            $t->string('dice_prize', 160)->nullable()->after('dice_rolls');
            $t->decimal('dice_discount', 12, 2)->default(0)->after('manual_discount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('dice_offer_id');
            $t->dropColumn(['dice_rolls', 'dice_prize', 'dice_discount']);
        });
        Schema::dropIfExists('dice_offers');
    }
};
