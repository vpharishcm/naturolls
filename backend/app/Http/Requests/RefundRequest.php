<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,upi,card'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'manager_pin' => ['nullable', 'string', 'max:12'],
            'restock' => ['nullable', 'array'],
            'restock.*.order_item_id' => ['required', 'integer'],
            'restock.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
