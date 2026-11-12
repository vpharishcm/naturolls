<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Takes orders that were entered offline. Each order carries a UUID, so sending it
 * again returns the existing order. The items were already handed over, so they are
 * always recorded; anything stock cannot cover is flagged for staff to resolve.
 */
class SyncService
{
    public function __construct(private OrderService $orders, private AuditService $audit) {}

    public function syncOrder(array $data, User $user): array
    {
        $existing = Order::where('client_uuid', $data['client_uuid'])->first();
        if ($existing) {
            return ['order' => $existing, 'duplicate' => true, 'conflicts' => []];
        }

        return DB::transaction(function () use ($data, $user) {
            $existing = Order::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
            if ($existing) {
                return ['order' => $existing, 'duplicate' => true, 'conflicts' => []];
            }

            $number = $data['order_number'];
            if (Order::where('order_number', $number)->exists()) {
                $number .= '-'.substr($data['client_uuid'], 0, 4);
            }
            $order = Order::create([
                'order_number' => $number,
                'client_uuid' => $data['client_uuid'],
                'status' => 'unbilled',
                'customer_name' => $data['customer_name'] ?? null,
                'customer_mobile' => $data['customer_mobile'] ?? null,
                'created_by' => $user->id,
                'terminal_id' => $this->audit->terminalId(),
                'created_offline' => true,
            ]);
            // Devices send UTC; the database stores the shop's local time.
            $order->forceFill(['created_at' => Carbon::parse($data['created_at'])->setTimezone(config('app.timezone'))])->save();

            $conflicts = [];
            foreach ($data['items'] as $line) {
                $product = Product::withTrashed()->with('category')->find($line['product_id']);
                if (! $product) {
                    $conflicts[] = ['type' => 'product_missing', 'product_id' => $line['product_id'], 'message' => 'Product no longer exists; line skipped.'];

                    continue;
                }
                $variant = isset($line['product_variant_id']) ? ProductVariant::withTrashed()->find($line['product_variant_id']) : null;
                $qty = number_format((float) $line['quantity'], 3, '.', '');
                $offlinePrice = number_format((float) $line['unit_price'], 2, '.', '');
                $current = number_format((float) ($variant?->price ?? $product->price), 2, '.', '');

                // The customer was charged the offline price, so it is kept and any change is flagged.
                $item = $this->orders->addItem($order, $product, $variant, $qty, $user, true, $offlinePrice);
                $notes = [];
                if ($offlinePrice !== $current) {
                    $notes[] = "Price changed to ₹{$current} after this was entered";
                    $conflicts[] = ['type' => 'price_changed', 'item' => $item->product_name, 'offline_price' => $offlinePrice, 'current_price' => $current];
                }
                if (! $product->is_active || $product->trashed()) {
                    $notes[] = 'No longer on sale';
                    $conflicts[] = ['type' => 'product_inactive', 'item' => $item->product_name];
                }
                if (bccomp((string) $item->stock_shortfall, '0', 3) > 0) {
                    $notes[] = 'Stock short by '.rtrim(rtrim((string) $item->stock_shortfall, '0'), '.');
                    $conflicts[] = ['type' => 'stock_shortfall', 'item_id' => $item->id, 'item' => $item->product_name, 'short' => $item->stock_shortfall];
                }
                if ($notes) {
                    OrderItem::whereKey($item->id)->update(['conflict_note' => implode('. ', $notes)]);
                }
            }

            $this->audit->record($user, 'order.synced_offline', $order, null, ['conflicts' => $conflicts]);
            $this->audit->activity($user, 'order.synced', $order->order_number);

            return ['order' => $order->fresh(), 'duplicate' => false, 'conflicts' => $conflicts];
        }, 3);
    }
}
