<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/', function () {
    return response()->json(['msg' => 'API is running.']);
});

// ─────────────────────────────────────────────
// Public Routes (No Auth Required)
// ─────────────────────────────────────────────
Route::post('/register', [UserController::class, 'register']);
Route::post('/login',    [UserController::class, 'login']);

Route::post('/addCategory', [CategoryController::class, 'store']);
Route::post('/addProduct',  [ProductController::class, 'store']);

Route::apiResource('categories', CategoryController::class);
Route::apiResource('products',   ProductController::class);
Route::apiResource('reviews',    ReviewController::class)->only(['index', 'show']);

// ─────────────────────────────────────────────
// Authenticated Routes (Any logged-in user)
// ─────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Profile
    Route::get('/profile', [UserController::class, 'profile']);

    // Cart
    Route::apiResource('carts', CartController::class);

    // Orders
    Route::apiResource('orders', OrderController::class);

    // Order Items
    Route::apiResource('order-items', OrderItemController::class);

    // Payments
    Route::apiResource('payments', PaymentController::class);

    // Reviews (write)
    Route::apiResource('reviews', ReviewController::class)->except(['index', 'show']);
});

// ─────────────────────────────────────────────
// Admin Routes (auth + admin middleware)
// ─────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'admin'])->group(function () {

    // User Management
    Route::get('/users',      [UserController::class, 'getUser']);
    Route::get('/users/{id}', [UserController::class, 'getUserById']);
});
