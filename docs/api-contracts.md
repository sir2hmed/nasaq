# API contracts

All public endpoints use `/api`, JSON, UTF-8, Laravel authentication, policy
checks, correlation IDs, and a consistent envelope:

```json
{
  "data": {},
  "message": "Human-readable summary",
  "errors": null,
  "meta": { "correlation_id": "uuid" }
}
```

Validation failures use HTTP 422 with stable field/code/message objects. Error
responses never contain secrets, stack traces in production, or raw provider
bodies.

## Public Laravel API

### Authentication

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/auth/register` | Guest | Create an account and authenticated session |
| POST | `/api/auth/login` | Guest | Start authenticated session |
| POST | `/api/auth/logout` | User | Revoke current session |
| GET | `/api/auth/me` | User | Return current user and preferences |
| PATCH | `/api/auth/locale` | User | Persist `preferred_locale` as `en` or `ar` |

Registration accepts `name`, `email`, `password`, `password_confirmation`, and
optional `preferred_locale`. Authentication uses a first-party Sanctum session
cookie: the SPA first initializes `/sanctum/csrf-cookie`, sends credentials on
every request, and supplies the decoded XSRF cookie on state-changing requests.
Password and session secrets are never returned.

### Workflows

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/workflows` | Paginated owned workflows |
| POST | `/api/workflows` | Create owned workflow |
| GET | `/api/workflows/{workflow}` | Retrieve owned workflow graph |
| PUT | `/api/workflows/{workflow}` | Replace mutable fields and graph snapshot |
| DELETE | `/api/workflows/{workflow}` | Delete owned workflow |
| POST | `/api/workflows/{workflow}/duplicate` | Copy as a new owned workflow |
| POST | `/api/workflows/{workflow}/validate` | Validate without executing |
| POST | `/api/workflows/{workflow}/run` | Create queued run from current snapshot |

Create/update request:

```json
{
  "name": "Research to Article",
  "description": "Demo workflow",
  "status": "draft",
  "graph_json": {
    "version": 1,
    "name": "Research to Article",
    "description": "Demo workflow",
    "nodes": [
      {
        "id": "researcher_01",
        "type": "researcher",
        "position": { "x": 120, "y": 180 },
        "config": { "topic": "AI agents in education" }
      }
    ],
    "edges": []
  }
}
```

`graph_json` is the authoritative versioned snapshot. Laravel also synchronizes
owned `agent_nodes` and `workflow_edges` rows for reporting and search. List
responses include node/edge counts and pagination metadata. Every single-resource
action is policy checked; deleted workflows are soft deleted and disappear from
normal route binding. Workflow resources also include `latest_run_id` and
`last_run_status`, allowing the editor to restore its latest durable timeline.

Validation returns `valid`, blocking `errors`, and non-blocking `warnings`.
Structural errors include cycles, self-loops, dangling nodes/edges, missing start
nodes, and incompatible contracts. Required agent configuration is reported as
node-scoped warnings so an incomplete draft can still be saved.

Run request:

```json
{
  "mode": "demo",
  "idempotency_key": "client-generated-uuid"
}
```

Successful run creation returns HTTP 202 with `id`, `status: queued`, and the
workflow snapshot version. Repeating the same user/workflow/idempotency key
returns the original run.

### Runs, logs, outputs, approvals

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/runs` | Paginated owned execution history |
| GET | `/api/runs/{run}` | Run and node-status snapshot |
| POST | `/api/runs/{run}/cancel` | Best-effort cancellation |
| GET | `/api/runs/{run}/logs` | Ordered, paginated safe logs |
| GET | `/api/runs/{run}/outputs` | Authorized output metadata/previews |
| GET | `/api/outputs/{output}/download` | Ownership-checked artifact stream |
| GET | `/api/outputs/{output}/stream` | Ownership-checked inline video stream |
| POST | `/api/runs/{run}/approval/{node_key}/approve` | Approve and resume |
| POST | `/api/runs/{run}/approval/{node_key}/reject` | Reject with optional comment |

Terminal run statuses are `success`, `failed`, and `cancelled`. The full model
is `queued`, `running`, `waiting_for_approval`, and those terminal statuses.
The run index returns owner-scoped pagination plus `meta.status_counts` for the
dashboard; resources never aggregate UUID columns in the database.

### Integrations and settings

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/integrations` | Redacted provider connection summaries |
| POST | `/api/integrations/{provider}/connect` | Validate and encrypt credentials |
| DELETE | `/api/integrations/{provider}` | Disconnect provider |
| GET | `/api/integrations/{provider}/status` | Safe connection readiness |
| GET | `/api/health` | Application/database/Redis readiness |

