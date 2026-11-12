<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashClosingRequest;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\EditInvoiceRequest;
use App\Http\Requests\PaymentCallbackRequest;
use App\Http\Requests\PreviewBillRequest;
use App\Http\Requests\PrintLogRequest;
use App\Http\Requests\RefundRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrinterLog;
use App\Services\BillEditService;
use App\Services\BillingService;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private BillingService $billing,
        private PaymentService $payments,
        private ReceiptService $receipts,
        private BillEditService $edits,
    ) {}

    public function preview(PreviewBillRequest $request, Order $order)
    {
        return $this->ok($this->billing->preview($order, $request->validated()));
    }

    public function checkout(CheckoutRequest $request, Order $order)
    {
        $invoice = $this->billing->checkout($order, $request->validated(), $request->user());

        return $this->ok($this->invoiceData($invoice), [], 201);
    }

    public function invoices(Request $request)
    {
        $q = Invoice::query()->latest('id');
        if ($search = trim((string) $request->query('search'))) {
            $q->where('invoice_number', 'like', "%{$search}%");
        }
        if ($customer = $request->query('customer_id')) {
            $q->where('customer_id', $customer);
        }

        return $this->page($q->with('payments')->paginate($this->perPage()), InvoiceResource::class);
    }

    public function invoice(Invoice $invoice)
    {
        return $this->ok($this->invoiceData($invoice));
    }

    /** Receipt data plus ESC/POS bytes, for printing from the device. */
    public function receipt(Invoice $invoice)
    {
        $data = $this->receipts->data($invoice);

        return $this->ok($data + ['escpos_base64' => base64_encode($this->receipts->escpos($data))]);
    }

    public function printNetwork(Request $request, Invoice $invoice)
    {
        return $this->ok($this->receipts->printToNetwork($invoice, $request->user()));
    }

    public function printLog(PrintLogRequest $request, Invoice $invoice)
    {
        $log = $this->receipts->log($invoice, $request->user(), $request->driver, $request->status, $request->error);

        return $this->ok($log, ['print_count' => $invoice->fresh()->print_count], 201);
    }

    public function printLogs(Request $request)
    {
        $q = PrinterLog::with('invoice:id,invoice_number', 'user:id,name')->latest('id');

        return $this->page($q->paginate($this->perPage()));
    }

    public function printerStatus()
    {
        return $this->ok($this->receipts->networkStatus());
    }

    public function refund(RefundRequest $request, Invoice $invoice)
    {
        $refund = $this->payments->refund($invoice, $request->validated(), $request->user());

        return $this->ok(new PaymentResource($refund), [], 201);
    }

    public function previewEdit(EditInvoiceRequest $request, Invoice $invoice)
    {
        return $this->ok($this->edits->preview($invoice, $request->validated(), $request->user()));
    }

    public function edit(EditInvoiceRequest $request, Invoice $invoice)
    {
        return $this->ok($this->invoiceData($this->edits->edit($invoice, $request->validated(), $request->user())));
    }

    public function payments(Request $request)
    {
        $q = Payment::with('invoice', 'user')->latest('id');
        if ($date = $request->query('date')) {
            $q->whereDate('created_at', $date);
        }
        if ($method = $request->query('method')) {
            $q->where('method', $method);
        }
        if ($request->query('unreconciled')) {
            $q->whereNull('reconciled_at')->whereIn('method', ['upi', 'card']);
        }

        return $this->page($q->paginate($this->perPage()), PaymentResource::class);
    }

    public function reconcile(Request $request)
    {
        $ids = $request->validate(['payment_ids' => ['required', 'array'], 'payment_ids.*' => ['integer']])['payment_ids'];

        return $this->ok(['updated' => $this->payments->reconcile($ids, $request->user())]);
    }

    public function summary(Request $request)
    {
        return $this->ok($this->payments->dailySummary($request->query('date', now()->toDateString())));
    }

    public function closeCash(CashClosingRequest $request)
    {
        return $this->ok($this->payments->closeCash($request->business_date, $request->counted_balance, $request->notes, $request->user()));
    }

    /** Provider webhook. It has no user session, so a signature over the raw body proves the sender. */
    public function callback(PaymentCallbackRequest $request)
    {
        $secret = (string) config('services.payments.callback_secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (in_array($secret, ['', 'change-me'], true) || ! hash_equals($expected, (string) $request->header('X-Signature'))) {
            throw new BusinessException('Invalid signature.', 'invalid_signature', 401);
        }

        return $this->ok($this->payments->handleCallback($request->validated()));
    }

    private function invoiceData(Invoice $invoice): array
    {
        return (new InvoiceResource($invoice->load('payments.user')))->resolve();
    }
}
