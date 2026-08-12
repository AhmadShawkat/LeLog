<?php

use Illuminate\Support\Facades\Route;

Route::get('/foundation', fn (): array => [
    'service' => 'lelog',
    'status' => 'ready-for-development',
]);
