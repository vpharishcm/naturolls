<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(
        private PricingService $pricing,
        private InventoryService $inventory,
        private OrderService $orders,
        private LoyaltyService $loyalty,
        private SequenceService $sequences,
        private SettingsService $settings,
        private AuditService $audit,
        private DiceService $dice,
    ) {}

    /** Totals for the given order and choices, using its latest saved items. */
    public function preview(Order $order, array $input): array
    {
        $items = OrderItem::where('order_id', $order->id)->get();
        $lines = $this->lines($items);
        $promotions = $this->choosePromotions($input, $lines);
        $points = (int) ($input['redeem_points'] ?? 0);
        $gross = $this->grossPaise($items);
        $dice = $this->dice->resolve($input['dice'] ?? null, $gross);
        $totals = $this->pricing->calculate(
            $lines,
            $promotions,
            $input['manual_discount'] ?? null,
            $this->loyalty->pointsToPaise($points),
            (bool) $this->settings->get('tax.prices_inclusive'),
            $dice['discount_paise'] ?? 0,
        );

        return $totals + [
            // The offer that saved the most, for screens that show one name.
            'promotion' => $this->biggest($totals['promotions']),
            'available_promotions' => $this->availablePromotions($lines),
            'dice' => $this->dice->forBill($gross),
            'dice_prize' => $dice['prize'] ?? null,
            'version' => $order->version,
        ];
    }

    /** The bill's value before any discount, which decides whether it can roll the dice. */
    private function grossPaise($items): int
    {
        return (int) $items->sum(fn ($i) => Money::toPaise($i->line_total));
    }

    /**
     * Completes a bill as one transaction: consumes remaining stock, issues the invoice,
     * records payments and closes the order. Printing happens afterwards, so a printer
     * fault can never undo a completed payment.
     */
    public function checkout(Order $order, array $input, User $user): Invoice
    {
        return DB::transaction(function () use ($order, $input, $user) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'billed') {
                throw new BusinessException("Order {$locked->order_number} is already billed.", 'already_billed', 409, ['invoice_id' => $locked->invoice?->id]);
            }
            if ($locked->status === 'cancelled') {
                throw new BusinessException('A cancelled order cannot be billed.', 'order_cancelled', 409);
            }
            if ((int) $input['expected_version'] !== (int) $locked->version) {
                throw new BusinessException('This order was just changed on another screen. Check the items and try again.', 'order_changed', 409, ['version' => $locked->version]);
            }

            $items = OrderItem::where('order_id', $locked->id)->orderBy('stock_key')->get();
            if ($items->isEmpty()) {
                throw new BusinessException('Add at least one item before billing.', 'empty_order');
            }
            if ($items->first(fn ($i) => bccomp((string) $i->stock_shortfall, '0', 3) > 0)) {
                throw new BusinessException('Some items are short of stock after syncing. Fix them before billing.', 'stock_shortfall', 409);
            }

            // A free item won on the dice joins the order at no charge, so its stock is deducted like any other.
            $dice = $this->dice->resolve($input['dice'] ?? null, $this->grossPaise($items));
            if ($dice && $dice['product']) {
                $prize = $this->orders->addItem($locked, $dice['product'], $dice['variant'], '1.000', $user, false, '0.00');
                $prize->update(['product_name' => $prize->product_name.' (dice prize)']);
                $locked->refresh();
                $items = OrderItem::where('order_id', $locked->id)->orderBy('stock_key')->get();
            }

            $lines = $this->lines($items);
            $promotions = $this->choosePromotions($input, $lines);
            $authorizerId = null;
            $manual = $input['manual_discount'] ?? null;
            $wasAuthorized = false;

            if (collect($promotions)->contains(fn (Promotion $p) => $p->requires_authorization)) {
                $authorizerId = $this->audit->authorize($user, 'discount.apply', $input['manager_pin'] ?? null);
                $wasAuthorized = true;
            }
            if ($manual && (float) $manual['value'] > 0) {
                $staffMax = (float) $this->settings->get('discount.staff_max_percent');
                $withinStaffLimit = $manual['type'] === 'percent' && (float) $manual['value'] <= $staffMax;
                if (! $withinStaffLimit) {
                    $authorizerId = $this->audit->authorize($user, 'discount.apply', $input['manager_pin'] ?? null);
                    $wasAuthorized = true;
                }
            } else {
                $manual = null;
            }

            $points = (int) ($input['redeem_points'] ?? 0);
            $customerId = $locked->customer_id;
            $this->loyalty->validateRedemption($customerId, $points);

            $totals = $this->pricing->calculate($lines, $promotions, $manual, $this->loyalty->pointsToPaise($points), (bool) $this->settings->get('tax.prices_inclusive'), $dice['discount_paise'] ?? 0);

            $paid = 0;
            foreach ($input['payments'] ?? [] as $p) {
                $paid += Money::toPaise($p['amount']);
            }
            if ($paid !== Money::toPaise($totals['total'])) {
                throw new BusinessException('Payments ('.Money::fromPaise($paid).') must equal the bill total ('.$totals['total'].').', 'payment_mismatch', 422, ['total' => $totals['total']]);
            }

            foreach ($items as $item) {
                $remaining = bcsub((string) $item->quantity, (string) $item->served_qty, 3);
                if (bccomp($remaining, '0', 3) > 0) {
                    $tracked = Product::withTrashed()->whereKey($item->product_id)->value('track_inventory');
                    if ($tracked) {
                        $this->inventory->consume($item, $remaining, $user);
                    }
                    $item->served_qty = $item->quantity;
                    $item->save();
                }
            }

            $cost = 0;
            foreach ($items as $item) {
                if ($item->unit_cost !== null) {
                    $cost += Money::toPaise(Money::lineTotal((string) $item->unit_cost, (string) $item->quantity));
                }
            }

            $invoice = Invoice::create([
                'invoice_number' => $this->sequences->invoiceNumber(),
                'order_id' => $locked->id,
                'customer_id' => $customerId,
                'billed_by' => $user->id,
                'subtotal' => $totals['subtotal'],
                'promotion_discount' => $totals['promotion_discount'],
                'manual_discount' => $totals['manual_discount'],
                'dice_discount' => $totals['dice_discount'],
                'loyalty_discount' => $totals['loyalty_discount'],
                'taxable_amount' => $totals['taxable_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'cost_total' => Money::fromPaise($cost),
                'promotion_id' => $this->biggest($totals['promotions'])['id'] ?? null,
                'dice_offer_id' => $dice['offer']->id ?? null,
                'dice_rolls' => $dice['rolls'] ?? null,
                'dice_prize' => $dice['prize'] ?? null,
                'discount_authorized_by' => $wasAuthorized ? $authorizerId : null,
                'discount_reason' => $input['discount_reason'] ?? null,
                'tax_breakup' => $totals['tax_breakup'],
                'snapshot' => [
                    'order_number' => $locked->order_number,
                    'customer_name' => $locked->customer_name,
                    'customer_mobile' => $locked->customer_mobile,
                    'staff' => $user->name,
                    'items' => $items->map(fn ($i) => [
                        'name' => $i->product_name,
                        'category' => $i->categoryName(),
                        'quantity' => $i->quantity,
                        'unit_price' => $i->unit_price,
                        'gst_rate' => $this->settings->get('tax.enabled') ? $i->gst_rate : 0,
                        'line_total' => $i->line_total,
                    ])->values()->all(),
                ],
                'issued_at' => now(),
            ]);

            $invoice->recordPromotions($totals['promotions']);

            foreach ($input['payments'] ?? [] as $p) {
                $amount = number_format((float) $p['amount'], 2, '.', '');
                $tendered = isset($p['tendered']) ? number_format((float) $p['tendered'], 2, '.', '') : null;
                if ($tendered !== null && $p['method'] === 'cash' && bccomp($tendered, $amount, 2) < 0) {
                    throw new BusinessException('Cash received is less than the amount due.', 'cash_short');
                }
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'type' => 'payment',
                    'method' => $p['method'],
                    'amount' => $amount,
                    'tendered' => $tendered,
                    'change_given' => $tendered !== null ? bcsub($tendered, $amount, 2) : 0,
                    'reference' => $p['reference'] ?? null,
                    'status' => 'success',
                    'user_id' => $user->id,
                ]);
            }

            if ($customerId) {
                $this->loyalty->settleInvoice($customerId, $invoice->id, $points, $totals['total'], $user);
            }

            $locked->update([
                'status' => 'billed',
                'billed_by' => $user->id,
                'billed_at' => now(),
                'version' => $locked->version + 1,
            ]);

            $this->audit->record($user, 'invoice.issued', $invoice, null, array_filter(['order' => $locked->order_number, 'total' => $totals['total'], 'dice' => $dice ? ['rolls' => $dice['rolls'], 'prize' => $dice['prize']] : null]), $input['discount_reason'] ?? null, $authorizerId);
            $this->audit->activity($user, 'order.billed', $invoice->invoice_number);

            return $invoice;
        }, 3);
    }

    public function lines($items, ?bool $taxed = null): array
    {
        $taxed ??= (bool) $this->settings->get('tax.enabled');
        // Items given free (a dice prize) have nothing to discount or tax, and must not count towards offers.
        $items = $items->filter(fn ($i) => bccomp((string) $i->unit_price, '0', 2) > 0);
        $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
        $parents = Category::withTrashed()->pluck('parent_id', 'id');

        return $items->values()->map(function ($item) use ($products, $parents, $taxed) {
            $category = $products[$item->product_id]->category_id ?? null;
            $ids = [];
            while ($category && ! in_array($category, $ids)) {
                $ids[] = $category;
                $category = $parents[$category] ?? null;
            }

            return [
                'id' => $item->id,
                'line_total' => $item->line_total,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                // Items keep their rates while GST is off, ready for when it is turned on.
                'gst_rate' => $taxed ? $item->gst_rate : 0,
                'product_id' => $item->product_id,
                'category_ids' => $ids,
            ];
        })->all();
    }

    /**
     * The offers for this bill, in the order they apply. By default every live automatic
     * offer that helps is used, in the mix that saves the customer the most. A cashier can
     * instead pick the offers (promotion_ids, or the older single promotion_id), or none.
     *
     * @return array<int, Promotion>
     */
    private function choosePromotions(array $input, array $lines): array
    {
        if (! empty($input['no_promotion'])) {
            return [];
        }
        $picked = $input['promotion_ids'] ?? (! empty($input['promotion_id']) ? [$input['promotion_id']] : null);
        if ($picked !== null) {
            $picked = array_values(array_unique(array_map('intval', $picked)));
            $promotions = Promotion::with('products', 'categories', 'getProducts', 'getCategories')->whereIn('id', $picked)->get();
            if ($promotions->count() !== count($picked)) {
                throw new BusinessException('This offer does not apply to this order.', 'promotion_not_eligible');
            }
            foreach ($promotions as $promotion) {
                if (! $this->pricing->isEligible($promotion, $lines)) {
                    throw new BusinessException("The offer {$promotion->name} does not apply to this order.", 'promotion_not_eligible');
                }
            }

            return $this->pricing->bestPromotions($promotions, $lines);
        }

        return $this->pricing->bestPromotions($this->activePromotions()->reject(fn (Promotion $p) => $p->requires_authorization), $lines);
    }

    private function activePromotions()
    {
        return Promotion::with('products', 'categories', 'getProducts', 'getCategories')->where('is_active', true)->get();
    }

    /** @param  array<int, array{id:int|null, name:string, discount:string}>  $applied */
    private function biggest(array $applied): ?array
    {
        usort($applied, fn ($a, $b) => bccomp($b['discount'], $a['discount'], 2));

        return $applied ? ['id' => $applied[0]['id'], 'name' => $applied[0]['name']] : null;
    }

    private function availablePromotions(array $lines): array
    {
        return $this->activePromotions()
            ->filter(fn ($p) => $this->pricing->isEligible($p, $lines))
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'requires_authorization' => $p->requires_authorization,
                'discount' => Money::fromPaise($this->pricing->promotionDiscount($p, $lines)),
            ])->values()->all();
    }
}
