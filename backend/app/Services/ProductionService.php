<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function __construct(private InventoryService $inventory, private AuditService $audit) {}

    /** Records one production session; each flavour's quantity goes straight into stock. */
    public function record(array $data, User $user): ProductionBatch
    {
        return DB::transaction(function () use ($data, $user) {
            $batch = ProductionBatch::create([
                'production_date' => $data['production_date'],
                'production_time' => $data['production_time'] ?? now()->format('H:i:s'),
                'user_id' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $variant = isset($line['product_variant_id']) ? ProductVariant::findOrFail($line['product_variant_id']) : null;
                if (! $product->track_inventory) {
                    throw new BusinessException("{$product->name} does not track stock.", 'not_tracked');
                }
                $row = $this->inventory->ensureRow($product, $variant);
                $qty = number_format((float) $line['quantity'], 3, '.', '');
                $item = $batch->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity' => $qty,
                ]);
                $this->inventory->produce($row->stock_key, $qty, $user, $item->id);
            }
            $this->audit->record($user, 'production.recorded', $batch, null, $data);
            $this->audit->activity($user, 'production.recorded', 'Batch #'.$batch->id);

            return $batch->load('items.product', 'user');
        });
    }

    public function reverse(ProductionBatchItem $item, string $reason, User $user, ?string $pin): ProductionBatchItem
    {
        return DB::transaction(function () use ($item, $reason, $user, $pin) {
            $authorizer = $this->audit->authorize($user, 'production.reverse', $pin);
            $item = ProductionBatchItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->reversed_at) {
                throw new BusinessException('This entry is already reversed.', 'already_reversed', 409);
            }
            $key = InventoryService::stockKey($item->product_id, $item->product_variant_id);
            $this->inventory->reverseProduction($key, (string) $item->quantity, $user, $item->id, $reason);
            $item->update(['reversed_at' => now(), 'reversed_by' => $user->id, 'reversal_reason' => $reason]);
            $this->audit->record($user, 'production.reversed', $item, null, ['quantity' => $item->quantity], $reason, $authorizer);

            return $item;
        });
    }

    /** Corrects a quantity by reversing the entry and recording the right figure in the same batch. */
    public function edit(ProductionBatchItem $item, string $quantity, string $reason, User $user, ?string $pin): ProductionBatchItem
    {
        return DB::transaction(function () use ($item, $quantity, $reason, $user, $pin) {
            $this->reverse($item, $reason, $user, $pin);
            $qty = number_format((float) $quantity, 3, '.', '');
            $new = ProductionBatchItem::create([
                'production_batch_id' => $item->production_batch_id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $qty,
            ]);
            $this->inventory->produce(InventoryService::stockKey($item->product_id, $item->product_variant_id), $qty, $user, $new->id);

            return $new;
        });
    }
}
