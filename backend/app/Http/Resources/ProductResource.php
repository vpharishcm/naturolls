<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rows = $this->whenLoaded('inventoryRows');
        $base = $rows instanceof \Illuminate\Support\Collection ? $rows->firstWhere('product_variant_id', null) : null;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'name' => $this->name,
            'sku' => $this->sku,
            'code' => $this->code,
            'description' => $this->description,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'art' => $this->art,
            'art_color' => $this->art_color,
            'unit' => $this->unit,
            'price' => $this->price,
            'cost' => $this->cost,
            'gst_rate' => $this->gst_rate,
            'track_inventory' => $this->track_inventory,
            'inventory_method' => $this->inventory_method,
            'min_stock' => $this->min_stock,
            'is_active' => $this->is_active,
            'is_favourite' => $this->is_favourite,
            'display_order' => $this->display_order,
            'stock_key' => 'p'.$this->id,
            'available_qty' => $base?->available_qty,
            'physical_qty' => $base?->physical_qty,
            'reserved_qty' => $base?->reserved_qty,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(function ($v) use ($rows) {
                $row = $rows instanceof \Illuminate\Support\Collection ? $rows->firstWhere('product_variant_id', $v->id) : null;

                return [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'price' => $v->price,
                    'cost' => $v->cost,
                    'is_active' => $v->is_active,
                    'stock_key' => 'p'.$this->id.'-v'.$v->id,
                    'available_qty' => $row?->available_qty,
                ];
            })),
        ];
    }
}
