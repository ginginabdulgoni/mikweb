<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RouterApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    Route::get('routers', [RouterApiController::class, 'index'])->name('api.routers.index');
    Route::get('routers/{router}', [RouterApiController::class, 'show'])->name('api.routers.show');
    Route::post('routers/{router}/test', [RouterApiController::class, 'test'])->name('api.routers.test');
});
