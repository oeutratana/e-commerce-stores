<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check (តេស្តមើលថា API ដើរឬអត់)
Route::get('/', function () {
    return response()->json(['msg' => 'API is running.']);
});

// ─────────────────────────────────────────────
// ១. ក្រុម Public Routes (មិនត្រូវការ Login ទេ)
// // ─────────────────────────────────────────────
Route::post('/register', [UserController::class, 'register']);
Route::post('/login', [UserController::class, 'login']);

// ----- Categories (ប្រភេទផលិតផល) -----
Route::get('/categories', [CategoryController::class, 'index']); // មើលទាំងអស់
Route::get('/categories/{id}', [CategoryController::class, 'show']);  // មើលមួយជាក់លាក់

// ----- Products (ផលិតផល) -----
Route::get('/products', [ProductController::class, 'index']); // មើលទាំងអស់
Route::get('/products/{id}', [ProductController::class, 'show']);  // មើលមួយជាក់លាក់

// ----- Product Variants / Sizes (ទំហំ & ថ្លៃតាមទំហំ) -----
Route::get('/product-variants', [ProductVariantController::class, 'index']); // មើលទាំងអស់
Route::get('/product-variants/{id}', [ProductVariantController::class, 'show']);  // មើលមួយជាក់លាក់

// ----- Reviews (ការវាយតម្លៃ) -----
Route::get('/reviews', [ReviewController::class, 'index']); // មើលទាំងអស់
Route::get('/reviews/{id}', [ReviewController::class, 'show']);  // មើលមួយជាក់លាក់

// ─────────────────────────────────────────────
// ២. ក្រុម Authenticated Routes (ត្រូវការពិន្ទុ/Token ពីការ Login)
// ─────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ----- Profile -----
    Route::get('/profile', [UserController::class, 'profile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);  // EDIT (កែប្រែទិន្នន័យ)

    // ----- Carts (កន្ត្រកទំនិញ) -----
    Route::get('/carts', [CartController::class, 'index']);   // មើលកន្ត្រក
    Route::post('/carts', [CartController::class, 'store']);   // បន្ថែមចូលកន្ត្រក
    Route::get('/carts/{id}', [CartController::class, 'show']);    // SHOW
    Route::put('/carts/{id}', [CartController::class, 'update']);  // EDIT (កែប្រែចំនួន)
    Route::delete('/carts/{id}', [CartController::class, 'destroy']); // DELETE (លុបចេញ)

    // ----- Reviews (សរសេរការវាយតម្លៃ) -----
    Route::post('/reviews', [ReviewController::class, 'store']);   // បង្កើតថ្មី
    Route::put('/reviews/{id}', [ReviewController::class, 'update']);  // EDIT (កែប្រែសម្រួល)
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']); // DELETE (លុបចោល)

    // ----- Orders (ការកុម្ម៉ង់ទិញ) -----
    Route::get('/orders', [OrderController::class, 'index']);   // មើលការកុម្ម៉ង់ទាំងអស់
    Route::post('/orders', [OrderController::class, 'store']);   // កុម្ម៉ង់ទិញ (បង្កើត)
    Route::get('/orders/{id}', [OrderController::class, 'show']);    // មើលមួយជាក់លាក់
    Route::put('/orders/{id}', [OrderController::class, 'update']);  // EDIT (កែប្រែស្ថានភាព)
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']); // DELETE (លុបចោល)

    // ----- Order Items (លម្អិតទំនិញក្នុង Order) -----
    Route::get('/order-items', [OrderItemController::class, 'index']);
    Route::post('/order-items', [OrderItemController::class, 'store']);
    Route::get('/order-items/{id}', [OrderItemController::class, 'show']);
    Route::put('/order-items/{id}', [OrderItemController::class, 'update']);
    Route::delete('/order-items/{id}', [OrderItemController::class, 'destroy']);

    // ----- Payments (ការបង់ប្រាក់) -----
    Route::get('/payments', [PaymentController::class, 'index']);   // មើលបញ្ជីបង់ប្រាក់
    Route::post('/payments', [PaymentController::class, 'store']);   // បង្កើតការបង់ប្រាក់
    Route::post('/payments/khqr', [PaymentController::class, 'createKhqr']); // បង្កើត KHQR Payment
    Route::post('/payments/payway', [PaymentController::class, 'createPayway']); // បង្កើត PayWay Payment
    Route::get('/payments/{payment}/status', [PaymentController::class, 'status']); // ពិនិត្យ status នៃការបង់ប្រាក់
    Route::get('/payments/{payment}/payway/status', [PaymentController::class, 'paywayStatus']); // ពិនិត្យ status PayWay
    Route::get('/payments/{id}', [PaymentController::class, 'show']);    // មើលមួយជាក់លាក់
    Route::put('/payments/{id}', [PaymentController::class, 'update']);  // EDIT (កែប្រែការបង់ប្រាក់)
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy']); // DELETE (លុបប្រវត្តិ)
});

// ----- PayWay Callback (server-to-server, no Sanctum token) -----
Route::post('/payments/payway/callback', [PaymentController::class, 'paywayCallback']);

// ─────────────────────────────────────────────
// ៣. ក្រុម Admin Routes (សម្រាប់តែ Admin - បង្កើត, កែប្រែ, លុប Categories & Products)
// ─────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'admin'])->group(function () {

    // ----- Admin គ្រប់គ្រង Categories -----
    Route::post('/addCategory', [CategoryController::class, 'store']);   // បង្កើតថ្មី
    Route::put('/categories/{id}', [CategoryController::class, 'update']);  // EDIT (កែប្រែ)
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']); // DELETE (លុប)

    // ----- Admin គ្រប់គ្រង Products -----
    Route::post('/addProduct', [ProductController::class, 'store']);   // បង្កើតថ្មី
    Route::put('/products/{id}', [ProductController::class, 'update']);  // EDIT (កែប្រែ)
    Route::delete('/products/{id}', [ProductController::class, 'destroy']); // DELETE (លុប)

    // ----- Admin គ្រប់គ្រង Product Variants / Sizes -----
    Route::post('/product-variants', [ProductVariantController::class, 'store']);   // បង្កើតថ្មី
    Route::put('/product-variants/{id}', [ProductVariantController::class, 'update']);  // EDIT (កែប្រែ)
    Route::delete('/product-variants/{id}', [ProductVariantController::class, 'destroy']); // DELETE (លុប)

    // ----- User Management -----
    Route::get('/users', [UserController::class, 'getUser']);
    Route::get('/users/{id}', [UserController::class, 'getUserById']);
});
