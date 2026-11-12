<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashClosingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'business_date' => ['required', 'date'],
            'counted_balance' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
