# Agent Guide

## Project Snapshot

This repository is a Laravel 10 backend for an e-commerce store API.

- PHP requirement: `^8.1`
- Framework: Laravel `^10.10`
- Auth package: Laravel Sanctum
- Main API routes: `routes/api.php`
- Main domain models: `User`, `Category`, `Product`, `Cart`, `Order`, `OrderItem`, `Payment`, `Review`
- Custom helper autoload: `app/helpers/helpers.php`

## Common Commands

Run these from the repository root.

```bash
composer install
php artisan key:generate
php artisan migrate
php artisan serve
php artisan test
./vendor/bin/pint
```

On Windows PowerShell, Pint can also be run as:

```powershell
vendor\bin\pint
```

## Repository Layout

- `app/Http/Controllers`: API controllers for each resource.
- `app/Http/Middleware/Admin.php`: admin authorization middleware.
- `app/Models`: Eloquent models.
- `database/migrations`: schema definitions.
- `database/seeders`: database seeders.
- `routes/api.php`: public, authenticated, and admin API routes.
- `tests/Feature` and `tests/Unit`: PHPUnit tests.

## API Route Notes

`routes/api.php` is organized into three groups:

- Public routes: health check, register, login, list/show categories, products, and reviews.
- Authenticated routes: profile, carts, reviews mutation, orders, order items, payments.
- Admin routes: category/product mutation and user management.

Authenticated routes use:

```php
Route::middleware('auth:sanctum')->group(function () {
    // ...
});
```

Admin routes use:

```php
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // ...
});
```

When adding endpoints, keep the route location aligned with the required access level.

## Development Guidelines

- Follow existing Laravel controller/model patterns before introducing new abstractions.
- Prefer Laravel validation, request helpers, Eloquent relationships, resources, and middleware over manual plumbing.
- Keep API responses consistent with nearby controller methods.
- Do not commit `.env`, generated cache files, or local-only artifacts.
- Preserve user data and existing worktree changes. Avoid destructive git commands unless explicitly requested.
- If changing schema, add or update migrations rather than editing an already-applied migration unless the project owner explicitly wants that.
- If adding or changing behavior, add focused tests when practical.

## Data And Auth Safety

- Treat user, payment, order, and token data as sensitive.
- Do not log bearer tokens, passwords, payment details, or full request payloads containing private data.
- Keep protected user actions behind `auth:sanctum`.
- Keep admin-only product/category/user management behind both `auth:sanctum` and `admin`.
- Validate IDs and ownership before returning or mutating user-owned resources such as carts, orders, reviews, and payments.

## Testing Checklist

Before handing work back, prefer to run:

```bash
php artisan test
```

For formatting-only or small PHP style changes, run:

```bash
./vendor/bin/pint
```

If tests cannot be run because of missing services, database setup, or environment values, mention that clearly in the final response.

## Notes For Future Agents

- The current `README.md` is the default Laravel README, so use this guide and the source code as the project-specific reference.
- Some comments in `routes/api.php` appear to have mojibake/encoding issues. Be careful when editing that file and avoid rewriting unrelated comments unless the task is to clean them up.
- Keep new documentation in plain Markdown and use ASCII unless there is a clear reason to preserve localized text.

## Bakong KHQR Payment Integration

### 1. Setup & Environment Variables
Add the following configuration keys to your `.env` file (see `.env.example`):

```env
BAKONG_API_URL=https://api-bakong.nbc.gov.kh
BAKONG_API_TOKEN=your_bakong_jwt_or_api_token
BAKONG_MERCHANT_ID=your_merchant_account@devb
BAKONG_MERCHANT_NAME="E-Commerce Store"
BAKONG_MERCHANT_CITY="Phnom Penh"
BAKONG_CURRENCY=USD
```

*Note: Never commit real production tokens or credentials to version control.*

### 2. Database Migrations
Run:
```bash
php artisan migrate
```
This applies `2026_09_06_000001_add_khqr_fields_to_payments_table.php`, adding `amount`, `currency`, `transaction_hash`, `qr_data`, `md5`, and `paid_at` columns to the `payments` table.

