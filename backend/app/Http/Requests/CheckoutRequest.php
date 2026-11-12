<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer'],
            'promotion_id' => ['nullable', 'exists:promotions,id'],
            'promotion_ids' => ['nullable', 'array'],
            'promotion_ids.*' => ['integer', 'exists:promotions,id'],
            'no_promotion' => ['nullable', 'boolean'],
            'manual_discount' => ['nullable', 'array'],
            'manual_discount.type' => ['required_with:manual_discount', 'in:percent,fixed'],
            'manual_discount.value' => ['required_with:manual_discount', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'manager_pin' => ['nullable', 'string', 'max:12'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'payments' => ['present', 'array'],
            'payments.*.method' => ['required', 'in:cash,upi,card'],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
