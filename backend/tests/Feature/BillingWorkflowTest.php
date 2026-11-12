<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Promotion;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class BillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
    }

    public function test_complete_order_to_billing_workflow(): void
    {
        app(SettingsService::class)->update(['tax.enabled' => true]);
        $product = $this->product(price: 105, stock: 10);
        $order = $this->newOrder();
        $this->addItem($order, $product, 2);

        $data = $this->bill($order)->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $data['invoice_number']);
        $this->assertSame('210.00', $data['total']);
        $this->assertSame('10.00', $data['tax_amount']);
        $this->assertSame('billed', $order->fresh()->status);
        $this->assertSame([8.0, 0.0, 8.0], $this->stock($product), 'Billing consumes what was still reserved');
        $this->assertSame(1, Payment::where('invoice_id', $data['id'])->count());
    }

    public function test_billing_twice_is_refused_and_no_duplicate_invoice_exists(): void
    {
        $order = $this->newOrder();
        $this->addItem($order, $this->product());
        $version = $order->fresh()->version;
        $this->bill($order)->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/bill", ['expected_version' => $version, 'payments' => [['method' => 'cash', 'amount' => 100]]])
            ->assertStatus(409)->assertJsonPath('code', 'already_billed');
        $this->assertSame(1, Invoice::count());
    }

    public function test_cancelled_order_cannot_be_billed(): void
    {
        $order = $this->newOrder();
        $this->addItem($order, $this->product());
        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'x', 'manager_pin' => '1234'])->assertOk();
        $this->bill($order, ['payments' => []])->assertStatus(409)->assertJsonPath('code', 'order_cancelled');
    }

    public function test_order_changed_just_before_checkout_is_caught(): void
    {
        $product = $this->product(price: 100);
        $order = $this->newOrder();
        $this->addItem($order, $product);
        $stale = $order->fresh()->version;
        $this->addItem($order, $product);

        $this->postJson("/api/v1/orders/{$order->id}/bill", ['expected_version' => $stale, 'payments' => [['method' => 'cash', 'amount' => 100]]])
            ->assertStatus(409)->assertJsonPath('code', 'order_changed');
        $this->assertSame(0, Invoice::count());

        $this->bill($order)->assertCreated()->assertJsonPath('data.total', '200.00');
    }

    public function test_payments_must_match_the_total(): void
    {
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $this->postJson("/api/v1/orders/{$order->id}/bill", ['expected_version' => $order->fresh()->version, 'payments' => [['method' => 'cash', 'amount' => 90]]])
            ->assertStatus(422)->assertJsonPath('code', 'payment_mismatch');
        $this->assertSame('unbilled', $order->fresh()->status);
    }

    public function test_split_payment_with_cash_change_and_reference(): void
    {
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $data = $this->postJson("/api/v1/orders/{$order->id}/bill", [
            'expected_version' => $order->fresh()->version,
            'payments' => [
                ['method' => 'cash', 'amount' => 40, 'tendered' => 50],
                ['method' => 'upi', 'amount' => 60, 'reference' => 'UPI123'],
            ],
        ])->assertCreated()->json('data');
        $this->assertCount(2, $data['payments']);
        $this->assertDatabaseHas('payments', ['method' => 'cash', 'change_given' => '10.00']);
        $this->assertDatabaseHas('payments', ['method' => 'upi', 'reference' => 'UPI123']);
    }

    public function test_manual_discount_needs_authorisation(): void
    {
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $discount = ['manual_discount' => ['type' => 'percent', 'value' => 10]];
        $this->bill($order, $discount)->assertStatus(403)->assertJsonPath('code', 'authorization_required');
        $this->assertSame(0, Invoice::count());

        $data = $this->bill($order, $discount + ['manager_pin' => '1234'])->assertCreated()->json('data');
        $this->assertSame('90.00', $data['total']);
        $this->assertSame($this->admin->id, Invoice::first()->discount_authorized_by);
    }

    public function test_staff_discount_within_the_configured_limit_needs_no_pin(): void
    {
        app(SettingsService::class)->update(['discount.staff_max_percent' => 5]);
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $this->bill($order, ['manual_discount' => ['type' => 'percent', 'value' => 5]])->assertCreated()->assertJsonPath('data.total', '95.00');
    }

    public function test_best_automatic_promotion_is_applied_and_reported(): void
    {
        Promotion::create(['name' => 'Small', 'discount_type' => 'fixed', 'discount_value' => 5, 'scope' => 'order', 'is_active' => true]);
        $best = Promotion::create(['name' => 'Big', 'discount_type' => 'percent', 'discount_value' => 20, 'scope' => 'order', 'min_purchase' => 50, 'is_active' => true]);
        Promotion::create(['name' => 'Expired', 'discount_type' => 'percent', 'discount_value' => 90, 'scope' => 'order', 'is_active' => true, 'ends_at' => now()->subDay()]);
        Promotion::create(['name' => 'Off', 'discount_type' => 'percent', 'discount_value' => 80, 'scope' => 'order', 'is_active' => false]);

        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $this->bill($order)->assertCreated()->assertJsonPath('data.total', '80.00');
        $this->assertSame($best->id, Invoice::first()->promotion_id);

        $this->actAsAdmin();
        $this->getJson('/api/v1/reports/promotion-summary')->assertOk()->assertJsonPath('data.rows.0.promotion', 'Big');
    }

    public function test_product_and_category_promotions_discount_only_matching_lines(): void
    {
        $a = $this->product(price: 100);
        $b = $this->product(price: 100);
        $promo = Promotion::create(['name' => 'One', 'discount_type' => 'percent', 'discount_value' => 50, 'scope' => 'product', 'is_active' => true]);
        $promo->products()->sync([$a->id]);

        $order = $this->newOrder();
        $this->addItem($order, $a);
        $this->addItem($order, $b);
        $this->bill($order)->assertCreated()->assertJsonPath('data.total', '150.00');
    }

    public function test_promotion_requiring_authorisation_is_never_automatic(): void
    {
        $promo = Promotion::create(['name' => 'Owner only', 'discount_type' => 'percent', 'discount_value' => 50, 'scope' => 'order', 'is_active' => true, 'requires_authorization' => true]);
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $this->postJson("/api/v1/orders/{$order->id}/bill/preview", [])->assertJsonPath('data.total', '100.00');
        $this->bill($order, ['promotion_id' => $promo->id])->assertStatus(403);
        $this->bill($order, ['promotion_id' => $promo->id, 'manager_pin' => '1234'])->assertCreated()->assertJsonPath('data.total', '50.00');
    }

    public function test_loyalty_earn_redeem_and_history(): void
    {
        $customer = Customer::create(['name' => 'Ravi', 'mobile' => '9000000002']);
        $account = LoyaltyAccount::create(['customer_id' => $customer->id, 'points_balance' => 50]);

        $order = Order::find($this->postJson('/api/v1/orders', ['customer_id' => $customer->id])->json('data.id'));
        $this->addItem($order, $this->product(price: 200));
        $this->bill($order, ['redeem_points' => 20])->assertCreated()->assertJsonPath('data.total', '180.00')->assertJsonPath('data.loyalty_discount', '20.00');

        // 50 - 20 redeemed + 5 points per ₹100 on ₹180 paid (9).
        $this->assertSame(39, $account->fresh()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', ['loyalty_account_id' => $account->id, 'type' => 'redeem', 'points' => -20]);
        $this->assertDatabaseHas('loyalty_transactions', ['loyalty_account_id' => $account->id, 'type' => 'earn', 'points' => 9]);
    }

    public function test_cannot_redeem_more_points_than_the_balance(): void
    {
        $customer = Customer::create(['name' => 'Ravi', 'mobile' => '9000000003']);
        LoyaltyAccount::create(['customer_id' => $customer->id, 'points_balance' => 15]);
        $order = Order::find($this->postJson('/api/v1/orders', ['customer_id' => $customer->id])->json('data.id'));
        $this->addItem($order, $this->product(price: 200));
        $this->bill($order, ['redeem_points' => 16])->assertStatus(422)->assertJsonPath('code', 'insufficient_points');
    }

    public function test_refund_is_authorised_capped_and_does_not_restock_by_default(): void
    {
        $product = $this->product(price: 100, stock: 10);
        $order = $this->newOrder();
        $this->addItem($order, $product, 2);
        $invoice = $this->bill($order)->json('data');
        $url = "/api/v1/invoices/{$invoice['id']}/refunds";

        $this->postJson($url, ['amount' => 50, 'method' => 'cash', 'reason' => 'Melted'])->assertStatus(403);
        $this->postJson($url, ['amount' => 50, 'method' => 'cash', 'reason' => 'Melted', 'manager_pin' => '1234'])->assertCreated();
        $this->assertSame([8.0, 0.0, 8.0], $this->stock($product));

        $this->postJson($url, ['amount' => 200, 'method' => 'cash', 'reason' => 'x', 'manager_pin' => '1234'])->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_total');
        $this->assertSame('50.00', (string) Invoice::find($invoice['id'])->refunded_amount);

        $item = $order->items()->first();
        $this->postJson($url, ['amount' => 100, 'method' => 'upi', 'reason' => 'Returned sealed', 'manager_pin' => '1234', 'restock' => [['order_item_id' => $item->id, 'quantity' => 1]]])->assertCreated();
        $this->assertSame([9.0, 0.0, 9.0], $this->stock($product));
    }

    public function test_owner_pin_locks_after_repeated_wrong_guesses(): void
    {
        $order = $this->newOrder();
        $this->addItem($order, $this->product());
        $url = "/api/v1/orders/{$order->id}/cancel";
        foreach (range(1, 5) as $i) {
            $this->postJson($url, ['reason' => 'x', 'manager_pin' => '0000'])->assertStatus(403);
        }
        $this->postJson($url, ['reason' => 'x', 'manager_pin' => '1234'])->assertStatus(429)->assertJsonPath('code', 'pin_locked');
        RateLimiter::clear('pin:'.$this->staff->id);
    }

    public function test_payment_callback_refuses_the_default_secret(): void
    {
        config(['services.payments.callback_secret' => 'change-me']);
        $body = json_encode(['gateway_txn_id' => 'G', 'reference' => 'R', 'amount' => 1, 'status' => 'success']);
        $this->call('POST', '/api/v1/payments/callback', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'change-me')], $body)->assertStatus(401);
    }

    public function test_payment_callback_received_twice_is_applied_once(): void
    {
        config(['services.payments.callback_secret' => 'secret']);
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $this->postJson("/api/v1/orders/{$order->id}/bill", ['expected_version' => $order->fresh()->version, 'payments' => [['method' => 'upi', 'amount' => 100, 'reference' => 'R1']]])->assertCreated();

        $body = json_encode(['gateway_txn_id' => 'G1', 'reference' => 'R1', 'amount' => 100, 'status' => 'success']);
        $send = fn (string $sig) => $this->call('POST', '/api/v1/payments/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => $sig,
        ], $body);

        $send('bad')->assertStatus(401);
        $sig = hash_hmac('sha256', $body, 'secret');
        $send($sig)->assertOk()->assertJsonPath('data.duplicate', false);
        $send($sig)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, Payment::where('gateway_txn_id', 'G1')->count());
        $this->assertSame(1, Payment::count());
    }

    public function test_payment_reconciliation_and_cash_closing(): void
    {
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100));
        $this->postJson("/api/v1/orders/{$order->id}/bill", ['expected_version' => $order->fresh()->version, 'payments' => [['method' => 'cash', 'amount' => 40], ['method' => 'card', 'amount' => 60, 'reference' => 'C9']]])->assertCreated();

        $this->getJson('/api/v1/payments/summary')->assertJsonPath('data.methods.cash.received', '40.00')->assertJsonPath('data.methods.card.received', '60.00');

        $this->postJson('/api/v1/payments/cash-closing', ['business_date' => now()->toDateString(), 'counted_balance' => 35])->assertStatus(403);
        $this->actAsAdmin();
        $data = $this->postJson('/api/v1/payments/cash-closing', ['business_date' => now()->toDateString(), 'counted_balance' => 35])->assertOk()->json('data');
        $this->assertSame('40.00', $data['expected_balance']);
        $this->assertSame('-5.00', $data['variance']);

        $card = Payment::where('method', 'card')->first();
        $this->getJson('/api/v1/payments?unreconciled=1')->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/payments/reconcile', ['payment_ids' => [$card->id]])->assertOk()->assertJsonPath('data.updated', 1);
        $this->getJson('/api/v1/payments?unreconciled=1')->assertJsonCount(0, 'data');
    }
}
