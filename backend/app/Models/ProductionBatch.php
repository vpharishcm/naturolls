<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'production_date' => 'date:Y-m-d',
        ];
    }

    public function items()
    {
        return $this->hasMany(ProductionBatchItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
