# File Inventory and Repository State

**Inventory date:** 2026-08-15. This is a functional inventory, not a byte-for-byte manifest.

## Repository state

- Current branch: `main`.
- No initial Git commit was found; the working tree consists of untracked material. This prevents trustworthy change provenance and should be remediated before recovery work begins.
- `.env` is present and ignored. Its values were not read or exposed.
- `GradProjectVersion1.zip` is approximately 1.13 GB at repository root and untracked. It is a repository-hygiene risk; it was not opened, removed, or altered.

## Major directories

| Path | Purpose | Audit judgement |
|---|---|---|
| `frontend/` | React/Vite UI, routes, pages, admin screens, component tests | Real UI; admin provider truthfulness needs repair. |
| `backend-laravel/` | Laravel API, auth, policies, migrations, feature tests | Core controls substantial; admin/provider control plane disconnected. |
| `ai-service-python/` | FastAPI, orchestration, agents, providers, Celery tasks, tests | Real worker path; duplicate/dead LLM adapter path needs consolidation. |
| `infrastructure/` | test/phase scripts and deployment helpers | Useful automation; worker health configuration defect. |
| `docs/` | architecture/contracts/testing/DoD material | Existing material needs reconciliation with this audit. |
| `docker-compose.yml` | local multi-service topology | Valid composition; worker inherits incorrect health check. |
| `docker-compose.prod.yml` | production-oriented topology | Present; not deployed or live-verified in this audit. |
| `.env.example` | safe configuration template | Uses `LLM_API_KEY`/`SEARCH_API_KEY`, unlike one admin status endpoint. |

## Key implementation files reviewed

- `backend-laravel/routes/api.php`, `bootstrap/app.php`, auth/admin middleware, user/policy/model code, provider/integration controllers and migrations.
- `ai-service-python/app/tasks/executions.py`, `app/providers/factory.py`, `llm.py`, `provider_factory.py`, OpenAI/Gemini adapters, orchestration and health files.
- `frontend/src` routes, admin pages/layout/route guard and associated tests.
- Compose, Dockerfile, phase/test scripts, requirements/DoD documentation.

## Deliberately excluded

Ignored secrets, dependency directories, caches, binary ZIP contents, production systems, and paid external provider calls were not treated as source evidence. No attempt was made to alter any of them.