### 3. Creating a KHQR Payment
**Endpoint:** `POST /api/payments/khqr`  
**Headers:** `Authorization: Bearer <sanctum_token>`, `Content-Type: application/json`  
**Request Payload:**
```json
{
  "order_id": 25,
  "currency": "USD"
}
```
*Rules & Validations:*
- Requires authenticated user (`auth:sanctum`).
- Order must belong to the authenticated user.
- Order must not already be `PAID` or `COMPLETED`.
- Order must not be `CANCELLED`.
- Order `total_price` must be greater than 0.
- Order must not already have a successful payment.
- Backend retrieves amount directly from `$order->total_price` in database (never trusts frontend amount).
- Generates standard EMVCo KHQR string and computes MD5 hash.
- Creates a `payments` record with `payment_status = 'PENDING'`.

**Response (201 Created):**
```json
{
  "success": true,
  "message": "KHQR created successfully",
  "data": {
    "payment_id": 15,
    "order_id": 25,
    "amount": 25.0,
    "currency": "USD",
    "status": "PENDING",
    "qr": "00020101021229210017merchant@devb520459995303840540525.005802KH5916E-Commerce Store6010Phnom Penh62120108ORDER-256304E8A2",
    "md5": "9b12a818c1...",
    "created_at": "2026-09-06T08:38:00.000000Z"
  }
}
```

### 4. Checking Payment Status
**Endpoint:** `GET /api/payments/{payment_id}/status`  
**Headers:** `Authorization: Bearer <sanctum_token>`  

*Idempotency & Verification Workflow:*
1. Checks authenticated user owns the payment/order (returns `403` if unauthorized).
2. If payment is already `SUCCESS`, immediately returns success without making redundant external calls to Bakong.
3. If payment is `PENDING`:
   - Calls Bakong Open API endpoint (`POST /v1/check_transaction_by_md5`) with stored MD5 hash.
   - If Bakong confirms payment:
     - Updates payment status to `SUCCESS`.
     - Records `transaction_hash`, `transaction_id`, and `paid_at`.
     - Updates order status to `PAID`.
   - If Bakong returns pending / not found: stays `PENDING`.
   - If Bakong returns failed: updates status to `FAILED`.
   - If Bakong API is unreachable / timed out: keeps status `PENDING` and gracefully handles the error without prematurely failing the transaction.

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Payment status retrieved successfully",
  "data": {
    "payment_id": 15,
    "order_id": 25,
    "status": "SUCCESS",
    "amount": 25.0,
    "currency": "USD",
    "transaction_hash": "c83b8b3b7...",
    "paid_at": "2026-09-06T08:39:10.000000Z",
    "order_status": "PAID"
  }
}
```

### 5. Running Feature Tests
Execute payment tests:
```bash
php artisan test --filter=BakongPaymentTest
```
All Bakong external API requests are mocked with `Http::fake()` to ensure repeatable and network-independent test runs.

## ABA PayWay Payment Integration

The PayWay integration lives in `app/Services/PayWayService.php` and the
controller methods in `app/Http/Controllers/PaymentController.php`. It reuses
the existing `payments` and `orders` tables, so **no migration is required**.

`payment_method` values used across the project: `PAYWAY`, `KHQR` (COD can be
added later). Payment status values: `PENDING`, `SUCCESS`, `FAILED`.

### 1. Setup & Environment Variables

Configure your `.env` (see `.env.example`):

```env
PAYWAY_MERCHANT_ID=your_merchant_id
PAYWAY_API_KEY=your_api_key
PAYWAY_PURCHASE_URL=https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/purchase
PAYWAY_CHECK_URL=https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/check-transaction-2
PAYWAY_RETURN_URL=http://localhost:5173/payment/payway/return
PAYWAY_CALLBACK_URL=http://localhost:8000/api/payments/payway/callback
```

*Never commit real sandbox or production credentials to version control.*
Credentials are read through `config('services.payway.*')`, never `env()`
inside controllers.

ABA PayWay registers the callback target as the base64-encoded `return_url`
sent in the Purchase call. For primary server-to-server pushback, point
`PAYWAY_RETURN_URL` at your backend callback endpoint
(`http://your-domain.com/api/payments/payway/callback`). The callback endpoint
is also safe to call directly with a JSON body.

