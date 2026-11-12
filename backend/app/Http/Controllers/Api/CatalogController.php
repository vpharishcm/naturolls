<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCategoryRequest;
use App\Http\Requests\SaveProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function __construct(private AuditService $audit, private InventoryService $inventory) {}

    public function categories()
    {
        return $this->ok(Category::orderBy('display_order')->orderBy('name')->get());
    }

    public function storeCategory(SaveCategoryRequest $request)
    {
        $data = $request->validated();
        $data['display_order'] ??= (int) Category::max('display_order') + 1;
        $category = Category::create($data);
        $this->audit->record($request->user(), 'category.created', $category, null, $data);

        return $this->ok($category, [], 201);
    }

    public function updateCategory(SaveCategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return $this->ok($category);
    }

    public function reorderCategories(Request $request)
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                Category::whereKey($id)->update(['display_order' => $position]);
            }
        });

        return $this->ok();
    }

    public function destroyCategory(Request $request, Category $category)
    {
        $category->update(['is_active' => false]);
        $category->delete();
        $this->audit->record($request->user(), 'category.deleted', $category);

        return $this->ok();
    }

    public function products(Request $request)
    {
        $q = Product::query()->with('category', 'variants', 'inventoryRows')->orderBy('display_order')->orderBy('name');
        if (! $request->boolean('include_inactive')) {
            $q->where('is_active', true);
        }
        if ($category = $request->query('category_id')) {
            $q->where('category_id', $category);
        }
        if ($search = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('code', $search));
        }

        return $this->ok(ProductResource::collection($q->get())->resolve());
    }

    /** Lightweight stock figures polled by every terminal to stay in step. */
    public function availability()
    {
        $rows = DB::table('inventory')->selectRaw('stock_key, physical_qty, reserved_qty, (physical_qty - reserved_qty) as available_qty')->get();

        return $this->ok($rows, ['server_time' => now()->toIso8601String()]);
    }

    public function quickLists()
    {
        $since = now()->subDays(30);
        $frequent = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'billed')->where('order_items.created_at', '>=', $since)
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as sold')->groupBy('order_items.product_id')
            ->orderByDesc('sold')->limit(8)->pluck('product_id');
        $recent = OrderItem::query()->selectRaw('product_id, MAX(id) as last_id')->groupBy('product_id')
            ->orderByDesc('last_id')->limit(8)->pluck('product_id');

        return $this->ok([
            'favourites' => Product::where('is_favourite', true)->where('is_active', true)->pluck('id'),
            'frequent' => $frequent,
            'recent' => $recent,
        ]);
    }

    public function storeProduct(SaveProductRequest $request)
    {
        $product = DB::transaction(function () use ($request) {
            $data = collect($request->validated())->except(['variants', 'opening_stock'])->all();
            $product = Product::create($data + ['display_order' => (int) Product::max('display_order') + 1]);
            $this->syncVariants($product, $request->validated('variants') ?? []);
            if ($product->track_inventory) {
                $row = $this->inventory->ensureRow($product);
                if ($request->filled('opening_stock')) {
                    $this->inventory->opening($row->id, number_format((float) $request->opening_stock, 3, '.', ''), $request->user());
                }
            }
            $this->audit->record($request->user(), 'product.created', $product, null, $data);

            return $product;
        });

        return $this->ok($this->resource($product), [], 201);
    }

    public function updateProduct(SaveProductRequest $request, Product $product)
    {
        DB::transaction(function () use ($request, $product) {
            $before = $product->only(['name', 'price', 'cost', 'gst_rate', 'is_active']);
            $product->update(collect($request->validated())->except(['variants', 'opening_stock'])->all());
            if ($request->has('variants')) {
                $this->syncVariants($product, $request->validated('variants') ?? []);
            }
            if ($product->track_inventory) {
                $this->inventory->ensureRow($product);
            }
            $this->audit->record($request->user(), 'product.updated', $product, $before, $product->only(array_keys($before)));
        });

        return $this->ok($this->resource($product));
    }

    /** Quick price update from the Manage Items screen. */
    public function bulkPrices(Request $request)
    {
        $rows = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.id' => ['required', 'exists:products,id'],
            'prices.*.price' => ['required', 'numeric', 'min:0'],
        ])['prices'];
        DB::transaction(function () use ($rows, $request) {
            foreach ($rows as $row) {
                $product = Product::find($row['id']);
                $old = $product->price;
                $product->update(['price' => $row['price']]);
                $this->audit->record($request->user(), 'product.price_changed', $product, ['price' => $old], ['price' => $product->price]);
            }
        });

        return $this->ok(['updated' => count($rows)]);
    }

    public function uploadImage(Request $request, Product $product)
    {
        $request->validate(['image' => ['required', 'image', 'max:4096']]);
        $product->update(['image_path' => $request->file('image')->store('products', 'public')]);

        return $this->ok($this->resource($product));
    }

    /** The heart on the order screen. Anyone at the counter may use it, and every device sees the same favourites. */
    public function favouriteProduct(Request $request, Product $product)
    {
        $value = $request->validate(['is_favourite' => ['required', 'boolean']])['is_favourite'];
        $product->update(['is_favourite' => $value]);
        $this->audit->activity($request->user(), $value ? 'product.favourited' : 'product.unfavourited', $product->name);

        return $this->ok($this->resource($product));
    }

    /** Items on past bills keep their snapshot, so a product is hidden rather than erased. */
    public function destroyProduct(Request $request, Product $product)
    {
        // SKU and code are free for a new item; past bills keep their own copy.
        $product->update(['is_active' => false, 'sku' => null, 'code' => null]);
        $product->delete();
        $this->audit->record($request->user(), 'product.deleted', $product);

        return $this->ok();
    }

    private function syncVariants(Product $product, array $variants): void
    {
        $keep = [];
        foreach ($variants as $v) {
            $variant = isset($v['id'])
                ? tap(ProductVariant::where('product_id', $product->id)->findOrFail($v['id']))->update(collect($v)->except('id')->all())
                : $product->variants()->create(collect($v)->except('id')->all());
            $keep[] = $variant->id;
            if ($product->track_inventory) {
                $this->inventory->ensureRow($product, $variant);
            }
        }
        foreach ($product->variants()->whereNotIn('id', $keep)->get() as $removed) {
            $removed->update(['sku' => null]);
            $removed->delete();
        }
    }

    private function resource(Product $product): array
    {
        return (new ProductResource($product->fresh()->load('category', 'variants', 'inventoryRows')))->resolve();
    }
}
