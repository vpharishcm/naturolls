<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentEditTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 100, gst: 0), 1);
        // The default helper pays the whole bill in cash.
        $this->invoice = Invoice::find($this->bill($order)->assertCreated()->json('data.id'));
    }

    private function change(array $payments, string $reason = 'Tapped the wrong mode')
    {
        return $this->postJson("/api/v1/invoices/{$this->invoice->id}/payments/edit", ['payments' => $payments, 'reason' => $reason]);
    }

    private function methods(): array
    {
        return Payment::where('invoice_id', $this->invoice->id)->orderBy('id')->get()->map(fn ($p) => [$p->method, (string) $p->amount])->all();
    }

    public function test_staff_can_change_cash_to_upi_and_the_day_totals_follow(): void
    {
        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.can_edit_payment', true);
        $this->change([['method' => 'upi', 'amount' => 100, 'reference' => 'UPI123']])->assertOk()->assertJsonPath('data.payments.0.method', 'upi');

        $this->assertSame([['upi', '100.00']], $this->methods());
        $summary = $this->getJson('/api/v1/payments/summary')->json('data.methods');
        $this->assertSame('0.00', $summary['cash']['received']);
        $this->assertSame('100.00', $summary['upi']['received']);
        $this->assertSame(1, AuditLog::where('action', 'payment.edited')->count());
    }

    public function test_a_payment_can_be_split_but_must_add_up(): void
    {
        $this->change([['method' => 'cash', 'amount' => 60], ['method' => 'card', 'amount' => 30]])->assertStatus(422)->assertJsonPath('code', 'payment_mismatch');
        $this->change([['method' => 'cash', 'amount' => 60], ['method' => 'card', 'amount' => 40]])->assertOk();
        $this->assertSame([['cash', '60.00'], ['card', '40.00']], $this->methods());
    }

    public function test_staff_window_comes_from_settings_and_owner_has_no_limit(): void
    {
        app(SettingsService::class)->update(['billing.payment_edit_minutes' => 10]);
        $this->travel(9)->minutes();
        $this->change([['method' => 'card', 'amount' => 100]])->assertOk();

        $this->travel(2)->minutes();
        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.can_edit_payment', false);
        $this->change([['method' => 'upi', 'amount' => 100]])->assertStatus(403)->assertJsonPath('code', 'payment_edit_window_closed');

        $this->actAsAdmin();
        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.payment_edit_until', null);
        $this->change([['method' => 'upi', 'amount' => 100]])->assertOk();
        $this->assertSame([['upi', '100.00']], $this->methods());
    }

    public function test_closed_cash_reconciled_or_refunded_payments_are_locked(): void
    {
        Payment::where('invoice_id', $this->invoice->id)->update(['reconciled_at' => now()]);
        $this->change([['method' => 'upi', 'amount' => 100]])->assertStatus(409)->assertJsonPath('code', 'payment_reconciled');
        Payment::where('invoice_id', $this->invoice->id)->update(['reconciled_at' => null]);

        $this->actAsAdmin();
        $this->postJson('/api/v1/payments/cash-closing', ['business_date' => now()->toDateString(), 'counted_balance' => 100])->assertOk();
        $this->change([['method' => 'upi', 'amount' => 100]])->assertStatus(409)->assertJsonPath('code', 'cash_closed');
        $this->assertSame([['cash', '100.00']], $this->methods());
    }

    public function test_a_reason_is_required(): void
    {
        $this->change([['method' => 'upi', 'amount' => 100]], '')->assertStatus(422);
    }
}
