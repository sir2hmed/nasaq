#!/usr/bin/env sh
set -eu

php artisan config:clear
php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
