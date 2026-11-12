<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OpeningStockRequest;
use App\Http\Requests\StockCorrectionRequest;
use App\Http\Requests\WastageRequest;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory, private AuditService $audit) {}

    public function index(Request $request)
    {
        $q = Inventory::query()->with('product.category', 'variant')
            ->whereHas('product', fn ($p) => $p->where('track_inventory', true))
            ->join('products', 'products.id', '=', 'inventory.product_id')
            ->select('inventory.*')->orderBy('products.name');
        if ($search = trim((string) $request->query('search'))) {
            $q->where('products.name', 'like', "%{$search}%");
        }
        if ($request->query('filter') === 'low') {
            $q->whereRaw('(inventory.physical_qty - inventory.reserved_qty) <= products.min_stock');
        }
        if ($request->query('filter') === 'out') {
            $q->whereRaw('(inventory.physical_qty - inventory.reserved_qty) <= 0');
        }

        return $this->ok($q->get()->map(fn ($r) => $this->row($r)));
    }

    public function movements(Request $request)
    {
        $q = InventoryMovement::with('inventory.product', 'inventory.variant', 'user:id,name')->latest('id');
        if ($id = $request->query('inventory_id')) {
            $q->where('inventory_id', $id);
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }

        return $this->page($q->paginate($this->perPage()));
    }

    public function correct(StockCorrectionRequest $request, Inventory $inventory)
    {
        $before = $inventory->physical_qty;
        $row = $this->inventory->correct($inventory->id, number_format((float) $request->counted, 3, '.', ''), $request->reason, $request->user());
        $this->audit->record($request->user(), 'stock.corrected', $row, ['physical' => $before], ['physical' => $row->physical_qty], $request->reason, $request->user()->id);

        return $this->ok($this->row($row->load('product', 'variant')));
    }

    public function wastage(WastageRequest $request, Inventory $inventory)
    {
        $row = $this->inventory->wastage($inventory->id, number_format((float) $request->quantity, 3, '.', ''), $request->reason, $request->user());
        $this->audit->record($request->user(), 'stock.wastage', $row, null, ['quantity' => $request->quantity], $request->reason, $request->user()->id);

        return $this->ok($this->row($row->load('product', 'variant')));
    }

    public function opening(OpeningStockRequest $request, Inventory $inventory)
    {
        $row = $this->inventory->opening($inventory->id, number_format((float) $request->quantity, 3, '.', ''), $request->user());
        $this->audit->record($request->user(), 'stock.opening_set', $row, null, ['quantity' => $request->quantity], null, $request->user()->id);

        return $this->ok($this->row($row->load('product', 'variant')));
    }

    public function reconcile(Request $request)
    {
        return $this->ok(['issues' => $this->inventory->reconcile($request->boolean('repair'))]);
    }

    private function row(Inventory $r): array
    {
        $available = $r->available_qty;
        $min = (string) ($r->product->min_stock ?? 0);

        return [
            'id' => $r->id,
            'stock_key' => $r->stock_key,
            'product_id' => $r->product_id,
            'name' => $r->product->name.($r->variant ? ' · '.$r->variant->name : ''),
            'category' => $r->product->category?->name,
            'opening_qty' => $r->opening_qty,
            'physical_qty' => $r->physical_qty,
            'reserved_qty' => $r->reserved_qty,
            'available_qty' => $available,
            'min_stock' => $min,
            'state' => bccomp($available, '0', 3) <= 0 ? 'out' : (bccomp($available, $min, 3) <= 0 ? 'low' : 'ok'),
        ];
    }
}
