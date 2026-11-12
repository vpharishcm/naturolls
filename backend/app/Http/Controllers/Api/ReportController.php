<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\BackupService;
use App\Services\PaymentService;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function catalog()
    {
        return $this->ok(ReportService::CATALOG);
    }

    public function show(Request $request, string $type)
    {
        return $this->ok($this->reports->run($type, $request->query()));
    }

    public function export(Request $request, string $type, string $format)
    {
        $report = $this->reports->run($type, $request->query());
        $name = $type.'_'.$report['from'].'_'.$report['to'];
        $rows = $this->flatten($report);
        $headers = array_column($report['columns'], 'label');

        return match ($format) {
            'csv' => response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    fputcsv($out, array_map([$this, 'safeCell'], $row));
                }
                fclose($out);
            }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']),
            'xlsx' => $this->xlsx($headers, $rows, $name),
            'pdf' => Pdf::loadView('reports.pdf', ['report' => $report, 'headers' => $headers, 'rows' => $rows])
                ->setPaper('a4', count($headers) > 5 ? 'landscape' : 'portrait')->download($name.'.pdf'),
            default => throw new BusinessException('Unknown export format.', 'unknown_format', 404),
        };
    }

    private function flatten(array $report): array
    {
        return collect($report['rows'])->map(function ($row) use ($report) {
            $row = (array) $row;

            return array_map(fn ($c) => isset($row[$c['key']]) ? (string) $row[$c['key']] : '', $report['columns']);
        })->values()->all();
    }

    /** Stops spreadsheet apps from running a customer-typed value as a formula. */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+@\-]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }

    private function xlsx(array $headers, array $rows, string $name)
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => is_numeric($v) ? (float) $v : $this->safeCell($v), $row)));
        }
        $writer->close();

        return response()->download($path, $name.'.xlsx')->deleteFileAfterSend();
    }

    public function dashboard(PaymentService $payments, BackupService $backups)
    {
        $today = [now()->startOfDay(), now()->endOfDay()];
        $invoices = DB::table('invoices')->whereBetween('issued_at', $today)->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(tax_amount),0) tax')->first();
        $unbilled = Order::where('status', 'unbilled')->selectRaw('COUNT(*) c, COALESCE(SUM(subtotal),0) t')->first();

        $stock = DB::table('inventory as s')->join('products as p', 'p.id', '=', 's.product_id')
            ->where('p.track_inventory', true)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->whereRaw('(s.physical_qty - s.reserved_qty) <= p.min_stock')
            ->selectRaw('p.name, (s.physical_qty - s.reserved_qty) as available, p.min_stock')->orderBy('available')->get();

        return $this->ok([
            'total_sales' => number_format((float) $invoices->t, 2, '.', ''),
            'total_orders' => Order::whereBetween('created_at', $today)->count(),
            'completed_bills' => (int) $invoices->c,
            'unbilled_count' => (int) $unbilled->c,
            'unbilled_value' => number_format((float) $unbilled->t, 2, '.', ''),
            'low_stock' => $stock->filter(fn ($s) => (float) $s->available > 0)->values(),
            'out_of_stock' => $stock->filter(fn ($s) => (float) $s->available <= 0)->values(),
            'payments' => $payments->dailySummary(now()->toDateString())['methods'],
            'tax_collected' => number_format((float) $invoices->tax, 2, '.', ''),
            'backup_failed' => (bool) $backups->lastFailureUnresolved(),
            'recent_invoices' => Invoice::latest('id')->limit(5)->get()->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'total' => $i->total, 'issued_at' => $i->issued_at->toIso8601String(),
            ]),
        ]);
    }
}
