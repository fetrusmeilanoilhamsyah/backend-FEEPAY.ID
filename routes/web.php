<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ✅ ADD: Route login untuk fix error "Route [login] not defined"
Route::get('/login', function () {
    return response()->json([
        'success' => false,
        'message' => 'Unauthenticated. Please login via /api/admin/login'
    ], 401);
})->name('login');