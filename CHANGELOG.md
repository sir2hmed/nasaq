# Changelog

All notable changes are recorded here. The project follows Keep a Changelog
conventions and uses semantic versioning once the first release is tagged.

## [Unreleased]

### Added

- Phase 0 monorepo foundation.
- Requirements traceability matrix derived from the Milestone 2 report.
- Architecture, API, agent-contract, bilingual UI, testing, deployment, and
  naming documentation.
- Versioned workflow JSON Schema and a sample Researcher → Writer → Export graph.
- Environment templates and a Docker Compose service topology skeleton.
- React 19/Vite frontend foundation with responsive Nasaq branding and a sample
  Research → Write → Export workflow presentation.
- Laravel 13 API foundation with health, database, Redis, and CORS checks.
- FastAPI foundation with liveness/readiness endpoints, restricted CORS, Redis
  health checks, and a Celery worker boundary.
- Reproducible service containers, PostgreSQL and Redis services, project-local
  development runtimes, and an end-to-end Phase 1 verifier.
- Sanctum SPA registration, login, logout, current-user, CSRF, and persisted
  preferred-locale endpoints with consistent JSON error envelopes.
- A protected bilingual React application shell, centralized API/session client,
  responsive landing/auth/dashboard pages, and full English/Arabic catalogs.
- An end-to-end Phase 2 verifier covering real browser-style CSRF, authentication,
  session persistence, locale persistence, logout, login, and SPA fallback.
- PostgreSQL workflow, agent-node, and edge migrations with JSONB snapshots,
  soft deletion, synchronized reporting rows, models, factories, and policies.
- Owned workflow list/create/read/update/delete/duplicate APIs, strict graph
  validation, a Researcher → Writer → Export seeder, and consistent authorization.
- Bilingual persisted workflow dashboard/list/detail UI with starter creation,
  duplication, deletion, metadata editing, and exact graph restoration.
- A Phase 3 container gate covering live CRUD, reload, ownership isolation,
  duplication, soft deletion, and direct SPA detail-route fallback.
- A lazy-loaded React Flow editor with agent library drag/add, node selection,
  movement, compatible connections, bilingual configuration, duplicate/delete,
  fit view, validation drawer, exact persistence, and unsaved-change protection.
- Shared client/server DAG validation covering cycles, self-loops, missing and
  dangling nodes, compatible contracts, executable starts, and config warnings.
- A Phase 4 live gate and real-browser pass across English, Arabic RTL, persisted
  node configuration, responsive width, and console health.
- Strict Python agent/result/execution contracts, an explicit registry,
  deterministic DAG validation and topological ordering, structured handoffs,
  safe logs, and normalized failures.
- Deterministic demo Researcher and Writer providers whose sources and content
  are visibly marked simulated and require no paid credentials.
- A real Export agent generating Markdown, PDF, and DOCX files with scoped paths,
  ownership/run/node metadata, MIME types, sizes, and SHA-256 checksums.
- A development-only synchronous FastAPI execution endpoint and a Phase 5 gate
  that validates successful execution and failure responses inside the container.
- Immutable idempotent workflow runs, protected FastAPI-to-Laravel callback
  events, persisted execution logs/node states/output metadata, and authorized
  artifact streaming from shared private storage.
- A bilingual polling timeline in the visual editor with live node status,
  Writer previews, real Markdown/PDF/DOCX download links, and explicit demo-mode
  labeling.
- A Phase 6 live gate covering callback authentication, event ordering, complete
  execution persistence, real downloads, and cross-user run isolation.
- Real Celery task submission through Redis with task-start tracking, late
  acknowledgement, one-task prefetching, and correlated immutable payloads.
- A three-attempt transient retry policy with exponential backoff, jitter,
  user-visible attempt logs, classified provider failures, and deterministic
  demo failure injection for acceptance testing.
- Redis-backed cooperative cancellation, an owner-authorized Laravel cancel
  endpoint, persisted cancellation intent, and a bilingual non-blocking UI action.
- A Phase 7 live gate proving quick queue acknowledgement, worker execution,
  responsive APIs during backoff, retry visibility, and cancellation completion.
- Real Tavily and OpenAI-compatible provider adapters with strict contracts,
  source-preserving Writer handoffs, explicit demo/real runs, TTS, safe
  credential failures, and an opt-in live-provider check.
