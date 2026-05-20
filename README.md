# Inventory API – Laravel Backend

A production-ready inventory reservation system built in Laravel.  
Covers eligibility checking, stock reservation, order confirmation, order cancellation, concurrency safety, and automatic 5-minute reservation expiry.

---

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Set DB_CONNECTION, DB_DATABASE, DB_USERNAME, DB_PASSWORD
# Set QUEUE_CONNECTION=database  (or redis in production)

# 3. Generate app key
php artisan key:generate

# 4. Run migrations + seed
php artisan migrate --seed

# 5. Start the queue worker (required for auto-expiry)
php artisan queue:work --queue=default

# 6. (Optional) start the scheduler in another terminal
php artisan schedule:work

# 7. Serve
php artisan serve
```

---

## API Reference

All endpoints are prefixed with `/api/v1`.

| Method   | Endpoint                              | Purpose                            |
|----------|---------------------------------------|------------------------------------|
| `GET`    | `/products/{id}/eligibility`          | Check if a product can be sold     |
| `POST`   | `/reservations`                       | Reserve stock for an order         |
| `POST`   | `/orders/{orderId}/confirm`           | Confirm (place) a reserved order   |
| `DELETE` | `/orders/{orderId}`                   | Cancel an order & release stock    |

### 1. Check eligibility

```http
GET /api/v1/products/1/eligibility
```

```json
{
  "product_id": 1,
  "sku": "WD-SAMSUNG-8KG",
  "name": "Samsung 8kg Front Load Washer Dryer",
  "price": "2499.00",
  "is_active": true,
  "stock_quantity": 10,
  "reserved_quantity": 2,
  "available_quantity": 8,
  "eligible_for_sale": true
}
```

### 2. Reserve stock

```http
POST /api/v1/reservations
Content-Type: application/json

{
  "order_id":   "ORD-20240501-XYZ",
  "product_id": 1,
  "quantity":   3
}
```

```json
{
  "message": "Reservation created. You have 5 minutes to confirm.",
  "reservation": {
    "id": 42,
    "order_id": "ORD-20240501-XYZ",
    "status": "pending",
    "expires_at": "2024-05-01T10:05:00+08:00",
    ...
  }
}
```

### 3. Confirm order

```http
POST /api/v1/orders/ORD-20240501-XYZ/confirm
```

### 4. Cancel order

```http
DELETE /api/v1/orders/ORD-20240501-XYZ
```

---

## How the 5-Minute Auto-Release Works

Two complementary mechanisms ensure a reservation is always released
if not confirmed in time. Together they form a belt-and-suspenders
approach so no reservation ever stays stuck.

### Primary – Delayed Queue Job

When a reservation is created, `InventoryService::reserve()` immediately
dispatches an `ExpireReservation` job **with a delay equal to the
`expires_at` timestamp** (5 minutes from now):

```php
ExpireReservation::dispatch($reservation->id)
    ->delay($reservation->expires_at);   // fires exactly at T+5 min
```

When the job fires it calls `InventoryService::expireReservation()`,
which:

1. Opens a DB transaction and locks the reservation row.
2. Checks if the reservation is still `pending`.  
   - If it was already confirmed or cancelled → **safe no-op** (idempotent).
3. Decrements `products.reserved_quantity` by the reservation's quantity,
   returning the stock to the available pool.
4. Sets `status = 'expired'`.

**Queue driver choice matters here.**  
- `database` driver: fine for development / low-traffic.  
- `redis` (Laravel Horizon) or `sqs`: recommended in production for
  reliable delayed-job delivery and horizontal scaling.

### Fallback – Scheduled Sweep Command

In case the queue worker was down when the job should have fired,
a scheduled Artisan command runs every minute:

```php
// App\Console\Kernel
$schedule->command('inventory:sweep-expired')->everyMinute();
```

It scans for any `pending` reservations where `expires_at <= now()` and
calls `expireReservation()` on each one – same idempotent method, so
there is zero risk of double-releasing.

---

## How Concurrency is Handled

All state-changing operations (reserve, confirm, cancel, expire) use
**pessimistic locking** via MySQL's / PostgreSQL's `SELECT … FOR UPDATE`.

```php
$product = Product::lockForUpdate()->findOrFail($productId);
```

This means:

| Property | Guarantee |
|---|---|
| **Mutual exclusion** | Only one transaction can read-then-write the product row at a time. Competing requests queue up at the database level. |
| **No oversell** | The check `available_quantity >= requested_qty` is performed *inside* the lock, so a second request sees the already-decremented value. |
| **No double-reserve** | `order_id` has a `UNIQUE` constraint in the DB + the service checks for an existing reservation inside the same transaction. |
| **Atomicity** | Both the `reservations` INSERT and the `products.reserved_quantity` INCREMENT happen in the same transaction; a crash mid-way rolls everything back. |

### Why not optimistic locking?

Optimistic locking (version column + retry loop) works well when
conflicts are rare. For a flash-sale / limited-stock scenario, conflicts
will be *frequent*, turning retries into a thundering-herd problem.
Pessimistic locking is the correct choice here.

---

## Project Structure

```
app/
├── Console/Commands/
│   └── SweepExpiredReservations.php  ← fallback scheduler command
├── Exceptions/
│   └── InventoryException.php        ← domain-specific exception
├── Http/Controllers/
│   └── InventoryController.php       ← thin HTTP layer
├── Jobs/
│   └── ExpireReservation.php         ← delayed auto-release job
├── Models/
│   ├── Product.php
│   └── Reservation.php
└── Services/
    └── InventoryService.php          ← all business logic lives here

database/migrations/
├── …_create_products_table.php
└── …_create_reservations_table.php

routes/
└── api.php

tests/Feature/
└── InventoryTest.php                 ← covers all 5 rules + edge cases
```

---

## Business Rules Checklist

| Rule | Enforcement |
|---|---|
| Cannot oversell | `lockForUpdate` + `available_quantity >= quantity` check inside TX |
| Reservation reduces available stock temporarily | `reserved_quantity` incremented at reserve, decremented at confirm/cancel/expire |
| Auto-release after 5 min | `ExpireReservation` delayed job + `SweepExpiredReservations` fallback |
| Same `orderId` cannot reserve twice | DB `UNIQUE` on `order_id` + explicit service check |
| Simultaneous requests handled safely | Pessimistic row lock (`lockForUpdate`) on product row |

---

## Running Tests

```bash
php artisan test --filter InventoryTest
```
