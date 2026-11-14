<?php

namespace App\Services;

use App\Models\Promotion;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Pure calculation of a bill, in paise. Discounts are applied in a fixed order
 * (promotions, manual, loyalty), spread across lines, then GST is worked out per rate.
 *
 * Several promotions can apply to one bill. Each unit on the bill counts towards at most
 * one offer: once a milk popsicle is used in "2 milk for ₹90" it cannot also be used in
 * another deal, but water popsicles on the same bill still get "2 water for ₹40".
 */
class PricingService
{
    /** Above this many candidate offers the best mix is found greedily instead of exhaustively. */
    private const EXHAUSTIVE_LIMIT = 6;

    /**
     * @param  array<int, array{id:int, line_total:string|float, gst_rate:string|float, product_id:int, category_ids:array}>  $lines
     * @param  Promotion|array<int, Promotion>|null  $promotions  applied in the order given
     * @param  array{type:string, value:string|float}|null  $manual
     */
    public function calculate(array $lines, Promotion|array|null $promotions, ?array $manual, int $loyaltyPaise, bool $inclusive, int $dicePaise = 0): array
    {
        $gross = array_map(fn ($l) => Money::toPaise($l['line_total']), $lines);
        $subtotal = array_sum($gross);

        $applied = $this->applyPromotions($this->toList($promotions), $lines);
        $promoPaise = min($subtotal, $applied['discount']);
        // A free item's value comes off the free items' lines, not the lines that earned it.
        $net = $this->subtract($gross, $applied['per_line']);

        // Rupees off won on the dice, after offers and before any manual discount.
        $dicePaise = min(max(0, $dicePaise), array_sum($net));
        $net = $this->subtract($net, $this->allocate($dicePaise, $net));

        $manualPaise = 0;
        if ($manual) {
            $base = array_sum($net);
            $manualPaise = $manual['type'] === 'percent'
                ? (int) round($base * min(100, (float) $manual['value']) / 100)
                : min($base, Money::toPaise($manual['value']));
            $net = $this->subtract($net, $this->allocate($manualPaise, $net));
        }

        $loyaltyPaise = min($loyaltyPaise, array_sum($net));
        $net = $this->subtract($net, $this->allocate($loyaltyPaise, $net));

        $taxByRate = [];
        foreach ($net as $i => $amount) {
            $rate = number_format((float) $lines[$i]['gst_rate'], 2, '.', '');
            $taxByRate[$rate] = ($taxByRate[$rate] ?? 0) + $amount;
        }
        $taxable = 0;
        $tax = 0;
        $breakup = [];
        foreach ($taxByRate as $rate => $amount) {
            $r = (float) $rate;
            if ($inclusive) {
                $base = (int) round($amount * 100 / (100 + $r));
                $gst = $amount - $base;
            } else {
                $base = $amount;
                $gst = (int) round($amount * $r / 100);
            }
            $taxable += $base;
            $tax += $gst;
            if ($amount > 0) {
                $half = intdiv($gst, 2);
                $breakup[] = [
                    'rate' => $rate,
                    'taxable' => Money::fromPaise($base),
                    'cgst' => Money::fromPaise($gst - $half),
                    'sgst' => Money::fromPaise($half),
                    'tax' => Money::fromPaise($gst),
                ];
            }
        }

        return [
            'subtotal' => Money::fromPaise($subtotal),
            'promotion_discount' => Money::fromPaise($promoPaise),
            'manual_discount' => Money::fromPaise($manualPaise),
            'dice_discount' => Money::fromPaise($dicePaise),
            'loyalty_discount' => Money::fromPaise($loyaltyPaise),
            'taxable_amount' => Money::fromPaise($taxable),
            'tax_amount' => Money::fromPaise($tax),
            'total' => Money::fromPaise($taxable + $tax),
            'tax_breakup' => $breakup,
            'promotions' => array_map(fn ($a) => [
                'id' => $a['promotion']->id,
                'name' => $a['promotion']->name,
                'discount' => Money::fromPaise($a['discount']),
            ], $applied['applied']),
        ];
    }

    public function isEligible(Promotion $promotion, array $lines): bool
    {
        return $this->isLive($promotion) && $this->qualifies($promotion, $lines);
    }

    /** Switched on and within its dates. */
    public function isLive(Promotion $promotion): bool
    {
        $now = now();

        return $promotion->is_active
            && ! ($promotion->starts_at && $promotion->starts_at->gt($now))
            && ! ($promotion->ends_at && $promotion->ends_at->lt($now));
    }

