<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PrinterLog;
use App\Models\User;

/** Builds receipts as plain data and as ESC/POS bytes, and sends them to network printers. */
class ReceiptService
{
    public function __construct(private SettingsService $settings, private AuditService $audit) {}

    public function data(Invoice $invoice): array
    {
        $invoice->loadMissing('payments');
        $s = $invoice->snapshot;
        $discount = bcadd(bcadd(bcadd((string) $invoice->promotion_discount, (string) $invoice->manual_discount, 2), (string) $invoice->loyalty_discount, 2), (string) $invoice->dice_discount, 2);

        return [
            'business' => [
                'name' => $this->settings->get('business.name'),
                'address' => $this->settings->get('business.address'),
                'phone' => $this->settings->get('business.phone'),
                // A bill without tax carries no GSTIN; old bills that charged GST keep theirs.
                'gstin' => (float) $invoice->tax_amount > 0 ? $this->settings->get('business.gstin') : '',
            ],
            'width' => (int) $this->settings->get('printer.width'),
            'footer' => $this->settings->get('receipt.footer'),
            'duplicate' => $invoice->print_count > 0,
            'edited' => $invoice->edit_count > 0,
            'invoice_number' => $invoice->invoice_number,
            'order_number' => $s['order_number'],
            'customer_name' => $s['customer_name'] ?? null,
            'staff' => $s['staff'] ?? null,
            'issued_at' => $invoice->issued_at->format('d-m-Y h:i A'),
            'items' => $s['items'],
            'subtotal' => $invoice->subtotal,
            'discount' => $discount,
            'dice_prize' => $invoice->dice_prize,
            'taxable_amount' => $invoice->taxable_amount,
            'tax_amount' => $invoice->tax_amount,
            'tax_breakup' => $invoice->tax_breakup,
            'total' => $invoice->total,
            'payments' => $invoice->payments->where('type', 'payment')->map(fn ($p) => [
                'method' => strtoupper($p->method),
                'amount' => $p->amount,
                'reference' => $p->reference,
                'tendered' => $p->tendered,
                'change_given' => $p->change_given,
            ])->values()->all(),
        ];
    }

    public function escpos(array $r): string
    {
        $cols = $r['width'] === 58 ? 32 : 48;
        $esc = "\x1B";
        $gs = "\x1D";
        $center = $esc."a\x01";
        $left = $esc."a\x00";
        $boldOn = $esc."E\x01";
        $boldOff = $esc."E\x00";
        $big = $gs."!\x11";
        $normal = $gs."!\x00";
        $rule = str_repeat('-', $cols)."\n";
        $row = fn (string $l, string $v) => $this->pad($l, $v, $cols);

        $out = $esc.'@'.$center;
        if ($r['duplicate']) {
            $out .= $boldOn."*** DUPLICATE ***\n".$boldOff;
        }
        if ($r['edited']) {
            $out .= $boldOn."*** EDITED BILL ***\n".$boldOff;
        }
        $out .= $boldOn.$big.$r['business']['name']."\n".$normal.$boldOff;
        foreach (['address', 'phone'] as $k) {
            if ($r['business'][$k]) {
                $out .= $r['business'][$k]."\n";
            }
        }
        if ($r['business']['gstin']) {
            $out .= 'GSTIN: '.$r['business']['gstin']."\n";
        }
        $out .= $left.$rule;
        $out .= $this->numbersRow($r['invoice_number'], $r['order_number'], $cols);
        $name = (string) $r['customer_name'];
        if ($name !== '' && mb_strlen($r['issued_at']) + 1 + mb_strlen($name) <= $cols) {
            $out .= $row($r['issued_at'], $name);
        } else {
            $out .= $r['issued_at']."\n".($name !== '' ? $name."\n" : '');
        }
        $out .= $rule;
        $out .= $this->itemRows($r['items'], $cols);
        $out .= $rule;
        $taxes = array_filter($r['tax_breakup'], fn ($t) => (float) $t['tax'] > 0);
        if ((float) $r['discount'] > 0 || $taxes) {
            $out .= $row('Subtotal', $this->amount($r['subtotal']));
        }
        if ((float) $r['discount'] > 0) {
            $out .= $row('Discount', '-'.$this->amount($r['discount']));
        }
        foreach ($taxes as $t) {
            $out .= $row('CGST @'.((float) $t['rate'] / 2).'%', $this->amount($t['cgst']));
            $out .= $row('SGST @'.((float) $t['rate'] / 2).'%', $this->amount($t['sgst']));
        }
        $out .= $boldOn.$row('TOTAL', 'Rs '.$this->amount($r['total'])).$boldOff;
        if (! empty($r['dice_prize'])) {
            // Printers here have no rupee sign.
            $out .= wordwrap('Dice: '.str_replace('₹', 'Rs ', $r['dice_prize']), $cols, "\n", true)."\n";
        }
        foreach ($r['payments'] as $p) {
            if ((float) $p['change_given'] > 0) {
                $given = $p['method'].' given '.$this->amount($p['tendered']);
                $change = 'Change '.$this->amount($p['change_given']);
                $out .= mb_strlen($given) + 1 + mb_strlen($change) <= $cols ? $row($given, $change) : $given."\n".$change."\n";
            } elseif ((float) $p['amount'] < 0) {
                $out .= $row($p['method'].' returned', $this->amount(abs((float) $p['amount'])));
            } else {
                $out .= $row($p['method'], $this->amount($p['amount']));
            }
            if ($p['reference']) {
                $out .= 'Ref: '.$p['reference']."\n";
            }
        }
        $out .= $center.$r['footer']."\n\n".$gs."V\x41\x03";

        return $out;
    }

