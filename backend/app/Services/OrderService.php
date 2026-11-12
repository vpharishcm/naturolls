<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /** A deadlocked transaction is retried automatically before it reaches the user. */
    private const ATTEMPTS = 3;

    public function __construct(
        private InventoryService $inventory,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    public function create(array $data, User $user): Order
    {
        return DB::transaction(function () use ($data, $user) {
            $order = Order::create([
                'order_number' => $this->sequences->orderNumber(),
                'status' => 'unbilled',
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_mobile' => $data['customer_mobile'] ?? null,
                'created_by' => $user->id,
                'terminal_id' => $this->audit->terminalId(),
            ]);
            $this->linkCustomer($order);
            $this->audit->activity($user, 'order.created', $order->order_number);

            return $order;
        }, self::ATTEMPTS);
    }

    public function updateCustomer(Order $order, array $data, User $user): Order
    {
        return DB::transaction(function () use ($order, $data, $user) {
            $order = $this->lockOpen($order->id);
            $order->fill(array_intersect_key($data, array_flip(['customer_id', 'customer_name', 'customer_mobile'])));
            $this->linkCustomer($order);
            $order->version++;
            $order->save();
            $this->audit->record($user, 'order.customer_updated', $order, null, $data);

            return $order;
        });
    }

    /** Adds to the matching line when the same product and price is already on the order. */
    public function addItem(Order $order, Product $product, ?ProductVariant $variant, string $qty, User $user, bool $allowShortfall = false, ?string $priceOverride = null): OrderItem
    {
        return DB::transaction(function () use ($order, $product, $variant, $qty, $user, $allowShortfall, $priceOverride) {
            $order = $this->lockOpen($order->id);
            if (! $product->is_active && ! $allowShortfall) {
                throw new BusinessException("{$product->name} is no longer on sale.", 'product_inactive');
            }
            $price = $priceOverride ?? (string) ($variant?->price ?? $product->price);
            $key = InventoryService::stockKey($product->id, $variant?->id);

            $item = OrderItem::where('order_id', $order->id)->where('stock_key', $key)
                ->where('unit_price', $price)->first();
            if (! $item) {
                $item = OrderItem::create([
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

            if ($product->track_inventory) {
                $this->inventory->ensureRow($product, $variant);
                $taken = $this->inventory->reserve($item, $qty, $user, $allowShortfall);
                $short = bcsub($qty, $taken, 3);
                if (bccomp($short, '0', 3) > 0) {
                    $item->stock_shortfall = bcadd((string) $item->stock_shortfall, $short, 3);
                }
                if ($product->inventory_method === 'on_add' && bccomp($taken, '0', 3) > 0) {
                    $this->inventory->consume($item, $taken, $user);
                    $item->served_qty = bcadd((string) $item->served_qty, $taken, 3);
                }
            }

            $before = (string) $item->quantity;
            $item->quantity = bcadd($before, $qty, 3);
            $item->line_total = Money::lineTotal((string) $item->unit_price, (string) $item->quantity);
            $item->save();
            $this->touch($order);
            $this->audit->record($user, 'order.item_added', $order, ['item' => $item->product_name, 'quantity' => $before], ['quantity' => $item->quantity]);

            return $item;
        }, self::ATTEMPTS);
    }

    /**
     * Changes a line's quantity. Reductions release unserved stock first. Stock already
     * served stays consumed unless an authorised user explicitly returns it to stock.
     */
    public function setQuantity(Order $order, OrderItem $item, string $newQty, User $user, bool $returnToStock = false, ?string $pin = null): Order
    {
        return DB::transaction(function () use ($order, $item, $newQty, $user, $returnToStock, $pin) {
            $order = $this->lockOpen($order->id);
            $item = OrderItem::where('order_id', $order->id)->whereKey($item->id)->firstOrFail();
            $product = Product::withTrashed()->find($item->product_id);
            $old = (string) $item->quantity;
            $delta = bcsub($newQty, $old, 3);

            if (bccomp($delta, '0', 3) > 0) {
                if ($product->track_inventory) {
                    $this->inventory->reserve($item, $delta, $user);
                    if ($product->inventory_method === 'on_add') {
                        $this->inventory->consume($item, $delta, $user);
                        $item->served_qty = bcadd((string) $item->served_qty, $delta, 3);
                    }
                }
            } elseif (bccomp($delta, '0', 3) < 0) {
                $reduce = bcmul($delta, '-1', 3);
                $short = (string) $item->stock_shortfall;
                $fromShort = bccomp($reduce, $short, 3) < 0 ? $reduce : $short;
                $item->stock_shortfall = bcsub($short, $fromShort, 3);
                $reduce = bcsub($reduce, $fromShort, 3);
                if ($product->track_inventory) {
                    $unserved = bcsub(bcsub($old, (string) $item->served_qty, 3), $short, 3);
                    $release = bccomp($reduce, $unserved, 3) < 0 ? $reduce : $unserved;
                    if (bccomp($release, '0', 3) > 0) {
                        $this->inventory->release($item, $release, $user, 'Quantity reduced');
                    }
                    $fromServed = bcsub($reduce, $release, 3);
                    if (bccomp($fromServed, '0', 3) > 0) {
                        $item->served_qty = bcsub((string) $item->served_qty, $fromServed, 3);
                        if ($returnToStock) {
                            $authorizer = $this->audit->authorize($user, 'stock.correct', $pin);
                            $this->inventory->restock($item->stock_key, $fromServed, 'Removed from order '.$order->order_number, $user, 'OrderItem', $item->id);
                            $this->audit->record($user, 'order.served_item_returned', $order, null, ['item' => $item->product_name, 'quantity' => $fromServed], null, $authorizer);
                        } else {
                            $this->audit->record($user, 'order.served_item_removed', $order, null, ['item' => $item->product_name, 'quantity' => $fromServed, 'stock_returned' => false]);
                        }
                    }
                }
            }

            $item->quantity = $newQty;
            $item->line_total = Money::lineTotal((string) $item->unit_price, $newQty);
            $item->save();
            $action = bccomp($newQty, '0', 3) === 0 ? 'order.item_removed' : 'order.item_quantity_changed';
            if (bccomp($newQty, '0', 3) === 0) {
                $item->delete();
            }
            $this->touch($order);
            $this->audit->record($user, $action, $order, ['item' => $item->product_name, 'quantity' => $old], ['quantity' => $newQty]);
            $this->audit->activity($user, 'order.modified', $order->order_number);

            return $order;
        }, self::ATTEMPTS);
    }

    /** One tap at the counter: the item has been handed over, so stock is deducted now. */
    public function markServed(Order $order, OrderItem $item, ?string $qty, User $user): Order
    {
        return DB::transaction(function () use ($order, $item, $qty, $user) {
            $order = $this->lockOpen($order->id);
            $item = OrderItem::where('order_id', $order->id)->whereKey($item->id)->firstOrFail();
            $remaining = bcsub(bcsub((string) $item->quantity, (string) $item->served_qty, 3), (string) $item->stock_shortfall, 3);
            $serve = $qty === null || bccomp($qty, $remaining, 3) > 0 ? $remaining : $qty;
            if (bccomp($serve, '0', 3) > 0) {
                $this->inventory->consume($item, $serve, $user);
                $item->served_qty = bcadd((string) $item->served_qty, $serve, 3);
                $item->save();
                $order->version++;
                $order->save();
            }

            return $order;
        });
    }

    /** After a sync conflict: try again to reserve what could not be reserved offline. */
    public function resolveShortfall(Order $order, OrderItem $item, User $user): Order
    {
        return DB::transaction(function () use ($order, $item, $user) {
            $order = $this->lockOpen($order->id);
            $item = OrderItem::where('order_id', $order->id)->whereKey($item->id)->firstOrFail();
            $this->inventory->reserve($item, (string) $item->stock_shortfall, $user);
            $item->stock_shortfall = 0;
            $item->conflict_note = null;
            $item->save();
            $this->touch($order);
            $this->audit->record($user, 'order.shortfall_resolved', $order, null, ['item' => $item->product_name]);

            return $order;
        });
    }

    /** Cancels an order. Unserved reservations go back; anything already served stays consumed. */
    public function cancel(Order $order, string $reason, User $user, ?string $pin): Order
    {
        return DB::transaction(function () use ($order, $reason, $user, $pin) {
            $order = $this->lockOpen($order->id);
            $authorizer = $this->audit->authorize($user, 'order.cancel', $pin);
            $consumed = [];
            foreach (OrderItem::where('order_id', $order->id)->with('reservation')->orderBy('stock_key')->get() as $item) {
                if ($item->reservation && bccomp((string) $item->reservation->quantity, '0', 3) > 0) {
                    $this->inventory->release($item, (string) $item->reservation->quantity, $user, 'Order cancelled');
                }
                if (bccomp((string) $item->served_qty, '0', 3) > 0) {
                    $consumed[] = ['item' => $item->product_name, 'quantity' => $item->served_qty];
                }
            }
            $order->update([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'version' => $order->version + 1,
            ]);
            $this->audit->record($user, 'order.cancelled', $order, null, ['served_not_returned' => $consumed], $reason, $authorizer);
            $this->audit->activity($user, 'order.cancelled', $order->order_number);

            return $order;
        }, self::ATTEMPTS);
    }

    public function lockOpen(int $orderId): Order
    {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        if ($order->status !== 'unbilled') {
            throw new BusinessException("Order {$order->order_number} is already {$order->status}.", 'order_closed', 409);
        }

        return $order;
    }

    private function touch(Order $order): void
    {
        $items = OrderItem::where('order_id', $order->id)->get();
        $order->item_count = number_format((float) $items->sum('quantity'), 3, '.', '');
        $order->subtotal = Money::fromPaise($items->sum(fn ($i) => Money::toPaise($i->line_total)));
        $order->version++;
        $order->save();
    }

    private function linkCustomer(Order $order): void
    {
        if ($order->customer_mobile && ! $order->customer_id) {
            $customer = Customer::firstOrCreate(
                ['mobile' => $order->customer_mobile],
                ['name' => $order->customer_name ?: 'Customer'],
            );
            $order->customer_id = $customer->id;
            $order->customer_name = $order->customer_name ?: $customer->name;
            $order->save();
        } elseif ($order->customer_id && ! $order->customer_name) {
            $customer = Customer::find($order->customer_id);
            $order->customer_name = $customer?->name;
            $order->customer_mobile = $order->customer_mobile ?: $customer?->mobile;
            $order->save();
        }
    }
}
