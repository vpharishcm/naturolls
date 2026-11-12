<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoyaltyAdjustRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'points' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:255'],
        ];
    }
}
