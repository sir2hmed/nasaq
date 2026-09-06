# Testing strategy

Testing follows the phase gates in the build specification. No phase is marked
complete until its formatter/linter, tests, startup checks, main-flow exercise,
and documentation update pass.

## Test layers

- **Frontend:** Vitest and Testing Library for components/state/i18n/errors;
  Playwright for browser flows, direction, persistence, downloads, and mobile.
- **Laravel:** PHPUnit/Pest feature tests for auth, ownership, CRUD, validation,
  runs, callbacks, logs, approvals, integrations, and downloads; unit tests for
  validation and transitions.
- **Python:** pytest for graph validation, cycle detection, deterministic sort,
  contracts, demo providers, retry, artifacts, video, callbacks, and full agent
  execution.
- **Compose/integration:** health/readiness, PostgreSQL migrations, Redis broker,
  Laravel → FastAPI → Celery → callback path, and clean database/bootstrap.
- **Security/quality:** cross-user IDs, callback token failure, path traversal,
  input bounds, secret redaction, accessibility, RTL, and responsive viewports.

## Required scenarios

1. Register/login; create and reload Researcher → Writer → Export; run in demo;
   watch statuses; preview and download Markdown/PDF/DOCX; inspect logs.
2. Switch to Arabic/RTL; create Arabic workflow; produce readable Arabic exports.
3. Run Researcher → Writer(script) → Video → Approval → Publisher → Email in demo.
4. Trigger a transient provider failure, observe three attempts and failure,
   correct configuration, and rerun successfully.

## Commands

The root Windows command, including every cumulative live phase and the final
production-configuration gate, is:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\test-all.ps1
```

The cross-platform component command is:

```bash
./infrastructure/scripts/test-all.sh
```

Windows project-local runtimes and the complete Compose readiness gate:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase1.ps1
```

Phase 2 repeats the component and container gates, then exercises the real
Sanctum CSRF/session flow through registration, current-user lookup, locale
persistence, logout protection, login, and the frontend SPA fallback:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase2.ps1
```

Phase 3 adds real containerized workflow CRUD, exact graph reload, duplication,
cross-user ownership denial, soft-delete visibility, and SPA detail-route checks:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase3.ps1
```

Phase 4 adds live DAG validation, cycle rejection, exact visual-position reload,
required-configuration warnings, and the editor SPA route:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase4.ps1
```

Phase 5 adds deterministic Researcher → Writer → Export execution plus genuine
Markdown/PDF/DOCX artifact validation:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase5.ps1
```

Phase 6 adds the live Laravel → FastAPI callback flow, persisted node/log/output
state, authorized downloads, callback-token rejection, and run ownership:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase6.ps1
```

Phase 7 adds real Redis/Celery submission, response-time and responsiveness
checks, persisted retry attempts 2/3, and cooperative cancellation:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase7.ps1
```

Phases 8–12 extend that same cumulative gate through real provider contracts,
human review, FFmpeg video, encrypted integrations/idempotency, and the complete
seven-agent demo workflow:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase8.ps1
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase9.ps1
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase10.ps1
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase11.ps1
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase12.ps1
```

Phase 13 adds authorization, rate-limit, safe-artifact, localization,
accessibility, and mobile negative paths. Phase 14 adds offline clean-install
resolution and a disposable migrated/seeded database. Phase 15 adds production
Compose rendering, required deployment/demo/API documentation, JPEG screenshot
integrity, and the final evidence audit.

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase13.ps1
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase14.ps1
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase15.ps1
```

Use `-SkipDocker` only for native component verification when the container
engine is intentionally unavailable. It covers lint, unit, feature, build,
clean-database, and production-configuration structural checks, but does not
satisfy live acceptance.

The script expands by phase and ultimately runs all component, integration, E2E,
and clean-install checks. Windows Phase 0 also supports:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\verify-phase0.ps1
```

Credential-dependent real integration tests are opt-in and must never consume
credentials in normal CI. Implementations, validation, and missing-key behavior
are still covered automatically.
