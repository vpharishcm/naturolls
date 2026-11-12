<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * All stock changes go through here. Every method locks the inventory row, so
 * two terminals can never both take the last unit, and writes one ledger row.
 */
class InventoryService
{
    public static function stockKey(int $productId, ?int $variantId = null): string
    {
        return $variantId ? "p{$productId}-v{$variantId}" : "p{$productId}";
    }

    public function ensureRow(Product $product, ?ProductVariant $variant = null): Inventory
    {
        return Inventory::firstOrCreate(
            ['stock_key' => self::stockKey($product->id, $variant?->id)],
            ['product_id' => $product->id, 'product_variant_id' => $variant?->id],
        );
    }

    public function locked(string $stockKey): ?Inventory
    {
        return Inventory::where('stock_key', $stockKey)->lockForUpdate()->first();
    }

    /** Reserve as much as requested, or throw when $allowPartial is false and stock is short. Returns the reserved quantity. */
    public function reserve(OrderItem $item, string $qty, ?User $user, bool $allowPartial = false): string
    {
        return DB::transaction(function () use ($item, $qty, $user, $allowPartial) {
            $row = $this->locked($item->stock_key);
            if (! $row) {
                throw new BusinessException('This item has no stock record.', 'no_stock_record');
            }
            $take = $qty;
            if (bccomp($row->available_qty, $qty, 3) < 0) {
                if (! $allowPartial) {
                    throw new BusinessException(
                        "Only {$this->pretty($row->available_qty)} left of {$item->product_name}.",
                        'insufficient_stock',
                        409,
                        ['available' => $row->available_qty, 'stock_key' => $item->stock_key],
                    );
                }
                $take = bccomp($row->available_qty, '0', 3) > 0 ? $row->available_qty : '0.000';
            }
            if (bccomp($take, '0', 3) <= 0) {
                return '0.000';
            }
            $reservation = InventoryReservation::firstOrCreate(
                ['order_item_id' => $item->id],
                ['inventory_id' => $row->id, 'quantity' => 0],
            );
            $reservation->update(['quantity' => bcadd((string) $reservation->quantity, $take, 3)]);
            $this->apply($row, 'reserve', '0', $take, 'OrderItem', $item->id, null, $user);

            return $take;
        });
    }

    public function release(OrderItem $item, string $qty, ?User $user, string $reason = null): void
    {
        DB::transaction(function () use ($item, $qty, $user, $reason) {
            $row = $this->locked($item->stock_key);
            $reservation = InventoryReservation::where('order_item_id', $item->id)->lockForUpdate()->first();
            if (! $row || ! $reservation) {
                return;
            }
            $qty = $this->min($qty, (string) $reservation->quantity);
            if (bccomp($qty, '0', 3) <= 0) {
                return;
            }
            $reservation->update(['quantity' => bcsub((string) $reservation->quantity, $qty, 3)]);
            $this->apply($row, 'release', '0', bcmul($qty, '-1', 3), 'OrderItem', $item->id, $reason, $user);
        });
    }

    /** Turn a reservation into a real stock deduction (the item has left the counter). */
    public function consume(OrderItem $item, string $qty, ?User $user): void
    {
        DB::transaction(function () use ($item, $qty, $user) {
            $row = $this->locked($item->stock_key);
            $reservation = InventoryReservation::where('order_item_id', $item->id)->lockForUpdate()->first();
            if (! $row || ! $reservation) {
                return;
            }
            $qty = $this->min($qty, (string) $reservation->quantity);
            if (bccomp($qty, '0', 3) <= 0) {
                return;
            }
            $reservation->update(['quantity' => bcsub((string) $reservation->quantity, $qty, 3)]);
            $negative = bcmul($qty, '-1', 3);
            $this->apply($row, 'consume', $negative, $negative, 'OrderItem', $item->id, null, $user);
        });
    }

    public function produce(string $stockKey, string $qty, ?User $user, int $batchItemId): void
    {
        DB::transaction(function () use ($stockKey, $qty, $user, $batchItemId) {
            $this->apply($this->locked($stockKey), 'production', $qty, '0', 'ProductionBatchItem', $batchItemId, null, $user);
        });
    }

    public function reverseProduction(string $stockKey, string $qty, ?User $user, int $batchItemId, string $reason): void
    {
        DB::transaction(function () use ($stockKey, $qty, $user, $batchItemId, $reason) {
            $row = $this->locked($stockKey);
            if (bccomp($row->available_qty, $qty, 3) < 0) {
                throw new BusinessException('Cannot reverse: part of this batch is already sold or reserved.', 'insufficient_stock', 409);
            }
            $this->apply($row, 'production_reversal', bcmul($qty, '-1', 3), '0', 'ProductionBatchItem', $batchItemId, $reason, $user);
        });
    }

