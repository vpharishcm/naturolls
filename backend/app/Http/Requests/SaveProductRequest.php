<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'sku.unique' => 'Another item already has this SKU.',
            'code.unique' => 'Another item already has this product code.',
            'variants.*.sku.unique' => 'Another option already has this SKU.',
        ];
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $rules = [
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:40', Rule::unique('products', 'sku')->ignore($product)],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('products', 'code')->ignore($product)],
            'description' => ['nullable', 'string', 'max:2000'],
            'art' => ['nullable', 'string', 'max:20'],
            'art_color' => ['nullable', 'string', 'max:9'],
            'unit' => ['nullable', 'string', 'max:20'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'track_inventory' => ['nullable', 'boolean'],
            'inventory_method' => ['nullable', 'in:on_serve,on_add'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_favourite' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:80'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ];
        $variants = (array) $this->input('variants');
        $repeats = array_count_values(array_filter(array_map(fn ($v) => (string) ($v['sku'] ?? ''), $variants)));
        foreach ($variants as $i => $variant) {
            $rules["variants.{$i}.sku"] = [
                'nullable', 'string', 'max:40',
                Rule::unique('product_variants', 'sku')->ignore($variant['id'] ?? null),
                function ($attribute, $value, $fail) use ($repeats) {
                    if (($repeats[(string) $value] ?? 0) > 1) {
                        $fail('Two options have the same SKU.');
                    }
                },
            ];
        }

        return $rules;
    }
}
