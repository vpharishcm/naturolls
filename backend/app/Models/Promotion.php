<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'buy_qty' => 'integer',
            'get_qty' => 'integer',
            'min_purchase' => 'decimal:2',
            'is_active' => 'boolean',
            'requires_authorization' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'promotion_categories');
    }

    public function getProducts()
    {
        return $this->belongsToMany(Product::class, 'promotion_get_products');
    }

    public function getCategories()
    {
        return $this->belongsToMany(Category::class, 'promotion_get_categories');
    }
}
