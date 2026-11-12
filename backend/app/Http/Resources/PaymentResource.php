<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice->invoice_number),
            'type' => $this->type,
            'method' => $this->method,
            'amount' => $this->amount,
            'tendered' => $this->tendered,
            'change_given' => $this->change_given,
            'reference' => $this->reference,
            'status' => $this->status,
            'reason' => $this->reason,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'staff' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
