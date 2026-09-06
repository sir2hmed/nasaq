# Recovery Implementation Roadmap

This roadmap follows the audit priority order. It is deliberately not implementation work; use it as the controlled recovery backlog.

## Phase 1 — Truthful provider control plane (P0/P1)

**Goal:** make the selected OpenAI/Gemini configuration and its credentials genuinely determine task execution.

1. Choose one source of truth for provider, model, enabled status, and credential reference. Do not keep independent Laravel and environment selections without a documented precedence rule.
2. Create a secure worker-facing resolution contract. The worker must receive only the selected configuration/short-lived secret reference; never expose keys to browser clients or logs.
3. Refactor the live `build_provider_bundle` path to use that contract. Delete or integrate duplicate provider abstractions only after tests protect behavior.
4. Wire OpenAI and Gemini through the same normalized interface. Move Gemini authentication out of URL query parameters.
5. Replace optimistic connection tests with a low-cost/read-only provider-specific probe, short timeout, redacted failures, and a distinct `unconfigured` state.
6. Make the admin status and system-health pages consume measured data. Repair the Celery health check to verify worker/Redis readiness rather than FastAPI localhost.
7. Add a transaction/invariant for exactly one platform default and require password confirmation for provider-default changes.

**Exit criteria:**

- A test configuration selected in admin causes a queued non-production task to use that exact provider/model, demonstrated with HTTP transport mocks and a controlled integration test.
- Disabling/unconfigured/failed credentials blocks or clearly fails execution; UI reflects it without false green results.
- OpenAI and Gemini outbound requests have no secret in URL/log output.
- Container health is green only when its real responsibility is ready.

**Implementation update (2026-08-15):** code and automated contract acceptance are complete. The remaining Phase 1 gate is browser acceptance of the admin provider/system pages in English and Arabic after resolving the local PostgreSQL credential/volume mismatch. Do not reset the volume as a shortcut.

## Phase 2 — Security and account lifecycle (P1/P2)

- Implement the agreed reset, verification, session/security-notification, and account-deletion policy.
- Review authorization around all admin changes; add step-up authentication and complete audit events.
- Add request-size/upload validation, retention policy, secret rotation process, and production environment validation.
- Establish Git history: create an intentional initial commit, define binary/archive policy, and move the 1.13 GB archive out of the working tree or into approved release storage.

**Exit criteria:** threat-model review, feature/browser tests for privileged flows, secrets scan in CI, and documented operations runbook.

## Phase 3 — Requirement completion and quality (P2)

- Complete per-agent/per-workflow provider and model semantics after Phase 1 resolver exists.
- Decide and implement scope for file transformation/multi-modal capabilities required by M2.
- Add accessibility, localization, error-state, load, queue-recovery, and browser acceptance tests.
- Reconcile documentation/screenshots/DoD with verified evidence only.

**Exit criteria:** each remaining M2/approved traceability row is Implemented or formally de-scoped with supervisor approval.

## Phase 4 — Release readiness (P2/P3)

- Run non-production Compose/browser acceptance against the repaired admin code.
- Perform performance, failure, backup/restore, and security testing; use real provider keys only in a controlled non-production account with budget controls.
- Produce a deployment checklist, monitoring/alerting runbook, rollback plan, and final evidence pack.

**Exit criteria:** all P0/P1 findings closed; no false operational indicators; substantive DoD signed off with reproducible evidence.
