<?php

use App\Http\Controllers\CateringController;
use App\Http\Controllers\CrewController;
use App\Http\Controllers\MockFlightController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// 本地模拟航班接口
Route::get('/mock/flights', [MockFlightController::class, 'index']);
Route::get('/mock/flights/{flight}', [MockFlightController::class, 'show']);

Route::get('/meta/meal-types', fn () => config('mealtypes'));

// 客服台
Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);
Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
Route::post('/orders/{order}/rebook', [OrderController::class, 'rebook']);
Route::post('/orders/{order}/reseat', [OrderController::class, 'reseat']);

// 配餐站
Route::get('/catering/tasks', [CateringController::class, 'tasks']);
Route::post('/catering/tasks/{task}/start', [CateringController::class, 'start']);
Route::post('/catering/tasks/{task}/label', [CateringController::class, 'printLabel']);
Route::post('/catering/items/{item}/dispatch', [CateringController::class, 'dispatch']);

// 机组装机（本地模拟扫码接口）
Route::get('/flights/{flight}/loading', [CrewController::class, 'summary']);
Route::post('/flights/{flight}/scan', [CrewController::class, 'scan']);
Route::post('/flights/{flight}/close', [CrewController::class, 'close']);
Route::post('/shortages/{shortage}/resolve', [CrewController::class, 'resolveShortage']);
