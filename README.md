# Malware Analysis Sandbox Booking

Analysts reserve isolated malware-detonation sandboxes by time slot. A sandbox is never shared by two analysts at once, bookings are capped at 2 hours, and nothing may overlap the nightly 02:00-03:00 UTC maintenance window (snapshot revert).

## Tech stack
- **Backend:** Laravel 13 (PHP 8.3+), REST JSON API
- **Frontend:** React 19 (Vite), plain CSS
- **Database:** MongoDB (official `mongodb/mongodb` PHP library; collections `sandboxes`, `bookings`, `booking_slots`, `counters`)
- **Tests:** PHPUnit (unit rules + API feature tests against a throwaway `sandbox_booking_test` DB), shell script for a concurrency race

## Architecture and design decisions
- `app/Services/BookingService.php` holds *all* business rules; controllers are thin.
- **Time handling:** the database stores **UTC only** (`start_utc`, `end_utc`, half-open intervals `[start, end)`). The client sends the analyst's *local* date, times and IANA timezone (e.g. `Asia/Kolkata`); the server converts to UTC using the tz database, so DST is handled server-side. API responses are always UTC ISO-8601 (`...Z`); the UI renders in the timezone chosen in the dropdown (default: browser tz). A "day" is the local calendar day in the chosen tz (so an IST day shows the right UTC bookings, and DST days with 23/25 hours work).
- **Overlap:** two bookings on one sandbox clash iff `existing.start < new.end AND existing.end > new.start`. Half-open intervals make back-to-back (10-11, 11-12) legal.
- **Maintenance window:** checked in UTC for every UTC day the booking touches (a booking ending exactly at 02:00 or starting exactly at 03:00 is allowed).
- **Concurrency:** a booking claims one `booking_slots` document per 15 minutes it covers, and a **unique index on `(sandbox_id, slot)`** makes it impossible for two bookings to hold the same sandbox+slot, however many requests/processes race. Slots are claimed in ascending time with an ordered insert, so for two overlapping requests exactly one wins; the loser rolls back its claimed slots and gets `409 overlap`. This needs no replica set or multi-document transactions (a standalone `mongod` has none). Verified with `scripts/concurrency_test.sh` (20 parallel requests -> 1x 201, 19x 409), including offset overlaps (10:00-11:00 vs 10:30-11:30).
- **Cancel** is a soft delete (`status = cancelled`, flipped atomically with `findOneAndUpdate`) and its slot docs are deleted to free the time; the booking is kept for audit. Bookings are created `pending` and become `active` once all slots are claimed, so half-finished writes never show in the day view.
- Bookings are on 15-minute boundaries (grid is 30-min rows).

## Run locally
Requires PHP >= 8.3 with the `mongodb` extension (`pecl install mongodb`), Composer, Node 20+, and a MongoDB server (default `mongodb://127.0.0.1:27017`).
```bash
composer install
cp .env.example .env && php artisan key:generate   # set MONGODB_URI / MONGODB_DATABASE if needed
php artisan db:seed              # creates indexes + 5 sandboxes (idempotent)
npm install && npm run build     # or `npm run dev` for hot reload
PHP_CLI_SERVER_WORKERS=8 php artisan serve   # http://127.0.0.1:8000
```

## Tests
```bash
php artisan test                       # unit + API tests (uses and drops the `sandbox_booking_test` database)
PHP_CLI_SERVER_WORKERS=8 php artisan serve &   # then, for the race test:
./scripts/concurrency_test.sh          # 20 parallel identical bookings -> expect "1 201, 19 409"
```

## API
| Method | Path | Notes |
|---|---|---|
| GET | `/api/sandboxes` | list sandboxes |
| GET | `/api/bookings?date=YYYY-MM-DD&timezone=Asia/Kolkata` | active bookings intersecting that local day (timezone defaults to UTC) |
| POST | `/api/bookings` | body: `sandbox_id, date, start_time (HH:MM), end_time, timezone, analyst_name, sample_ref` -> 201 |
| DELETE | `/api/bookings/{id}` | cancel |

Errors are `{ "error": "<code>", "message": "<human readable>" }`:

| Code | HTTP | Meaning |
|---|---|---|
| `overlap` | 409 | slot already booked (message names the existing booking) |
| `too_long` | 422 | > 2 hours |
| `maintenance_window` | 422 | overlaps 02:00-03:00 UTC |
| `invalid_range` | 422 | end <= start |
| `invalid_granularity` | 422 | not on 15-minute boundary |
| `sandbox_not_found` / `not_found` | 404 | unknown sandbox / booking |
| `already_cancelled` | 409 | cancelling twice |
| `invalid_timezone` / `invalid_datetime` | 400 | bad input |
| (Laravel validation) | 422 | missing/oversized fields |

## Deployment (Docker)
```bash
php artisan key:generate --show          # copy output, e.g. base64:...
export APP_KEY='base64:...'
docker compose up --build -d             # app on http://localhost:8000 + mongo:7
```
The entrypoint runs `php artisan db:seed --force` on each start: it creates the unique indexes and seeds the sandboxes (idempotent; there are no SQL migrations). Data lives in the `mongodata` volume.
Environment variables: `APP_KEY` (required), `APP_URL`, `MONGODB_URI` (e.g. `mongodb+srv://...` for Atlas), `MONGODB_DATABASE`.
Without Docker: follow "Run locally" with `APP_ENV=production APP_DEBUG=false` and serve `public/` via nginx/php-fpm.

## Assumptions
- Maintenance window is fixed 02:00-03:00 **UTC** for every sandbox, regardless of the analyst's tz.
- A booking is for a single local calendar day (start and end on the same local date); it may still cross a UTC midnight.
- No auth: analyst name is free text and anyone can cancel any booking (a real system would restrict cancel to the owner/admin). Sample references (hashes/tickets) are stored as plain strings; the UI escapes output (React) and queries are parameterised. Never put sample *content* in this field.
- Past dates are not blocked.

## Known limitations / with more time
- A crash between claiming slots and marking the booking `active` leaves a `pending` booking holding slots; a janitor job should reap stale `pending` ones.
- Overnight (cross-local-midnight) bookings, past-time rejection, per-analyst limits, authentication/audit trail, edit-booking, real-time refresh (websocket), pagination, E2E tests.
- With a replica set, the slot claim could be wrapped in a multi-document transaction; the unique index would still remain the safety net.
