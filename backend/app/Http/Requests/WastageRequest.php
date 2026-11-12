<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WastageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
