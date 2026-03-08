# FEEPAY.ID — PPOB Backend Platform

![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?style=flat-square&logo=mysql&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-7.x-DC382D?style=flat-square&logo=redis&logoColor=white)
![Midtrans](https://img.shields.io/badge/Midtrans-Payment-003E6B?style=flat-square)
![Digiflazz](https://img.shields.io/badge/Digiflazz-PPOB-F7941D?style=flat-square)
![License](https://img.shields.io/badge/License-Proprietary-lightgrey?style=flat-square)

A production-grade REST API backend for a PPOB (Payment Point Online Bank) platform. Handles the complete transaction lifecycle — product catalog management, Midtrans payment processing, and automated fulfillment via Digiflazz — without manual intervention.

---

## Table of Contents

1. [Overview](#1-overview)
2. [System Architecture](#2-system-architecture)
3. [Tech Stack](#3-tech-stack)
4. [Core Features & Implementation Details](#4-core-features--implementation-details)
5. [API Reference](#5-api-reference)
6. [Database Schema](#6-database-schema)
7. [Security](#7-security)
8. [Performance](#8-performance)
9. [Installation](#9-installation)
10. [Production Deployment](#10-production-deployment)
11. [Configuration Reference](#11-configuration-reference)
12. [Monitoring & Observability](#12-monitoring--observability)
13. [Troubleshooting](#13-troubleshooting)
14. [Developer Notes](#14-developer-notes)
15. [Contact](#15-contact)

---

## 1. Overview

FEEPAY.ID is a backend API platform for digital product resellers and PPOB service operators. It connects to the Digiflazz wholesale API for product fulfillment and to Midtrans for payment processing, exposing a clean REST API to frontend clients and third-party integrations.

### Supported Product Categories

| Category | Examples | Provider |
|---|---|---|
| Prepaid Credit (Pulsa) | Telkomsel, Indosat, XL, Tri, Smartfren | Digiflazz |
| Mobile Data Packages | All major Indonesian operators | Digiflazz |
| PLN Electricity Tokens | Prepaid PLN nationwide | Digiflazz |
| Game Top-Up | Mobile Legends, Free Fire, PUBG, Genshin Impact | Digiflazz |
| Game Vouchers | Steam, Garena, Razer Gold, Google Play | Digiflazz |
| E-Money Top-Up | GoPay, OVO, Dana, ShopeePay | Digiflazz |

### Supported Use Cases

- Digital storefronts (web and mobile app)
- PPOB marketplace platforms
- Point-of-sale systems for physical retail
- Corporate reward and employee benefits platforms
- WhatsApp Business API automation

---

## 2. System Architecture

```
+-------------------------------------------------------------------+
|                         FEEPAY.ID PLATFORM                        |
|                                                                   |
|  +--------------+   REST API   +-----------------------------+   |
|  | Client Apps  | <----------> |     Laravel 12 API          |   |
|  +--------------+              |                             |   |
|                                |  +----------+ +----------+  |   |
|  +--------------+              |  |  Redis   | |  Queue   |  |   |
|  |  Midtrans    | --webhook--> |  |  Cache   | | Workers  |  |   |
|  |  Payment GW  |              |  +----+-----+ +----+-----+  |   |
|  +--------------+              +-------|-----------|---------+   |
|                                        |           |             |
|  +--------------+              +-------v-----------v---------+   |
|  |  Digiflazz   | <----------> |     Business Logic Layer    |   |
|  |  PPOB API    | --webhook--> |   - Atomic Transactions     |   |
|  +--------------+              |   - Idempotency Handling    |   |
|                                |   - Race Condition Guard    |   |
|  +--------------+              +-------------+---------------+   |
|  |  Telegram    | <--alerts----              |                   |
|  |  Bot         |                    +-------v-------+           |
|  +--------------+                    |     MySQL     |           |
|                                      |   (Primary)   |           |
|  +--------------+                    +---------------+           |
|  |  SMTP Email  | <--queue jobs--                                |
|  +--------------+                                                |
+-------------------------------------------------------------------+
```

### Request Lifecycle

A typical order transaction flows through the following stages:

1. Client authenticates via `/api/login` and receives a Sanctum Bearer token.
2. Client calls `POST /api/orders` with `product_id` and `customer_id`.
3. `OrderController` opens a DB transaction, acquires a `lockForUpdate` on the user row, validates sufficient balance, and performs an atomic balance deduction.
4. The order record is created with status `pending` and the DB transaction is committed.
5. A `ProcessOrderTransaction` job is dispatched to the Redis queue. The HTTP response returns immediately.
6. The queue worker picks up the job, calls the Digiflazz API, and updates the order status to `success` or `failed`.
7. If `failed`, the user's balance is atomically refunded.
8. Email notifications are dispatched as separate queue jobs (`SendOrderSuccessEmail` or `SendOrderFailedEmail`).
9. Digiflazz sends a webhook callback confirming the final transaction status. The callback handler validates the HMAC-SHA256 signature, acquires a `lockForUpdate`, checks idempotency, and updates the order if it has not already reached a terminal state.

---

## 3. Tech Stack

| Layer | Technology | Version | Role |
|---|---|---|---|
| Framework | Laravel | 12.x | API framework and application core |
| Language | PHP | 8.2+ | Runtime |
| Database | MySQL | 8.0+ | Primary data store |
| Cache | Redis | 7.x | Response caching, session storage |
| Queue | Redis Queue | — | Async job processing |
| Authentication | Laravel Sanctum | 4.x | API token management |
| Payment Gateway | Midtrans Snap | — | Payment processing and top-up |
| PPOB Fulfillment | Digiflazz | v1 | Product catalog and order fulfillment |
| Notifications | Telegram Bot API | — | Real-time admin alerts |
| Email | SMTP (Brevo / Gmail) | — | Transactional emails |
| API Documentation | Dedoc Scramble | 0.13+ | Auto-generated OpenAPI docs |
| Web Server | Nginx + PHP-FPM | — | HTTP server |
| Process Manager | Supervisor | — | Queue worker lifecycle management |

### Composer Dependencies

**Production:**

```json
"require": {
    "php": "^8.2",
    "dedoc/scramble": "^0.13",
    "laravel/framework": "^12.0",
    "laravel/sanctum": "^4.0",
    "laravel/tinker": "^2.10.1",
    "midtrans/midtrans-php": "^2.6"
}
```

**Development only:**

```json
"require-dev": {
    "fakerphp/faker": "^1.23",
    "laravel/pail": "^1.2.2",
    "laravel/pint": "^1.24",
    "laravel/sail": "^1.41",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.6",
    "phpunit/phpunit": "^11.5.3"
}
```

---

## 4. Core Features & Implementation Details

### 4.1 Atomic Balance Management

**Problem:** Concurrent requests for the same user can create a race condition where two threads both read the same balance, both see sufficient funds, and both deduct — resulting in a negative balance or double-spend.

**Solution:** A two-step approach. First, a `lockForUpdate` is acquired on the user row inside a DB transaction, preventing any other transaction from reading or writing that row until the current transaction commits. Second, the deduction is performed via a conditional atomic SQL update:

```php
DB::beginTransaction();

$user = User::where('id', auth()->id())
    ->lockForUpdate()
    ->first();

if ($user->balance < $totalPrice) {
    DB::rollBack();
    return response()->json(['message' => 'Insufficient balance'], 400);
}

$affected = DB::table('users')
    ->where('id', $user->id)
    ->where('balance', '>=', $totalPrice)  // Re-checks at DB level
    ->update([
        'balance'    => DB::raw('balance - ' . $totalPrice),
        'updated_at' => now()
    ]);

if ($affected === 0) {
    DB::rollBack();
    return response()->json(['message' => 'Balance deduction failed'], 500);
}
```

The `where('balance', '>=', $totalPrice)` guard on the `UPDATE` statement is the final defense. If another concurrent transaction somehow slipped through between the lock and the update, `$affected` will be `0` and the transaction rolls back.

Refunds on failure or cancellation use the same pattern in reverse:

```php
DB::table('users')
    ->where('id', $order->user_id)
    ->update([
        'balance'    => DB::raw('balance + ' . $order->total_price),
        'updated_at' => now()
    ]);
```

---

### 4.2 Async Order Processing with Retry Logic

**Problem:** Calling the Digiflazz API synchronously blocks the HTTP response for up to 60 seconds, which is unacceptable for a consumer-facing API.

**Solution:** After committing the order and balance deduction, the API call is dispatched as a queue job. The HTTP response returns in under 200ms.

```php
// Dispatched AFTER DB::commit() in OrderController::store()
ProcessOrderTransaction::dispatch($order);
```

The `ProcessOrderTransaction` job is configured with exponential backoff:

```php
class ProcessOrderTransaction implements ShouldQueue
{
    public $tries   = 3;
    public $timeout = 120;
    public $backoff = [60, 300, 900]; // Retry after 1 min, 5 min, 15 min
}
```

The job's `handle()` method follows this flow:

1. Re-fetch the order from the database to get its current state.
2. If the order is no longer `pending` or `processing`, skip processing. This guards against duplicate dispatches.
3. Update order status to `processing`.
4. Call `DigiflazzService::placeOrder()` with the product SKU, customer number, and transaction reference ID.
5. On `Sukses`: update status to `success`, store the serial number, dispatch `SendOrderSuccessEmail`.
6. On `Gagal`: update status to `failed`, perform atomic balance refund, dispatch `SendOrderFailedEmail`.
7. On ambiguous status: store the raw provider response and await the Digiflazz webhook callback for the definitive result.

If an exception is thrown and all retries are exhausted, the `handle()` method catches the final attempt, marks the order `failed`, refunds the balance, and dispatches the failure email. The `failed()` method logs a `critical` event for manual investigation.

---

### 4.3 Idempotent Webhook Processing

**Problem:** Both Midtrans and Digiflazz may send duplicate webhook callbacks due to their own retry mechanisms. Processing the same callback twice could result in double refunds or incorrect status overwrites.

**Solution:** Every webhook handler acquires a pessimistic lock on the order row before reading its status. If the order already has a terminal status (`success` or `failed`), the callback is acknowledged with `200 OK` and no further processing occurs.

```php
// In CallbackController::digiflazz()
$order = Order::where('trx_id', $data['ref_id'])
    ->lockForUpdate()
    ->first();

if (in_array($order->status, ['success', 'failed'])) {
    DB::rollBack();
    return response()->json(['message' => 'Already processed'], 200);
}
```

This pattern ensures that even if ten identical callbacks arrive simultaneously, only the first one to acquire the lock proceeds. The rest see the terminal status and return `200 OK` without side effects.

---

### 4.4 Webhook Signature Validation

All incoming webhook callbacks are validated before any database interaction occurs.

**Digiflazz** uses HMAC-SHA256 sent in the `X-Digiflazz-Signature` header:

```php
$payload           = $request->getContent();
$expectedSignature = hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expectedSignature, $signature)) {
    return response()->json(['message' => 'Invalid signature'], 403);
}
```

**Midtrans** uses SHA-512 computed from a concatenation of order fields and the server key, sent as `signature_key` in the request body. `MidtransService::verifySignature()` wraps this:

```php
$expectedSignature = hash('sha512',
    $orderId . $statusCode . $grossAmount . $serverKey
);

if (!hash_equals($expectedSignature, $receivedSignature)) {
    return false;
}
```

Both handlers use `hash_equals()` instead of `===` to prevent timing-based signature oracle attacks.

---

### 4.5 Product Catalog Sync

The `digiflazz:sync` Artisan command synchronizes the live Digiflazz price list into the local `products` table. It is scheduled to run four times daily (00:00, 06:00, 12:00, 18:00 WIB).

The sync uses a batch upsert strategy — products are processed in chunks of 100 and inserted or updated via `DB::table('products')->upsert()` keyed on `buyer_sku_code`. This is significantly more efficient than individual `updateOrCreate()` calls for large catalogs.

**Price protection** is enforced on every sync: if an existing product's `selling_price` is already above the incoming `cost_price`, the selling price is left unchanged. It is only recalculated if the new cost price has risen above it:

```php
if ($existing) {
    $sellingPrice = (float) $existing->selling_price;
    if ($costPrice >= $sellingPrice) {
        $sellingPrice = $costPrice + $defaultMargin; // cost rose above selling price
    }
    // Otherwise: preserve existing selling_price
} else {
    $sellingPrice = $costPrice + $defaultMargin; // new product
}
```

The default margin is controlled by the `FEEPAY_MARGIN` environment variable (default: Rp 2,000).

The command accepts an optional `--category` flag to sync only a specific category:

```bash
php artisan digiflazz:sync --category=Pulsa
```

Admins can also trigger an on-demand sync via the admin API endpoint (`POST /api/admin/products/sync`), which uses a batched upsert through `ProductController::sync()`.

---

### 4.6 Redis Caching Strategy

| Cache Key Pattern | TTL | Invalidated On |
|---|---|---|
| `products:list:{hash}` | 10 min | Admin sync or status update |
| `products:categories` | 30 min | Admin sync |
| `product:detail:{id}` | 10 min | Admin status update |
| `products:category:{name}` | 10 min | Admin sync |
| `dashboard:user:{id}` | 5 min | Order status change |
| `dashboard:recent:{id}:{limit}` | 3 min | Order status change |
| `dashboard:chart:{id}:{days}` | 10 min | Order status change |
| `dashboard:category:{id}:{days}` | 10 min | Order status change |
| `dashboard:admin:stats` | 5 min | Any order change |
| `dashboard:admin:chart:{days}` | 5 min | Any order change |

Product list queries use a hash of the validated request parameters as the cache key suffix (`md5(json_encode($validated))`), so different filter combinations are cached independently without key collisions.

The categories list uses a 30-minute TTL since category additions come only from Digiflazz syncs and are infrequent. Static product detail pages use 10 minutes to reflect admin status changes promptly.

---

### 4.7 Admin Security — Three Independent Layers

Admin routes (`/api/admin/*`) are protected by three independent middleware layers that must all pass:

**Layer 1 — Role check (`admin` middleware)**

Verifies `user.role === 'admin'` AND `user.is_active === true`. A deactivated admin account is rejected even with a valid token.

**Layer 2 — IP whitelist (`AdminIpWhitelist` middleware)**

The `ADMIN_IP_WHITELIST` environment variable accepts a comma-separated list of allowed IPs. Requests from any other source receive a `403` with the client IP logged. The middleware relies on `$request->ip()`, which correctly handles proxied IPs via the `TrustProxies` middleware — preventing bypass via `X-Forwarded-For` header spoofing.

If `ADMIN_IP_WHITELIST` is empty (development mode), a warning is logged but access is permitted to avoid blocking local development.

**Layer 3 — PIN verification (`VerifyPinMiddleware`)**

The `X-Admin-Pin` header must contain exactly 6 digits matching `FEEPAY_ADMIN_PIN`. Comparison uses `hash_equals()` to prevent timing attacks.

Failed attempts are rate-limited to 5 tries per 15 minutes per IP (lockout duration: 900 seconds via `RateLimiter::hit($key, 900)`). On successful authentication, the rate limiter counter is cleared.

---

### 4.8 Email Notification System

Transactional emails are sent as queue jobs to avoid blocking order processing.

**`SendOrderSuccessEmail`**
- 3 retry attempts (framework default backoff)
- Sends an `OrderSuccess` Mailable with full order details and serial number
- Re-throws exceptions to trigger retries
- `failed()` handler logs a `critical` event

**`SendOrderFailedEmail`**
- 2 retry attempts with backoff `[30, 180]` seconds
- Subject includes the `trx_id` and confirms the balance refund
- Loads related user and product via eager loading before sending

Both jobs are dispatched after the DB transaction commits — never inside the transaction — to ensure the email is only sent if the order was actually saved.

---

### 4.9 Telegram Alert System

`TelegramService::notify()` is a static method that posts Markdown-formatted messages to a configured Telegram chat. It is invoked for:

- Digiflazz order rejection (provider returns `Gagal`)
- System exceptions in `DigiflazzService::placeOrder()`
- Digiflazz deposit balance falling below Rp 100,000

The method uses a 10-second HTTP timeout and catches all exceptions internally so that a Telegram API failure never interrupts the main transaction flow. All failures are logged at `error` level.

```php
// Example alert for low balance
TelegramService::notify(
    "*WARNING: SALDO TIPIS!*\n" .
    "Sisa Saldo: Rp " . number_format($balance, 0, ',', '.') . "\n" .
    "Segera Top Up saldo Digiflazz!"
);
```

---

### 4.10 Dashboard Aggregation

The user dashboard computes all order statistics in a single SQL query using conditional aggregates, replacing the previous approach of 7 separate queries:

```php
$orderStats = DB::table('orders')
    ->select([
        DB::raw('COUNT(*) as total_orders'),
        DB::raw('COUNT(CASE WHEN status = "success"    THEN 1 END) as success_orders'),
        DB::raw('COUNT(CASE WHEN status = "pending"    THEN 1 END) as pending_orders'),
        DB::raw('COUNT(CASE WHEN status = "failed"     THEN 1 END) as failed_orders'),
        DB::raw('COALESCE(SUM(CASE WHEN status = "success" THEN total_price ELSE 0 END), 0) as total_spent'),
        DB::raw('COALESCE(SUM(CASE WHEN status = "pending" THEN total_price ELSE 0 END), 0) as pending_amount'),
    ])
    ->where('user_id', $userId)
    ->first();
```

The result is cached for 5 minutes. Cache is explicitly invalidated via `DashboardController::clearUserCache($userId)` on every order status change.

Chart data endpoints support a configurable time range via `?days=` (default: 7 for user charts, 30 for admin charts) and are cached independently from the stats endpoint.

---

## 5. API Reference

### Base URLs

```
Development:  http://localhost:8000/api
Production:   https://api.feepay.id/api
```

### Authentication

Protected endpoints require a Bearer token in the `Authorization` header. Admin endpoints additionally require an `X-Admin-Pin` header containing a 6-digit PIN.

```
Authorization: Bearer {token}
X-Admin-Pin:   {6-digit PIN}
```

Tokens expire after 1,440 minutes (24 hours). Only one token per user is active at a time — a new login revokes all previous tokens for that user.

---

### 5.1 Authentication Endpoints

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| `POST` | `/register` | Register new user account | Public |
| `POST` | `/login` | Obtain API token | Public |
| `POST` | `/logout` | Revoke current token | Bearer |
| `GET` | `/profile` | Get authenticated user | Bearer |
| `PUT` | `/profile` | Update profile | Bearer |
| `POST` | `/change-password` | Change password | Bearer |
| `POST` | `/forgot-password` | Initiate password reset | Public |

All auth endpoints are grouped under `throttle:login` (5 requests per 5 minutes).

**Login request:**
```json
POST /api/login
Content-Type: application/json

{
  "email": "admin@feepay.id",
  "password": "yourpassword"
}
```

**Login response:**
```json
HTTP/1.1 200 OK

{
  "success": true,
  "message": "Login berhasil.",
  "data": {
    "token": "1|abcdefghijk...",
    "expires_in": 1440,
    "user": {
      "id": 1,
      "name": "Admin",
      "email": "admin@feepay.id",
      "role": "admin"
    }
  }
}
```

**Validation rules:**

| Field | Rules |
|---|---|
| `email` | Required, valid email, max 255 chars |
| `password` | Required, string, min 8 chars, max 128 chars |

The login endpoint mitigates user enumeration by always performing `Hash::check()` even when the provided email is not found, ensuring uniform response time regardless of account existence.

**Token refresh:**
```
POST /api/admin/refresh
Authorization: Bearer {current_token}
```
Revokes the current token and issues a new one with a fresh 1,440-minute expiry.

---

### 5.2 Product Endpoints

All product endpoints require Bearer authentication and are grouped under `throttle:api`.

| Method | Endpoint | Description | Cache TTL |
|---|---|---|---|
| `GET` | `/products` | List products with filters | 10 min |
| `GET` | `/products/{id}` | Product detail by ID, code, or SKU | 10 min |
| `GET` | `/products/categories` | List distinct active categories | 30 min |
| `GET` | `/products/category/{category}` | All active products in a category | 10 min |

**Query parameters for `GET /products`:**

| Parameter | Type | Description |
|---|---|---|
| `category` | string | Filter by category name |
| `status` | string | `active` or `inactive` |
| `search` | string | Searches `name`, `code`, and `buyer_sku_code` |
| `per_page` | integer | Results per page. Min 10, max 100, default 20. |

**Product response fields:**

| Field | Description |
|---|---|
| `id` | Internal product ID |
| `sku` | Digiflazz `buyer_sku_code` |
| `name` | Product display name |
| `category` | Category name (e.g., `Pulsa`, `Data`, `Games`) |
| `brand` | Brand or operator (e.g., `Telkomsel`, `Mobile Legends`) |
| `type` | `standard` or game-specific variant |
| `selling_price` | End-user price (IDR) |
| `profit_margin` | Computed: `selling_price - cost_price` |
| `status` | `active` or `inactive` |
| `stock` | `unlimited` or numeric count |

The `show` endpoint matches the `{id}` parameter against `id`, `code`, and `buyer_sku_code` — whichever matches first — to support lookup by any identifier.

---

### 5.3 Order Endpoints

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| `POST` | `/orders` | Create new order | Bearer |
| `GET` | `/orders` | List authenticated user's orders | Bearer |
| `GET` | `/orders/{id}` | Order detail | Bearer |
| `POST` | `/orders/{id}/cancel` | Cancel pending order (triggers refund) | Bearer |
| `POST` | `/orders/{id}/retry` | Retry a failed order | Bearer |

Order write operations are grouped under `throttle:transactions` (30 requests per minute per user).

**Create order request:**
```json
POST /api/orders
Authorization: Bearer {token}
Content-Type: application/json

{
  "product_id": 42,
  "customer_id": "08123456789",
  "quantity": 1
}
```

**Validation rules:**

| Field | Rules |
|---|---|
| `product_id` | Required, must exist in `products` table |
| `customer_id` | Required, string, max 50 chars. Phone number, game user ID, or electricity meter number depending on product type. |
| `quantity` | Optional, integer, min 1, max 100, default 1 |

**Create order — success response:**
```json
HTTP/1.1 201 Created

{
  "message": "Order created successfully",
  "data": {
    "id": 101,
    "order_id": "ORD-1741234567-1-4823",
    "trx_id": "TRX-1741234567-1-4823",
    "status": "pending",
    "total_price": "52000.00",
    "customer_id": "08123456789",
    "product": {
      "id": 42,
      "code": "TSEL50",
      "name": "Pulsa Telkomsel 50.000",
      "category": "Pulsa"
    }
  }
}
```

**Create order — insufficient balance:**
```json
HTTP/1.1 400 Bad Request

{
  "message": "Insufficient balance",
  "required": 52000,
  "available": 30000,
  "shortage": 22000
}
```

**Order status values:**

| Status | Description |
|---|---|
| `pending` | Created, awaiting queue worker |
| `processing` | Submitted to Digiflazz API |
| `success` | Fulfilled. `sn` field contains the serial number. |
| `failed` | Rejected by provider or system error. Balance automatically refunded. |

**List orders — query parameters:**

| Parameter | Type | Description |
|---|---|---|
| `status` | string | Filter by status value |
| `date_from` | date | Start of date range (inclusive) |
| `date_to` | date | End of date range (inclusive, must be >= `date_from`) |
| `per_page` | integer | Min 10, max 100, default 20 |

**Cancel order:**

Only `pending` orders can be cancelled. A successful cancellation triggers an automatic atomic balance refund.

```json
POST /api/orders/101/cancel

HTTP/1.1 200 OK
{
  "message": "Order cancelled successfully",
  "refund_amount": 52000
}
```

**Retry order:**

Only `failed` orders can be retried. The status is reset to `pending` and `ProcessOrderTransaction` is redispatched to the queue.

---

### 5.4 Top-Up Endpoints

Top-up allows users to add balance to their account via Midtrans Snap payment. These endpoints are grouped under `throttle:topup`.

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| `POST` | `/topup` | Initiate top-up, returns Midtrans Snap token | Bearer |
| `GET` | `/topup/history` | Top-up transaction history | Bearer |
| `GET` | `/topup/{id}` | Top-up detail | Bearer |
| `POST` | `/topup/{id}/check-status` | Poll current Midtrans payment status | Bearer |

The `MidtransService::createSnapToken()` method validates that the requested amount is greater than zero, constructs the transaction payload with a 60-minute payment expiry, and returns the Snap token. The amount is always taken from server-side calculation — never from raw user input — to prevent price tampering.

---

### 5.5 Dashboard Endpoints

| Method | Endpoint | Description | Cache TTL |
|---|---|---|---|
| `GET` | `/dashboard` | Aggregated stats for the authenticated user | 5 min |
| `GET` | `/dashboard/recent-orders` | Last N orders (default 10, via `?limit=`) | 3 min |
| `GET` | `/dashboard/chart` | Daily transaction chart data | 10 min |
| `GET` | `/dashboard/spending` | Spending breakdown by product category | 10 min |

**Dashboard response:**
```json
{
  "data": {
    "total_orders": 142,
    "success_orders": 138,
    "pending_orders": 2,
    "failed_orders": 2,
    "total_spent": 7340000.00,
    "pending_amount": 104000.00,
    "current_balance": 250000.00
  }
}
```

**Chart and spending endpoints support `?days=` parameter:**

| Endpoint | Default Days | Description |
|---|---|---|
| `/dashboard/chart` | 7 | Number of past days to include |
| `/dashboard/spending` | 30 | Number of past days to include |

---

### 5.6 Webhook Endpoints

Webhook endpoints have no Bearer authentication requirement but validate cryptographic signatures on every request before any processing begins.

| Method | Endpoint | Provider | Validation Method |
|---|---|---|---|
| `POST` | `/webhooks/digiflazz` | Digiflazz | HMAC-SHA256 via `X-Digiflazz-Signature` header |
| `POST` | `/webhooks/midtrans` | Midtrans | SHA-512 via `signature_key` field in request body |

Both endpoints are grouped under `throttle:webhook` (100 requests per minute per IP).

**Digiflazz required payload fields:**

| Field | Description |
|---|---|
| `ref_id` | Reference ID matching `orders.trx_id` |
| `status` | `Sukses`, `Gagal`, or intermediate status string |
| `sn` | Serial number (present on `Sukses` only) |
| `message` | Provider rejection message (present on `Gagal`) |

**Midtrans transaction status mapping:**

| `transaction_status` | `fraud_status` | Outcome |
|---|---|---|
| `capture` | `accept` | Payment marked `paid` |
| `settlement` | — | Payment marked `paid` |
| `cancel`, `deny`, `expire` | — | Payment marked `failed` |
| `pending` | — | Payment remains `pending` |

---

### 5.7 Admin Endpoints

All admin endpoints require a valid Bearer token, an IP in `ADMIN_IP_WHITELIST`, and a correct 6-digit `X-Admin-Pin` header.

```
Authorization: Bearer {token}
X-Admin-Pin:   123456
```

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/admin/dashboard` | Platform-wide stats (users, orders, revenue) |
| `GET` | `/admin/dashboard/chart` | Revenue and transaction volume chart |
| `GET` | `/admin/orders` | All orders across all users |
| `POST` | `/admin/orders/{id}/confirm` | Manually confirm and queue an order |
| `POST` | `/admin/products/sync` | Trigger on-demand Digiflazz catalog sync |
| `PUT` | `/admin/products/{id}/status` | Toggle product `active`/`inactive` |
| `POST` | `/admin/products/bulk-status` | Bulk toggle product status |
| `GET` | `/admin/users` | List all users |
| `GET` | `/admin/users/{id}` | User detail |
| `PUT` | `/admin/users/{id}/balance` | Manually adjust user balance |
| `PUT` | `/admin/users/{id}/status` | Activate or deactivate a user account |

**Admin dashboard response:**
```json
{
  "data": {
    "total_users": 250,
    "active_users": 87,
    "total_products": 1842,
    "active_products": 1731,
    "total_orders": 9841,
    "pending_orders": 12,
    "success_orders": 9710,
    "failed_orders": 119,
    "total_revenue": 482950000.00,
    "today_revenue": 1250000.00,
    "total_balance": 8750000.00
  }
}
```

**Bulk status update request:**
```json
POST /api/admin/products/bulk-status
Content-Type: application/json

{
  "product_ids": [101, 102, 103],
  "status": "inactive"
}
```

---

### 5.8 Health Endpoint

```
GET /api/health
```

```json
{
  "status": "ok",
  "timestamp": "2026-03-08T14:30:00+07:00",
  "service": "PPOB API"
}
```

---

## 6. Database Schema

The schema is defined across 11 migration files. The following is derived directly from the migration source code.

### 6.1 users

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | Auto-increment |
| `name` | varchar(255) | |
| `email` | varchar(255) | Unique |
| `email_verified_at` | timestamp | Nullable |
| `password` | varchar(255) | Bcrypt hashed |
| `role` | enum(`admin`) | Default: `admin`. Single-role schema. |
| `is_active` | boolean | Default: `true` |
| `remember_token` | varchar(100) | Nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Indexes:** `email` (unique), `is_active`, `created_at` (performance)

**Note:** The migration defines `role` as an enum with only `admin`. Consumer-facing users are managed via a separate flow or a future iteration.

---

### 6.2 products

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `sku` | varchar | Unique. Digiflazz `buyer_sku_code`. |
| `name` | varchar(255) | |
| `category` | varchar | e.g., `Pulsa`, `Data`, `Games`, `PLN` |
| `brand` | varchar | Nullable. Operator or game title. |
| `cost_price` | decimal(15,2) | Wholesale price from Digiflazz |
| `selling_price` | decimal(15,2) | End-user price. Price-protected on sync. |
| `status` | varchar | `active` or `inactive` |
| `stock` | varchar | `unlimited` (default) or numeric count |
| `type` | varchar | `standard` (default) or game variant |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Computed attribute (not stored):** `profit_margin = selling_price - cost_price` (appended via Eloquent)

**Indexes:** `sku` (unique), `(category, brand, status)`, `(category, status)`, `buyer_sku_code`, `status`, `brand`

---

### 6.3 orders

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | varchar(50) | Unique. Display ID shown to user. |
| `sku` | varchar | Product SKU snapshot at time of order |
| `product_name` | varchar | Product name snapshot |
| `target_number` | varchar | Customer target: phone number, game user ID, or electricity meter |
| `zone_id` | varchar | Nullable. Game server/zone ID for game top-ups. |
| `customer_email` | varchar | Email for notifications |
| `total_price` | decimal(15,2) | Price locked at order creation |
| `status` | enum | `pending`, `processing`, `success`, `failed` |
| `sn` | varchar | Nullable. Serial number from Digiflazz on success. |
| `payment_id` | bigint | Nullable. FK to top-up payment record. |
| `confirmed_by` | bigint | Nullable. Admin user ID for manual confirmations. |
| `confirmed_at` | timestamp | Nullable |
| `midtrans_snap_token` | varchar | Nullable |
| `midtrans_transaction_id` | varchar | Nullable |
| `midtrans_payment_type` | varchar | Nullable. e.g., `bank_transfer`, `credit_card` |
| `midtrans_transaction_status` | varchar | Nullable. Raw Midtrans status string. |
| `midtrans_transaction_time` | timestamp | Nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Indexes:** `(order_id, status, customer_email)`, `(user_id, status, created_at)`, `trx_id`, `(provider_trx_id, status)`, `status`, `created_at`

---

### 6.4 order_status_histories

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | bigint FK | → `orders.id`. Cascade delete. |
| `status` | enum | `pending`, `processing`, `success`, `failed` |
| `note` | text | Nullable. Human-readable reason. |
| `changed_by` | bigint FK | Nullable. → `users.id`. Set null on user delete. |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Indexes:** `order_id`, `status`

---

### 6.5 support_messages

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_email` | varchar | Nullable |
| `user_name` | varchar | Nullable |
| `message` | text | |
| `platform` | enum | `whatsapp` or `telegram` |
| `status` | enum | `pending`, `sent`, `failed` |
| `order_id` | varchar | Nullable. Reference to related order. |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Indexes:** `user_email`, `status`, `created_at`

---

### 6.6 Infrastructure Tables

| Table | Purpose |
|---|---|
| `personal_access_tokens` | Laravel Sanctum tokens (expiry-indexed) |
| `jobs` | Redis-backed queue job payload store |
| `failed_jobs` | Permanently failed jobs with full exception trace |
| `sessions` | Session data (Redis-backed in production) |
| `cache` | Database fallback cache (Redis preferred) |
| `cache_locks` | Distributed lock storage for atomic operations |
| `password_reset_tokens` | Password reset flow |

---

## 7. Security

### 7.1 Rate Limiting

| Scope | Limit | Key |
|---|---|---|
| Login / Register / Forgot Password | 5 req / 5 min | Per email address |
| General API | 60 req / min | Per authenticated user |
| Order creation | 30 req / min | Per authenticated user |
| Top-up | Strict per-user limit | Per authenticated user |
| Webhook callbacks | 100 req / min | Per IP address |
| Product sync | Dedicated throttle | Per user |
| Admin PIN verification | 5 attempts / 15 min | Per IP (lockout 900s) |

All rate-limit violations return `HTTP 429` with a `Retry-After` header.

### 7.2 Security Headers

The `SecurityHeaders` middleware injects the following on every response:

```
X-Content-Type-Options:  nosniff
X-Frame-Options:         DENY
X-XSS-Protection:        1; mode=block
Referrer-Policy:         strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'
Permissions-Policy:      geolocation=(), microphone=(), camera=()
```

In production, `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` is added. The `X-Powered-By` and `Server` response headers are removed on every request to prevent version disclosure.

### 7.3 HTTPS Enforcement

The `ForceHttps` middleware redirects all non-HTTPS requests to their HTTPS equivalent with a `301` permanent redirect when `APP_ENV=production`. This operates at the application layer as a fallback; Nginx handles HTTPS termination at the infrastructure layer.

### 7.4 CSRF

CSRF verification is disabled for all `api/*` routes via `VerifyCsrfToken::$except`. API security is handled entirely by Sanctum Bearer token authentication. CSRF protection applies only to stateful web routes.

### 7.5 SQL Injection

All database interactions use Eloquent ORM or the Laravel query builder with parameterized bindings. The only raw SQL fragments (`DB::raw('balance - ' . $amount)`) use numeric values that have been validated upstream by Laravel's validation layer before reaching the query.

### 7.6 OWASP Top 10 Coverage

| Risk | Implementation |
|---|---|
| A01: Broken Access Control | Sanctum token auth, role checks, IP whitelist, PIN middleware |
| A02: Cryptographic Failures | HTTPS enforced, all secrets in `.env`, bcrypt passwords, `hash_equals()` throughout |
| A03: Injection | Eloquent ORM, query builder bindings, strict input validation |
| A04: Insecure Design | Webhook signature validation, server-side amount locking |
| A05: Security Misconfiguration | `APP_DEBUG=false` in production, security headers middleware, version headers stripped |
| A06: Vulnerable Components | Composer audit, `composer install --no-dev` in production |
| A07: Identification and Auth Failures | Bcrypt, rate limiting, single-session token enforcement, timing-safe comparisons |
| A08: Software and Data Integrity | DB transactions with rollback, idempotency checks on all webhooks |
| A09: Logging and Monitoring Failures | Structured logging for all critical events, Telegram alerts, Sentry DSN support |
| A10: SSRF | All external API calls confined to service classes with hardcoded endpoint patterns |

---

## 8. Performance

### 8.1 Query Optimization

Dashboard statistics are computed via a single aggregated `SELECT` using conditional `SUM(CASE WHEN ...)` expressions, replacing the previous approach of 7 separate queries and reducing dashboard load time significantly.

All list queries use explicit `select()` column lists to avoid loading unused data. Related models are loaded via eager loading (`with(['product:id,code,name,category'])`), eliminating N+1 query patterns.

### 8.2 Database Indexes

A dedicated `add_performance_indexes` migration adds composite indexes tuned to the platform's most frequent query patterns:

| Table | Index Columns | Serves |
|---|---|---|
| `orders` | `(user_id, status, created_at)` | User order list with status filter and date sort |
| `orders` | `trx_id` | Webhook callback lookups |
| `orders` | `(provider_trx_id, status)` | Provider reconciliation queries |
| `orders` | `status` | Admin order list filtering |
| `orders` | `created_at` | Date range and chart queries |
| `products` | `(category, status)` | Product list by category |
| `products` | `buyer_sku_code` | SKU lookup during order processing |
| `products` | `brand` | Brand filter |
| `products` | `status` | Active product filtering |
| `users` | `email` | Login lookup |
| `users` | `created_at` | Admin analytics |

### 8.3 Response Time Targets

| Operation | Target | Mechanism |
|---|---|---|
| Product list (Redis cache hit) | < 50ms | Redis, 10 min TTL |
| Product list (cache cold) | < 200ms | Optimized query + indexes |
| Order creation | < 200ms | Async queue dispatch after commit |
| Dashboard stats (cache hit) | < 100ms | Redis, 5 min TTL |
| Webhook processing | < 300ms | Pessimistic lock + in-transaction update |

### 8.4 Infrastructure Tuning

**PHP-FPM** (`/etc/php/8.2/fpm/pool.d/www.conf`):

```ini
pm                   = dynamic
pm.max_children      = 50
pm.start_servers     = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests      = 500
```

**MySQL** (`/etc/mysql/mysql.conf.d/mysqld.cnf`):

```ini
max_connections         = 200
innodb_buffer_pool_size = 2G
innodb_log_file_size    = 512M
query_cache_type        = 0
```

**Redis** (`/etc/redis/redis.conf`):

```ini
maxmemory        1gb
maxmemory-policy allkeys-lru
```

---

## 9. Installation

### 9.1 Requirements

| Dependency | Minimum Version |
|---|---|
| PHP | 8.2 |
| Composer | 2.5 |
| MySQL | 8.0 |
| Redis | 7.0 |
| Node.js | 18.x |

### 9.2 Local Setup

```bash
git clone https://github.com/fetrusmeilanoilhamsyah/feepay-backend.git
cd feepay-backend

composer install
npm install

cp .env.example .env
php artisan key:generate
nano .env  # Configure database, Redis, and API credentials

php artisan migrate --seed
```

Start all services at once using the Composer dev script (requires `concurrently` via npm):

```bash
composer run dev
# Starts: php artisan serve, queue:listen, pail (log viewer), npm run dev
```

Or start each service individually:

```bash
php artisan serve           # Terminal 1: API server on :8000
php artisan queue:work      # Terminal 2: Queue worker
php artisan schedule:work   # Terminal 3: Scheduler
npm run dev                 # Terminal 4: Frontend assets (if applicable)
```

### 9.3 Running Tests

```bash
php artisan test
php artisan test --filter=OrderTest
php artisan test --coverage
```

---

## 10. Production Deployment

### 10.1 Server Requirements

| Resource | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 22.04 LTS | Ubuntu 22.04 LTS |
| RAM | 4 GB | 8 GB |
| CPU | 2 cores | 4 cores |
| Storage | 50 GB SSD | 100 GB SSD |

### 10.2 Step 1 — System Dependencies

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.2 and required extensions
sudo add-apt-repository ppa:ondrej/php
sudo apt install -y php8.2-fpm php8.2-mysql php8.2-redis \
    php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip

# Database, cache, web server, process manager
sudo apt install -y mysql-server redis-server nginx supervisor
sudo mysql_secure_installation

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 10.3 Step 2 — Application Setup

```bash
sudo mkdir -p /var/www/feepay
sudo git clone https://github.com/fetrusmeilanoilhamsyah/feepay-backend.git /var/www/feepay
cd /var/www/feepay

composer install --optimize-autoloader --no-dev
npm install && npm run build

sudo chown -R www-data:www-data /var/www/feepay
sudo chmod -R 755 /var/www/feepay
sudo chmod -R 775 /var/www/feepay/storage
sudo chmod -R 775 /var/www/feepay/bootstrap/cache

cp .env.example .env
nano .env  # Set all production values

php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### 10.4 Step 3 — Nginx

```nginx
# /etc/nginx/sites-available/feepay
server {
    listen 80;
    server_name api.feepay.id;
    root /var/www/feepay/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    limit_req_zone $binary_remote_addr zone=api:10m rate=60r/m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/feepay /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 10.5 Step 4 — SSL Certificate

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.feepay.id
sudo certbot renew --dry-run  # Verify auto-renewal
```

### 10.6 Step 5 — Supervisor (Queue Workers)

```ini
# /etc/supervisor/conf.d/feepay-worker.conf
[program:feepay-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/feepay/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/feepay/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start feepay-queue:*
```

### 10.7 Step 6 — Cron (Scheduler)

```bash
crontab -e

# Add:
* * * * * cd /var/www/feepay && php artisan schedule:run >> /dev/null 2>&1
```

This runs the Laravel scheduler every minute. The scheduler itself determines when to execute each command based on its defined frequency (`digiflazz:sync` runs at 00:00, 06:00, 12:00, 18:00 WIB).

### 10.8 Zero-Downtime Redeployment

```bash
php artisan down --retry=60
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

`queue:restart` sends a graceful restart signal — workers finish their current job before restarting, preventing mid-flight transactions from being interrupted.

---

## 11. Configuration Reference

### 11.1 Complete `.env` Reference

```bash
# ── Application ───────────────────────────────────────────────────
APP_NAME="FEEPAY.ID"
APP_ENV=production
APP_KEY=base64:GENERATE_WITH_php_artisan_key:generate
APP_DEBUG=false            # Must be false in production
APP_URL=https://api.feepay.id

# ── Logging ───────────────────────────────────────────────────────
LOG_CHANNEL=stack
LOG_LEVEL=error            # Use 'debug' for development
LOG_SLACK_WEBHOOK_URL=     # Optional Slack alerting

# ── Database ──────────────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=feepay_production
DB_USERNAME=feepay_user
DB_PASSWORD=STRONG_PASSWORD_HERE

# ── Redis ─────────────────────────────────────────────────────────
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=STRONG_REDIS_PASSWORD
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2

# ── Sanctum ───────────────────────────────────────────────────────
SANCTUM_STATEFUL_DOMAINS=feepay.id,www.feepay.id
SESSION_DOMAIN=.feepay.id

# ── Digiflazz ─────────────────────────────────────────────────────
DIGIFLAZZ_USERNAME=your_production_username
DIGIFLAZZ_API_KEY=your_production_api_key
DIGIFLAZZ_BASE_URL=https://api.digiflazz.com/v1
DIGIFLAZZ_TIMEOUT=30
DIGIFLAZZ_WEBHOOK_SECRET=your_webhook_secret    # Used for HMAC-SHA256 callback validation

# ── Midtrans ──────────────────────────────────────────────────────
MIDTRANS_SERVER_KEY=Mid-server-xxxxxxxxxx
MIDTRANS_CLIENT_KEY=Mid-client-xxxxxxxxxx
MIDTRANS_MERCHANT_ID=your_merchant_id
MIDTRANS_IS_PRODUCTION=true
MIDTRANS_IS_SANITIZED=true
MIDTRANS_IS_3DS=true

# ── Security ──────────────────────────────────────────────────────
ADMIN_IP_WHITELIST=103.xxx.xxx.xxx,180.xxx.xxx.xxx    # Comma-separated, no spaces

# ── Telegram ──────────────────────────────────────────────────────
TELEGRAM_BOT_TOKEN=123456789:AAFxxxxxxxxxxxxxxx
TELEGRAM_ADMIN_CHAT_ID=987654321

# ── Email ─────────────────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=your_smtp_username
MAIL_PASSWORD=your_smtp_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@feepay.id
MAIL_FROM_NAME="${APP_NAME}"

# ── WhatsApp (optional) ───────────────────────────────────────────
WHATSAPP_API_URL=
WHATSAPP_API_KEY=
WHATSAPP_SENDER_NUMBER=

# ── Monitoring ────────────────────────────────────────────────────
SENTRY_LARAVEL_DSN=           # Optional error tracking
SENTRY_TRACES_SAMPLE_RATE=1.0

# ── Business Logic ────────────────────────────────────────────────
FEEPAY_MARGIN=2000            # Default profit margin added on sync (IDR)
QUEUE_WORKERS=4               # Informational — actual count is set in Supervisor
```

---

## 12. Monitoring & Observability

### 12.1 Health Check

```bash
GET /api/health

# Healthy response:
{
  "status": "ok",
  "timestamp": "2026-03-08T14:30:00+07:00",
  "service": "PPOB API"
}
```

### 12.2 Alert Thresholds

| Metric | Warning | Critical |
|---|---|---|
| API p95 response time | > 500ms | > 1,000ms |
| Queue backlog depth | > 100 jobs | > 500 jobs |
| Failed jobs per hour | > 5 | > 10 |
| Database connection pool usage | > 80% | > 90% |
| Redis memory usage | > 80% | > 90% |
| Disk usage | > 80% | > 90% |

### 12.3 Log Locations

```bash
# Application logs (daily files)
tail -f /var/www/feepay/storage/logs/laravel.log

# Nginx error log
tail -f /var/log/nginx/error.log

# Nginx access log
tail -f /var/log/nginx/access.log

# Queue worker output
sudo supervisorctl tail -f feepay-queue:feepay-queue_00 stdout
```

### 12.4 Service Status

```bash
sudo systemctl status nginx
sudo systemctl status php8.2-fpm
sudo systemctl status mysql
sudo systemctl status redis
sudo supervisorctl status
```

### 12.5 Pre-Launch Checklist

- [ ] `APP_DEBUG=false` and `APP_ENV=production`
- [ ] `ADMIN_IP_WHITELIST` restricted to admin IPs only
- [ ] Redis configured for cache, session, and queue (separate DB indexes)
- [ ] Supervisor configured and running 4 queue workers
- [ ] Cron entry active and tested
- [ ] SSL certificate installed and auto-renewal verified
- [ ] Midtrans production keys set and webhook URL registered in Midtrans dashboard
- [ ] Digiflazz production credentials, webhook secret set, and callback URL registered
- [ ] Telegram bot configured for admin alerts
- [ ] SMTP credentials verified
- [ ] `php artisan config:cache && route:cache && view:cache` executed
- [ ] File permissions: `775` on `storage/` and `bootstrap/cache/`
- [ ] Database backup strategy in place and tested
- [ ] Load test completed at 100+ concurrent users

---

## 13. Troubleshooting

### Queue workers not processing

```bash
sudo supervisorctl status
sudo supervisorctl restart feepay-queue:*
php artisan queue:flush       # Clears pending jobs from failed queue
php artisan queue:restart     # Sends graceful restart signal to workers
```

### Redis connection failure

```bash
sudo systemctl status redis
redis-cli ping                # Should return PONG
redis-cli AUTH your_redis_password
redis-cli PING
```

### Database deadlock

```sql
-- Check for locked tables
SHOW OPEN TABLES WHERE In_use > 0;

-- Identify long-running or blocking queries
SHOW FULL PROCESSLIST;

-- Terminate a blocking query
KILL <process_id>;
```

### Webhook callback not received

```bash
# Verify port 443 is open
sudo netstat -tlnp | grep nginx
sudo ufw status
sudo ufw allow 443/tcp

# Test the endpoint manually
curl -X POST https://api.feepay.id/api/webhooks/digiflazz \
     -H "Content-Type: application/json" \
     -H "X-Digiflazz-Signature: test_sig" \
     -d '{"ref_id":"test","status":"Sukses"}'

# Check Nginx error log for clues
tail -f /var/log/nginx/error.log
```

### High PHP-FPM memory usage

```bash
# Count active PHP-FPM processes
ps aux | grep php-fpm | wc -l

# Reduce max_children if under memory pressure
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
# Lower pm.max_children from 50 to an appropriate value for available RAM
sudo systemctl restart php8.2-fpm
```

### Product sync returning empty or failing

```bash
# Test Digiflazz service directly via Tinker
php artisan tinker
>>> app(\App\Services\DigiflazzService::class)->getPriceList()

# Run sync manually with verbose output
php artisan digiflazz:sync --category=Pulsa -v
```

### Cache not refreshing after product sync

```bash
php artisan cache:clear
php artisan config:clear
sudo supervisorctl restart feepay-queue:*
```

---

## 14. Developer Notes

### 14.1 Code Conventions

**Use dependency injection over service locators:**

```php
// Preferred — constructor injection
public function __construct(DigiflazzService $digiflazz)
{
    $this->digiflazz = $digiflazz;
}
```

**Always wrap write operations in transactions:**

```php
DB::beginTransaction();
try {
    // All writes here
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

**Always use eager loading to prevent N+1:**

```php
// Correct
Order::with(['product:id,code,name,category', 'user:id,name,email'])->get();

// Never do this
foreach (Order::all() as $order) {
    echo $order->product->name; // Executes one query per iteration
}
```

**Always use `select()` on large tables:**

```php
Order::select(['id', 'product_id', 'status', 'total_price', 'created_at'])
    ->where('user_id', $userId)
    ->latest()
    ->get();
```

**Dispatch queue jobs only after DB commit:**

```php
DB::commit();
ProcessOrderTransaction::dispatch($order); // Never dispatch inside a transaction
```

---

### 14.2 Adding a New Queue Job

```bash
php artisan make:job MyNewJob
```

Always define retry parameters explicitly:

```php
class MyNewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 3;
    public $timeout = 60;
    public $backoff = [30, 120, 300];

    public function handle(): void
    {
        // Implementation
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical(static::class . ' failed permanently', [
            'error' => $exception->getMessage()
        ]);
    }
}
```

---

### 14.3 Adding a New Admin Endpoint

1. Add the route inside the `admin` middleware group in `routes/api.php`:

```php
Route::prefix('admin')->middleware(['admin', 'admin.ip'])->group(function () {
    Route::get('/new-resource', [ResourceController::class, 'index']);
    Route::post('/new-resource', [ResourceController::class, 'store']);
});
```

2. The `VerifyPinMiddleware` (which validates `X-Admin-Pin`) is applied globally to the admin group — no need to add it per-route.

3. Controllers under the admin group can still call `auth()->user()->isAdmin()` for secondary checks if the route is also accessible via non-admin paths.

---

### 14.4 Git Workflow

```bash
# Feature development
git checkout -b feature/descriptive-name
git commit -m "feat: add order retry endpoint"
git push origin feature/descriptive-name

# Hotfix
git checkout -b hotfix/balance-refund-race-condition
git commit -m "fix: prevent double refund on concurrent webhook callbacks"
git push origin hotfix/balance-refund-race-condition
```

---

### 14.5 OrderStatus Enum

The `App\Enums\OrderStatus` backed enum provides helper methods used throughout the codebase:

```php
OrderStatus::SUCCESS->label();    // "Berhasil"
OrderStatus::FAILED->color();     // "red"
OrderStatus::SUCCESS->isFinal();  // true
OrderStatus::PENDING->isFinal();  // false
```

Use `isFinal()` in idempotency checks to avoid hardcoding status strings:

```php
if ($order->status->isFinal()) {
    return response()->json(['message' => 'Already processed'], 200);
}
```

---

## 15. Contact

**Fetrus Meilano Ilhamsyah**
Full-Stack Developer

- Telegram: [@FEE999888](https://t.me/FEE999888)
- Email: fetrusmeilanoilham@gmail.com
- GitHub: [fetrusmeilanoilhamsyah](https://github.com/fetrusmeilanoilhamsyah)

---

## License

Proprietary software. All rights reserved.