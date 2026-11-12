<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1', 'max:3'],
            'payments.*.method' => ['required', 'in:cash,upi,card'],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
