#!/usr/bin/env bash
# Fires N simultaneous booking requests for the SAME sandbox + slot against a running server.
# Expected: exactly one 201, everything else 409.
# Start the server with workers first:  PHP_CLI_SERVER_WORKERS=8 php artisan serve
BASE=${BASE:-http://127.0.0.1:8000}
N=${N:-20}
DATE=${DATE:-2030-01-15}
BODY="{\"sandbox_id\":1,\"date\":\"$DATE\",\"start_time\":\"10:00\",\"end_time\":\"11:00\",\"timezone\":\"UTC\",\"analyst_name\":\"racer\",\"sample_ref\":\"race\"}"
for i in $(seq "$N"); do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST "$BASE/api/bookings" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$BODY" &
done | sort | uniq -c
wait
