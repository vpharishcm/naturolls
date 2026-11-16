<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'decimal:3',
            'served_qty' => 'decimal:3',
            'stock_shortfall' => 'decimal:3',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** Includes deleted products, so an old order still knows which category each item came from. */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** The item's category, so items of the same name (Strawberry in two categories) can be told apart. */
    public function categoryName(): ?string
    {
        return $this->product?->category?->name;
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reservation()
    {
        return $this->hasOne(InventoryReservation::class);
    }
}
