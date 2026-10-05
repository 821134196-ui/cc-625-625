<?php

use App\Http\Controllers\FlightController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// 本地模拟航班动态接口
Route::get('/flights', [FlightController::class, 'index']);
Route::get('/flights/{flight}/loading', [FlightController::class, 'loading']);
Route::post('/flights/{flight}/shortage-ack', [FlightController::class, 'ackShortage']);

// 特殊餐申请与确认（同一份记录贯穿全程）
Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);
Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
Route::post('/orders/{order}/rebook', [OrderController::class, 'rebook']);
Route::post('/orders/{order}/substitute', [OrderController::class, 'substitute']);
Route::get('/meal-types', [OrderController::class, 'mealTypes']);

// 配餐任务与贴标
Route::get('/tasks', [TaskController::class, 'index']);
Route::post('/tasks/{task}/produce', [TaskController::class, 'produce']);

// 本地模拟扫码枪
Route::post('/scan', [ScanController::class, 'store']);
