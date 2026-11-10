# Malware Analysis Sandbox Booking

Analysts reserve isolated malware-detonation sandboxes by time slot. One analyst per sandbox at a time, bookings capped at 2 hours, and nothing may overlap the nightly 02:00-03:00 UTC maintenance window.

## Tech stack
- **Backend:** Laravel (PHP), REST JSON API
- **Frontend:** React (Vite)
- **Database:** MongoDB
- **Tests:** PHPUnit + a shell script for concurrency

## Architecture and design decisions
- **Flow:** React UI -> Laravel REST API -> `BookingService` (rules) -> `MongoStore` -> MongoDB. Collections: `sandboxes`, `bookings`, `booking_slots`, `counters`.
- **Time zones:** stored in UTC only. The browser sends local date/time plus an IANA zone; the server converts. The UI displays in the zone chosen in the dropdown.
- **Overlaps:** half-open intervals `[start, end)`, so back-to-back bookings (10-11, 11-12) are allowed.
- **Maintenance window:** checked in UTC; touching the edge (ending at 02:00, starting at 03:00) is allowed.
- **Concurrency:** each booking claims one record per 15 minutes in `booking_slots`, protected by a **unique index on (sandbox, slot)**. Two simultaneous requests for the same slot: exactly one wins, the other gets `409`. Needs no transactions.
- **Cancel:** soft delete; the slot is freed, the record is kept for audit.
- **Business logic** lives in `app/Services/BookingService.php`; storage in `app/Services/MongoStore.php`.

## Run locally
Needs PHP 8.3+ with the `mongodb` extension, Composer, Node 20+, and a running MongoDB.

1. `composer install`, then copy `.env.example` to `.env` and run `php artisan key:generate`
2. `php artisan db:seed` (creates indexes and 5 sandboxes)
3. `npm install && npm run build`
4. `PHP_CLI_SERVER_WORKERS=8 php artisan serve`, then open http://127.0.0.1:8000

## Tests
- `php artisan test` runs unit and API tests on a separate test database.
- `./scripts/concurrency_test.sh` fires 20 parallel identical bookings. Expect one `201` and nineteen `409`.

## API
| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/sandboxes` | List sandboxes |
| GET | `/api/bookings?date=&timezone=` | Bookings for a local day |
| POST | `/api/bookings` | Create a booking |
| DELETE | `/api/bookings/{id}` | Cancel a booking |

Errors return `{ "error": "<code>", "message": "..." }` with codes `overlap` (409), `too_long`, `maintenance_window`, `invalid_range`, `invalid_granularity` (422), `sandbox_not_found`, `not_found` (404), `already_cancelled` (409), and `invalid_timezone`, `invalid_datetime` (400).

## Deployment (Docker Compose)
1. Install Docker and Docker Compose, and clone the repository.
2. Generate an app key: `php artisan key:generate --show` (or any `base64:` 32-byte key).
3. Export it: `export APP_KEY='base64:...'`.
4. Start everything: `docker compose up --build -d` (app on port 8000, MongoDB 7 alongside).
5. Open http://localhost:8000.

**Environment variables:** `APP_KEY` (required), `APP_URL`, `MONGODB_URI` (e.g. an Atlas `mongodb+srv://` URL), `MONGODB_DATABASE`.

**Seeding / migration:** there are no SQL migrations. On every start the container runs `php artisan db:seed --force`, which creates the indexes and the 5 sandboxes (safe to repeat). Data persists in the `mongodata` volume.

## Domain and security assumptions
- Maintenance is a fixed 02:00-03:00 UTC for every sandbox, regardless of the analyst's time zone.
- A booking is within one local calendar day, on 15-minute boundaries. Past dates are not blocked.
- **No authentication** (per the brief): anyone can cancel any booking; a real system would restrict this to the owner or an admin.
- Inputs are validated and length-limited, queries are parameterised through the MongoDB driver, and React escapes output. The sample reference is a plain string (hash or ticket id), never sample contents.
- Sandboxes are treated as single-tenant: sharing one would risk sample cross-contamination, which is why double-booking is blocked at the database level.

## Known limitations
- A crash mid-booking could leave a `pending` record holding slots; it needs a cleanup job.
- No overnight (cross-midnight local) bookings, no editing of a booking, no live refresh.

## What I would improve with more time
- Authentication, per-analyst ownership of bookings and an audit trail.
- Reject bookings in the past, and add per-analyst limits.
- Run on a MongoDB replica set and wrap the slot claim in a transaction (the unique index stays as the safety net).
- Real-time updates over websockets and browser end-to-end tests in CI.
