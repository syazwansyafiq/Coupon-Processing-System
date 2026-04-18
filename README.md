# Coupon Processing System

A high-concurrency coupon processing system built with Laravel 13, designed to handle race conditions, dynamic rule changes, and high load through asynchronous job processing, atomic Redis reservations, and full event tracking.

---

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Architecture](#architecture)
- [User Journey](#user-journey)
- [Getting Started](#getting-started)
- [API Reference](#api-reference)
- [Queue & Horizon](#queue--horizon)
- [Rule Engine](#rule-engine)
- [Reservation Strategy](#reservation-strategy)
- [Event Tracking](#event-tracking)
- [Failure & Recovery](#failure--recovery)
- [Project Structure](#project-structure)

---

## Overview

The system solves three core challenges in coupon processing at scale:

1. **Race conditions** — Two users applying the same limited coupon simultaneously will never both succeed. Reservations are committed through a Lua script that runs atomically inside Redis.
2. **Dynamic rules** — Coupon rules (limits, eligibility, discounts) are versioned in MySQL. Jobs always load the latest active settings at execution time, so rule changes take effect immediately without redeployment or cache flushes.
3. **Reliability** — Every job is idempotent and retryable. A failed job leaves no orphaned state. Stuck reservations are cleaned up automatically by a scheduled recovery job.

---

## Tech Stack

| Layer | Technology |
| --- | --- |
| Framework | Laravel 13 |
| Queue workers | Laravel Horizon |
| Reservation store | Redis 7 |
| Database | MySQL 8 |
| Web server | Nginx 1.27 |
| Runtime | PHP 8.3-FPM |
| Containers | Docker + Docker Compose |

---

## Architecture

```text
Client
  │
  ▼
POST /api/coupons/apply
  │
  ▼
CouponController          ← dispatches job, returns 202 immediately
  │
  ▼
ValidateCouponJob          [queue: high]
  ├─ CouponRuleEngine      ← loads latest CouponSetting from MySQL
  ├─ CouponReservationService  ← Lua script atomically reserves in Redis
  └─ TrackCouponEventJob   [queue: low]  ← writes coupon_events row

Client polls GET /api/coupons/status/{requestId}
  │
  ▼
Redis status key           ← set by job, TTL 10 min

On checkout success:
  POST /api/coupons/consume → ConsumeCouponJob  [queue: default]
    ├─ writes coupon_usages (MySQL, inside transaction)
    └─ releases Redis reservation

On checkout failure:
  POST /api/coupons/release → ReleaseCouponJob  [queue: default]
    └─ deletes Redis reservation key + sorted set entry
```

---

## User Journey

```text
1. User applies coupon at checkout
         │
         ▼
2. API returns immediately: "Coupon verification in progress"
         │
         ▼
3. ValidateCouponJob runs asynchronously
         │
    ┌────┴────┐
    │         │
  Valid    Invalid
    │         │
    ▼         ▼
4. Coupon   User sees
   reserved  failure reason
   5 minutes
         │
    ┌────┴────┐
    │         │
 Success   Failure /
    │       Timeout
    ▼         │
5. Coupon    Reservation
   consumed   released
```

---

## Getting Started

### Prerequisites

- Docker >= 24
- Docker Compose >= 2.20
- Make

### 1. Clone and install

```bash
git clone <repo-url>
cd Coupon-Processing-System
make install
```

`make install` handles everything in one step:

- Copies `.env.example` → `.env`
- Builds Docker images
- Starts all containers
- Waits for MySQL to be ready
- Generates the app key
- Runs migrations
- Seeds sample coupons

### 2. Access the application

| Service | URL |
| --- | --- |
| App | http://localhost:8000 |
| Horizon dashboard | http://localhost:8000/horizon |

### All available commands

```bash
make install          Bootstrap the project from scratch
make up               Start all containers
make down             Stop all containers
make restart          Restart all containers
make build            Rebuild images without cache
make ps               Show running containers
make logs             Tail logs from all containers
make logs-app         Tail app container logs only
make logs-horizon     Tail Horizon worker logs
make shell            Open a shell inside the app container
make migrate          Run pending migrations
make migrate-fresh    Drop all tables and re-run migrations
make seed             Seed sample coupon data
make migrate-seed     Fresh migrate + seed
make horizon          Print the Horizon dashboard URL
make horizon-pause    Pause all Horizon workers
make horizon-continue Resume paused Horizon workers
make queue-flush      Flush all pending jobs from Redis queues
make test             Run the test suite
make lint             Auto-fix code style with Pint
make lint-check       Check code style without fixing
```

---

## API Reference

All endpoints require authentication (`Authorization: Bearer <token>`).

### Apply a coupon

```
POST /api/coupons/apply
```

**Request body**

```json
{
  "coupon_code": "SUMMER20",
  "cart_id": "cart_abc123",
  "cart_value": 120.00,
  "item_categories": ["clothing"],
  "product_ids": [101, 202],
  "user_segments": ["premium"]
}
```

**Response** `202 Accepted`

```json
{
  "data": {
    "request_id": "a3f9e2...",
    "status": "processing",
    "message": "Coupon verification in progress"
  }
}
```

---

### Poll for validation result

```
GET /api/coupons/status/{requestId}
```

**Response** `200 OK` — possible status values:

| Status | Meaning |
|---|---|
| `processing` | Job is still running |
| `reserved` | Coupon valid and reserved for 5 minutes |
| `failed` | Validation failed — see `message` for reason |
| `consumed` | Coupon permanently applied to an order |
| `released` | Reservation was freed |
| `error` | System error — safe to retry |

**Example — reserved**

```json
{
  "data": {
    "status": "reserved",
    "message": "Coupon reserved successfully",
    "coupon_code": "SUMMER20",
    "discount_amount": 24.00,
    "setting_version": 3,
    "expires_in_seconds": 300
  }
}
```

---

### Consume a coupon (checkout success)

```
POST /api/coupons/consume
```

```json
{
  "coupon_code": "SUMMER20",
  "cart_id": "cart_abc123",
  "cart_value": 120.00,
  "order_id": "order_xyz789",
  "discount_amount": 24.00,
  "setting_version": 3,
  "request_id": "a3f9e2..."
}
```

**Response** `202 Accepted`

---

### Release a coupon (checkout failure)

```
POST /api/coupons/release
```

```json
{
  "coupon_code": "SUMMER20",
  "cart_id": "cart_abc123",
  "cart_value": 120.00,
  "request_id": "a3f9e2...",
  "reason": "checkout_failed"
}
```

**Response** `202 Accepted`

---

## Queue & Horizon

Three supervisor pools handle different workloads:

| Queue | Supervisor | Workers (prod) | Timeout | Used for |
|---|---|---|---|---|
| `high` | supervisor-high | 3 – 15 | 30s | Coupon validation |
| `default` | supervisor-default | 2 – 8 | 30s | Consume, release |
| `low` | supervisor-low | 1 – 5 | 60s | Event tracking, cleanup |

Horizon auto-scales workers based on queue wait time. Alert thresholds: `high` > 5s, `default` > 30s, `low` > 120s.

View the Horizon dashboard at `/horizon` to monitor throughput, failed jobs, and wait times.

---

## Rule Engine

`CouponRuleEngine::validate()` always loads the latest active `CouponSetting` from MySQL — no caching — so rule changes apply to the next job execution without any redeployment.

**Supported rules (stored as JSON in `coupon_settings.rules`)**

| Rule | Type | Description |
|---|---|---|
| `global_usage_limit` | integer | Maximum total redemptions |
| `per_user_limit` | integer | Maximum redemptions per user |
| `min_cart_value` | decimal | Minimum cart total required |
| `first_time_user` | boolean | Only users with no previous orders |
| `categories` | string[] | Cart must contain at least one matching category |
| `product_ids` | integer[] | Cart must contain at least one matching product |
| `time_window` | `{start, end}` | Valid only between these times (HH:MM, UTC) |
| `user_segments` | string[] | User must belong to at least one segment |

**Example `coupon_settings.rules`**

```json
{
  "first_time_user": true,
  "categories": ["electronics", "gadgets"],
  "time_window": { "start": "10:00", "end": "14:00" },
  "user_segments": ["vip", "premium"]
}
```

To add a new rule: add a `check*` method in `CouponRuleEngine` and call it from `checkCartRules()`. No other changes required.

---

## Reservation Strategy

Reservations use a **Lua script executed atomically inside Redis**, preventing race conditions across multiple app servers.

**Redis key layout**

| Key | Type | TTL | Purpose |
|---|---|---|---|
| `coupon:res:{code}:{user_id}` | String | 5 min | Reservation payload (idempotency guard) |
| `coupon:res_set:{code}` | Sorted Set | 5 min + 60s | Active reservation set, scored by expiry timestamp |
| `coupon:status:{idempotency_key}` | String | 10 min | Async job result for client polling |

**Reservation Lua script logic**

1. If the reservation key already exists → refresh TTL and return success (idempotent)
2. Prune expired entries from the sorted set (`ZREMRANGEBYSCORE`)
3. Compute `total = mysql_committed_count + redis_active_count`
4. If `total >= global_limit` → return failure
5. Commit: `SET` reservation key with 5-min TTL + `ZADD` to sorted set

MySQL remains the source of truth for committed usage counts. Redis only tracks in-flight reservations.

---

## Event Tracking

Every coupon lifecycle transition is recorded in `coupon_events` via `TrackCouponEventJob` (low queue).

**Event types**

| Event | Trigger |
|---|---|
| `applied` | User submits a coupon |
| `validation_passed` | Rule engine passes |
| `validation_failed` | Rule engine rejects, or reservation slot full |
| `reserved` | Redis reservation committed |
| `consumed` | MySQL usage record written |
| `released` | Reservation freed (failure or cancellation) |
| `expired` | Reservation TTL elapsed (pruned by scheduler) |

Each event stores a full `payload` snapshot:

```json
{
  "cart_context": {
    "cart_id": "cart_abc123",
    "user_id": 42,
    "cart_value": 120.00,
    "item_categories": ["clothing"],
    "is_first_order": false
  },
  "discount_amount": 24.00,
  "rule_snapshot": {
    "version": 3,
    "global_usage_limit": 500,
    "per_user_limit": 1,
    "min_cart_value": 30.00,
    "rules": {},
    "activated_at": "2026-04-18T00:00:00+00:00"
  }
}
```

The `rule_version` column is indexed for analytics queries like "how many coupons were consumed under rule version 3?"

---

## Failure & Recovery

### Job retries

| Job | Tries | Backoff |
|---|---|---|
| `ValidateCouponJob` | 3 | 5s |
| `ConsumeCouponJob` | 5 | 10s |
| `ReleaseCouponJob` | 5 | 5s |
| `TrackCouponEventJob` | 3 | 10s |

All jobs implement `failed()` hooks — failed jobs update the Redis status key so the client is never left polling indefinitely.

### Idempotency

- `ValidateCouponJob` implements `ShouldBeUnique` — duplicate jobs for the same `idempotency_key` are discarded by Horizon.
- `ConsumeCouponJob` checks for an existing `coupon_usages` row before writing — safe to retry any number of times.
- The Redis Lua script uses SETNX semantics — re-reserving the same `{code}:{user_id}` pair simply refreshes the TTL.

### Stuck reservation cleanup

`ReleaseExpiredReservationsJob` runs every 10 minutes (via `php artisan schedule:work` in the `scheduler` container). It calls `ZREMRANGEBYSCORE` on every active coupon's reservation sorted set, pruning entries whose expiry score has passed. This keeps `activeCount()` accurate even when reservation keys expire without an explicit release.

---

## Project Structure

```
app/
├── Console/Commands/
│   └── ReleaseExpiredReservations.php   # Artisan command → dispatches cleanup job
├── DTOs/
│   ├── CartContext.php                  # Typed cart data passed to jobs
│   └── CouponValidationResult.php       # Rule engine output
├── Enums/
│   └── CouponStatus.php                 # Backed string enum for all status values
├── Http/
│   ├── Controllers/CouponController.php
│   ├── Requests/
│   │   ├── ApplyCouponRequest.php
│   │   ├── ConsumeCouponRequest.php
│   │   └── ReleaseCouponRequest.php
│   └── Resources/
│       ├── CouponApplyResource.php
│       ├── CouponStatusResource.php
│       └── CouponActionResource.php
├── Jobs/
│   ├── ValidateCouponJob.php            # [high] validates rules + reserves
│   ├── ConsumeCouponJob.php             # [default] permanent MySQL record
│   ├── ReleaseCouponJob.php             # [default] free reservation
│   ├── TrackCouponEventJob.php          # [low] write coupon_events
│   └── ReleaseExpiredReservationsJob.php# [low] prune Redis sorted sets
├── Messages/
│   └── CouponMessage.php                # All user-facing message strings
├── Models/
│   ├── Coupon.php
│   ├── CouponSetting.php                # Versioned rules
│   ├── CouponUsage.php                  # Permanent consumption records
│   └── CouponEvent.php                  # Audit log
└── Services/
    ├── CouponRuleEngine.php             # Stateless rule validation
    └── CouponReservationService.php     # Redis Lua script + status helpers

config/
└── horizon.php                          # 3 supervisor pools

database/migrations/
├── 2026_04_18_000001_create_coupons_table.php
├── 2026_04_18_000002_create_coupon_settings_table.php
├── 2026_04_18_000003_create_coupon_usages_table.php
└── 2026_04_18_000004_create_coupon_events_table.php

docker/
├── nginx/default.conf
└── php/php.ini

Dockerfile                               # 3-stage: Node assets → Composer → PHP-FPM
docker-compose.yml                       # nginx, app, horizon, scheduler, mysql, redis
```
