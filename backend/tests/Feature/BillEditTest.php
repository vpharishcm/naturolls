<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\LoyaltyService;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillEditTest extends TestCase
{
    use RefreshDatabase;

    private Product $mango;

    private Order $order;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
        $this->mango = $this->product(price: 20, stock: 10);
        $this->order = $this->newOrder();
        $this->addItem($this->order, $this->mango, 3);
        $this->invoice = Invoice::find($this->bill($this->order)->assertCreated()->json('data.id'));
    }

    private function itemId(): int
    {
        return $this->order->items()->first()->id;
    }

    private function send(array $items, array $extra = [])
    {
        return $this->postJson("/api/v1/invoices/{$this->invoice->id}/edit", $extra + [
            'expected_edit_count' => $this->invoice->fresh()->edit_count,
            'items' => $items,
            'reason' => 'Wrong quantity',
            'settlement_method' => 'cash',
        ]);
    }

    public function test_staff_can_edit_within_30_minutes_but_not_after(): void
    {
        $this->travel(29)->minutes();
        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.can_edit', true);
        $this->send([['id' => $this->itemId(), 'quantity' => 2]])->assertOk();

        $this->travel(2)->minutes();
        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.can_edit', false);
        $this->send([['id' => $this->itemId(), 'quantity' => 1]])->assertStatus(403)->assertJsonPath('code', 'edit_window_closed');
        $this->assertSame('40.00', (string) $this->invoice->fresh()->total);
    }

    public function test_an_edit_does_not_extend_the_staff_window(): void
    {
        $this->travel(29)->minutes();
        $this->send([['id' => $this->itemId(), 'quantity' => 2]])->assertOk();
        $this->travel(2)->minutes();
        $this->send([['id' => $this->itemId(), 'quantity' => 3]])->assertStatus(403);
    }

    public function test_admin_can_edit_at_any_time(): void
    {
        $this->travel(90)->days();
        $this->actAsAdmin();
        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.can_edit', true)->assertJsonPath('data.edit_until', null);
        $this->send([['id' => $this->itemId(), 'quantity' => 1]])->assertOk();
        $this->assertSame('20.00', (string) $this->invoice->fresh()->total);
    }

    public function test_the_window_comes_from_settings(): void
    {
        app(SettingsService::class)->update(['billing.staff_edit_minutes' => 5]);
        $this->travel(6)->minutes();
        $this->send([['id' => $this->itemId(), 'quantity' => 2]])->assertStatus(403);
    }

    public function test_reducing_returns_stock_and_records_money_given_back(): void
    {
        $this->assertSame([7.0, 0.0, 7.0], $this->stock($this->mango));
        $this->send([['id' => $this->itemId(), 'quantity' => 1]])->assertOk();

        $invoice = $this->invoice->fresh();
        $this->assertSame('20.00', (string) $invoice->total);
        $this->assertSame($invoice->invoice_number, $this->invoice->invoice_number);
        $this->assertSame([9.0, 0.0, 9.0], $this->stock($this->mango));
        $this->assertSame('-40.00', (string) Payment::where('invoice_id', $invoice->id)->latest('id')->first()->amount);
        $this->assertEquals(20, Payment::where('invoice_id', $invoice->id)->sum('amount'));
        $this->assertSame('1.000', $invoice->snapshot['items'][0]['quantity']);
        $this->assertSame('20.00', (string) $this->order->fresh()->subtotal);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.edited', 'entity_id' => $invoice->id, 'reason' => 'Wrong quantity', 'user_id' => $this->staff->id]);

        $cash = app(PaymentService::class)->dailySummary(now()->toDateString())['methods']['cash'];
        $this->assertSame('20.00', $cash['net']);
    }

    public function test_keeping_removed_items_out_of_stock_when_they_did_not_come_back(): void
    {
        $this->send([['id' => $this->itemId(), 'quantity' => 1]], ['return_to_stock' => false])->assertOk();
        $this->assertSame([7.0, 0.0, 7.0], $this->stock($this->mango));
    }

    public function test_adding_takes_stock_and_records_money_collected(): void
    {
        $choco = $this->product(price: 30, stock: 5);
        $this->send([['id' => $this->itemId(), 'quantity' => 4], ['product_id' => $choco->id, 'quantity' => 1]], ['settlement_method' => 'upi'])->assertOk();

        $this->assertSame('110.00', (string) $this->invoice->fresh()->total);
        $this->assertSame([6.0, 0.0, 6.0], $this->stock($this->mango));
        $this->assertSame([4.0, 0.0, 4.0], $this->stock($choco));
        $this->assertDatabaseHas('payments', ['invoice_id' => $this->invoice->id, 'method' => 'upi', 'amount' => '50.00']);
    }

    public function test_removing_a_line_keeps_the_rest_and_stock_shortage_changes_nothing(): void
    {
        $choco = $this->product(price: 30, stock: 1);
        $this->send([['id' => $this->itemId(), 'quantity' => 3], ['product_id' => $choco->id, 'quantity' => 2]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame([1.0, 0.0, 1.0], $this->stock($choco));
        $this->assertSame('60.00', (string) $this->invoice->fresh()->total);

        $this->send([['product_id' => $choco->id, 'quantity' => 1]])->assertOk();
        $this->assertSame('30.00', (string) $this->invoice->fresh()->total);
        $this->assertSame([10.0, 0.0, 10.0], $this->stock($this->mango));
        $this->assertSame(1, $this->order->items()->count());
    }

    public function test_guards_against_bad_edits(): void
    {
        $this->send([])->assertStatus(422);
        $this->send([['id' => $this->itemId(), 'quantity' => 0]])->assertStatus(422)->assertJsonPath('code', 'empty_order');
        $this->send([['id' => $this->itemId(), 'quantity' => 3]])->assertStatus(422)->assertJsonPath('code', 'no_changes');
        $this->send([['id' => 999999, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'item_not_on_bill');
        $this->send([['id' => $this->itemId(), 'quantity' => 2]], ['settlement_method' => null])->assertStatus(422)->assertJsonPath('code', 'settlement_required');
        $this->send([['id' => $this->itemId(), 'quantity' => 2]], ['reason' => ''])->assertStatus(422);
        $this->send([['id' => $this->itemId(), 'quantity' => 2]], ['expected_edit_count' => 5])->assertStatus(409)->assertJsonPath('code', 'order_changed');
    }

    public function test_a_refunded_bill_cannot_be_edited(): void
    {
        $this->actAsAdmin();
        $this->postJson("/api/v1/invoices/{$this->invoice->id}/refunds", ['amount' => 10, 'method' => 'cash', 'reason' => 'x'])->assertCreated();
        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.can_edit', false);
        $this->send([['id' => $this->itemId(), 'quantity' => 2]])->assertStatus(409)->assertJsonPath('code', 'invoice_refunded');
    }

    public function test_preview_changes_nothing(): void
    {
        $data = $this->postJson("/api/v1/invoices/{$this->invoice->id}/edit/preview", [
            'expected_edit_count' => 0, 'items' => [['id' => $this->itemId(), 'quantity' => 1]],
        ])->assertOk()->json('data');

        $this->assertSame('20.00', $data['total']);
        $this->assertSame('-40.00', $data['difference']);
        $this->assertTrue($data['changed']);
        $this->assertSame('60.00', (string) $this->invoice->fresh()->total);
        $this->assertSame([7.0, 0.0, 7.0], $this->stock($this->mango));
        $this->assertSame(1, Payment::where('invoice_id', $this->invoice->id)->count());
    }

    public function test_receipt_marks_the_edit_and_the_next_print_is_not_a_duplicate(): void
    {
        $this->postJson("/api/v1/invoices/{$this->invoice->id}/print-log", ['driver' => 'browser', 'status' => 'success'])->assertCreated();
        $this->send([['id' => $this->itemId(), 'quantity' => 1]])->assertOk();

        $r = $this->getJson("/api/v1/invoices/{$this->invoice->id}/receipt")->json('data');
        $bytes = base64_decode($r['escpos_base64']);
        $this->assertFalse($r['duplicate']);
        $this->assertStringContainsString('EDITED BILL', $bytes);
        $this->assertStringContainsString('CASH returned', $bytes);
        $this->assertStringContainsString('Rs 20.0', $bytes);
    }

    public function test_an_offer_is_kept_while_it_qualifies_and_dropped_when_it_does_not(): void
    {
        Promotion::create(['name' => 'Ten off', 'discount_type' => 'percent', 'discount_value' => 10, 'scope' => 'order', 'min_purchase' => 100, 'is_active' => true]);
        $order = $this->newOrder();
        $this->addItem($order, $this->mango, 5);
        $this->invoice = Invoice::find($this->bill($order)->assertCreated()->json('data.id'));
        $this->assertSame('90.00', (string) $this->invoice->total);
        $id = $order->items()->first()->id;

        $this->send([['id' => $id, 'quantity' => 6]])->assertOk();
        $this->assertSame('108.00', (string) $this->invoice->fresh()->total);

        $this->send([['id' => $id, 'quantity' => 4]])->assertOk();
        $this->assertSame('80.00', (string) $this->invoice->fresh()->total);
    }

    public function test_loyalty_points_follow_the_new_total(): void
    {
        $customer = Customer::create(['name' => 'Asha', 'mobile' => '9000000002']);
        $account = app(LoyaltyService::class)->register($customer);
        $order = $this->newOrder();
        $this->patchJson("/api/v1/orders/{$order->id}", ['customer_id' => $customer->id])->assertOk();
        $this->addItem($order, $this->mango, 5);
        $this->invoice = Invoice::find($this->bill($order)->assertCreated()->json('data.id'));
        $this->assertSame(5, $account->fresh()->points_balance);

        $this->send([['id' => $order->items()->first()->id, 'quantity' => 2]])->assertOk();
        $this->assertSame(2, $account->fresh()->points_balance);
    }

    public function test_tax_stays_as_the_bill_was_charged(): void
    {
        app(SettingsService::class)->update(['tax.enabled' => true]);
        $this->send([['id' => $this->itemId(), 'quantity' => 2]])->assertOk();
        $this->assertSame('0.00', (string) $this->invoice->fresh()->tax_amount);

        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 105), 2);
        $this->invoice = Invoice::find($this->bill($order)->assertCreated()->json('data.id'));
        app(SettingsService::class)->update(['tax.enabled' => false]);
        $this->send([['id' => $order->items()->first()->id, 'quantity' => 1]])->assertOk();
        $this->assertSame('5.00', (string) $this->invoice->fresh()->tax_amount);
    }
}
