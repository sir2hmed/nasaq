# Requirements Traceability

Status vocabulary: **Implemented** = execution or server behavior evidenced; **Partial** = material real portion with gap; **Mock only** = UI/test/static simulation without claimed integration; **Not implemented** = no credible implementation path found.

| Requirement | Baseline | Status | Evidence and decision |
|---|---|---|---|
| FR-01 Visual orchestration | M2 | Implemented | Workflow/node/edge models, graph validation, React workflow routes and native tests. |
| FR-02 Multi-agent library | M2 | Partial | Agent registry/orchestrator and Researcher/Writer/Export paths exist; newer generic LLM abstraction is not compatible with current writer contract or live bundle. |
| FR-03 Multi-modal generation | M2 | Partial | Real Markdown/PDF/DOCX and video paths/test coverage found; broad image/media generation is not verified. |
| FR-04 File transformation | M2 | Partial | Export formats exist; a general transform service/catalogue is not evidenced. |
| FR-05 API integration | M2 | Partial | OpenAI Responses and Tavily paths exist through worker settings; Google Drive/YouTube/email have contracts/demo paths; admin connection tests are synthetic. |
| FR-06 Fault tolerance | M2 | Partial | Retry, cancellation, idempotency, callbacks, and queue settings exist; worker health is broken and no dead-letter/production resilience proof found. |
| NFR-01 Performance | M2 | Partial | Async worker architecture exists; no current load/performance evidence. |
| NFR-02 Reliability | M2 | Partial | Queue/retry patterns exist; false worker health and unverified external-provider behavior prevent completion. |
| NFR-03 Usability | M2 | Partial | Protected/public routes and bilingual UI foundation exist; current admin acceptance/accessibility not verified. |
| NFR-04 Maintainability | M2 | Partial | Clear service separation and tests, but duplicate/dead provider abstractions and uncommitted workspace reduce maintainability. |
| User/admin roles | Approved post-M2 | Implemented | DB role/status, server middleware, tested privilege and last-admin protection. |
| Admin user management | Approved post-M2 | Partial | Role/status endpoints and pages exist; broader account lifecycle is missing. |
| Secure API key management | Approved post-M2 | Partial | Encryption, masking, password confirmation and audit entries exist; worker cannot consume saved keys and tests are synthetic. |
| Global provider selection | Approved post-M2 | Implemented (browser pending) | Laravel resolves the enabled default per run through a protected service contract; mocked task contract tests prove selected provider/model reaches the live bundle. |
| Per-agent provider/model selection | Approved post-M2 | Not implemented | No live resolution from workflow agent/node settings to a selected provider found. |
| Per-workflow provider/model selection | Approved post-M2 | Not implemented | Same disconnection; current worker uses global environment `LLM_PROVIDER`/`LLM_MODEL`. |
| OpenAI provider | Approved post-M2 | Partial | Selected resolved OpenAI model is exercised through mocked live contract; paid execution intentionally not run. |
| Gemini provider | Approved post-M2 | Partial | Selected resolved Gemini model is exercised through mocked live contract, and API key is header-only; paid execution/browser acceptance pending. |
| Auditable secure administration | Approved post-M2 | Partial | Integration audit records/server controls, safe probe statuses, and measured status API are implemented; current browser verification is blocked by local DB authentication. |

## Requirement-to-test gap

Automated tests prove many route, workflow, and persistence contracts, but none proves a real or mocked-at-HTTP-boundary selected admin provider is used by a Celery task. The existing provider tests exercise constructor/shape logic and synthetic Laravel success responses. This is the essential missing acceptance test.
