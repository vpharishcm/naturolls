<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CashClosing;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixes how a bill was paid when the wrong mode was tapped: cash recorded as UPI, or a
 * split entered wrongly. The amount received never changes, only how it came in. Staff
 * can do this for a set number of minutes after billing (setting
 * billing.payment_edit_minutes); anyone allowed to edit bills at any time has no limit.
 */
class PaymentEditService
{
    public function __construct(
        private SettingsService $settings,
        private AuditService $audit,
    ) {}

    /** When this user can no longer change the payment; null for no limit. */
    public function editableUntil(Invoice $invoice, User $user): ?Carbon
    {
        if ($user->hasPermission('invoice.edit_anytime')) {
            return null;
        }

        return $invoice->issued_at->copy()->addMinutes((int) $this->settings->get('billing.payment_edit_minutes'));
    }

    /** Why the payment cannot be changed now, or null if it can. */
    public function blocker(Invoice $invoice, User $user): ?array
    {
        if ((float) $invoice->refunded_amount > 0) {
            return ['This bill has a refund, so its payment can no longer be changed.', 'invoice_refunded', 409];
        }
        $until = $this->editableUntil($invoice, $user);
        if ($until && now()->gt($until)) {
            $minutes = (int) $this->settings->get('billing.payment_edit_minutes');

            return ["Staff can change how a bill was paid for {$minutes} minutes after billing. Ask the owner to change this one.", 'payment_edit_window_closed', 403];
        }
        $rows = $this->editableRows($invoice);
        if ($rows->isEmpty()) {
            return ['This bill has no payment to change.', 'no_payment', 409];
        }
        if ($rows->contains(fn (Payment $p) => $p->reconciled_at || $p->gateway_txn_id)) {
            return ['This payment is already matched with the bank or UPI statement, so it cannot be changed.', 'payment_reconciled', 409];
        }
        $days = $rows->map(fn (Payment $p) => $p->created_at->toDateString())->unique();
        if (CashClosing::whereIn('business_date', $days)->exists()) {
            return ["Cash for this bill's day is already closed, so its payment cannot be changed.", 'cash_closed', 409];
        }

        return null;
    }

    public function canEdit(Invoice $invoice, User $user): bool
    {
        return $this->blocker($invoice, $user) === null;
    }

    /**
     * Replaces the bill's payment entries with the corrected ones. They must add up to
     * the same money received; they keep the original time so they stay in that day's totals.
     *
     * @param  array{payments: array<int, array{method:string, amount:string|float, reference?:string|null, tendered?:string|float|null}>, reason: string}  $data
     */
    public function edit(Invoice $invoice, array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $user) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($blocker = $this->blocker($invoice, $user)) {
                throw new BusinessException(...$blocker);
            }

            $old = $this->editableRows($invoice);
            $received = $old->reduce(fn ($sum, Payment $p) => bcadd($sum, (string) $p->amount, 2), '0.00');
            $entered = '0.00';
            foreach ($data['payments'] as $p) {
                $entered = bcadd($entered, number_format((float) $p['amount'], 2, '.', ''), 2);
            }
            if (bccomp($entered, $received, 2) !== 0) {
                throw new BusinessException("The payments must add up to ₹{$received}, the amount received for this bill.", 'payment_mismatch', 422, ['total' => $received]);
            }

            $before = $old->map(fn (Payment $p) => $this->summary($p))->values()->all();
            $first = $old->first();
            Payment::whereIn('id', $old->pluck('id'))->delete();

            $after = [];
            foreach ($data['payments'] as $p) {
                $amount = number_format((float) $p['amount'], 2, '.', '');
                if (bccomp($amount, '0', 2) <= 0) {
                    continue;
                }
                $tendered = $p['method'] === 'cash' && isset($p['tendered']) ? number_format((float) $p['tendered'], 2, '.', '') : null;
                if ($tendered !== null && bccomp($tendered, $amount, 2) < 0) {
                    throw new BusinessException('Cash received is less than the amount due.', 'cash_short');
                }
                $row = new Payment([
                    'invoice_id' => $invoice->id,
                    'type' => 'payment',
                    'method' => $p['method'],
                    'amount' => $amount,
                    'tendered' => $tendered,
                    'change_given' => $tendered !== null ? bcsub($tendered, $amount, 2) : 0,
                    'reference' => $p['method'] !== 'cash' ? ($p['reference'] ?? null) : null,
                    'status' => 'success',
                    'reason' => 'Payment corrected: '.$data['reason'],
                    'user_id' => $first->user_id,
                ]);
                // Kept on the original time, so the day's cash and UPI totals move with it.
                $row->created_at = $first->created_at;
                $row->updated_at = now();
                $row->save();
                $after[] = $this->summary($row);
            }

            $this->audit->record($user, 'payment.edited', $invoice, ['payments' => $before], ['payments' => $after], $data['reason']);
            $this->audit->activity($user, 'payment.edited', $invoice->invoice_number);

            return $invoice->fresh();
        }, 3);
    }

    /** Money received for the bill. Refunds and money given back after an edit stay as they are. */
    private function editableRows(Invoice $invoice)
    {
        return Payment::where('invoice_id', $invoice->id)->where('type', 'payment')->where('status', 'success')
            ->where('amount', '>', 0)->orderBy('id')->get();
    }

    private function summary(Payment $p): array
    {
        return ['method' => $p->method, 'amount' => (string) $p->amount, 'reference' => $p->reference];
    }
}
