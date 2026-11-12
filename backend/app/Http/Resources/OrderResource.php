<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'client_uuid' => $this->client_uuid,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_mobile' => $this->customer_mobile,
            'item_count' => $this->item_count,
            'subtotal' => $this->subtotal,
            'version' => $this->version,
            'created_offline' => $this->created_offline,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'billed_at' => $this->billed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'invoice_id' => $this->whenLoaded('invoice', fn () => $this->invoice?->id),
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
            'invoice_total' => $this->whenLoaded('invoice', fn () => $this->invoice?->total),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_variant_id' => $i->product_variant_id,
                'stock_key' => $i->stock_key,
                'name' => $i->product_name,
                'unit_price' => $i->unit_price,
                'gst_rate' => $i->gst_rate,
                'quantity' => $i->quantity,
                'served_qty' => $i->served_qty,
                'stock_shortfall' => $i->stock_shortfall,
                'conflict_note' => $i->conflict_note,
                'line_total' => $i->line_total,
            ])->values()),
        ];
    }
}
