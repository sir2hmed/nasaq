# Nasaq AI - Continuation Update

**Date:** 2026-08-15  
**Branch / commit:** `main`; no initial commit available (Git reports a safe-directory ownership restriction in this sandbox).  
**Phase:** Phase 1 implementation is partial pending browser acceptance.

## Implemented

- Laravel is the source of truth for real global LLM provider/model resolution. Celery requests the selected configuration for each run through the protected internal endpoint instead of choosing an environment provider.
- OpenAI and Gemini are constructed through the live normalized provider path; Gemini uses an `x-goog-api-key` header, never a URL query key.
- Default selection requires admin password confirmation and a configured/enabled provider; updates use row locking/transactional replacement.
- Admin connection tests now call a bounded protected FastAPI probe and record safe measured statuses. Existing credentials alone are never a success result.
- Admin system health is measured through Laravel/FastAPI health data. The worker Compose health check is Celery/Redis-aware and runtime healthy.

## Exact files changed

See the complete file list in `phase-1-provider-control-plane-implementation.md`. Key entry points: `backend-laravel/routes/api.php`, `InternalProviderResolutionController.php`, `ProviderProbeClient.php`, `AiProviderConfigurationController.php`, `PlatformIntegrationController.php`, `ai-service-python/app/tasks/executions.py`, `app/providers/factory.py`, `app/services/provider_resolution.py`, `app/api/provider_probe.py`, `frontend/src/pages/admin/AdminProvidersPage.jsx`, `AdminSystemPage.jsx`, and both Compose files.

## Database migrations

None added. Existing platform integration, provider configuration, and audit fields were used.

## API changes

- Added service-only `GET /api/internal/runs/{run}/provider-resolution`.
- Added admin-only `GET /api/admin/system`.
- Changed provider config update to require `confirm_password`.
- Changed provider test routes to return a measured safe status via the FastAPI probe.
- Added FastAPI `POST /internal/providers/probe`, service-token protected.

## Security decisions

- Raw provider keys stay encrypted at rest and are masked for admin APIs.
- Raw keys are transferred only in memory over the internal service-token contract; never in browser APIs, task messages, URLs, logs, audit records, or result metadata.
- Environment fallback defaults off and cannot override Laravel resolution.

## Tests run

- Laravel Pint: pass. Laravel tests: 65 passed / 447 assertions.
- Python Ruff: pass. Python tests: 47 passed, 1 skipped (host FFmpeg condition).
- Frontend: lint pass; 39 tests pass; production build pass.
- Compose configuration: pass.

## Docker/service health

FastAPI, Celery worker, Redis, and PostgreSQL are healthy after rebuild. The Celery worker is healthy using its new worker-specific health check. Laravel cannot start because its existing PostgreSQL volume rejects the configured `nasaq` password. The frontend waits on Laravel and is therefore not running.

## Verified / unverified

Verified with safe mocked boundary tests: selected OpenAI/Gemini model propagation, no Gemini key in URL, no default environment override, worker resolution before provider construction, masking/encryption, service-token endpoint protection, truthful probe result handling, and worker health.

Unverified: a paid provider response (not attempted), current browser acceptance (blocked by Laravel/PostgreSQL startup), and visual Arabic RTL confirmation of the updated provider page.

## Blocker and next phase

First resolve the local PostgreSQL credential/volume mismatch without resetting or deleting data. Then read `phase-1-provider-control-plane-implementation.md`, `docker-compose.yml`, `.env` (without printing secrets), `backend-laravel/app/Http/Controllers/AdminController.php`, and `frontend/src/pages/admin/AdminProvidersPage.jsx`; run browser acceptance in English and Arabic. After that, proceed to Phase 2 security/account lifecycle work.

## API Providers & Agent Routing redesign — 2026-08-25

Implemented the foundation of the requested per-agent provider control plane. The new migration creates a database-driven catalog, the eight requested agent categories, encrypted per-category configurations, routes with primary/fallback priority, connection-test history, and sanitized routing audit logs. `AiProviderRoutingController` exposes admin-only, password-confirmed endpoints to create, test, activate, reorder, and delete configurations. Only successful OpenAI/Gemini probe results can verify configurations; catalog entries without a truthful official execution adapter remain unavailable for activation.

The Admin Providers page was replaced with responsive routing cards, provider catalog, health summary, fallback ordering controls, and a secure configuration form in English and Arabic. Added docs: `docs/API_PROVIDER_CONTROL_PLANE_REDESIGN.md`, `docs/API_PROVIDER_TEST_MATRIX.md`, `docs/API_PROVIDER_SECURITY_NOTES.md`, and `docs/API_PROVIDER_IMPLEMENTATION_REPORT.md`, plus `AiProviderRoutingTest` feature coverage.

Local verification: frontend ESLint and production build passed. Docker Desktop was stopped during the Laravel build/migration/test attempt, so runtime migration, backend feature tests, and browser/RTL acceptance remain pending until Docker Desktop is started.

## Gemini API-key-only provider — 2026-08-25

Gemini now uses a single API-key-only configuration form with the official endpoint fixed internally. The catalog migration makes `gemini-3.7-flash` the recommended model and limits Gemini routing to text-oriented categories. Save & Test encrypts the key and performs the existing bounded real probe. Verified Writer routes are resolved by the worker through the central routing response and use verified fallbacks in priority order. See `docs/GEMINI_PROVIDER_IMPLEMENTATION.md` and `docs/GEMINI_PROVIDER_TEST_REPORT.md`.

## Provider configuration flow repair — 2026-08-25

The add/test/activate journey now persists the selected agent category, prepares CSRF protection for every routing mutation, and exposes verified fresh configurations in a Saved provider configurations panel where they can be activated. The initial form now selects Google Gemini and `gemini-3.7-flash`. See `docs/API_PROVIDER_ADD_TEST_USE_REPAIR_REPORT.md`, `docs/API_PROVIDER_END_TO_END_TEST_MATRIX.md`, and `docs/GEMINI_PROVIDER_CONFIGURATION_GUIDE.md`.
