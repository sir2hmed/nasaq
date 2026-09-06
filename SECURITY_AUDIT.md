# Security Audit

**Scope:** source, routes, configuration shape, native tests, and local Compose status. No secret value was read, displayed, or changed.

## Priority findings

| ID | Priority | Finding | Impact | Required remediation |
|---|---|---|---|---|
| SEC-01 | P0 | Admin-managed provider credentials/configuration are not used by the worker. | Operators can believe an encrypted credential/configuration is active when execution uses another environment secret or no provider. | Build one secure credential/provider resolver and test task-level consumption. |
| SEC-02 | P1 | Connection-test endpoints report success without an authenticated network probe; one defaults to success with unset `DEMO_MODE`. | False assurance and unsafe deployment decisions. | Bounded provider-specific read-only probe, timeout, redacted error code, and no optimistic success. |
| SEC-03 | P1 | Gemini code puts API key in query string. | Secrets can leak through URL/proxy/error telemetry if code is activated. | Move credential to secure header/SDK; ensure URL redaction. |
| SEC-04 | P1 | Celery worker health check targets a FastAPI liveness endpoint in the worker container. | False unhealthy alarms and unreliable orchestrator decisions. | Separate worker health check based on Celery/Redis readiness. |
| SEC-05 | P1 | Admin provider status uses different environment names than worker configuration; system UI uses static green badges. | Misleading security/operations posture. | Derive all displayed health/configuration from the same resolver/check. |
| SEC-06 | P2 | Provider default change lacks password confirmation and transaction/uniqueness protection. | Privileged configuration change has weaker assurance and race risk. | Step-up auth, transaction, enforced invariant, audit. |
| SEC-07 | P2 | Password reset, email verification, and account deletion were not found. | Incomplete account lifecycle controls. | Decide policy, then implement and test. |
| SEC-08 | P2 | No current browser/e2e security exercise of admin controls and no real provider-boundary test. | Regression could reintroduce unsafe UI claims. | Add API contract and browser tests. |

## Controls confirmed

- Sanctum authentication, server-side `admin` and active-account middleware, route throttles, and correlation IDs are configured.
- User workflow/run ownership is enforced in backend policies; frontend guards are supplementary.
- Public registration ignores role escalation.
- Platform integration credentials use Laravel encryption, API responses are masked, destructive credential actions require password confirmation, and audit records include action/context fields.
- Output artifacts are stored on private/shared storage and service callbacks require tokens.
- Source scan (excluding environment files and dependencies) found only test fixtures/documentation key-shaped strings, not an apparent live secret.

## Phase 1 remediation update

SEC-01 through SEC-05 are implemented and test-covered at the code/contract level. Laravel now resolves provider/model/credential only to the authenticated worker for a specific run; worker task messages never contain the credential. Gemini authentication moved to a request header. Admin tests no longer infer connectivity from credential presence, and measured system data replaces static green UI state. The rebuilt Celery worker reports healthy. Current browser acceptance and a real paid provider probe remain unverified due the local Laravel/PostgreSQL startup blocker and intentional no-cost policy.

## Residual risks and boundary

This is not a penetration test or production infrastructure assessment. No production systems, paid API calls, customer data, or secrets were used. Passing unit/feature tests does not validate outbound provider security or the current admin UI behavior.
