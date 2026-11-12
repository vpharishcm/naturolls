<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'min_stock' => 'decimal:3',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'is_favourite' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function inventoryRows()
    {
        return $this->hasMany(Inventory::class);
    }
}
