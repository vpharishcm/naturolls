<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'order_number' => ['required', 'string', 'max:32'],
            'created_at' => ['required', 'date'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ];
    }
}
