<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReverseProductionRequest;
use App\Http\Requests\StoreProductionRequest;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchItem;
use App\Services\ProductionService;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(private ProductionService $production) {}

    public function index(Request $request)
    {
        $q = ProductionBatch::with('items.product:id,name,category_id', 'items.product.category:id,name', 'user:id,name')->orderByDesc('production_date')->orderByDesc('id');
        if ($from = $request->query('from')) {
            $q->where('production_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('production_date', '<=', $to);
        }

        return $this->page($q->paginate($this->perPage()));
    }

    public function store(StoreProductionRequest $request)
    {
        return $this->ok($this->production->record($request->validated(), $request->user()), [], 201);
    }

    public function reverse(ReverseProductionRequest $request, ProductionBatchItem $item)
    {
        return $this->ok($this->production->reverse($item, $request->reason, $request->user(), $request->manager_pin));
    }

    public function edit(ReverseProductionRequest $request, ProductionBatchItem $item)
    {
        $request->validate(['quantity' => ['required', 'numeric', 'gt:0']]);

        return $this->ok($this->production->edit($item, (string) $request->quantity, $request->reason, $request->user(), $request->manager_pin));
    }
}
