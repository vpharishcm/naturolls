<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $guarded = [];

    protected $appends = ['available_qty'];

    protected function casts(): array
    {
        return [
            'opening_qty' => 'decimal:3',
            'physical_qty' => 'decimal:3',
            'reserved_qty' => 'decimal:3',
        ];
    }

    public function getAvailableQtyAttribute(): string
    {
        return bcsub((string) $this->physical_qty, (string) $this->reserved_qty, 3);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
