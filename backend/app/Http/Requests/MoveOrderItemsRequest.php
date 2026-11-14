<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveOrderItemsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Leave out for a new order.
            'target_order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'expected_version' => ['nullable', 'integer'],
        ];
    }
}
