<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePromotionRequest extends FormRequest
{
    public function rules(): array
    {
        $type = $this->input('discount_type');

        return [
            'name' => ['required', 'string', 'max:120'],
            'discount_type' => ['required', 'in:percent,fixed,buy_get,bundle'],
            'discount_value' => $type === 'buy_get' ? ['nullable', 'numeric'] : ['required', 'numeric', 'gt:0'],
            'buy_qty' => in_array($type, ['buy_get', 'bundle'], true) ? ['required', 'integer', 'min:'.($type === 'bundle' ? 2 : 1), 'max:999'] : ['nullable', 'integer'],
            'get_qty' => $type === 'buy_get' ? ['required', 'integer', 'min:1', 'max:999'] : ['nullable', 'integer'],
            'get_scope' => ['nullable', 'in:same,product,category'],
            'get_product_ids' => [$type === 'buy_get' && $this->input('get_scope') === 'product' ? 'required' : 'nullable', 'array'],
            'get_product_ids.*' => ['exists:products,id'],
            'get_category_ids' => [$type === 'buy_get' && $this->input('get_scope') === 'category' ? 'required' : 'nullable', 'array'],
            'get_category_ids.*' => ['exists:categories,id'],
            'scope' => ['required', 'in:order,product,category'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'requires_authorization' => ['nullable', 'boolean'],
            'product_ids' => ['required_if:scope,product', 'nullable', 'array'],
            'product_ids.*' => ['exists:products,id'],
            'category_ids' => ['required_if:scope,category', 'nullable', 'array'],
            'category_ids.*' => ['exists:categories,id'],
        ];
    }
}
