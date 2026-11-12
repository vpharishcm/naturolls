<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// Product photos, served without the storage symlink that many shared hosts do not allow.
Route::get('storage/{path}', function (string $path) {
    abort_if(str_contains($path, '..'), 404);
    abort_unless(Storage::disk('public')->exists($path), 404);

    return response()->file(Storage::disk('public')->path($path), ['Cache-Control' => 'public, max-age=86400']);
})->where('path', '.*');
