# NASAQ AI Phase 1 - Truthful Provider Control Plane

**Date:** 2026-08-15  
**Status:** Partially complete: implementation and automated boundary verification complete; browser acceptance blocked by an existing local PostgreSQL credential mismatch.

## Objective

Make Laravel's admin-managed global provider/model configuration the authoritative input for real Celery workflow execution, without putting credentials in browser responses, task payloads, URLs, logs, or persisted run metadata.

## Findings addressed

- P0: independent worker environment provider selection.
- P1: inactive Gemini path, query-string credentials, optimistic connection tests, false-green system state, and an inappropriate worker health check.

## Architecture

### Before

```text
Admin UI -> Laravel provider tables         (not used by worker)
Celery -> Python Settings -> LLM_API_KEY/LLM_PROVIDER -> OpenAI only
```

### After

```text
Admin -> Laravel encrypted PlatformIntegration + AiProviderConfiguration
Run starts -> Celery task -> service-token GET /api/internal/runs/{run}/provider-resolution
Laravel validates enabled default + decrypts only for response -> worker memory
Worker -> normalized OpenAI or Gemini adapter -> sanitized workflow events
```

The per-run resolution endpoint is protected by the existing internal callback service token, returns `Cache-Control: no-store, private`, and is not a browser route. A real run with no enabled/configured default returns a safe `unconfigured` response; the worker then constructs an unavailable provider rather than falling back silently. Demo mode remains explicit and deterministic. Environment LLM settings can only be used with the explicit development flag `ALLOW_ENVIRONMENT_PROVIDER_FALLBACK=true`; its default is false and it never overrides a Laravel resolution.

## Laravel changes

- Added `InternalProviderResolutionController` and the service-only `GET /api/internal/runs/{run}/provider-resolution` endpoint.
- Provider configuration now starts unconfigured/disabled, links to its platform integration, requires password confirmation to select a global default, locks provider rows in a transaction, and permits only an enabled, credentialed provider to become default.
- Credential updates synchronize the LLM configuration link; disabling/removing credentials removes default/execution eligibility.
- Added `ProviderProbeClient`: admin provider testing makes a bounded internal FastAPI probe, records only a safe status/message/timestamp, and never treats a present credential as success.
- Added measured `GET /api/admin/system`; the UI no longer renders fixed green service badges.

## FastAPI/Celery changes

- Added `ProviderResolutionClient`, invoked by the Celery task immediately before live provider-bundle construction. Raw keys are not included in the Laravel-to-FastAPI task submission or Celery message.
- Added `ResolvedLanguageModelProvider` to adapt the shared normalized OpenAI/Gemini clients to the existing writer contract.
- Added protected `POST /internal/providers/probe` for low-cost, timeout-limited provider checks. It returns only safe categories: reachable, unreachable, authentication_failed, rate_limited, unsupported, unknown, or unconfigured.
- Gemini now uses `x-goog-api-key` request headers; its URL contains no API key or query credential.
- The FastAPI readiness endpoint measures Redis and Celery control-ping state.

## Secret-handling design

Credentials remain Laravel-encrypted at rest and are masked in every admin API response. The only plaintext transfer is the service-token-authenticated, non-cacheable Laravel-to-worker resolution/probe request over the internal service path. Providers keep keys in memory. Task payloads, callbacks, audit records, API responses, URLs, exceptions, and display status contain provider/model/state only.

## Environment and health rules

- Production/default real runs: Laravel provider configuration is authoritative.
- Demo runs: only the submitted explicit demo mode selects deterministic providers.
- Development fallback: disabled unless `ALLOW_ENVIRONMENT_PROVIDER_FALLBACK=true` is deliberately set.
- FastAPI uses its web liveness check. Compose overrides Celery with `celery inspect ping` against Redis instead of an HTTP localhost check.

## UI changes

The provider-default form requires administrator password confirmation. Provider cards show configured/enabled status, safe last test state, and whether each LLM is currently available to execute. The system page consumes `GET /api/admin/system` and renders measured `reachable`/`unreachable` values rather than hard-coded healthy labels. Existing LTR/RTL container direction and admin route protection remain in place.

## Files created

- `backend-laravel/app/Http/Controllers/InternalProviderResolutionController.php`
- `backend-laravel/app/Services/ProviderProbeClient.php`
- `backend-laravel/tests/Feature/ProviderResolutionTest.php`
- `ai-service-python/app/services/provider_resolution.py`
- `ai-service-python/app/providers/resolved_llm.py`
- `ai-service-python/app/api/provider_probe.py`
- `ai-service-python/tests/test_provider_control_plane.py`
- This implementation record.

## Files changed

`routes/api.php`, provider/admin controllers and tests, Python settings/task/factory/providers/health/app factory/tests, React admin API/provider/system pages, both Compose files, and `.env.example` files. No database migration was required: the existing provider/integration relations and test-result fields support this phase.

## Tests and commands

- Laravel: 65 passing tests / 447 assertions; Pint passes.
- Python: Ruff passes; 47 tests pass and 1 FFmpeg-related test is skipped.
- Frontend: ESLint passes; 39 Vitest tests pass; production build passes.
- Compose configuration validates.
- Provider boundary tests prove selected OpenAI/Gemini models, normalized responses, no Gemini key in request URL, no default environment override, worker resolution-before-bundle behavior, and service-token-only Laravel resolution.

## Runtime and browser verification

After `docker compose up --build --detach`, FastAPI, Redis, PostgreSQL, and Celery were healthy. The Celery worker's health check is now healthy. Laravel exited because the existing PostgreSQL data volume rejected the configured `nasaq` password; frontend dependency startup therefore did not occur. Browser acceptance of the updated admin UI could not be run. No paid OpenAI/Gemini call was made.

## Acceptance criteria

| Criterion | Status |
|---|---|
| Laravel is authoritative for real provider selection | Verified by source and contract tests |
| Celery resolves provider/model per run | Verified with mocked task contract |
| OpenAI/Gemini shared contract and selected model | Verified with mocked transports |
| No Gemini credential in URL | Verified with mocked Gemini request |
| Masking/encryption/service-only resolution | Verified by Laravel tests |
| Truthful connection results | Verified through mocked internal probe; no paid call |
| Measured admin status | Implemented and source-tested |
| Worker health check | Runtime verified healthy |
| Browser admin acceptance / RTL visual check | Blocked by local Laravel/PostgreSQL startup failure |

## Remaining risks and next phase

Resolve the local PostgreSQL credential/volume mismatch without exposing secrets or discarding data, then run current browser acceptance in English and Arabic. Next, Phase 2 should cover account lifecycle/security hardening and formalize CI/browser coverage.
