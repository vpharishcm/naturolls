<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintingTest extends TestCase
{
    use RefreshDatabase;

    private array $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
        app(SettingsService::class)->update(['tax.enabled' => true]);
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 105), 2);
        $this->invoice = $this->bill($order)->assertCreated()->json('data');
    }

    public function test_receipt_contains_every_required_field(): void
    {
        app(SettingsService::class)->update(['business.address' => '12 Main Road', 'receipt.footer' => 'Come again']);
        $r = $this->getJson("/api/v1/invoices/{$this->invoice['id']}/receipt")->assertOk()->json('data');
        $bytes = base64_decode($r['escpos_base64']);
        foreach (['Naturolls', '12 Main Road', $r['invoice_number'], $r['order_number'], 'Subtotal', 'CGST', 'SGST', 'TOTAL', 'Rs 210.0', 'CASH', 'Come again'] as $text) {
            $this->assertStringContainsString($text, $bytes);
        }
        $this->assertFalse($r['duplicate']);
    }

    /** Plain text lines of a receipt, without printer commands. */
    private function receiptLines(int $invoiceId): array
    {
        $bytes = base64_decode($this->getJson("/api/v1/invoices/{$invoiceId}/receipt")->json('data.escpos_base64'));
        $text = preg_replace('/\x1B[@]|\x1B[aE].|\x1D!.|\x1DV../s', '', $bytes);

        return explode("\n", $text);
    }

    public function test_invoice_and_order_share_a_row_and_items_are_one_row_each(): void
    {
        app(SettingsService::class)->update(['tax.enabled' => false, 'printer.width' => 80]);
        $mango = $this->product(price: 20, extra: ['name' => 'Mango']);
        $longName = 'Very Long Named Tender Coconut Special Popsicle';
        $long = $this->product(price: 12.5, extra: ['name' => $longName]);
        $order = $this->newOrder();
        $this->addItem($order, $mango, 2);
        $this->addItem($order, $long, 1);
        $invoice = $this->bill($order)->assertCreated()->json('data');

        foreach ([80 => 48, 58 => 32] as $width => $cols) {
            app(SettingsService::class)->update(['printer.width' => $width]);
            $lines = $this->receiptLines($invoice['id']);
            foreach ($lines as $line) {
                $this->assertLessThanOrEqual($cols, mb_strlen($line), "Too wide at {$width}mm: {$line}");
            }
            $numbers = collect($lines)->first(fn ($l) => str_contains($l, $invoice['invoice_number']));
            $this->assertStringContainsString($width === 80 ? $order->order_number : str_replace('-', '', $order->order_number), $numbers);
            $this->assertMatchesRegularExpression('/^Item\s+Price\s+Qty\s+Amt$/', collect($lines)->first(fn ($l) => str_starts_with($l, 'Item')));
            // The category follows the name, so same-named items from two categories differ.
            $this->assertMatchesRegularExpression('/^Mango \| Test\s+20\s+2\s+40\.0$/', collect($lines)->first(fn ($l) => str_starts_with($l, 'Mango')));
            $this->assertMatchesRegularExpression('/\s12\.5\s+1\s+12\.5$/', collect($lines)->first(fn ($l) => str_starts_with($l, 'Very')));
            $this->assertStringContainsString('Popsicle', implode(' ', $lines));
        }
    }

    public function test_cash_with_change_prints_what_was_given_and_what_went_back(): void
    {
        app(SettingsService::class)->update(['tax.enabled' => false]);
        $order = $this->newOrder();
        $this->addItem($order, $this->product(price: 20), 1);
        $invoice = $this->bill($order, ['payments' => [['method' => 'cash', 'amount' => 20, 'tendered' => 50]]])->assertCreated()->json('data');

        foreach ([80, 58] as $width) {
            app(SettingsService::class)->update(['printer.width' => $width]);
            $row = collect($this->receiptLines($invoice['id']))->first(fn ($l) => str_starts_with($l, 'CASH'));
            $this->assertMatchesRegularExpression('/^CASH given 50\.0\s+Change 30\.0$/', $row);
        }
    }

    public function test_without_gst_the_bill_has_no_tax_and_the_receipt_no_tax_lines(): void
    {
        app(SettingsService::class)->update(['tax.enabled' => false, 'business.gstin' => '29ABCDE1234F1Z5']);
        $product = $this->product(price: 105, gst: 5);
        $order = $this->newOrder();
        $this->addItem($order, $product, 2);
        $invoice = $this->bill($order)->assertCreated()->json('data');

        $saved = Invoice::find($invoice['id']);
        $this->assertSame('210.00', (string) $saved->total);
        $this->assertSame('0.00', (string) $saved->tax_amount);
        $this->assertSame('5.00', (string) $product->fresh()->gst_rate);

        $text = implode("\n", $this->receiptLines($invoice['id']));
        foreach (['GST', 'Subtotal'] as $absent) {
            $this->assertStringNotContainsString($absent, $text);
        }

        // Turning GST on later taxes new bills and leaves the finished one alone.
        app(SettingsService::class)->update(['tax.enabled' => true]);
        $next = $this->newOrder();
        $this->addItem($next, $product, 2);
        $taxed = $this->bill($next)->assertCreated()->json('data');
        $this->assertSame('10.00', (string) Invoice::find($taxed['id'])->tax_amount);
        $this->assertSame('0.00', (string) Invoice::find($invoice['id'])->tax_amount);
        $this->assertStringContainsString('GSTIN', implode("\n", $this->receiptLines($taxed['id'])));
    }

    public function test_58mm_and_80mm_layouts_fit_their_paper(): void
    {
        foreach ([58 => 32, 80 => 48] as $width => $cols) {
            app(SettingsService::class)->update(['printer.width' => $width]);
            $r = $this->getJson("/api/v1/invoices/{$this->invoice['id']}/receipt")->json('data');
            $text = preg_replace('/[\x00-\x1F]./s', "\n", base64_decode($r['escpos_base64']));
            $this->assertStringContainsString(str_repeat('-', $cols)."\n", base64_decode($r['escpos_base64']));
            $this->assertStringNotContainsString(str_repeat('-', $cols + 1), $text);
        }
    }

    public function test_printer_failure_keeps_invoice_and_is_logged(): void
    {
        app(SettingsService::class)->update(['printer.host' => '127.0.0.1', 'printer.port' => 1]);
        $res = $this->postJson("/api/v1/invoices/{$this->invoice['id']}/print")->assertOk()->json('data');
        $this->assertFalse($res['printed']);
        $this->assertNotNull($res['error']);

        $invoice = Invoice::find($this->invoice['id']);
        $this->assertSame(0, $invoice->print_count);
        $this->assertSame('billed', $invoice->order->status);
        $this->assertDatabaseHas('printer_logs', ['invoice_id' => $invoice->id, 'status' => 'failed', 'driver' => 'network']);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_network_print_writes_to_the_socket_and_reprint_is_marked_duplicate(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        app(SettingsService::class)->update(['printer.host' => '127.0.0.1', 'printer.port' => $port]);

        $this->getJson('/api/v1/printer/status')->assertJsonPath('data.connected', true);
        stream_socket_accept($server, 1);

        $this->postJson("/api/v1/invoices/{$this->invoice['id']}/print")->assertJsonPath('data.printed', true)->assertJsonPath('data.duplicate', false);
        $conn = stream_socket_accept($server, 1);
        $first = stream_get_contents($conn);
        $this->assertStringNotContainsString('DUPLICATE', $first);
        $this->assertStringContainsString($this->invoice['invoice_number'], $first);

        $this->postJson("/api/v1/invoices/{$this->invoice['id']}/print")->assertJsonPath('data.printed', true)->assertJsonPath('data.duplicate', true);
        $conn = stream_socket_accept($server, 1);
        $this->assertStringContainsString('DUPLICATE', stream_get_contents($conn));

        $this->assertSame(2, Invoice::find($this->invoice['id'])->print_count);
        fclose($server);
    }

    public function test_device_printing_logs_and_counts_only_successes(): void
    {
        $url = "/api/v1/invoices/{$this->invoice['id']}/print-log";
        $this->postJson($url, ['driver' => 'bluetooth', 'status' => 'failed', 'error' => 'Printer off'])->assertCreated()->assertJsonPath('meta.print_count', 0);
        $this->getJson("/api/v1/invoices/{$this->invoice['id']}/receipt")->assertJsonPath('data.duplicate', false);
        $this->postJson($url, ['driver' => 'bluetooth', 'status' => 'success'])->assertCreated()->assertJsonPath('meta.print_count', 1);
        $this->getJson("/api/v1/invoices/{$this->invoice['id']}/receipt")->assertJsonPath('data.duplicate', true);
        $this->getJson('/api/v1/printer/logs')->assertJsonPath('meta.total', 2);
    }
}
