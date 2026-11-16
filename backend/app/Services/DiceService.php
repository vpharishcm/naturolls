<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\DiceOffer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Money;

/**
 * "Roll the dice". The customer rolls a real dice at the counter and staff enter the numbers.
 * A "roll again" face asks for another roll; the first other face decides the prize.
 * Eligibility uses the bill's value before any discount.
 */
class DiceService
{
    public const TYPES = ['discount', 'free_item', 'reroll', 'none'];

    /** The offer running today, if any. With several, the newest wins. */
    public function current(): ?DiceOffer
    {
        $today = now()->toDateString();

        return DiceOffer::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->latest('id')->first();
    }

    /** What the bill screen needs: the offer, whether this bill qualifies, and each face with its choices. */
    public function forBill(int $subtotalPaise): ?array
    {
        $offer = $this->current();
        if (! $offer) {
            return null;
        }

        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'min_bill' => $offer->min_bill,
            'eligible' => $subtotalPaise >= Money::toPaise($offer->min_bill),
            'faces' => collect($offer->faces)->values()->map(fn ($face, $i) => [
                'number' => $i + 1,
                'type' => $face['type'],
                'label' => $face['label'] ?? '',
                'amount' => $face['type'] === 'discount' ? number_format((float) ($face['amount'] ?? 0), 2, '.', '') : null,
                'choices' => $face['type'] === 'free_item' ? $this->choices($face) : [],
            ])->all(),
        ];
    }

    /**
     * Checks a roll and works out the prize. Returns null when no roll was sent.
     *
     * @param  array{rolls?: array<int,int>, product_id?: int|null, product_variant_id?: int|null}|null  $input
     * @return array{offer: DiceOffer, rolls: array<int,int>, prize: string, discount_paise: int, product: ?Product, variant: ?ProductVariant}|null
     */
    public function resolve(?array $input, int $subtotalPaise): ?array
    {
        $rolls = array_map('intval', $input['rolls'] ?? []);
        if (! $rolls) {
            return null;
        }
        $offer = $this->current();
        if (! $offer) {
            throw new BusinessException('The dice offer is not running today.', 'dice_not_running');
        }
        if ($subtotalPaise < Money::toPaise($offer->min_bill)) {
            throw new BusinessException('This bill is under '.Money::fromPaise(Money::toPaise($offer->min_bill)).', so it cannot roll the dice.', 'dice_not_eligible');
        }
        $faces = array_values($offer->faces);
        foreach ($rolls as $i => $roll) {
            if ($roll < 1 || $roll > 6) {
                throw new BusinessException('A dice roll must be a number from 1 to 6.', 'dice_invalid_roll');
            }
            $isLast = $i === count($rolls) - 1;
            $again = ($faces[$roll - 1]['type'] ?? 'none') === 'reroll';
            if ($again && $isLast) {
                throw new BusinessException("{$roll} means roll again. Enter the next roll.", 'dice_roll_again');
            }
            if (! $again && ! $isLast) {
                throw new BusinessException("Only the last roll can win. {$roll} does not say roll again.", 'dice_invalid_roll');
            }
        }

        $last = end($rolls);
        $face = $faces[$last - 1];
        $label = trim(($face['label'] ?? '') ?: ucfirst(str_replace('_', ' ', $face['type'])));
        $result = ['offer' => $offer, 'rolls' => $rolls, 'prize' => "Rolled {$last}: {$label}", 'discount_paise' => 0, 'product' => null, 'variant' => null];

        if ($face['type'] === 'discount') {
            $result['discount_paise'] = Money::toPaise((string) ($face['amount'] ?? 0));
        } elseif ($face['type'] === 'free_item') {
            $productId = (int) ($input['product_id'] ?? 0);
            $variantId = isset($input['product_variant_id']) ? (int) $input['product_variant_id'] : null;
            $allowed = collect($this->choices($face))->first(fn ($c) => $c['product_id'] === $productId && $c['product_variant_id'] === $variantId);
            if (! $allowed) {
                throw new BusinessException('Choose which free item the customer gets.', 'dice_choose_item');
            }
            $result['product'] = Product::with('category')->findOrFail($productId);
            $result['variant'] = $variantId ? ProductVariant::where('product_id', $productId)->findOrFail($variantId) : null;
            $result['prize'] = "Rolled {$last}: free {$allowed['name']}";
        }

        return $result;
    }

    /** The items a free-item face can give: the chosen items, and everything in the chosen categories. */
    public function choices(array $face): array
    {
        $categoryIds = $this->withChildren(array_map('intval', $face['category_ids'] ?? []));
        $products = Product::with(['category', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', array_map('intval', $face['product_ids'] ?? []))->orWhereIn('category_id', $categoryIds))
            ->orderBy('name')->get();

        return $products->flatMap(fn (Product $p) => $p->variants->isNotEmpty()
            ? $p->variants->map(fn ($v) => ['product_id' => $p->id, 'product_variant_id' => $v->id, 'name' => "{$p->name} · {$v->name}", 'category_name' => $p->category?->name, 'price' => (string) $v->price])
            : [['product_id' => $p->id, 'product_variant_id' => null, 'name' => $p->name, 'category_name' => $p->category?->name, 'price' => (string) $p->price]]
        )->values()->all();
    }

    private function withChildren(array $ids): array
    {
        $all = $ids;
        while ($ids) {
            $ids = Category::whereIn('parent_id', $ids)->whereNotIn('id', $all)->pluck('id')->all();
            $all = array_merge($all, $ids);
        }

        return $all;
    }
}