    /** Whether the items meet the offer's terms, whatever today's date. */
    public function qualifies(Promotion $promotion, array $lines): bool
    {
        if (! $this->meetsMinimum($promotion, $lines)) {
            return false;
        }

        if (in_array($promotion->discount_type, ['buy_get', 'bundle'], true)) {
            return $this->promotionDiscount($promotion, $lines) > 0;
        }

        $mask = $this->buyMask($promotion, $lines);
        foreach ($this->fresh($lines)['value'] as $i => $value) {
            if ($mask[$i] && $value > 0) {
                return true;
            }
        }

        return false;
    }

    /** What the offer saves on these items when it is the only offer on the bill. */
    public function promotionDiscount(Promotion $promotion, array $lines): int
    {
        return $this->dealOn($promotion, $lines, $this->fresh($lines))['discount'];
    }

    /** Picks the automatic promotion that saves the customer the most on its own. */
    public function bestPromotion(Collection $promotions, array $lines): ?Promotion
    {
        return $this->bestPromotions($promotions->filter(fn (Promotion $p) => ! $p->requires_authorization), $lines)[0] ?? null;
    }

    /**
     * The mix of offers that saves the customer the most, in the order to apply them.
     * Offers that are switched off, out of date or save nothing are left out.
     *
     * @param  iterable<Promotion>  $promotions
     * @return array<int, Promotion>
     */
    public function bestPromotions(iterable $promotions, array $lines, bool $checkDates = true): array
    {
        $start = $this->fresh($lines);
        $candidates = [];
        foreach ($promotions as $p) {
            if ($checkDates && ! $this->isLive($p)) {
                continue;
            }
            $step = $this->step($p, $lines, $start);
            if ($step) {
                $candidates[] = [$p, $step['discount']];
            }
        }
        // Biggest saving first, so ties go to the offer that is worth most by itself.
        usort($candidates, fn ($a, $b) => $b[1] <=> $a[1]);
        $candidates = array_column($candidates, 0);

        if (count($candidates) <= self::EXHAUSTIVE_LIMIT) {
            return $this->search($candidates, $lines, $start)[1];
        }

        $chosen = [];
        $left = $start;
        while ($candidates) {
            $best = null;
            foreach ($candidates as $k => $p) {
                $step = $this->step($p, $lines, $left);
                if ($step && (! $best || $step['discount'] > $best[1]['discount'])) {
                    $best = [$k, $step];
                }
            }
            if (! $best) {
                break;
            }
            $chosen[] = $candidates[$best[0]];
            $left = $best[1]['left'];
            unset($candidates[$best[0]]);
        }

        return $chosen;
    }

    /**
     * Applies offers one after another; each only sees the units earlier offers left.
     *
     * @param  array<int, Promotion>  $promotions
     * @return array{discount:int, per_line:array<int,int>, applied:array<int, array{promotion:Promotion, discount:int}>}
     */
    public function applyPromotions(array $promotions, array $lines): array
    {
        $left = $this->fresh($lines);
        $perLine = array_map(fn () => 0, $lines);
        $applied = [];
        foreach ($promotions as $p) {
            $step = $this->step($p, $lines, $left);
            if (! $step) {
                continue;
            }
            $perLine = $this->add($perLine, $step['per_line']);
            $left = $step['left'];
            $applied[] = ['promotion' => $p, 'discount' => $step['discount']];
        }

        return ['discount' => array_sum($perLine), 'per_line' => $perLine, 'applied' => $applied];
    }

    /** Tries every order of the candidates and keeps the one that saves the most. */
    private function search(array $candidates, array $lines, array $left): array
    {
        $best = [0, []];
        foreach ($candidates as $k => $p) {
            $step = $this->step($p, $lines, $left);
            if (! $step) {
                continue;
            }
            $rest = $candidates;
            unset($rest[$k]);
            [$total, $order] = $this->search($rest, $lines, $step['left']);
            if ($step['discount'] + $total > $best[0]) {
                $best = [$step['discount'] + $total, [$p, ...$order]];
            }
        }

        return $best;
    }

    /** One offer applied to what is left of the bill, or null when it saves nothing. */
    private function step(Promotion $promotion, array $lines, array $left): ?array
    {
        if (! $this->meetsMinimum($promotion, $lines)) {
            return null;
        }
        $deal = $this->dealOn($promotion, $lines, $left);
        if ($deal['discount'] <= 0) {
            return null;
        }
        foreach ($lines as $i => $line) {
            if ($deal['clear'][$i]) {
                $left['units'][$i] = 0;
                $left['value'][$i] = 0;
            } elseif ($deal['units'][$i] > 0) {
                $left['units'][$i] -= $deal['units'][$i];
                $left['value'][$i] = max(0, $left['value'][$i] - $deal['units'][$i] * Money::toPaise($line['unit_price']));
            }
        }

        return ['discount' => $deal['discount'], 'per_line' => $deal['per_line'], 'left' => $left];
    }

