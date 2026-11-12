<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    /** Paginated list in the standard envelope; $resource wraps each row when given. */
    protected function page(LengthAwarePaginator $page, ?string $resource = null): JsonResponse
    {
        $items = $resource ? $resource::collection($page->getCollection())->resolve() : $page->items();

        return $this->ok($items, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    protected function perPage(): int
    {
        return min(100, max(1, (int) request('per_page', 25)));
    }
}
