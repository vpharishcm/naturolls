<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrintLogRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'driver' => ['required', 'in:browser,bluetooth,usb,network'],
            'status' => ['required', 'in:success,failed'],
            'error' => ['nullable', 'string', 'max:500'],
        ];
    }
}
