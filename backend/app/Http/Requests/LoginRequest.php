<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
