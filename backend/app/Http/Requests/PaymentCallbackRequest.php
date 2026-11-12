<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCallbackRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'gateway_txn_id' => ['required', 'string', 'max:100'],
            'reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'status' => ['required', 'in:success,failed'],
        ];
    }
}
