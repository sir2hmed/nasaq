# Definition of Done evidence

Evidence is cumulative: each phase verifier executes every earlier phase. Real
paid-provider calls are opt-in; normal gates exercise exact provider/SMTP upload
contracts, safe missing-key behavior, and deterministic demo execution.

| # | Requirement | Evidence | Result |
|---:|---|---|---|
| 1 | Website starts from documented instructions | README clean start; Phase 1 and final live Compose health | Pass |
| 2 | Register and log in | Laravel auth features; live Sanctum gate; browser login | Pass |
| 3 | English and Arabic work | Complete locale catalogs and frontend/browser checks | Pass |
| 4 | RTL works | `html` direction persistence, mobile no-overflow browser check, screenshot | Pass |
| 5 | Create workflow | Workflow API/UI tests and live CRUD gate | Pass |
| 6 | Drag agents to canvas | React Flow drag payload/drop implementation and editor tests | Pass |
| 7 | Connect agents | Compatibility/cycle tests and live graph acceptance | Pass |
| 8 | Configure nodes | Localized per-agent forms and editor save tests | Pass |
| 9 | Save workflow | Policy-protected update API and UI test | Pass |
| 10 | Reload preserves workflow | Exact graph/position reload plus persisted browser fixture | Pass |
| 11 | Validate workflow | Shared client/server DAG and semantic validators | Pass |
| 12 | Run workflow | Owner-authorized run endpoint and demo/real controls | Pass |
| 13 | Laravel → FastAPI execution | Phase 6+ protected live execution gate | Pass |
| 14 | Long execution is asynchronous | Celery/Redis queue, responsiveness, retry, cancellation gate | Pass |
| 15 | Statuses update in UI | Polling/node statuses and live callback/browser evidence | Pass |
| 16 | Logs are persisted | Ordered PostgreSQL logs, API tests, and reload evidence | Pass |
| 17 | Researcher demo works | Deterministic source-preserving provider tests/live workflow | Pass |
| 18 | Writer demo works | Deterministic structured handoff tests/live workflow | Pass |
| 19 | Actual Markdown/PDF/DOCX | File signatures, MIME, checksums, and authorized downloads | Pass |
| 20 | Real provider mode with valid keys | Exact Tavily/OpenAI/TTS contract tests and explicit opt-in path | Pass |
| 21 | Real MP4 | FFmpeg H.264 1280×720 live gate with ffprobe | Pass |
| 22 | Human review pause/resume | Durable approval/reject tests and live continuation gate | Pass |
| 23 | Publisher and Email demo | Clearly simulated, persisted, idempotent full workflow | Pass |
| 24 | Real integrations when configured | Exact Drive/YouTube multipart and SMTP contract tests; encrypted credential path | Pass |
| 25 | Cross-user access blocked | Policy, run/output/integration negative tests and live owner isolation | Pass |
| 26 | Core automated tests pass | Final cumulative verifier | Pass |
| 27 | Clean-install instructions correct | Offline resolution plus disposable migrated/seeded database | Pass |
| 28 | README explains architecture/setup | README and architecture/deployment/API docs | Pass |
| 29 | No core dead button | UI interaction tests, browser navigation/run/output checks, disabled unavailable actions | Pass |
| 30 | No hidden critical error | Error boundary, localized recovery, correlation IDs, safe logs, zero browser errors | Pass |

Operationally, items 20 and 24 still require deployment-owned valid credentials,
provider consent, quota, and billing. The automated suite deliberately never
consumes such credentials; deployment smoke tests must use private/unlisted
destinations as documented.
