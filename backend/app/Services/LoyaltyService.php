<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public function __construct(private SettingsService $settings) {}

    public function register(Customer $customer): LoyaltyAccount
    {
        return LoyaltyAccount::firstOrCreate(['customer_id' => $customer->id]);
    }

    public function pointsToPaise(int $points): int
    {
        return Money::toPaise(bcmul((string) $points, (string) $this->settings->get('loyalty.rupees_per_point'), 2));
    }

    public function validateRedemption(?int $customerId, int $points): void
    {
        if ($points <= 0) {
            return;
        }
        $account = $customerId ? LoyaltyAccount::where('customer_id', $customerId)->first() : null;
        if (! $account || ! $this->settings->get('loyalty.enabled')) {
            throw new BusinessException('This customer has no loyalty account.', 'no_loyalty_account');
        }
        if ($points > $account->points_balance) {
            throw new BusinessException("Only {$account->points_balance} points available.", 'insufficient_points');
        }
        $min = (int) $this->settings->get('loyalty.min_redeem_points');
        if ($points < $min) {
            throw new BusinessException("Redeem at least {$min} points.", 'below_min_redeem');
        }
    }

    public function earnedFor(string $amount): int
    {
        return (int) floor(((float) $amount / 100) * (float) $this->settings->get('loyalty.points_per_100'));
    }

    /** Applies redemption and earning for an invoice in the caller's transaction. */
    public function settleInvoice(int $customerId, int $invoiceId, int $redeem, string $paidAmount, User $user): void
    {
        $account = LoyaltyAccount::where('customer_id', $customerId)->lockForUpdate()->first();
        if (! $account || ! $this->settings->get('loyalty.enabled')) {
            return;
        }
        if ($redeem > 0) {
            $this->write($account, 'redeem', -$redeem, $invoiceId, null, $user);
            $account->lifetime_redeemed += $redeem;
        }
        $earned = $this->earnedFor($paidAmount);
        if ($earned > 0) {
            $this->write($account, 'earn', $earned, $invoiceId, null, $user);
            $account->lifetime_earned += $earned;
        }
        $account->save();
    }

    /** Takes back the share of points earned on the refunded part of an invoice. */
    public function reverseForRefund(int $customerId, int $invoiceId, string $refund, User $user): void
    {
        $account = LoyaltyAccount::where('customer_id', $customerId)->lockForUpdate()->first();
        $points = $this->earnedFor($refund);
        if ($account && $points > 0) {
            $this->write($account, 'reversal', -min($points, max(0, $account->points_balance)), $invoiceId, 'Refund', $user);
            $account->save();
        }
    }

    /** Moves earned points to match a bill's new total. */
    public function settleEdit(int $customerId, int $invoiceId, string $oldTotal, string $newTotal, User $user): void
    {
        $account = LoyaltyAccount::where('customer_id', $customerId)->lockForUpdate()->first();
        $points = $this->earnedFor($newTotal) - $this->earnedFor($oldTotal);
        if (! $account || $points === 0) {
            return;
        }
        $points = max($points, -max(0, $account->points_balance));
        $this->write($account, 'adjust', $points, $invoiceId, 'Bill edited', $user);
        $account->lifetime_earned = max(0, $account->lifetime_earned + $points);
        $account->save();
    }

    public function adjust(LoyaltyAccount $account, int $points, string $note, User $user): LoyaltyAccount
    {
        return DB::transaction(function () use ($account, $points, $note, $user) {
            $account = LoyaltyAccount::whereKey($account->id)->lockForUpdate()->first();
            if ($account->points_balance + $points < 0) {
                throw new BusinessException('Points balance cannot go below zero.', 'insufficient_points');
            }
            $this->write($account, 'adjust', $points, null, $note, $user);
            $account->save();

            return $account;
        });
    }

    private function write(LoyaltyAccount $account, string $type, int $points, ?int $invoiceId, ?string $note, User $user): void
    {
        $account->points_balance += $points;
        LoyaltyTransaction::create([
            'loyalty_account_id' => $account->id,
            'invoice_id' => $invoiceId,
            'type' => $type,
            'points' => $points,
            'balance_after' => $account->points_balance,
            'note' => $note,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
    }
}
