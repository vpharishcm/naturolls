<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0', 'max:100000'],
            'return_to_stock' => ['nullable', 'boolean'],
            'manager_pin' => ['nullable', 'string', 'max:12'],
        ];
    }
}
