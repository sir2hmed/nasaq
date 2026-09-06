# Nasaq AI Laravel API

Laravel 13 is the public application, authorization, and persistence boundary.
It exposes dependency-aware `/api/health` plus Phase 2 registration, login,
logout, current-user, and locale endpoints using Sanctum's first-party SPA
session and CSRF protection. Workflows and later resources remain phase-gated.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
vendor/bin/pint --test
php artisan test
php artisan serve --host=0.0.0.0 --port=8000
```
