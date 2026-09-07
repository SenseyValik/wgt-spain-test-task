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
- The test suite overrides the database in `phpunit.xml` (`wgt_spain_test`)

## API

Endpoints are documented as they are implemented:

- `POST /api/imports` — queue a supplier import, returns `202 Accepted`
- `GET  /api/imports/{import}` — import status
- `GET  /api/properties` — cheapest actual offer per property
- `POST /api/offers/{offer}/reservations` — reserve an offer, returns `201 Created`
