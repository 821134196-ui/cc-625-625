<?php

use Illuminate\Support\Facades\Route;

// React SPA 入口（构建产物在 public/spa）
Route::get('/', fn () => view('spa'));
