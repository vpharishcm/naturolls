<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CashClosing;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private InventoryService $inventory,
        private LoyaltyService $loyalty,
        private AuditService $audit,
    ) {}

    /**
     * Refunds are new payment rows, so the original payment is never rewritten.
     * Stock comes back only for lines explicitly listed in $data['restock'].
     */
    public function refund(Invoice $invoice, array $data, User $user): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $user) {
            $authorizer = $this->audit->authorize($user, 'refund.create', $data['manager_pin'] ?? null);
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $amount = number_format((float) $data['amount'], 2, '.', '');
            $left = bcsub((string) $invoice->total, (string) $invoice->refunded_amount, 2);
            if (bccomp($amount, $left, 2) > 0) {
                throw new BusinessException("Only ₹{$left} can still be refunded on this invoice.", 'refund_exceeds_total');
            }

            $refund = Payment::create([
                'invoice_id' => $invoice->id,
                'type' => 'refund',
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'status' => 'success',
                'reason' => $data['reason'],
                'user_id' => $user->id,
                'authorized_by' => $authorizer,
            ]);
            $invoice->refunded_amount = bcadd((string) $invoice->refunded_amount, $amount, 2);
            $invoice->save();

            foreach ($data['restock'] ?? [] as $line) {
                $item = OrderItem::where('order_id', $invoice->order_id)->whereKey($line['order_item_id'])->firstOrFail();
                if ((float) $line['quantity'] > (float) $item->quantity) {
                    throw new BusinessException('Cannot restock more than was sold.', 'restock_exceeds_sold');
                }
                $this->inventory->restock($item->stock_key, number_format((float) $line['quantity'], 3, '.', ''), 'Refund '.$invoice->invoice_number, $user, 'Payment', $refund->id);
            }
            if ($invoice->customer_id) {
                $this->loyalty->reverseForRefund($invoice->customer_id, $invoice->id, $amount, $user);
            }
            $this->audit->record($user, 'payment.refunded', $invoice, null, ['amount' => $amount, 'method' => $data['method']], $data['reason'], $authorizer);
            $this->audit->activity($user, 'payment.refunded', $invoice->invoice_number);

            return $refund;
        });
    }

    public function reconcile(array $paymentIds, User $user): int
    {
        return Payment::whereIn('id', $paymentIds)->whereNull('reconciled_at')
            ->update(['reconciled_at' => now(), 'reconciled_by' => $user->id]);
    }

    /**
     * Confirmation from a UPI or card provider, matched on our payment reference.
     * A repeated callback changes nothing because the provider's id is stored once.
     */
    public function handleCallback(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $seen = Payment::where('gateway_txn_id', $data['gateway_txn_id'])->first();
            if ($seen) {
                return ['duplicate' => true, 'payment_id' => $seen->id];
            }
            $payment = Payment::where('reference', $data['reference'])->where('type', 'payment')
                ->whereNull('gateway_txn_id')->lockForUpdate()->first();
            if (! $payment) {
                throw new BusinessException('No payment matches this reference.', 'payment_not_found', 404);
            }
            if (bccomp((string) $payment->amount, number_format((float) $data['amount'], 2, '.', ''), 2) !== 0) {
                throw new BusinessException('The amount does not match the recorded payment.', 'amount_mismatch', 409);
            }
            $payment->update([
                'gateway_txn_id' => $data['gateway_txn_id'],
                'status' => $data['status'] === 'success' ? 'success' : 'failed',
                'reconciled_at' => now(),
            ]);
            $this->audit->record(null, 'payment.callback', $payment, null, $data);

            return ['duplicate' => false, 'payment_id' => $payment->id];
        });
    }

    public function dailySummary(string $date): array
    {
        $day = Carbon::parse($date);
        $rows = Payment::where('status', 'success')
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->selectRaw('method, type, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method', 'type')->get();

        $methods = [];
        foreach (['cash', 'upi', 'card'] as $method) {
            $in = $rows->first(fn ($r) => $r->method === $method && $r->type === 'payment');
            $out = $rows->first(fn ($r) => $r->method === $method && $r->type === 'refund');
            $received = number_format((float) ($in->total ?? 0), 2, '.', '');
            $refunded = number_format((float) ($out->total ?? 0), 2, '.', '');
            $methods[$method] = [
                'received' => $received,
                'refunded' => $refunded,
                'net' => bcsub($received, $refunded, 2),
                'count' => (int) ($in->count ?? 0),
            ];
        }
        $opening = (string) (CashClosing::where('business_date', '<', $day->toDateString())->orderByDesc('business_date')->value('counted_balance') ?? '0.00');
        $closing = CashClosing::whereDate('business_date', $day->toDateString())->first();

        return [
            'date' => $day->toDateString(),
            'methods' => $methods,
            'opening_balance' => number_format((float) $opening, 2, '.', ''),
            'expected_cash' => bcadd(number_format((float) $opening, 2, '.', ''), $methods['cash']['net'], 2),
            'closing' => $closing,
        ];
    }

    public function closeCash(string $date, string $counted, ?string $notes, User $user): CashClosing
    {
        return DB::transaction(function () use ($date, $counted, $notes, $user) {
            $summary = $this->dailySummary($date);
            $counted = number_format((float) $counted, 2, '.', '');
            $closing = CashClosing::updateOrCreate(['business_date' => $date], [
                'opening_balance' => $summary['opening_balance'],
                'cash_sales' => $summary['methods']['cash']['received'],
                'cash_refunds' => $summary['methods']['cash']['refunded'],
                'expected_balance' => $summary['expected_cash'],
                'counted_balance' => $counted,
                'variance' => bcsub($counted, $summary['expected_cash'], 2),
                'notes' => $notes,
                'user_id' => $user->id,
            ]);
            $this->audit->record($user, 'cash.closed', $closing, null, $closing->toArray());

            return $closing;
        });
    }
}
