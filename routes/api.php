<?php

use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::post('imports', [ImportController::class, 'store']);
Route::get('imports/{import}', [ImportController::class, 'show']);

Route::get('properties', [PropertyController::class, 'index']);

Route::post('offers/{offer}/reservations', [ReservationController::class, 'store']);

Route::get('ping', fn () => ['data' => ['status' => 'ok']]);
