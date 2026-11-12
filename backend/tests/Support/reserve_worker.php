<?php

// Test helper: runs as its own process so reservations race on real database locks.
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $orderId, $productId, $startAt] = $argv;
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    app(OrderService::class)->addItem(Order::find($orderId), Product::with('category')->find($productId), null, '1.000', User::first());
    echo 'ok';
} catch (BusinessException $e) {
    echo $e->errorCode;
}
