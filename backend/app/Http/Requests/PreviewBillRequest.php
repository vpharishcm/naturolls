<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewBillRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'promotion_id' => ['nullable', 'exists:promotions,id'],
            'promotion_ids' => ['nullable', 'array'],
            'promotion_ids.*' => ['integer', 'exists:promotions,id'],
            'no_promotion' => ['nullable', 'boolean'],
            'manual_discount' => ['nullable', 'array'],
            'manual_discount.type' => ['required_with:manual_discount', 'in:percent,fixed'],
            'manual_discount.value' => ['required_with:manual_discount', 'numeric', 'min:0'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