    /** Manual stock count: sets physical stock to the counted figure. */
    public function correct(int $inventoryId, string $counted, string $reason, ?User $user): Inventory
    {
        return DB::transaction(function () use ($inventoryId, $counted, $reason, $user) {
            $row = Inventory::whereKey($inventoryId)->lockForUpdate()->firstOrFail();
            if (bccomp($counted, (string) $row->reserved_qty, 3) < 0) {
                throw new BusinessException("{$this->pretty($row->reserved_qty)} units are reserved by open orders, so the count cannot be lower.", 'below_reserved');
            }

            return $this->apply($row, 'correction', bcsub($counted, (string) $row->physical_qty, 3), '0', 'Inventory', $row->id, $reason, $user);
        });
    }

    public function opening(int $inventoryId, string $qty, ?User $user): Inventory
    {
        return DB::transaction(function () use ($inventoryId, $qty, $user) {
            $row = Inventory::whereKey($inventoryId)->lockForUpdate()->firstOrFail();
            $row->opening_qty = $qty;

            return $this->apply($row, 'opening', bcsub($qty, (string) $row->physical_qty, 3), '0', 'Inventory', $row->id, 'Opening stock', $user);
        });
    }

    public function wastage(int $inventoryId, string $qty, string $reason, ?User $user): Inventory
    {
        return DB::transaction(function () use ($inventoryId, $qty, $reason, $user) {
            $row = Inventory::whereKey($inventoryId)->lockForUpdate()->firstOrFail();
            if (bccomp($row->available_qty, $qty, 3) < 0) {
                throw new BusinessException('Wastage is more than the available stock.', 'insufficient_stock', 409);
            }

            return $this->apply($row, 'wastage', bcmul($qty, '-1', 3), '0', 'Inventory', $row->id, $reason, $user);
        });
    }

    /** Explicit, authorised return of a consumed item to sellable stock. */
    public function restock(string $stockKey, string $qty, string $reason, ?User $user, string $refType, int $refId): void
    {
        DB::transaction(function () use ($stockKey, $qty, $reason, $user, $refType, $refId) {
            $row = $this->locked($stockKey);
            if ($row) {
                $this->apply($row, 'return', $qty, '0', $refType, $refId, $reason, $user);
            }
        });
    }

    /**
     * Compares each row with its ledger and live reservations. With $repair, reserved
     * and physical totals are corrected and the correction is written to the ledger.
     */
    public function reconcile(bool $repair = false): array
    {
        $issues = [];
        foreach (Inventory::query()->pluck('id') as $id) {
            DB::transaction(function () use ($id, $repair, &$issues) {
                $row = Inventory::whereKey($id)->lockForUpdate()->first();
                $reserved = (string) (InventoryReservation::where('inventory_id', $id)->sum('quantity') ?: '0');
                $physical = (string) (InventoryMovement::where('inventory_id', $id)->sum('physical_delta') ?: '0');
                $reservedDiff = bcsub(number_format((float) $reserved, 3, '.', ''), (string) $row->reserved_qty, 3);
                $physicalDiff = bcsub(number_format((float) $physical, 3, '.', ''), (string) $row->physical_qty, 3);
                if (bccomp($reservedDiff, '0', 3) === 0 && bccomp($physicalDiff, '0', 3) === 0) {
                    return;
                }
                $issues[] = ['stock_key' => $row->stock_key, 'reserved_diff' => $reservedDiff, 'physical_diff' => $physicalDiff];
                if ($repair) {
                    $row->reserved_qty = bcadd((string) $row->reserved_qty, $reservedDiff, 3);
                    $row->physical_qty = bcadd((string) $row->physical_qty, $physicalDiff, 3);
                    $row->save();
                    InventoryMovement::create([
                        'inventory_id' => $row->id, 'type' => 'reconciliation',
                        'physical_delta' => 0, 'reserved_delta' => $reservedDiff,
                        'physical_after' => $row->physical_qty, 'reserved_after' => $row->reserved_qty,
                        'reason' => 'Automatic reconciliation (physical diff '.$physicalDiff.')', 'created_at' => now(),
                    ]);
                }
            });
        }

        return $issues;
    }

    private function apply(Inventory $row, string $type, string $physicalDelta, string $reservedDelta, ?string $refType, ?int $refId, ?string $reason, ?User $user): Inventory
    {
        $row->physical_qty = bcadd((string) $row->physical_qty, $physicalDelta, 3);
        $row->reserved_qty = bcadd((string) $row->reserved_qty, $reservedDelta, 3);
        $row->save();
        InventoryMovement::create([
            'inventory_id' => $row->id,
            'type' => $type,
            'physical_delta' => $physicalDelta,
            'reserved_delta' => $reservedDelta,
            'physical_after' => $row->physical_qty,
            'reserved_after' => $row->reserved_qty,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'reason' => $reason,
            'user_id' => $user?->id,
            'created_at' => now(),
        ]);

        return $row;
    }

    private function min(string $a, string $b): string
    {
        return bccomp($a, $b, 3) <= 0 ? $a : $b;
    }

    private function pretty(string $qty): string
    {
        return rtrim(rtrim($qty, '0'), '.') ?: '0';
    }
}