    /**
     * What one offer saves on the units still free to use, which lines it comes off, and
     * which units it uses up.
     *
     * @return array{discount:int, per_line:array<int,int>, units:array<int,int>, clear:array<int,bool>}
     */
    private function dealOn(Promotion $promotion, array $lines, array $left): array
    {
        $result = match ($promotion->discount_type) {
            'buy_get' => $this->buyGetDeal($promotion, $lines, $left),
            'bundle' => $this->bundleDeal($promotion, $lines, $left),
            default => $this->amountDeal($promotion, $lines, $left),
        };
        // A line never gives more than is left of it.
        foreach ($result['per_line'] as $i => $amount) {
            $result['per_line'][$i] = max(0, min($amount, $left['value'][$i]));
        }
        $result['discount'] = array_sum($result['per_line']);

        return $result;
    }

    /** Percent or rupees off the matching lines; those lines are then used up. */
    private function amountDeal(Promotion $promotion, array $lines, array $left): array
    {
        $mask = $this->buyMask($promotion, $lines);
        $weights = array_map(fn ($i) => $mask[$i] ? $left['value'][$i] : 0, array_keys($lines));
        $base = array_sum($weights);
        $discount = $promotion->discount_type === 'percent'
            ? (int) round($base * min(100, (float) $promotion->discount_value) / 100)
            : min($base, Money::toPaise($promotion->discount_value));

        return [
            'per_line' => $this->allocate($discount, $weights),
            'units' => array_map(fn () => 0, $lines),
            'clear' => array_map(fn ($w) => $w > 0, $weights),
        ];
    }

    /**
     * Buy 2 get 1 free: each full set makes one unit from the get list free, and the
     * cheapest are the free ones. No unit counts twice: an item on both lists is either
     * bought or free, so buy 2 get 1 free on the same item needs 3 on the bill.
     */
    private function buyGetDeal(Promotion $promotion, array $lines, array $left): array
    {
        $buys = $this->buyMask($promotion, $lines);
        $gets = $this->getMask($promotion, $lines);
        $units = [];
        $onlyBuy = 0;
        $both = 0;
        foreach ($lines as $i => $line) {
            if (! $buys[$i] && ! $gets[$i]) {
                continue;
            }
            $count = $left['units'][$i];
            for ($k = 0; $k < $count; $k++) {
                $units[] = ['i' => $i, 'price' => Money::toPaise($line['unit_price']), 'buy' => $buys[$i], 'get' => $gets[$i]];
            }
            if ($buys[$i] && $gets[$i]) {
                $both += $count;
            } elseif ($buys[$i]) {
                $onlyBuy += $count;
            }
        }
        $free = array_keys(array_filter($units, fn ($u) => $u['get']));
        $buy = max(1, (int) $promotion->buy_qty);
        $get = max(1, (int) $promotion->get_qty);
        $sets = min(intdiv($onlyBuy + $both, $buy), intdiv(count($free), $get), intdiv($onlyBuy + count($free), $buy + $get));

        $perLine = array_map(fn () => 0, $lines);
        $used = array_map(fn () => 0, $lines);
        if ($sets <= 0) {
            return ['per_line' => $perLine, 'units' => $used, 'clear' => array_map(fn () => false, $lines)];
        }

        // Free units may not use up the items the customer has to buy.
        $spareBoth = $onlyBuy + $both - $sets * $buy;
        usort($free, fn ($a, $b) => $units[$a]['price'] <=> $units[$b]['price']);
        $taken = [];
        $toFree = $sets * $get;
        foreach ($free as $k) {
            if ($toFree === 0) {
                break;
            }
            if ($units[$k]['buy']) {
                if ($spareBoth === 0) {
                    continue;
                }
                $spareBoth--;
            }
            $taken[$k] = true;
            $perLine[$units[$k]['i']] += $units[$k]['price'];
            $used[$units[$k]['i']]++;
            $toFree--;
        }

        // The units paid for in each set: buy-only items first, then the cheapest, so the
        // dearer ones stay free for other offers.
        $paid = array_keys(array_filter($units, fn ($u, $k) => $u['buy'] && ! isset($taken[$k]), ARRAY_FILTER_USE_BOTH));
        usort($paid, fn ($a, $b) => [$units[$a]['get'], $units[$a]['price']] <=> [$units[$b]['get'], $units[$b]['price']]);
        foreach (array_slice($paid, 0, $sets * $buy) as $k) {
            $used[$units[$k]['i']]++;
        }

        return ['per_line' => $perLine, 'units' => $used, 'clear' => array_map(fn () => false, $lines)];
    }

