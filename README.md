# WGT Spain — Accommodation Offers API

REST API on Laravel 12 that asynchronously imports accommodation offers from
suppliers, returns the cheapest actual offer per property, and allows an offer
to be reserved safely.

> Note: the task description mentions MySQL 8+; this implementation uses
> **PostgreSQL 17** instead (same relational feature set, `SELECT … FOR UPDATE`
> row locking is used for reservations). The queue runs on **Redis**.

## Requirements

| Component  | Version | Notes                                        |
| ---------- | ------- | -------------------------------------------- |
| PHP        | 8.4     | with `pdo_pgsql`, `pgsql`, `redis` extensions (8.2+ works) |
| Laravel    | 12.x    |                                              |
| PostgreSQL | 17      |                                              |
| Redis      | 8.x     | queue + cache driver                          |
| Composer   | 2.x     |                                              |

## Installation

```bash
# 1. Dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Databases (adjust DB_USERNAME / DB_PASSWORD in .env first)
createdb wgt_spain
createdb wgt_spain_test

# 4. Schema
php artisan migrate
```

Make sure PostgreSQL and Redis are running:

```bash
brew services start postgresql@17
brew services start redis
redis-cli ping   # -> PONG
```

## Running

```bash
# HTTP server
php artisan serve                 # http://localhost:8000

# Queue worker (required — imports are processed in a Job)
php artisan queue:work redis

# Migrations
php artisan migrate               # run
php artisan migrate:fresh --seed  # reset + seed

# Seeders (suppliers supplier-a / supplier-b)
php artisan db:seed

# Tests (uses the wgt_spain_test database)
php artisan test
```

Smoke check: `curl http://localhost:8000/api/ping` → `{"data":{"status":"ok"}}`

## Configuration

Everything is driven by `.env` (see `.env.example`, which contains no secrets):

- `DB_CONNECTION=pgsql`, `DB_DATABASE=wgt_spain`
- `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`
- `DB_TIMEZONE=UTC` — **must stay UTC.** Laravel writes datetimes as naive
  `Y-m-d H:i:s` strings and means UTC by them; without pinning the session timezone,
  Postgres reads them in the server's own zone and silently shifts every `timestamptz`.
- The test suite overrides the database in `phpunit.xml` (`wgt_spain_test`)

## API

### `POST /api/imports`

Validates the payload, records the import, queues processing, returns **202** immediately.

```bash
curl -X POST http://localhost:8000/api/imports \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"supplier":"supplier-a","external_import_id":"import-2026-09-01-001",
       "sent_at":"2026-09-01T10:00:00Z","offers":[{"external_id":"offer-a-10001",
       "property":{"code":"BCN-0001","name":"Apartment near Sagrada Familia","city":"Barcelona"},
       "check_in":"2026-10-10","check_out":"2026-10-15","max_guests":4,"price":72500,
       "currency":"EUR","available_units":2,"expires_at":"2026-12-10T23:59:59Z"}]}'
# → 202 {"data":{"id":1,"status":"pending"}}
```

Prices are integers in **minor units** (`72500` = 725.00 EUR).

### `GET /api/imports/{import}`

Current state of the asynchronous import: `status`, `total_offers`, `processed_offers`,
`error`, `completed_at`.

### `GET /api/properties`

Cheapest actual offer per property. An offer is actual when the dates match exactly,
`max_guests >= guests`, `available_units > 0` and `expires_at > now()`.

```bash
curl 'http://localhost:8000/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&page=1'
```

Paginated: `links.next`, `links.prev`, `meta.per_page`, `meta.total`. `per_page` is
accepted up to 100.

### `POST /api/offers/{offer}/reservations`

**201** on success, **200** for an idempotent replay, **409** if the offer is sold out or
expired, **404** for an unknown offer.

```bash
curl -X POST http://localhost:8000/api/offers/1/reservations \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"client_reference":"web-order-9f782b1c","customer_name":"John Smith","customer_email":"john@example.com"}'
```

## Import idempotency

Three separate keys, each doing one job:

1. **`unique(supplier_id, external_import_id)`** — resending the same import returns the
   existing record with 202 and **dispatches no second job**, so processing never re-runs.
   `ImportService::createAndQueueImport()` looks the import up first; if two identical requests arrive at the
   same instant, both pass that lookup and one hits the unique index — the resulting
   violation is caught and the existing row returned, rather than surfacing as a 500.
