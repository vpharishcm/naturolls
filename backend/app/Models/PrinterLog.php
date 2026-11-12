<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrinterLog extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_duplicate' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
