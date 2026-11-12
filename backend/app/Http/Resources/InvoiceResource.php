<?php

namespace App\Http\Resources;

use App\Services\BillEditService;
use App\Services\PaymentEditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'order_id' => $this->order_id,
            'order_number' => $this->snapshot['order_number'] ?? null,
            'customer_name' => $this->snapshot['customer_name'] ?? null,
            'items' => $this->snapshot['items'] ?? [],
            'subtotal' => $this->subtotal,
            'promotion_discount' => $this->promotion_discount,
            'manual_discount' => $this->manual_discount,
            'loyalty_discount' => $this->loyalty_discount,
            'taxable_amount' => $this->taxable_amount,
            'tax_amount' => $this->tax_amount,
            'tax_breakup' => $this->tax_breakup,
            'total' => $this->total,
            'refunded_amount' => $this->refunded_amount,
            'print_count' => $this->print_count,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'edit_count' => $this->edit_count,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'can_edit' => $request->user() ? app(BillEditService::class)->canEdit($this->resource, $request->user()) : false,
            'edit_until' => $request->user() ? app(BillEditService::class)->editableUntil($this->resource, $request->user())?->toIso8601String() : null,
            'can_edit_payment' => $request->user() ? app(PaymentEditService::class)->canEdit($this->resource, $request->user()) : false,
            'payment_edit_until' => $request->user() ? app(PaymentEditService::class)->editableUntil($this->resource, $request->user())?->toIso8601String() : null,
            'payments' => $this->whenLoaded('payments', fn () => PaymentResource::collection($this->payments)),
        ];
    }
}
