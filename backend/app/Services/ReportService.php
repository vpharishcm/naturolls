<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every report returns the same shape (title, columns, rows, summary), so the
 * screen and the Excel, CSV and PDF exports all share one definition.
 */
class ReportService
{
    public const CATALOG = [
        'Sales' => ['sales-period' => 'Sales by day, week or month', 'sales-product' => 'Product-wise sales', 'sales-category' => 'Category-wise sales', 'sales-hours' => 'Peak sales hours'],
        'Inventory' => ['stock-current' => 'Current stock', 'stock-movements' => 'Stock movement history', 'stock-consumption' => 'Stock consumption', 'stock-wastage' => 'Wastage', 'stock-low' => 'Low-stock products'],
        'Financial' => ['financial-summary' => 'Financial summary', 'financial-payments' => 'Payment mode breakdown', 'financial-refunds' => 'Refunds', 'financial-profit' => 'Cost and estimated profit'],
        'Production' => ['production-daily' => 'Daily production', 'production-flavour' => 'Flavour-wise production', 'production-vs-sales' => 'Production versus sales'],
        'Staff' => ['staff-summary' => 'Orders and billing by staff', 'staff-activity' => 'Staff activity', 'staff-modifications' => 'Modifications', 'staff-cancellations' => 'Cancellations'],
        'Customers' => ['customer-spending' => 'Customer-wise spending', 'customer-repeat' => 'Repeat customers', 'customer-history' => 'Customer purchase history'],
        'Loyalty and offers' => ['loyalty-summary' => 'Loyalty points', 'promotion-summary' => 'Promotion performance', 'dice-summary' => 'Roll the dice'],
    ];

