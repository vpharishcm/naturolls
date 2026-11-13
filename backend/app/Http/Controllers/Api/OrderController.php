<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddOrderItemRequest;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\SyncOrderRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\OrderService;
use App\Services\SyncService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $q = Order::query()->with('invoice', 'creator')->latest('id');
        // The Orders to Be Billed cards list what is in each order, so staff can tell orders of the same amount apart.
        if ($request->boolean('include_items')) {
            $q->with('items');
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from.' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }
        if ($search = trim((string) $request->query('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_mobile', 'like', "%{$search}%");
            });
        }

        return $this->page($q->paginate($this->perPage()), OrderResource::class);
    }

    public function store(StoreOrderRequest $request)
    {
        $order = $this->orders->create($request->validated(), $request->user());

        return $this->ok($this->full($order), [], 201);
    }

    public function show(Order $order)
    {
        return $this->ok($this->full($order));
    }

    public function update(StoreOrderRequest $request, Order $order)
    {
        return $this->ok($this->full($this->orders->updateCustomer($order, $request->validated(), $request->user())));
    }

    public function addItem(AddOrderItemRequest $request, Order $order)
    {
        $product = Product::with('category')->findOrFail($request->product_id);
        $variant = $request->product_variant_id
            ? ProductVariant::where('product_id', $product->id)->findOrFail($request->product_variant_id)
            : null;
        $qty = number_format((float) ($request->quantity ?? 1), 3, '.', '');
        $this->orders->addItem($order, $product, $variant, $qty, $request->user());

        return $this->ok($this->full($order), [], 201);
    }

    public function updateItem(UpdateOrderItemRequest $request, Order $order, OrderItem $item)
    {
        $qty = number_format((float) $request->quantity, 3, '.', '');
        $this->orders->setQuantity($order, $item, $qty, $request->user(), (bool) $request->return_to_stock, $request->manager_pin);

        return $this->ok($this->full($order));
    }

    public function removeItem(Request $request, Order $order, OrderItem $item)
    {
        $this->orders->setQuantity($order, $item, '0.000', $request->user(), $request->boolean('return_to_stock'), $request->input('manager_pin'));

        return $this->ok($this->full($order));
    }

    public function serveItem(Request $request, Order $order, OrderItem $item)
    {
        $qty = $request->filled('quantity') ? number_format((float) $request->quantity, 3, '.', '') : null;
        $this->orders->markServed($order, $item, $qty, $request->user());

        return $this->ok($this->full($order));
    }

    public function resolveItem(Request $request, Order $order, OrderItem $item)
    {
        $this->orders->resolveShortfall($order, $item, $request->user());

        return $this->ok($this->full($order));
    }

    public function cancel(CancelOrderRequest $request, Order $order)
    {
        $this->orders->cancel($order, $request->reason, $request->user(), $request->manager_pin);

        return $this->ok($this->full($order));
    }

    public function sync(SyncOrderRequest $request, SyncService $sync)
    {
        $result = $sync->syncOrder($request->validated(), $request->user());

        return $this->ok([
            'order' => $this->full($result['order']),
            'duplicate' => $result['duplicate'],
            'conflicts' => $result['conflicts'],
        ]);
    }

    private function full(Order $order): array
    {
        return (new OrderResource($order->fresh()->load('items', 'invoice', 'creator')))->resolve();
    }
}
