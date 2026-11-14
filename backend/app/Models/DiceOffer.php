<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A "roll the dice" offer: six faces, each with a prize, for bills of at least min_bill. */
class DiceOffer extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_bill' => 'decimal:2',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'faces' => 'array',
        ];
    }
}
