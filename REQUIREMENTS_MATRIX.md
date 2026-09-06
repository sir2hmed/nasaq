# Requirements traceability matrix

This matrix treats the May 2026 Milestone 2 report as the academic source of
truth. Implementation details come from the Master Build Specification when the
report is abstract. Every requirement is linked to a planned implementation and
verification gate.

## Academic intent and scope

| ID | Report source | Requirement | Planned implementation | Verification |
|---|---|---|---|---|
| INT-01 | pp. 9–11 | Reduce fragmented content work and context switching | One workflow links research, creation, export, publishing, and communication | End-to-end scenarios A–C |
| INT-02 | pp. 9–10 | Make agentic AI usable by non-technical users | No-code visual canvas, localized configuration forms, templates | Canvas E2E test; usability QA |
| INT-03 | p. 10 | Modular specialized agents with structured handoffs | Registry-backed BaseAgent contract and normalized results | Agent contract/unit tests |
| INT-04 | p. 10 | Visual drag-and-drop workflow creation | React Flow editor with persistent positions and edges | Phase 4 component/E2E tests |
| INT-05 | p. 10 | Integrate YouTube, Drive, and Gmail | Encrypted user connections and idempotent provider adapters | Phase 11 demo and credential-gated real tests |
| INT-06 | p. 10 | Reliability through retries and logs | Celery retries, exponential backoff/jitter, persistent structured events | Retry/failure integration tests |
| INT-07 | p. 11 | Empower Saudi creators, educators, and small businesses | Full Arabic UI/output and approachable workflow templates | Arabic scenario and responsive QA |
| LIT-01 | pp. 12–14 | Combine multi-agent reasoning, tools, files, and visual LCNC interaction | Three-layer architecture with artifact-producing agents | Architecture review and full demo |
| SCP-01 | p. 15 | Researcher, Writer, Video, Publisher, and Email agents are in scope | Five report agents plus practical Export and Approval utilities | Agent registry and template tests |
| SCP-02 | p. 15 | Text, images/thumbnails, scripted video | Writer text, Video slides/thumbnails, FFmpeg MP4 | Artifact tests |
| SCP-03 | p. 15 | PDF, DOCX, and MP4 output | Export service and Video agent | File signatures/MIME/playback checks |
| SCP-04 | p. 15 | Advanced video editing is out of scope | Deterministic slide-based rendering only | Documented limitation; MP4 acceptance |
| SCP-05 | p. 15 | Real-time voice/chat workflow creation is out of scope | No voice/chat builder | Scope audit |
| SCP-06 | p. 15 | Complex multi-user permission systems are out of scope | Guest/authenticated user only; strict per-owner authorization | Cross-user access tests |

## Functional requirements

| ID | Report source | Academic requirement | Implementation mapping | Acceptance evidence |
|---|---|---|---|---|
| FR-01 | p. 20 | Visual orchestration without code | React Flow drag/drop, move, connect, configure, validate, save/reload | Exact graph round-trip; cycle rejection; E2E scenario A |
| FR-02 | p. 20 | Preconfigured multi-agent library | Researcher, Writer, Video, Publisher, Email; Export/Approval utilities | Library rendering and config-schema tests |
| FR-03 | p. 20 | Multi-modal text, image/thumbnail, and video generation | Structured text outputs, generated scene frames, MP4 | Output previews and artifact checks |
| FR-04 | p. 20 | Transform content to PDF, DOCX, and MP4 | Server-side artifact service; Markdown also supported | Actual downloadable files with signatures and sizes |
| FR-05 | p. 20 | YouTube, Drive, Gmail/SMTP integrations | Provider interfaces, encrypted credentials, idempotency keys | Simulated demo tests and credential-gated real smoke tests |
| FR-06 | p. 20 | Retry failed API operations and log execution | Three attempts, exponential backoff/jitter, non-retryable error classification | Scenario D and retry unit/integration tests |

## Quality requirements

| ID | Report source | Academic requirement | Implementation mapping | Acceptance evidence |
|---|---|---|---|---|
| NFR-01 | p. 21 | Long work is asynchronous and UI remains responsive | FastAPI acceptance endpoint, Celery/Redis worker, React polling | Run endpoint latency and active-run UI tests |
| NFR-02 | p. 21 | Reliable agent handoffs; 99% target | Schema-validated outputs, persisted events, metrics for measured handoff success | Contract tests and observable metric; no unmeasured claim |
| NFR-03 | p. 21 | Non-technical usability | Templates, plain-language forms/errors, no-code canvas | Usability checklist and final scenarios |
| NFR-04 | p. 21 | New agents require minimal core changes | Agent registry + declarative UI/config/input/output definitions | Test-only agent extension proof |
| NFR-05 | pp. 18, 25 | At most three execution attempts | Central retry policy with transient/permanent classification | Retry count/log assertions |
| NFR-06 | pp. 18, 24 | Human validation before publication | Approval requests and resumable waiting state | Approve/reject integration tests |
| NFR-07 | pp. 18, 24 | Complete success/failure/retry logging | Correlated run/node event timeline | Logs API and UI tests |
| NFR-08 | Build spec | Accessibility and responsive use | Semantic controls, focus states, text+icon status, mobile run/output layouts | Automated accessibility and viewport tests |
| NFR-09 | Build spec | English/Arabic and RTL/LTR | Translation catalogs, persisted locale, document direction | Direction/persistence and Arabic E2E tests |
| NFR-10 | Build spec | Security and ownership | Laravel policies, encrypted secrets, protected callbacks/downloads, rate limits | Security/cross-user feature tests |

