<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_mobile' => ['nullable', 'string', 'regex:/^[0-9+\- ]{6,20}$/'],
        ];
    }
}
