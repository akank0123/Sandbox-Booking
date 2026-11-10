<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\SandboxController;
use Illuminate\Support\Facades\Route;

Route::get('/sandboxes', [SandboxController::class, 'index']);
Route::get('/bookings', [BookingController::class, 'index']);
Route::post('/bookings', [BookingController::class, 'store']);
Route::delete('/bookings/{id}', [BookingController::class, 'destroy'])->whereNumber('id');
