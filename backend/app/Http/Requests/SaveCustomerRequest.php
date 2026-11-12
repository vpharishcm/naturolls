<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    public function messages(): array
    {
        return ['mobile.unique' => 'Another customer already has this mobile number.'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['nullable', 'string', 'regex:/^[0-9+\- ]{6,20}$/', Rule::unique('customers', 'mobile')->ignore($this->route('customer'))],
            'birthday' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'join_loyalty' => ['nullable', 'boolean'],
        ];
    }
}