## Agents and handoffs

| Agent | Report source | Inputs | Outputs | Tools/modes | Primary consumer |
|---|---|---|---|---|---|
| Researcher | pp. 16, 23 | Topic, source count, language, optional range/depth | Summary, key points, source metadata | Demo fixtures or real search provider | Writer |
| Writer | pp. 16, 23 | Research, style, length, format, language | Title, content/script, citations, word count | Demo templates or real LLM | Export, Video, Publisher |
| Video | pp. 16, 23 | Script, scene limits, language | MP4, scene metadata, optional narration | FFmpeg; optional TTS; local demo fallback | Export, Publisher |
| Publisher | pp. 16, 23 | Artifact, provider target, approval policy | Provider ID and share/watch URL | Simulated demo or Drive/YouTube | Email |
| Email | pp. 16, 23 | Recipients, subject, body/link, attachments | Delivery status/preview | Simulated demo or Gmail/SMTP | Terminal |
| Export | pp. 15, 20 | Writer or other text output | Markdown, PDF, DOCX artifacts | Real local/object-storage files in both modes | User/download |
| Approval | pp. 18, 24 | Previewable predecessor output | Approved/rejected decision | Human action | Publication-capable node |

## Use cases

| ID | Report source | Use case | API/UI realization | Test |
|---|---|---|---|---|
| UC-01 | p. 24 | Create workflow | Workflow editor + CRUD API | Create/save/reload E2E |
| UC-02 | p. 24 | Execute workflow | Run API → FastAPI/Celery → callbacks | Core full-stack E2E |
| UC-03 | p. 24 | Configure agent | Localized schema-based side panel | Config validation tests |
| UC-04 | p. 24 | Review output | Safe text/source/file/video previews | Preview component/E2E tests |
| UC-05 | p. 24 | Publish content | Approval gate + provider adapters | Demo/real integration tests |
| UC-06 | p. 24 | View execution logs | Correlated persisted timeline | Logs endpoint/UI tests |

## Architecture, data, and UI traceability

| ID | Report source | Design requirement | Adopted decision |
|---|---|---|---|
| ARC-01 | pp. 22–23 | Three independent layers | React UI; Laravel product/API layer plus FastAPI orchestration; modular agent/provider execution |
| ARC-02 | pp. 22–23 | REST boundary and DAG validation | React talks only to Laravel; Laravel sends immutable workflow snapshot to protected FastAPI API |
| ARC-03 | pp. 17, 22–23 | Celery/Redis async processing | Redis broker/result state and Celery workers; polling first |
| ARC-04 | pp. 25, 37–38 | Topological processing, structured context, max-three retry | Deterministic graph validator/sorter/executor and centralized retry policy |
| DAT-01 | pp. 26–28 | Users, workflows, nodes, logs, outputs | Laravel migrations plus runs, edges, connections, approvals; owner keys/indexes |
| DAT-02 | pp. 26–28 | Flexible unstructured agent results | PostgreSQL JSONB and authorized artifact storage; MongoDB omitted to reduce coupling/deployment burden |
| UI-01 | pp. 28–30 | Canvas receives about 80% of workspace with library left and configuration right | Responsive three-panel editor with dominant center canvas and bottom run drawer |
| UI-02 | pp. 29–30 | Save/load/run/reset/status controls and agent-specific forms | Toolbar, config panel, validation, statuses, fit/zoom, safe reset, unsaved-change guard |
| TEC-01 | pp. 31–32 | React/React Flow, FastAPI, Celery/Redis, PostgreSQL, FFmpeg, external APIs, Docker | Retained; Laravel added by build specification for product concerns; current maintained versions locked |

## Definition-of-Done linkage

The 30 final criteria are tracked phase-by-phase in `PROJECT_STATUS.md`. Phase 14
provides automated acceptance evidence; Phase 15 records clean-install and
deployment evidence. Credential-dependent real-provider tests remain explicitly
conditional but the implementations and actionable configuration errors are
required before completion.

