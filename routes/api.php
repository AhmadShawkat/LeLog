<?php

use App\Http\Controllers\LogController;
use Illuminate\Support\Facades\Route;

Route::get('/api/foundation', fn (): array => [
    'service' => 'lelog',
    'status' => 'ready-for-development',
]);

Route::post('/logs', [LogController::class, 'store']);
Route::get('/logs/aggregate', [LogController::class, 'aggregate']);
Route::get('/logs', [LogController::class, 'index']);
