<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseProductionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'manager_pin' => ['nullable', 'string', 'max:12'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