### 2. Creating a PayWay Payment

**Endpoint:** `POST /api/payments/payway`
**Headers:** `Authorization: Bearer <sanctum_token>`, `Content-Type: application/json`
**Request Payload:**
```json
{
  "order_id": 25
}
```

*Rules & Validation:*
- Requires authenticated user (`auth:sanctum`).
- Order must belong to the authenticated user.
- Order must not already be `PAID` or `COMPLETED`.
- Order must not be `CANCELLED`.
- Order `total_price` must be greater than 0.
- Order must not already have a successful payment.
- Backend reads the amount from `$order->total_price` in the database and
  **never trusts the frontend**.
- Generates a unique PayWay `tran_id`, creates a `payments` record with
  `payment_status = 'PENDING'`, then sends the purchase request to PayWay
  (HMAC-SHA512 signed). `return_url` is base64-encoded per the official spec.

**Response (201 Created):**
```json
{
  "success": true,
  "message": "PayWay transaction created successfully",
  "data": {
    "payment_id": 30,
    "order_id": 25,
    "amount": 25.0,
    "currency": "USD",
    "status": "PENDING",
    "transaction_id": "TXN-25-260914101500",
    "checkout_url": null,
    "checkout_html": "<!DOCTYPE html>... PayWay checkout page ...</html>",
    "payway_data": null,
    "created_at": "2026-09-14T08:38:00.000000Z"
  }
}
```

### 3. Checking Payment Status

**Endpoint:** `GET /api/payments/{payment_id}/payway/status`
**Headers:** `Authorization: Bearer <sanctum_token>`

*Idempotent Verification Workflow:*
1. Verifies the authenticated user owns the payment's order (returns `403`
   otherwise).
2. If payment is already `SUCCESS`, returns immediately without external
   PayWay calls.
3. If payment is `PENDING`, calls the PayWay Check Transaction API with the
   stored `tran_id`.
   - PayWay confirms APPROVED: payment becomes `SUCCESS`, order becomes `PAID`,
     `paid_at` is set.
   - PayWay reports DECLINED/CANCELLED: payment becomes `FAILED`.
   - PayWay unreachable / timeout / still PENDING: payment stays `PENDING`.
4. Returns the current status.

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Payment status retrieved successfully",
  "data": {
    "payment_id": 30,
    "order_id": 25,
    "status": "SUCCESS",
    "amount": 25.0,
    "currency": "USD",
    "transaction_id": "TXN-25-260914101500",
    "paid_at": "2026-09-14T08:39:10.000000Z",
    "order_status": "PAID"
  }
}
```

### 4. PayWay Callback / Webhook

**Endpoint:** `POST /api/payments/payway/callback`

This is a server-to-server endpoint and does **not** require a Sanctum token.

ABA PayWay posts a JSON pushback to the `return_url` after payment:
```json
{
  "tran_id": "TXN-25-260914101500",
  "apv": "619195",
  "status": "0"
}
```

The callback is **never blindly trusted**. It:
1. Validates that `tran_id` is present.
2. Finds the `PAYWAY` payment by `transaction_id`.
3. Is idempotent: if already `SUCCESS`, returns without changes.
4. Re-verifies the transaction with PayWay's Check Transaction API before
   marking anything.
5. On confirmation, updates payment and order atomically inside
   `DB::transaction()`.
6. On confirmed failure, marks the payment `FAILED`.
7. On timeout / unavailability, leaves the payment `PENDING`.

### 5. Running Feature Tests

Execute the PayWay tests:
```bash
php artisan test --filter=PayWay
```

All PayWay external calls are mocked with `Http::fake()` so the suite is fully
offline. Full suite: `php artisan test`.

