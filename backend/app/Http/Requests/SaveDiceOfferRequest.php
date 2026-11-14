<?php

namespace App\Http\Requests;

use App\Services\DiceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveDiceOfferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'min_bill' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['nullable', 'boolean'],
            'faces' => ['required', 'array', 'size:6'],
            'faces.*.type' => ['required', 'in:'.implode(',', DiceService::TYPES)],
            'faces.*.label' => ['nullable', 'string', 'max:60'],
            'faces.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'faces.*.product_ids' => ['nullable', 'array'],
            'faces.*.product_ids.*' => ['integer', 'exists:products,id'],
            'faces.*.category_ids' => ['nullable', 'array'],
            'faces.*.category_ids.*' => ['integer', 'exists:categories,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('faces', []) as $i => $face) {
                $n = $i + 1;
                $type = $face['type'] ?? null;
                if ($type === 'discount' && (float) ($face['amount'] ?? 0) <= 0) {
                    $v->errors()->add("faces.{$i}.amount", "Face {$n}: enter how many rupees off.");
                }
                if ($type === 'free_item' && empty($face['product_ids']) && empty($face['category_ids'])) {
                    $v->errors()->add("faces.{$i}.product_ids", "Face {$n}: choose the items or categories that can be given free.");
                }
            }
        });
    }
}
