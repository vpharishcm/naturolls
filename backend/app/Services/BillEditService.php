<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a finished bill. The invoice keeps its number and is updated in place; money
 * that changes hands is a new payment row (negative when returned), so the payment rows
 * of a bill always add up to its total and the day's cash stays right.
 */
class BillEditService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private BillingService $billing,
        private PricingService $pricing,
        private InventoryService $inventory,
        private LoyaltyService $loyalty,
        private SettingsService $settings,
        private AuditService $audit,
    ) {}

    /** When staff can no longer edit this bill; null for someone who can edit at any time. */
    public function editableUntil(Invoice $invoice, User $user): ?Carbon
    {
        if ($user->hasPermission('invoice.edit_anytime')) {
            return null;
        }

        return $invoice->issued_at->copy()->addMinutes((int) $this->settings->get('billing.staff_edit_minutes'));
    }

    public function canEdit(Invoice $invoice, User $user): bool
    {
        if ((float) $invoice->refunded_amount > 0) {
            return false;
        }
        $until = $this->editableUntil($invoice, $user);

        return $until === null || now()->lte($until);
    }

    /** The result of an edit, worked out for real and then rolled back. */
    public function preview(Invoice $invoice, array $data, User $user): array
    {
        DB::beginTransaction();
        try {
            return $this->apply($invoice, $data, $user, true);
        } finally {
            DB::rollBack();
        }
    }

    public function edit(Invoice $invoice, array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $user) {
            $this->apply($invoice, $data, $user, false);

            return $invoice->fresh();
        }, self::ATTEMPTS);
    }

    private function apply(Invoice $invoice, array $data, User $user, bool $preview): array
    {
        $order = Order::whereKey($invoice->order_id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        if ((float) $invoice->refunded_amount > 0) {
            throw new BusinessException('This bill has a refund, so it can no longer be edited.', 'invoice_refunded', 409);
        }
        if (! $this->canEdit($invoice, $user)) {
            $minutes = (int) $this->settings->get('billing.staff_edit_minutes');
            throw new BusinessException("Staff can edit a bill for {$minutes} minutes after billing. Ask the owner to change this one.", 'edit_window_closed', 403);
        }
        if ((int) $data['expected_edit_count'] !== (int) $invoice->edit_count) {
            throw new BusinessException('This bill was just changed on another screen. Check it and try again.', 'order_changed', 409);
        }

        $before = OrderItem::where('order_id', $order->id)->get();
        $beforeItems = $this->itemSummary($before);
        $changes = $this->changeItems($order, $invoice, $before, $data, $user);
        $items = OrderItem::where('order_id', $order->id)->orderBy('stock_key')->get();
        if ($items->isEmpty()) {
            throw new BusinessException('A bill needs at least one item. To give all the money back, use Refund.', 'empty_order');
        }

        $totals = $this->newTotals($invoice, $items);
        $difference = bcsub($totals['total'], (string) $invoice->total, 2);
        $result = $totals + ['previous_total' => (string) $invoice->total, 'difference' => $difference, 'changed' => $changes !== []];
        if ($preview) {
            return $result;
        }

        if ($changes === []) {
            throw new BusinessException('Nothing on this bill has changed.', 'no_changes');
        }
        if (bccomp($difference, '0', 2) !== 0) {
            if (empty($data['settlement_method'])) {
                throw new BusinessException('Choose how the difference was paid.', 'settlement_required');
            }
            Payment::create([
                'invoice_id' => $invoice->id,
                'type' => 'payment',
                'method' => $data['settlement_method'],
                'amount' => $difference,
                'status' => 'success',
                'reason' => 'Bill edited: '.$data['reason'],
                'user_id' => $user->id,
            ]);
        }

        if ($invoice->customer_id) {
            $this->loyalty->settleEdit($invoice->customer_id, $invoice->id, (string) $invoice->total, $totals['total'], $user);
        }

        $cost = $items->sum(fn ($i) => $i->unit_cost === null ? 0 : Money::toPaise(Money::lineTotal((string) $i->unit_cost, (string) $i->quantity)));
        $snapshot = $invoice->snapshot;
        $snapshot['items'] = $items->map(fn ($i) => [
            'name' => $i->product_name,
            'category' => $i->categoryName(),
            'quantity' => $i->quantity,
            'unit_price' => $i->unit_price,
            'gst_rate' => (float) $invoice->tax_amount > 0 ? $i->gst_rate : 0,
            'line_total' => $i->line_total,
        ])->values()->all();

        $beforeTotal = (string) $invoice->total;
        // A corrected bill starts its printing again, so its first print is not a duplicate.
        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'promotion_discount' => $totals['promotion_discount'],
            'manual_discount' => $totals['manual_discount'],
            'dice_discount' => $totals['dice_discount'],
            'loyalty_discount' => $totals['loyalty_discount'],
            'taxable_amount' => $totals['taxable_amount'],
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
            'cost_total' => Money::fromPaise($cost),
            'tax_breakup' => $totals['tax_breakup'],
            'snapshot' => $snapshot,
            'print_count' => 0,
            'edit_count' => $invoice->edit_count + 1,
            'edited_at' => now(),
            'edited_by' => $user->id,
        ]);
        $invoice->recordPromotions($totals['promotions']);
        $order->update([
            'item_count' => number_format((float) $items->sum('quantity'), 3, '.', ''),
            'subtotal' => $totals['subtotal'],
            'version' => $order->version + 1,
        ]);

        foreach ($changes as $change) {
            $this->audit->record($user, 'invoice.item_changed', $order, ['item' => $change['item'], 'quantity' => $change['from']], ['quantity' => $change['to']], $data['reason']);
        }
        $this->audit->record($user, 'invoice.edited', $invoice, ['total' => $beforeTotal, 'items' => $beforeItems], ['total' => $totals['total'], 'items' => $this->itemSummary($items), 'settled_by' => $data['settlement_method'] ?? null], $data['reason']);
        $this->audit->activity($user, 'invoice.edited', $invoice->invoice_number);

        return $result;
    }

    /** Brings the order's lines in line with the requested list. Returns what changed. */
    private function changeItems(Order $order, Invoice $invoice, $existing, array $data, User $user): array
    {
        $returnToStock = $data['return_to_stock'] ?? true;
        $wanted = [];
        $new = [];
        foreach ($data['items'] as $line) {
            if (! empty($line['id'])) {
                $wanted[(int) $line['id']] = bcadd($wanted[(int) $line['id']] ?? '0', number_format((float) $line['quantity'], 3, '.', ''), 3);
            } else {
                $new[] = $line;
            }
        }
        $unknown = array_diff(array_keys($wanted), $existing->pluck('id')->all());
        if ($unknown) {
            throw new BusinessException('An item on this list is not on this bill.', 'item_not_on_bill', 422);
        }

        $changes = [];
        foreach ($existing->sortBy('stock_key') as $item) {
            $target = $wanted[$item->id] ?? '0.000';
            $old = (string) $item->quantity;
            if (bccomp($target, $old, 3) === 0) {
                continue;
            }
            $this->setQuantity($item, $target, $returnToStock, $invoice, $user);
            $changes[] = ['item' => $item->product_name, 'from' => $old, 'to' => $target];
        }

        foreach ($new as $line) {
            $qty = number_format((float) $line['quantity'], 3, '.', '');
            if (bccomp($qty, '0', 3) <= 0) {
                continue;
            }
            $item = $this->lineFor($order, $line);
            $old = (string) $item->quantity;
            $this->setQuantity($item, bcadd($old, $qty, 3), $returnToStock, $invoice, $user);
            $changes[] = ['item' => $item->product_name, 'from' => $old, 'to' => (string) $item->quantity];
        }

        return $changes;
    }

    /** A billed line is fully handed over, so stock moves at once in either direction. */
    private function setQuantity(OrderItem $item, string $target, bool $returnToStock, Invoice $invoice, User $user): void
    {
        $tracked = Product::withTrashed()->whereKey($item->product_id)->value('track_inventory');
        $delta = bcsub($target, (string) $item->quantity, 3);
        if ($tracked && bccomp($delta, '0', 3) > 0) {
            $this->inventory->reserve($item, $delta, $user);
            $this->inventory->consume($item, $delta, $user);
        } elseif ($tracked && $returnToStock && bccomp($delta, '0', 3) < 0) {
            $this->inventory->restock($item->stock_key, bcmul($delta, '-1', 3), 'Bill edited '.$invoice->invoice_number, $user, 'OrderItem', $item->id);
        }
        $item->quantity = $target;
        $item->served_qty = $target;
        $item->line_total = Money::lineTotal((string) $item->unit_price, $target);
        $item->save();
        if (bccomp($target, '0', 3) === 0) {
            $item->delete();
        }
    }

    /** A product added to the bill sells at today's price, joining a line with the same item and price. */
    private function lineFor(Order $order, array $line): OrderItem
    {
        $product = Product::find($line['product_id']);
        if (! $product || ! $product->is_active) {
            throw new BusinessException('This item is no longer on sale.', 'product_inactive');
        }
        $variant = ! empty($line['product_variant_id'])
            ? ProductVariant::where('product_id', $product->id)->whereKey($line['product_variant_id'])->first()
            : null;
        if (! empty($line['product_variant_id']) && ! $variant) {
            throw new BusinessException('This option is no longer on sale.', 'product_inactive');
        }
        if ($product->track_inventory) {
            $this->inventory->ensureRow($product, $variant);
        }
        $key = InventoryService::stockKey($product->id, $variant?->id);
        $price = (string) ($variant?->price ?? $product->price);

        return OrderItem::where('order_id', $order->id)->where('stock_key', $key)->where('unit_price', $price)->first()
            ?? OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'stock_key' => $key,
                'product_name' => $product->name.($variant ? ' · '.$variant->name : ''),
                'category_name' => $product->category?->name,
                'sku' => $variant?->sku ?? $product->sku,
                'unit_price' => $price,
                'unit_cost' => $variant?->cost ?? $product->cost,
                'gst_rate' => $product->gst_rate,
                'quantity' => 0,
                'line_total' => 0,
            ]);
    }

    /**
     * The bill's own terms carry over: the same offers where they still qualify, even if they
     * have since ended, the same rupees of manual discount and points, and GST only if it was charged.
     */
    private function newTotals(Invoice $invoice, $items): array
    {
        $lines = $this->billing->lines($items, (float) $invoice->tax_amount > 0);
        $ids = $invoice->promotions()->pluck('promotions.id')->all() ?: array_filter([$invoice->promotion_id]);
        $promotions = $this->pricing->bestPromotions(
            Promotion::withTrashed()->with('products', 'categories', 'getProducts', 'getCategories')->whereIn('id', $ids)->get(),
            $lines,
            checkDates: false,
        );
        $manual = (float) $invoice->manual_discount > 0 ? ['type' => 'fixed', 'value' => $invoice->manual_discount] : null;
        // A dice prize was won when the bill was paid, so its rupees off carry over like a manual discount.
        $totals = $this->pricing->calculate($lines, $promotions, $manual, Money::toPaise($invoice->loyalty_discount), (bool) $this->settings->get('tax.prices_inclusive'), Money::toPaise($invoice->dice_discount));
        if (bccomp($totals['loyalty_discount'], (string) $invoice->loyalty_discount, 2) !== 0) {
            throw new BusinessException('The points used on this bill are worth more than the new total. Use Refund instead.', 'points_exceed_total');
        }

        return $totals + ['promotion' => implode(', ', array_column($totals['promotions'], 'name')) ?: null];
    }

    private function itemSummary($items): array
    {
        return $items->map(fn ($i) => ['name' => $i->product_name, 'quantity' => (string) $i->quantity, 'price' => (string) $i->unit_price])->values()->all();
    }
}