    /** Invoice on the left, order on the right, shortening the labels and the order number only as far as the paper needs. */
    private function numbersRow(string $invoice, string $order, int $cols): string
    {
        $short = str_replace('-', '', $order);
        $options = [
            ["Invoice: {$invoice}", "Order: {$order}"],
            ["Invoice: {$invoice}", "Order: {$short}"],
            [$invoice, "Order: {$order}"],
            [$invoice, "Order: {$short}"],
            [$invoice, "#{$short}"],
        ];
        foreach ($options as [$left, $right]) {
            if (mb_strlen($left) + 1 + mb_strlen($right) <= $cols) {
                return $this->pad($left, $right, $cols);
            }
        }

        return $invoice."\n"."Order: {$short}\n";
    }

    /**
     * A heading, then one row per item: name, price, quantity, amount. A name too long for its column continues underneath,
     * and the item's category goes on a line of its own, so Strawberry from two categories reads as two different items.
     */
    private function itemRows(array $items, int $cols): string
    {
        $rows = array_map(fn ($i) => [
            $i['name'],
            $this->plain($i['quantity'], 3),
            $this->plain($i['unit_price'], 2),
            $this->amount($i['line_total']),
            $i['category'] ?? '',
        ], $items);
        $qtyWidth = max(3, ...array_map(fn ($r) => mb_strlen($r[1]), $rows));
        $priceWidth = max(5, ...array_map(fn ($r) => mb_strlen($r[2]), $rows));
        $amountWidth = max(3, ...array_map(fn ($r) => mb_strlen($r[3]), $rows));
        $nameWidth = max(1, $cols - $qtyWidth - $priceWidth - $amountWidth - 3);

        $format = fn (string $name, string $qty, string $price, string $amount) => $name.str_repeat(' ', $nameWidth - mb_strlen($name) + 1)
            .str_pad($price, $priceWidth, ' ', STR_PAD_LEFT).' '.str_pad($qty, $qtyWidth, ' ', STR_PAD_LEFT).' '.str_pad($amount, $amountWidth, ' ', STR_PAD_LEFT)."\n";

        $out = $format('Item', 'Qty', 'Price', 'Amt');
        foreach ($rows as [$name, $qty, $price, $amount, $category]) {
            $lines = $this->wrap($name, $nameWidth);
            $out .= $format(array_shift($lines), $qty, $price, $amount);
            foreach ($lines as $line) {
                $out .= $line."\n";
            }
            if ($category !== '') {
                foreach ($this->wrap("({$category})", $cols - 2) as $line) {
                    $out .= '  '.$line."\n";
                }
            }
        }

        return $out;
    }

    private function wrap(string $text, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            foreach (mb_str_split($word, $width) as $i => $part) {
                $candidate = $line === '' ? $part : "{$line} {$part}";
                if ($i === 0 && mb_strlen($candidate) <= $width) {
                    $line = $candidate;
                } else {
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                    $line = $part;
                }
            }
        }
        $lines[] = $line;

        return $lines;
    }

    /** 20.00 prints as 20, 1.500 as 1.5. */
    private function plain(string|float $value, int $decimals): string
    {
        return rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    }

    /** 40.00 prints as 40.0 and 12.55 stays 12.55, saving a column without hiding paise. */
    private function amount(string|float $value): string
    {
        $text = number_format((float) $value, 2, '.', '');

        return str_ends_with($text, '0') ? substr($text, 0, -1) : $text;
    }

    /** Sends to a network printer. Failures are logged and returned, never thrown. */
    public function printToNetwork(Invoice $invoice, User $user): array
    {
        $data = $this->data($invoice);
        $host = (string) $this->settings->get('printer.host');
        $port = (int) $this->settings->get('printer.port');
        $error = null;
        if ($host === '') {
            $error = 'No printer address is set.';
        } else {
            $socket = @fsockopen($host, $port, $code, $message, 3);
            if (! $socket) {
                $error = "Printer not reachable at {$host}:{$port} ({$message}).";
            } else {
                $written = fwrite($socket, $this->escpos($data));
                fclose($socket);
                if ($written === false) {
                    $error = 'The printer connection dropped while printing.';
                }
            }
        }
        $this->log($invoice, $user, 'network', $error ? 'failed' : 'success', $error);

        return ['printed' => $error === null, 'error' => $error, 'duplicate' => $data['duplicate']];
    }

    /** Logs a print attempt. Only a successful print counts, so a failed first print is not marked duplicate. */
    public function log(Invoice $invoice, ?User $user, string $driver, string $status, ?string $error = null): PrinterLog
    {
        $log = PrinterLog::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user?->id,
            'terminal_id' => $this->audit->terminalId(),
            'driver' => $driver,
            'status' => $status,
            'is_duplicate' => $invoice->print_count > 0,
            'error' => $error,
            'created_at' => now(),
        ]);
        if ($status === 'success') {
            $invoice->increment('print_count');
        }

        return $log;
    }

    public function networkStatus(): array
    {
        $host = (string) $this->settings->get('printer.host');
        if ($host === '') {
            return ['connected' => false, 'message' => 'No printer address is set.'];
        }
        $socket = @fsockopen($host, (int) $this->settings->get('printer.port'), $code, $message, 2);
        if ($socket) {
            fclose($socket);

            return ['connected' => true, 'message' => 'Printer is reachable.'];
        }

        return ['connected' => false, 'message' => $message ?: 'Printer not reachable.'];
    }

    private function pad(string $left, string $right, int $cols): string
    {
        $space = max(1, $cols - mb_strlen($left) - mb_strlen($right));

        return $left.str_repeat(' ', $space).$right."\n";
    }
}
