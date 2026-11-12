<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        $saving = ! $this->routeIs('*.preview') && ! str_ends_with($this->path(), '/preview');

        return [
            'expected_edit_count' => ['required', 'integer'],
            'items' => ['required', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required_without:items.*.id', 'nullable', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:9999'],
            'return_to_stock' => ['nullable', 'boolean'],
            'settlement_method' => ['nullable', 'in:cash,upi,card'],
            'reason' => [$saving ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }
}