2. **`unique(supplier_id, external_id)` on offers** — an offer already seen under a
   *different* import is updated in place and its `import_id` repointed, never duplicated.
3. **`properties.code`** — properties are found-or-created by code, so two suppliers
   offering `BCN-0001` share one property row (which is what makes "cheapest per property"
   able to compare suppliers).

Because every write is an upsert, re-processing an import from the start is harmless. That
is what makes the queue retry safe. `processed_offers` is reset at the start of each attempt
so a retry cannot double-count, and a job redelivered for an import that already reached
`completed` or `failed` returns immediately.

Offers are written in **bulk**, in three statements no matter how large the payload is:

1. one `upsert` for the distinct properties in the batch (deduplicated by `code` first —
   Postgres refuses an `ON CONFLICT` statement that would touch the same row twice),
2. one `select` for the resulting `[code => id]` dictionary,
3. one `upsert` for the offers, taking `property_id` from that dictionary.

All three run in **one transaction**, so an import either lands in full or writes nothing;
`processed_offers` is therefore `0` or `total_offers`, never anything between. Rows are
chunked at 500 per statement because Postgres caps a statement at 65535 bind parameters.
A feature test asserts a 40-offer payload costs the same number of queries as a 2-offer one.

A single bad offer rolls the whole import back and marks it `failed` with the database error
in `error`; a half-succeeded import reporting `completed` would be a lie.

## Protection against two concurrent bookings of the last unit

`ReservationService::reserve()` runs inside a transaction and takes a **pessimistic row lock**
on the offer:

```php
DB::transaction(function () use ($offer, $data) {
    $locked = Offer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();
    // …re-read availability, then create + decrement
});
```

`lockForUpdate()` issues `SELECT … FOR UPDATE`. Given two simultaneous requests for the last
unit:

1. Request A acquires the row lock and reads `available_units = 1`.
2. Request B issues the same `SELECT … FOR UPDATE` and **blocks** — inside its own
   transaction, holding no reservation.
3. A creates the reservation, decrements to 0, commits, releasing the lock.
4. B unblocks and re-reads `available_units`, now **0**, and throws
   `WGTSpainException('The offer has no available units left.', 409)`.

The availability check happens **after** the lock is acquired, not before. Checking first and
locking second would be a time-of-check/time-of-use race, which is exactly the bug this
guards against.

Two further layers back it up:

- **`CHECK (available_units >= 0)`** on `offers`. If the application guard were ever wrong,
  the database itself refuses the oversell. There is a test asserting this.
- **`unique(client_reference)`** on `reservations` makes a resubmitted booking an idempotent
  replay (200, the original reservation, no second unit consumed) instead of a double booking.
  The lookup sits inside the offer lock, so two identical references for one offer are
  serialised and cannot both insert.

An equally safe alternative is a single conditional statement —
`UPDATE offers SET available_units = available_units - 1 WHERE id = ? AND available_units > 0`
— checking the affected-row count. `FOR UPDATE` was chosen because the guard clauses stay
readable and each rejection reason can return its own message.

## Architecture

```
Route → Form Request → Controller → Service → Model
                            ↓          ↓
                       Resource      Job (import processing)
```

- **Controllers** are ~5 lines: validated input in, service call, Resource out. No queries,
  no transactions, no branching on business state.
- **Services** hold the business logic, transactions and locking. They throw a single
  `WGTSpainException`, carrying the message and the HTTP status as its code, and know nothing
  about how a response is built — the mapping to JSON lives once in `bootstrap/app.php`.
  That keeps a service callable from a Job or a console command, where `abort()` would be
  meaningless.
- **No interfaces and no repositories.** There is one implementation of each service, and
  Eloquent is the data access layer. `GET /api/imports/{import}` has no service at all,
  because there is no logic for one to hold.
- `ImportService` holds both halves of an import: `createAndQueueImport()` runs inside the
  request and only records and queues, `createOffersFromPayload()` runs inside the queue
  worker and writes the offers.
  They live together because they share the same idempotency rules.

The cheapest-offer search is a single SQL statement using a **lateral join**
(`PropertySearchService`), so filtering, cheapest-per-property, ordering and pagination all
happen in Postgres. A feature test asserts the query count does not grow with the result
set, which is what would fail if the resolution ever moved into a PHP collection.
