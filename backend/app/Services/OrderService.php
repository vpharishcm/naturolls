<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\InventoryReservation;
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

    /**
     * Moves whole lines, or part of them, to another open order, or to a new order when no target is given.
     * Stock does not change: reserved stock moves with the items, and served items stay served.
     * If nothing is left on the source order, it is closed as merged into the target.
     *
     * @param  array<int, string>  $lines  order item id => quantity to move
     * @return array{0: Order, 1: Order} the source and target orders
     */
    public function moveItems(Order $source, ?int $targetId, array $lines, User $user, ?int $expectedVersion = null): array
    {
        return DB::transaction(function () use ($source, $targetId, $lines, $user, $expectedVersion) {
            if ($targetId === $source->id) {
                throw new BusinessException('Choose a different order.', 'same_order');
            }
            $toNewOrder = $targetId === null;
            $targetId ??= $this->create([], $user)->id;
            [$source, $target] = $this->lockPair($source->id, $targetId, $expectedVersion);

            $moved = $this->transfer($source, $target, $lines);
            $this->touch($target);
            if (! OrderItem::where('order_id', $source->id)->exists()) {
                if ($toNewOrder) {
                    // Everything to a brand-new order changes nothing but the number, so refuse (and roll back the new order).
                    throw new BusinessException('Leave at least one item on this order. To move all of them to another order, use Merge.', 'nothing_left');
                }
                $this->closeAsMerged($source, $target);
            } else {
                $this->touch($source);
            }
            $this->audit->record($user, 'order.items_moved', $source, null, ['to' => $target->order_number, 'items' => $moved]);
            $this->audit->activity($user, 'order.items_moved', "{$source->order_number} → {$target->order_number}");

            return [$source->fresh(), $target->fresh()];
        }, self::ATTEMPTS);
    }

    /** Moves every item to another open order and closes this one as merged. */
    public function merge(Order $source, int $targetId, User $user, ?int $expectedVersion = null): array
    {
        return DB::transaction(function () use ($source, $targetId, $user, $expectedVersion) {
            if ($targetId === $source->id) {
                throw new BusinessException('Choose a different order.', 'same_order');
            }
            [$source, $target] = $this->lockPair($source->id, $targetId, $expectedVersion);

            $lines = OrderItem::where('order_id', $source->id)->pluck('quantity', 'id')->map(fn ($q) => (string) $q)->all();
            // An empty order can be merged too, which is the quick way to close one opened by mistake.
            $moved = $lines ? $this->transfer($source, $target, $lines) : [];
            if (! $target->customer_id && ! $target->customer_name && ($source->customer_id || $source->customer_name)) {
                $target->fill($source->only(['customer_id', 'customer_name', 'customer_mobile']));
            }
            $this->touch($target);
            $this->closeAsMerged($source, $target);
            $this->audit->record($user, 'order.merged', $source, null, ['into' => $target->order_number, 'items' => $moved]);
            $this->audit->activity($user, 'order.merged', "{$source->order_number} → {$target->order_number}");

            return [$source->fresh(), $target->fresh()];
        }, self::ATTEMPTS);
    }

    /** Locks both orders in id order, so two people moving between the same pair cannot deadlock. */
    private function lockPair(int $sourceId, int $targetId, ?int $expectedVersion): array
    {
        $locked = [];
        foreach ([min($sourceId, $targetId), max($sourceId, $targetId)] as $id) {
            $locked[$id] = $this->lockOpen($id);
        }
        $source = $locked[$sourceId];
        if ($expectedVersion !== null && (int) $source->version !== $expectedVersion) {
            throw new BusinessException("Order {$source->order_number} was changed on another device. Check it and try again.", 'order_changed', 409);
        }

        return [$source, $locked[$targetId]];
    }

    /**
     * Moves quantities line by line. A line moved whole to an order without the same item and price
     * simply changes order. Otherwise it is split: reserved stock moves first, then any shortfall, then served items.
     */
    private function transfer(Order $source, Order $target, array $lines): array
    {
        if (! $lines) {
            throw new BusinessException('Choose at least one item to move.', 'nothing_to_move');
        }
        $moved = [];
        foreach ($lines as $itemId => $qty) {
            $qty = number_format((float) $qty, 3, '.', '');
            $item = OrderItem::where('order_id', $source->id)->whereKey($itemId)->lockForUpdate()->first();
            if (! $item) {
                throw new BusinessException('An item is no longer on this order. Check the order and try again.', 'order_changed', 409);
            }
            if (bccomp($qty, '0', 3) <= 0 || bccomp($qty, (string) $item->quantity, 3) > 0) {
                throw new BusinessException("Choose between 1 and {$this->plain($item->quantity)} of {$item->product_name}.", 'invalid_quantity');
            }
            $moved[] = ['item' => $item->product_name, 'quantity' => $qty];

            $match = OrderItem::where('order_id', $target->id)->where('stock_key', $item->stock_key)
                ->where('unit_price', $item->unit_price)->lockForUpdate()->first();
            $whole = bccomp($qty, (string) $item->quantity, 3) === 0;
            if ($whole && ! $match) {
                $item->update(['order_id' => $target->id]);

                continue;
            }

            $reservation = InventoryReservation::where('order_item_id', $item->id)->lockForUpdate()->first();
            $left = $qty;
            $reserved = $this->smaller($left, (string) ($reservation->quantity ?? '0'));
            $left = bcsub($left, $reserved, 3);
            $short = $this->smaller($left, (string) $item->stock_shortfall);
            $left = bcsub($left, $short, 3);
            $served = $this->smaller($left, (string) $item->served_qty);

            $dest = $match ?? tap($item->replicate(['order_id', 'quantity', 'served_qty', 'stock_shortfall', 'line_total', 'conflict_note']), function ($copy) use ($target) {
                $copy->fill(['order_id' => $target->id, 'quantity' => 0, 'served_qty' => 0, 'stock_shortfall' => 0, 'line_total' => 0])->save();
            });
            $dest->quantity = bcadd((string) $dest->quantity, $qty, 3);
            $dest->served_qty = bcadd((string) $dest->served_qty, $served, 3);
            $dest->stock_shortfall = bcadd((string) $dest->stock_shortfall, $short, 3);
            if (bccomp($short, '0', 3) > 0) {
                $dest->conflict_note ??= $item->conflict_note;
            }
            $dest->line_total = Money::lineTotal((string) $dest->unit_price, (string) $dest->quantity);
            $dest->save();

            if (bccomp($reserved, '0', 3) > 0) {
                $to = InventoryReservation::firstOrCreate(['order_item_id' => $dest->id], ['inventory_id' => $reservation->inventory_id, 'quantity' => 0]);
                $to->update(['quantity' => bcadd((string) $to->quantity, $reserved, 3)]);
                $reservation->update(['quantity' => bcsub((string) $reservation->quantity, $reserved, 3)]);
            }

            $item->quantity = bcsub((string) $item->quantity, $qty, 3);
            $item->served_qty = bcsub((string) $item->served_qty, $served, 3);
            $item->stock_shortfall = bcsub((string) $item->stock_shortfall, $short, 3);
            if (bccomp((string) $item->stock_shortfall, '0', 3) === 0) {
                $item->conflict_note = null;
            }
            $item->line_total = Money::lineTotal((string) $item->unit_price, (string) $item->quantity);
            $item->save();
            if (bccomp((string) $item->quantity, '0', 3) === 0) {
                $reservation?->delete();
                $item->delete();
            }
        }

        return $moved;
    }

    private function closeAsMerged(Order $source, Order $target): void
    {
        $source->update([
            'status' => 'merged',
            'merged_into_id' => $target->id,
            'item_count' => 0,
            'subtotal' => 0,
            'version' => $source->version + 1,
        ]);
    }

    private function smaller(string $a, string $b): string
    {
        return bccomp($a, $b, 3) <= 0 ? $a : $b;
    }

    private function plain(string|float $qty): string
    {
        return rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');
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
