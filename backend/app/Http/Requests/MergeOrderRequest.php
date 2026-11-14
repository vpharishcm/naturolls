<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'target_order_id' => ['required', 'integer', 'exists:orders,id'],
            'expected_version' => ['nullable', 'integer'],
        ];
    }
}