- Durable human approval requests, owner-authorized approve/reject decisions,
  immutable-run resume, preview UI, and a live no-side-effect rejection gate.
- A deterministic slide renderer and safe FFmpeg/ffprobe boundary producing a
  real H.264 MP4 with optional TTS, private inline playback, and download.
- Encrypted-at-rest owner integration connections for Google Drive, YouTube,
  and SMTP, including redacted APIs and a token-protected worker credential
  boundary that keeps secrets out of Redis task payloads.
- Official multipart Drive/YouTube adapter shapes, validated SMTP delivery,
  deterministic reserved-domain simulations, email previews, external URL/ID
  persistence, and Redis run/node side-effect idempotency.
- Publisher and Email agents in the visual library, localized configuration,
  Video-to-Export production notes, and five independently copyable templates,
  including a seven-agent full-product demo with human review.
- Rich safe output cards for research sources, text copy/download, file facts,
  HTML video playback, publication IDs/URLs, email delivery state, and demo badges.
- Sequential Phase 8–12 verification scripts and expanded provider, integration,
  template, idempotency, approval, video, and complete-workflow tests.

- Phase 13 quality/security hardening with owner-bound negative paths, semantic
  configuration validation, rate limits, safe artifact roots/MIME types,
  correlation IDs, error recovery, accessibility, Arabic parity, and mobile UI.
- Phase 14 browser and clean-install acceptance, real dashboard run metrics,
  latest-run restoration, deterministic browser fixtures, and a disposable
  migrated/seeded database gate.
- Phase 15 production-style gateway/private-network Compose configuration,
  required secrets, authenticated Redis, deployment/backup/rollback guidance,
  final demo and Definition-of-Done evidence, and verified product screenshots.
- Localized agent search/categories, visible drag affordances, and a functional
  editor More menu for the final workflow-editor specification.

### Verified

- Phase 0 structural verification passes in PowerShell and the cross-platform
  root test wrapper.
- Frontend lint, unit test, and production build pass.
- Laravel formatting and feature tests pass (10 tests, 59 assertions).
- FastAPI lint, formatting, and tests pass (3 tests).
- Native health smoke tests pass for frontend, Laravel, and FastAPI.
- Docker Compose builds and runs all six services with healthy PostgreSQL, Redis,
  Laravel, FastAPI, and frontend checks.
- Phase 2 passes 8 frontend tests and the real containerized Sanctum acceptance
  flow for registration, session restoration, locale, logout, and login.
- Phase 3 passes 11 frontend tests, 16 Laravel tests (108 assertions), native
  production builds, and the real PostgreSQL workflow acceptance flow.
- Phase 4 passes 17 frontend tests, 18 Laravel tests (118 assertions), the live
  DAG/position/configuration gate, and the interactive browser acceptance pass.
- Phase 5 passes 11 Python tests and the full prior suite; its live container gate
  verifies deterministic three-node execution, real file signatures/checksums,
  persisted metadata, and structured cycle rejection.
- Phase 6 passes 18 frontend tests, 27 Laravel tests (174 assertions), 15 Python
  tests, its full live-service gate, and interactive English/Arabic desktop/mobile
  acceptance with no overflow or browser warnings/errors.
- Phase 7 passes 19 frontend tests, 30 Laravel tests (197 assertions), 19 Python
  tests, and its full live gate; submission returned in 160 ms and persisted
  retries 2/3 before success, while a separate run reached cancelled.
- Phase 8–10 full live gates pass, including real-mode safe failure, approval
  resume/rejection, and a probed owner-only H.264 MP4.
- The Phase 12 native chain passes 28 frontend tests, 41 Laravel tests (299
  assertions), 36 Python tests plus one host-only FFmpeg skip, all format/lint
  checks, and the production frontend build.
- Phase 11/12 live Compose gates pass, including encrypted integration state,
  worker-only credential access, idempotent side effects, human review, and the
  complete seven-agent workflow with all expected artifacts.
- The Phase 14 native chain passes 35 frontend tests, 46 Laravel tests (343
  assertions), 37 Python tests plus one host-only FFmpeg skip, clean offline
  dependency resolution, a clean database bootstrap, and desktop/mobile RTL
  browser acceptance with no console warnings or errors.
- The uninterrupted Phase 0–15 live gate passes all service, workflow, security,
  clean-install, production Compose, screenshot-integrity, documentation, and
  30-point Definition-of-Done checks.