    /** 4 for ₹300: each full set of 4 costs the set price. Flavours mix, and the dearest units go in the sets. */
    private function bundleDeal(Promotion $promotion, array $lines, array $left): array
    {
        $mask = $this->buyMask($promotion, $lines);
        $units = [];
        foreach ($lines as $i => $line) {
            if ($mask[$i]) {
                for ($k = 0; $k < $left['units'][$i]; $k++) {
                    $units[] = [Money::toPaise($line['unit_price']), $i];
                }
            }
        }
        usort($units, fn ($a, $b) => $b[0] <=> $a[0]);
        $size = max(1, (int) $promotion->buy_qty);
        $sets = intdiv(count($units), $size);
        $inSets = array_slice($units, 0, $sets * $size);
        $discount = max(0, array_sum(array_column($inSets, 0)) - $sets * Money::toPaise($promotion->discount_value));

        $weights = array_map(fn () => 0, $lines);
        $used = array_map(fn () => 0, $lines);
        if ($discount > 0) {
            foreach ($inSets as [$price, $i]) {
                $weights[$i] += $price;
                $used[$i]++;
            }
        }

        return ['per_line' => $this->allocate($discount, $weights), 'units' => $used, 'clear' => array_map(fn () => false, $lines)];
    }

    /** Whole units and paise of each line that no offer has used yet. */
    private function fresh(array $lines): array
    {
        return [
            'units' => array_map(fn ($l) => max(0, (int) floor((float) ($l['quantity'] ?? 0))), $lines),
            'value' => array_map(fn ($l) => Money::toPaise($l['line_total']), $lines),
        ];
    }

    private function meetsMinimum(Promotion $promotion, array $lines): bool
    {
        $subtotal = array_sum(array_map(fn ($l) => Money::toPaise($l['line_total']), $lines));

        return $subtotal >= Money::toPaise($promotion->min_purchase ?? 0);
    }

    /** Lines the free units can come from: the get list, or the buy list when it is the same. */
    private function getMask(Promotion $promotion, array $lines): array
    {
        if (! in_array($promotion->get_scope, ['product', 'category'], true)) {
            return $this->buyMask($promotion, $lines);
        }
        $productIds = $promotion->get_scope === 'product' ? $promotion->getProducts->pluck('id')->all() : [];
        $categoryIds = $promotion->get_scope === 'category' ? $promotion->getCategories->pluck('id')->all() : [];

        return array_map(fn ($l) => $promotion->get_scope === 'product'
            ? in_array($l['product_id'], $productIds)
            : (bool) array_intersect($l['category_ids'], $categoryIds), $lines);
    }

    /** Lines the offer covers (for a deal, the ones that must be bought). */
    private function buyMask(Promotion $promotion, array $lines): array
    {
        $productIds = $promotion->scope === 'product' ? $promotion->products->pluck('id')->all() : [];
        $categoryIds = $promotion->scope === 'category' ? $promotion->categories->pluck('id')->all() : [];

        return array_map(fn ($l) => match ($promotion->scope) {
            'product' => in_array($l['product_id'], $productIds),
            'category' => (bool) array_intersect($l['category_ids'], $categoryIds),
            default => true,
        }, $lines);
    }

    private function toList(Promotion|array|null $promotions): array
    {
        if ($promotions === null) {
            return [];
        }

        return $promotions instanceof Promotion ? [$promotions] : array_values($promotions);
    }

    /** Splits an amount in proportion to weights; rounding leftovers land on the largest lines. */
    private function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($amount <= 0 || $total <= 0) {
            return array_map(fn () => 0, $weights);
        }
        $shares = array_map(fn ($w) => (int) floor($amount * $w / $total), $weights);
        $left = $amount - array_sum($shares);
        arsort($weights);
        foreach (array_keys($weights) as $i) {
            if ($left <= 0) {
                break;
            }
            $shares[$i]++;
            $left--;
        }

        return $shares;
    }

    private function add(array $a, array $b): array
    {
        foreach ($a as $i => $v) {
            $a[$i] = $v + $b[$i];
        }

        return $a;
    }

    private function subtract(array $a, array $b): array
    {
        foreach ($a as $i => $v) {
            $a[$i] = $v - $b[$i];
        }

        return $a;
    }
}
