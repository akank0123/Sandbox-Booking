# Malware Analysis Sandbox Booking

Analysts reserve isolated malware-detonation sandboxes by time slot. One analyst per sandbox at a time, bookings capped at 2 hours, and nothing may overlap the nightly 02:00-03:00 UTC maintenance window.

## Tech stack
- **Backend:** Laravel (PHP), REST JSON API
- **Frontend:** React (Vite)
- **Database:** MongoDB
- **Tests:** PHPUnit + a shell script for concurrency

## Key design decisions
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

## Deployment
Docker Compose runs the app plus MongoDB: generate a key with `php artisan key:generate --show`, set it as `APP_KEY`, then `docker compose up --build`.
Variables: `APP_KEY` (required), `APP_URL`, `MONGODB_URI`, `MONGODB_DATABASE`. Seeding runs automatically on start.

## Assumptions
- Maintenance is a fixed 02:00-03:00 UTC for every sandbox.
- A booking is within one local calendar day, on 15-minute boundaries.
- No authentication: anyone can cancel any booking. Sample references are plain text (never sample contents).
- Past dates are not blocked.

## Known limitations / next steps
- A crash mid-booking could leave a `pending` record holding slots; add a cleanup job.
- Overnight bookings, past-time rejection, authentication and audit trail, editing bookings, live refresh, E2E tests in CI.
