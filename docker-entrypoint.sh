#!/bin/sh
set -e
php artisan db:seed --force      # create indexes + seed sandboxes (idempotent)
exec php artisan serve --host=0.0.0.0 --port=8000
