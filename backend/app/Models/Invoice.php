<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'promotion_discount' => 'decimal:2',
            'manual_discount' => 'decimal:2',
            'loyalty_discount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'tax_breakup' => 'array',
            'snapshot' => 'array',
            'issued_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function biller()
    {
        return $this->belongsTo(User::class, 'billed_by');
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    /** Every offer on the bill, with what each saved. `promotion` is the biggest of them. */
    public function promotions()
    {
        return $this->belongsToMany(Promotion::class, 'invoice_promotions')->withTrashed()->withPivot('discount');
    }

    /** @param  array<int, array{id:int|null, discount:string}>  $applied */
    public function recordPromotions(array $applied): void
    {
        $rows = [];
        foreach ($applied as $a) {
            if ($a['id']) {
                $rows[$a['id']] = ['discount' => $a['discount']];
            }
        }
        $this->promotions()->sync($rows);
    }
}