Supported provider identifiers are declared server-side; arbitrary provider
class names are never accepted.

Laravel also exposes a service-token-only, `Cache-Control: no-store` endpoint at
`GET /api/internal/users/{user}/integrations/{provider}`. Only the Celery worker
uses it at side-effect execution time; credentials never enter browser responses
or queued workflow payloads.

## FastAPI internal API

FastAPI is private in production. Requests require
`X-Nasaq-Service-Token`, a correlation ID, and strict schemas.

| Method | Path | Purpose |
|---|---|---|
| GET | `/health` | Process readiness; no secret required on private health route |
| POST | `/internal/executions` | Validate and enqueue immutable workflow snapshot |
| POST | `/internal/executions/{run_id}/cancel` | Set cancellation request |

Non-production environments also expose `POST /internal/demo-executions`. It
runs the Phase 5 engine synchronously and returns the complete standardized run
result. The route is absent when `APP_ENV=production`; normal product execution
uses the protected `/internal/executions` contract.

Execution request:

```json
{
  "run_id": "uuid",
  "correlation_id": "uuid",
  "workflow": { "version": 1, "nodes": [], "edges": [] },
  "demo_mode": true,
  "callback": {
    "base_url": "http://laravel:8000/api/internal",
    "token": "server-supplied-secret"
  }
}
```

Response is HTTP 202:

```json
{
  "accepted": true,
  "task_id": "celery-task-uuid",
  "run_id": "uuid",
  "correlation_id": "uuid"
}
```

Cancellation accepts the correlated Celery `task_id` and stores a cooperative
Redis flag. The worker checks it before each node and retry. Laravel preserves
the requested state until the worker emits terminal `RUN_CANCELLED`; already
terminal runs return HTTP 409 without dispatching a cancellation request.

## Callback API

`POST /api/internal/executions/{run_id}/events` requires
`X-Nasaq-Service-Token`. Each payload has a unique `event_id`; duplicate IDs are
acknowledged without applying the transition twice.

```json
{
  "event_id": "uuid",
  "event_type": "NODE_SUCCEEDED",
  "correlation_id": "uuid",
  "node_key": "writer_01",
  "occurred_at": "2026-07-15T00:00:00Z",
  "attempt": 1,
  "message": "Writer completed",
  "data": {
    "result": {},
    "artifacts": []
  }
}
```

Allowed event types:

- `RUN_STARTED`, `RUN_SUCCEEDED`, `RUN_FAILED`, `RUN_CANCELLED`
- `NODE_STARTED`, `NODE_RETRYING`, `NODE_SUCCEEDED`, `NODE_FAILED`
- `APPROVAL_REQUIRED`

Laravel validates service token, path/body run identity, allowed transition,
node membership, schema, timestamps, artifact metadata, and event idempotency in
one database transaction.

## Limits and diagnostics

Authentication is limited to 10 requests/minute, run creation to 10/minute,
approval/cancellation to 30/minute, integration mutation to 20/minute, internal
events to 600/minute, and artifact delivery to 120/minute. All API responses
carry `X-Correlation-ID`; standardized 404, 405, 419, and 429 JSON errors include
the same safe identifier. Validation errors are field/node scoped. Provider
credentials, stack traces, raw filesystem paths, and upstream response bodies
are never returned.
