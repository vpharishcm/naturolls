<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_count' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'created_offline' => 'boolean',
            'billed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    /** The order that took this order's items when it was merged. */
    public function mergedInto()
    {
        return $this->belongsTo(Order::class, 'merged_into_id');
    }
}
