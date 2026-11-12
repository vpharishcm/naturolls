<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockCorrectionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'counted' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