    public function run(string $type, array $f): array
    {
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $type))));
        if (! $this->known($type) || ! method_exists($this, $method)) {
            throw new BusinessException('Unknown report.', 'unknown_report', 404);
        }
        $from = Carbon::parse($f['from'] ?? now()->toDateString())->startOfDay();
        $to = Carbon::parse($f['to'] ?? now()->toDateString())->endOfDay();

        return $this->$method($from, $to, $f) + [
            'title' => $this->title($type),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [],
        ];
    }

    private function known(string $type): bool
    {
        return $this->title($type) !== null;
    }

    private function title(string $type): ?string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$type])) {
                return $group[$type];
            }
        }

        return null;
    }

    private function cols(array $defs): array
    {
        return array_map(fn ($k, $d) => ['key' => $k, 'label' => $d[0], 'type' => $d[1] ?? 'text'], array_keys($defs), $defs);
    }

    private function salesPeriod($from, $to, $f): array
    {
        $group = $f['group'] ?? 'day';
        $expr = match ($group) {
            'week' => "DATE_FORMAT(issued_at, '%x-W%v')",
            'month' => "DATE_FORMAT(issued_at, '%Y-%m')",
            default => 'DATE(issued_at)',
        };
        $rows = DB::table('invoices')->whereBetween('issued_at', [$from, $to])
            ->selectRaw("$expr as period, COUNT(*) as orders, SUM(total) as revenue, AVG(total) as average")
            ->groupBy('period')->orderBy('period')->get();

        return [
            'columns' => $this->cols(['period' => [ucfirst($group)], 'orders' => ['Orders', 'number'], 'revenue' => ['Revenue', 'money'], 'average' => ['Average order', 'money']]),
            'rows' => $rows,
            'summary' => [
                ['label' => 'Orders', 'value' => $rows->sum('orders'), 'type' => 'number'],
                ['label' => 'Revenue', 'value' => $rows->sum('revenue'), 'type' => 'money'],
                ['label' => 'Average order', 'value' => $rows->sum('orders') ? $rows->sum('revenue') / $rows->sum('orders') : 0, 'type' => 'money'],
            ],
        ];
    }

    private function soldItems($from, $to)
    {
        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.status', 'billed')->whereNull('i.deleted_at')->whereBetween('o.billed_at', [$from, $to]);
    }

    /** Adds each sold item's category (as c), so the same name in two categories stays two rows. */
    private function withCategory($q)
    {
        return $q->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id');
    }

    private function salesProduct($from, $to): array
    {
        $rows = $this->withCategory($this->soldItems($from, $to))->selectRaw('i.product_name as product, c.name as category, SUM(i.quantity) as quantity, SUM(i.line_total) as revenue')
            ->groupBy('i.product_name', 'c.name')->orderByDesc('revenue')->get();

        return ['columns' => $this->cols(['product' => ['Product'], 'category' => ['Category'], 'quantity' => ['Quantity sold', 'qty'], 'revenue' => ['Revenue', 'money']]), 'rows' => $rows];
    }

    private function salesCategory($from, $to): array
    {
        $rows = $this->soldItems($from, $to)->selectRaw("COALESCE(i.category_name, 'Uncategorised') as category, SUM(i.quantity) as quantity, SUM(i.line_total) as revenue")
            ->groupBy('category')->orderByDesc('revenue')->get();

        return ['columns' => $this->cols(['category' => ['Category'], 'quantity' => ['Quantity sold', 'qty'], 'revenue' => ['Revenue', 'money']]), 'rows' => $rows];
    }

    private function salesHours($from, $to): array
    {
        $rows = DB::table('invoices')->whereBetween('issued_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(issued_at, '%H:00') as hour, COUNT(*) as orders, SUM(total) as revenue")
            ->groupBy('hour')->orderBy('hour')->get();

        return ['columns' => $this->cols(['hour' => ['Hour'], 'orders' => ['Orders', 'number'], 'revenue' => ['Revenue', 'money']]), 'rows' => $rows];
    }

    private function stockRows()
    {
        return DB::table('inventory as s')->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 's.product_variant_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at')->where('p.track_inventory', true)
            ->selectRaw("CONCAT(p.name, COALESCE(CONCAT(' · ', v.name), '')) as product, c.name as category, s.opening_qty as opening, s.physical_qty as physical, s.reserved_qty as reserved, (s.physical_qty - s.reserved_qty) as available, p.min_stock as minimum")
            ->orderBy('p.name')->orderBy('c.name');
    }

    private function stockCurrent(): array
    {
        return ['columns' => $this->cols(['product' => ['Product'], 'category' => ['Category'], 'opening' => ['Opening', 'qty'], 'physical' => ['Physical', 'qty'], 'reserved' => ['Reserved', 'qty'], 'available' => ['Available', 'qty'], 'minimum' => ['Minimum', 'qty']]), 'rows' => $this->stockRows()->get()];
    }

    private function stockLow(): array
    {
        return ['columns' => $this->cols(['product' => ['Product'], 'category' => ['Category'], 'available' => ['Available', 'qty'], 'minimum' => ['Minimum', 'qty']]), 'rows' => $this->stockRows()->whereRaw('(s.physical_qty - s.reserved_qty) <= p.min_stock')->get()];
    }

    private function movementQuery($from, $to)
    {
        return DB::table('inventory_movements as m')->join('inventory as s', 's.id', '=', 'm.inventory_id')
            ->join('products as p', 'p.id', '=', 's.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->whereBetween('m.created_at', [$from, $to]);
    }

    private function stockMovements($from, $to, $f): array
    {
        $rows = $this->movementQuery($from, $to)
            ->selectRaw("m.created_at as time, p.name as product, c.name as category, m.type, m.physical_delta as physical_change, m.reserved_delta as reserved_change, m.physical_after, COALESCE(u.name, 'System') as staff, m.reason")
            ->when($f['movement_type'] ?? null, fn ($q, $t) => $q->where('m.type', $t))
            ->orderByDesc('m.id')->limit(5000)->get();

        return ['columns' => $this->cols(['time' => ['Time', 'datetime'], 'product' => ['Product'], 'category' => ['Category'], 'type' => ['Type'], 'physical_change' => ['Physical change', 'qty'], 'reserved_change' => ['Reserved change', 'qty'], 'physical_after' => ['Physical after', 'qty'], 'staff' => ['Staff'], 'reason' => ['Reason']]), 'rows' => $rows];
    }

    private function stockConsumption($from, $to): array
    {
        $rows = $this->movementQuery($from, $to)->where('m.type', 'consume')
            ->selectRaw('p.name as product, c.name as category, ABS(SUM(m.physical_delta)) as consumed')->groupBy('p.id', 'p.name', 'c.name')->orderByDesc('consumed')->get();

        return ['columns' => $this->cols(['product' => ['Product'], 'category' => ['Category'], 'consumed' => ['Consumed', 'qty']]), 'rows' => $rows];
    }

    private function stockWastage($from, $to): array
    {
        $rows = $this->movementQuery($from, $to)->where('m.type', 'wastage')
            ->selectRaw("m.created_at as time, p.name as product, c.name as category, ABS(m.physical_delta) as quantity, m.reason, COALESCE(u.name, 'System') as staff")->orderByDesc('m.id')->get();

        return ['columns' => $this->cols(['time' => ['Time', 'datetime'], 'product' => ['Product'], 'category' => ['Category'], 'quantity' => ['Quantity', 'qty'], 'reason' => ['Reason'], 'staff' => ['Staff']]), 'rows' => $rows];
    }

    private function financialSummary($from, $to): array
    {
        $i = DB::table('invoices')->whereBetween('issued_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(subtotal),0) gross, COALESCE(SUM(promotion_discount+manual_discount+loyalty_discount+dice_discount),0) discounts, COALESCE(SUM(tax_amount),0) tax, COALESCE(SUM(total),0) total, COALESCE(SUM(cost_total),0) cost')->first();
        $refunds = (float) DB::table('payments')->where('type', 'refund')->where('status', 'success')->whereBetween('created_at', [$from, $to])->sum('amount');
        $net = $i->total - $i->tax - $refunds;
        $rows = [
            ['measure' => 'Gross sales', 'amount' => $i->gross],
            ['measure' => 'Discounts', 'amount' => $i->discounts],
            ['measure' => 'Tax collected', 'amount' => $i->tax],
            ['measure' => 'Refunds', 'amount' => $refunds],
            ['measure' => 'Net sales (after discounts, tax and refunds)', 'amount' => $net],
            ['measure' => 'Cost of goods sold (where cost is set)', 'amount' => $i->cost],
            ['measure' => 'Estimated gross profit', 'amount' => $net - $i->cost],
        ];

        return ['columns' => $this->cols(['measure' => ['Measure'], 'amount' => ['Amount', 'money']]), 'rows' => $rows];
    }

    private function financialPayments($from, $to): array
    {
        $rows = DB::table('payments')->where('status', 'success')->whereBetween('created_at', [$from, $to])
            ->selectRaw("UPPER(method) as method, SUM(CASE WHEN type='payment' THEN amount ELSE 0 END) as received, SUM(CASE WHEN type='refund' THEN amount ELSE 0 END) as refunded, SUM(CASE WHEN type='payment' THEN amount ELSE -amount END) as net, SUM(type='payment') as transactions")
            ->groupBy('method')->get();

        return ['columns' => $this->cols(['method' => ['Mode'], 'transactions' => ['Payments', 'number'], 'received' => ['Received', 'money'], 'refunded' => ['Refunded', 'money'], 'net' => ['Net', 'money']]), 'rows' => $rows];
    }

    private function financialRefunds($from, $to): array
    {
        $rows = DB::table('payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.type', 'refund')->whereBetween('p.created_at', [$from, $to])
            ->selectRaw('p.created_at as time, i.invoice_number as invoice, UPPER(p.method) as method, p.amount, p.reason, u.name as staff')->orderByDesc('p.id')->get();

        return ['columns' => $this->cols(['time' => ['Time', 'datetime'], 'invoice' => ['Invoice'], 'method' => ['Mode'], 'amount' => ['Amount', 'money'], 'reason' => ['Reason'], 'staff' => ['Staff']]), 'rows' => $rows];
    }

    private function financialProfit($from, $to): array
    {
        $rows = $this->withCategory($this->soldItems($from, $to))->whereNotNull('i.unit_cost')
            ->selectRaw('i.product_name as product, c.name as category, SUM(i.quantity) as quantity, SUM(i.line_total) as revenue, SUM(i.quantity * i.unit_cost) as cost, SUM(i.line_total - i.quantity * i.unit_cost) as profit')
            ->groupBy('i.product_name', 'c.name')->orderByDesc('profit')->get();

        return ['columns' => $this->cols(['product' => ['Product'], 'category' => ['Category'], 'quantity' => ['Quantity', 'qty'], 'revenue' => ['Revenue incl. tax', 'money'], 'cost' => ['Cost', 'money'], 'profit' => ['Estimated profit', 'money']]), 'rows' => $rows];
    }

    private function productionItems($from, $to)
    {
        return DB::table('production_batch_items as i')->join('production_batches as b', 'b.id', '=', 'i.production_batch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->whereNull('i.reversed_at')
            ->whereBetween('b.production_date', [$from->toDateString(), $to->toDateString()]);
    }

    private function productionDaily($from, $to): array
    {
        $rows = $this->productionItems($from, $to)->selectRaw('b.production_date as date, COUNT(DISTINCT p.id) as flavours, SUM(i.quantity) as quantity')
            ->groupBy('b.production_date')->orderBy('b.production_date')->get();

        return ['columns' => $this->cols(['date' => ['Date', 'date'], 'flavours' => ['Flavours', 'number'], 'quantity' => ['Quantity', 'qty']]), 'rows' => $rows];
    }

    private function productionFlavour($from, $to): array
    {
        $rows = $this->productionItems($from, $to)->selectRaw('p.name as flavour, c.name as category, SUM(i.quantity) as quantity')->groupBy('p.id', 'p.name', 'c.name')->orderByDesc('quantity')->get();

        return ['columns' => $this->cols(['flavour' => ['Flavour'], 'category' => ['Category'], 'quantity' => ['Quantity', 'qty']]), 'rows' => $rows];
    }

    private function productionVsSales($from, $to): array
    {
        $made = $this->productionItems($from, $to)->selectRaw('i.product_id, p.name, c.name as category, SUM(i.quantity) as qty')->groupBy('i.product_id', 'p.name', 'c.name')->get()->keyBy('product_id');
        $sold = $this->soldItems($from, $to)->selectRaw('i.product_id, SUM(i.quantity) as qty')->groupBy('i.product_id')->pluck('qty', 'product_id');
        $rows = $made->map(fn ($m) => [
            'flavour' => $m->name,
            'category' => $m->category,
            'produced' => $m->qty,
            'sold' => $sold[$m->product_id] ?? 0,
            'difference' => $m->qty - ($sold[$m->product_id] ?? 0),
        ])->values();

        return ['columns' => $this->cols(['flavour' => ['Flavour'], 'category' => ['Category'], 'produced' => ['Produced', 'qty'], 'sold' => ['Sold', 'qty'], 'difference' => ['Difference', 'qty']]), 'rows' => $rows];
    }

    private function staffSummary($from, $to): array
    {
        $rows = DB::table('users as u')->selectRaw('u.name as staff,
            (SELECT COUNT(*) FROM orders o WHERE o.created_by = u.id AND o.created_at BETWEEN ? AND ?) as orders,
            (SELECT COUNT(*) FROM invoices i WHERE i.billed_by = u.id AND i.issued_at BETWEEN ? AND ?) as bills,
            (SELECT COALESCE(SUM(i.total),0) FROM invoices i WHERE i.billed_by = u.id AND i.issued_at BETWEEN ? AND ?) as billed,
            (SELECT COUNT(*) FROM orders o WHERE o.cancelled_by = u.id AND o.cancelled_at BETWEEN ? AND ?) as cancellations', [$from, $to, $from, $to, $from, $to, $from, $to])
            ->whereNull('u.deleted_at')->orderBy('u.name')->get();

        return ['columns' => $this->cols(['staff' => ['Staff'], 'orders' => ['Orders', 'number'], 'bills' => ['Bills', 'number'], 'billed' => ['Billed value', 'money'], 'cancellations' => ['Cancellations', 'number']]), 'rows' => $rows];
    }

    private function staffActivity($from, $to): array
    {
        $rows = DB::table('staff_activities as a')->join('users as u', 'u.id', '=', 'a.user_id')->whereBetween('a.created_at', [$from, $to])
            ->selectRaw('a.created_at as time, u.name as staff, a.activity, a.description')->orderByDesc('a.id')->limit(5000)->get();

        return ['columns' => $this->cols(['time' => ['Time', 'datetime'], 'staff' => ['Staff'], 'activity' => ['Activity'], 'description' => ['Details']]), 'rows' => $rows];
    }

    private function staffModifications($from, $to): array
    {
        $rows = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('orders as o', function ($j) {
            $j->on('o.id', '=', 'a.entity_id')->where('a.entity_type', '=', 'Order');
        })->whereBetween('a.created_at', [$from, $to])->whereIn('a.action', ['order.item_quantity_changed', 'order.item_removed', 'order.served_item_removed', 'order.served_item_returned', 'invoice.item_changed'])
            ->selectRaw("a.created_at as time, u.name as staff, a.action, o.order_number, JSON_UNQUOTE(JSON_EXTRACT(a.before, '$.item')) as item, JSON_UNQUOTE(JSON_EXTRACT(a.before, '$.quantity')) as from_qty, JSON_UNQUOTE(JSON_EXTRACT(a.after, '$.quantity')) as to_qty")
            ->orderByDesc('a.id')->limit(5000)->get();

        return ['columns' => $this->cols(['time' => ['Time', 'datetime'], 'staff' => ['Staff'], 'action' => ['Change'], 'order_number' => ['Order'], 'item' => ['Item'], 'from_qty' => ['From'], 'to_qty' => ['To']]), 'rows' => $rows];
    }

    private function staffCancellations($from, $to): array
    {
        $rows = DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.cancelled_by')->where('o.status', 'cancelled')->whereBetween('o.cancelled_at', [$from, $to])
            ->selectRaw('o.cancelled_at as time, o.order_number, u.name as staff, o.subtotal as value, o.cancel_reason as reason')->orderByDesc('o.id')->get();

        return ['columns' => $this->cols(['time' => ['Time', 'datetime'], 'order_number' => ['Order'], 'staff' => ['Staff'], 'value' => ['Value', 'money'], 'reason' => ['Reason']]), 'rows' => $rows];
    }

    private function customerSpendingQuery($from, $to)
    {
        return DB::table('invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')->whereBetween('i.issued_at', [$from, $to])
            ->selectRaw('c.name as customer, c.mobile, COUNT(*) as orders, SUM(i.total) as spent, MAX(i.issued_at) as last_visit')->groupBy('c.id', 'c.name', 'c.mobile');
    }

    private function customerSpending($from, $to): array
    {
        return ['columns' => $this->cols(['customer' => ['Customer'], 'mobile' => ['Mobile'], 'orders' => ['Orders', 'number'], 'spent' => ['Spent', 'money'], 'last_visit' => ['Last visit', 'datetime']]), 'rows' => $this->customerSpendingQuery($from, $to)->orderByDesc('spent')->get()];
    }

    private function customerRepeat($from, $to): array
    {
        return ['columns' => $this->cols(['customer' => ['Customer'], 'mobile' => ['Mobile'], 'orders' => ['Orders', 'number'], 'spent' => ['Spent', 'money'], 'last_visit' => ['Last visit', 'datetime']]), 'rows' => $this->customerSpendingQuery($from, $to)->havingRaw('COUNT(*) > 1')->orderByDesc('orders')->get()];
    }

    private function customerHistory($from, $to, $f): array
    {
        $rows = DB::table('invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')->whereBetween('i.issued_at', [$from, $to])
            ->when($f['customer_id'] ?? null, fn ($q, $id) => $q->where('c.id', $id))
            ->selectRaw('i.issued_at as time, c.name as customer, c.mobile, i.invoice_number as invoice, i.total')->orderByDesc('i.id')->limit(5000)->get();

        return ['columns' => $this->cols(['time' => ['Time', 'datetime'], 'customer' => ['Customer'], 'mobile' => ['Mobile'], 'invoice' => ['Invoice'], 'total' => ['Total', 'money']]), 'rows' => $rows];
    }

    private function loyaltySummary($from, $to): array
    {
        $rows = DB::table('loyalty_accounts as a')->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoin('loyalty_transactions as t', function ($j) use ($from, $to) {
                $j->on('t.loyalty_account_id', '=', 'a.id')->whereBetween('t.created_at', [$from, $to]);
            })->selectRaw("c.name as customer, c.mobile, a.points_balance as balance, COALESCE(SUM(CASE WHEN t.type='earn' THEN t.points END),0) as earned, COALESCE(ABS(SUM(CASE WHEN t.type='redeem' THEN t.points END)),0) as redeemed")
            ->groupBy('a.id', 'c.name', 'c.mobile', 'a.points_balance')->orderByDesc('balance')->get();

        return ['columns' => $this->cols(['customer' => ['Customer'], 'mobile' => ['Mobile'], 'balance' => ['Balance', 'number'], 'earned' => ['Earned in period', 'number'], 'redeemed' => ['Redeemed in period', 'number']]), 'rows' => $rows];
    }

    private function promotionSummary($from, $to): array
    {
        // A bill with two offers counts once under each, with each offer's own saving.
        $rows = DB::table('invoice_promotions as ip')->join('invoices as i', 'i.id', '=', 'ip.invoice_id')->join('promotions as p', 'p.id', '=', 'ip.promotion_id')
            ->whereBetween('i.issued_at', [$from, $to])
            ->selectRaw('p.name as promotion, COUNT(*) as uses, SUM(ip.discount) as discount, SUM(i.total) as revenue')->groupBy('p.id', 'p.name')->orderByDesc('uses')->get();

        return ['columns' => $this->cols(['promotion' => ['Offer'], 'uses' => ['Times used', 'number'], 'discount' => ['Discount given', 'money'], 'revenue' => ['Bill revenue', 'money']]), 'rows' => $rows];
    }

    /** Each prize won on the dice: how often, the rupees taken off, and free items at their menu price. */
    private function diceSummary($from, $to): array
    {
        $freeValue = DB::table('order_items as oi')
            ->leftJoin('product_variants as v', 'v.id', '=', 'oi.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->whereNull('oi.deleted_at')->where('oi.unit_price', 0)->where('oi.product_name', 'like', '% (dice prize)')
            ->groupBy('oi.order_id')
            ->selectRaw('oi.order_id, SUM(oi.quantity * COALESCE(v.price, p.price, 0)) as value');
        $bills = DB::table('invoices as i')->leftJoinSub($freeValue, 'f', 'f.order_id', '=', 'i.order_id')
            ->whereNotNull('i.dice_prize')->whereBetween('i.issued_at', [$from, $to]);

        $rows = (clone $bills)
            ->selectRaw("TRIM(SUBSTRING_INDEX(i.dice_prize, ':', -1)) as prize, COUNT(*) as wins, SUM(i.dice_discount) as discount, COALESCE(SUM(f.value), 0) as free_value, SUM(i.total) as revenue")
            ->groupBy('prize')->orderByDesc('wins')->get();
        $rolledAgain = (clone $bills)->get(['i.dice_rolls'])->filter(fn ($r) => count(json_decode((string) $r->dice_rolls, true) ?: []) > 1)->count();

        return [
            'columns' => $this->cols(['prize' => ['Prize'], 'wins' => ['Times won', 'number'], 'discount' => ['Rupees off', 'money'], 'free_value' => ['Free items (menu price)', 'money'], 'revenue' => ['Bill revenue', 'money']]),
            'rows' => $rows,
            'summary' => [
                ['label' => 'Bills that rolled', 'value' => $rows->sum('wins'), 'type' => 'number'],
                ['label' => 'Rolled again at least once', 'value' => $rolledAgain, 'type' => 'number'],
                ['label' => 'Cost of prizes', 'value' => $rows->sum('discount') + $rows->sum('free_value'), 'type' => 'money'],
            ],
        ];
    }
}
