# Agent contracts

## Common interface

Every registered agent exposes an agent type, configuration schema, input schema,
output schema, and `execute` behavior. Product runs execute through Celery while
the non-production acceptance route can invoke the same engine synchronously.
The engine calls:

```text
validate_config → gather predecessor data → validate_input → execute
→ normalize_output → validate_output → persist/hand off
```

Standard result:

```json
{
  "agent_type": "writer",
  "node_id": "writer_01",
  "status": "success",
  "output_type": "text",
  "data": {},
  "artifacts": [],
  "metadata": {
    "provider": "demo",
    "model": null,
    "duration_ms": 10,
    "demo_mode": true
  },
  "warnings": []
}
```

Execution context includes `workflow_run_id`, correlation ID, locale, provider
mode, predecessor results, retry attempt, cancellation probe, and an idempotency
key. It does not expose another user's credentials or raw database models.

## Researcher

- Config/input: required `topic`; `source_count` 1–20; language `en`, `ar`, or
  `same_as_input`; optional date range and search depth.
- Output: topic, summary, key points, and sources containing title, URL, snippet,
  and optional publication metadata.
- Demo: deterministic `demo://` source references, visibly marked simulated.
- Real: configured search provider; URLs must come from provider results.

## Writer

- Input: Researcher result or explicit source text.
- Config: style, length, format (`article`, `summary`, `script`),
  language, optional custom instructions.
- Output: title, content, source references, word count, language, and format.
- Demo: deterministic template content carrying the demo-source warning.
- Real: configured LLM provider; source metadata is preserved and citations are
  not invented.

## Export

- Input: Writer-like title/content/language payload or a Video scene plan.
- Config: one or more of `markdown`, `pdf`, `docx`.
- Output: artifact metadata including stable output ID, safe display name, MIME
  type, byte size, checksum, and private storage reference.
- Both modes create real files. Video input becomes exportable production notes.
  Arabic exports must shape RTL text readably.

## Video

- Input: script text, title, and language.
- Config: scene count/duration within safe limits, aspect ratio, narration mode.
- Output: playable MP4 artifact, scenes, duration, and optional narration data.
- Demo: deterministic locally rendered slides; silent/local narration is clearly
  documented when no TTS provider is configured.

## Publisher

- Input: authorized artifact metadata.
- Config: `google_drive` or `youtube`, optional title/description, and YouTube
  privacy (`private`, `unlisted`, or `public`).
- Output: provider, resource ID, external URL, and simulated flag.
- Side effects use a run/node idempotency key. Demo URLs use a reserved example
  domain and never imply a real upload.

## Email

- Input: content, publication links, and optional safe attachments.
- Config: validated recipients, subject, and a `{content}`/`{links}` template.
- Output: provider delivery ID/status, recipients, subject, and simulated flag.
- Logs redact message secrets and credentials. Retries cannot duplicate delivery.

## Approval utility

- Input: safe predecessor preview.
- Config: prompt and optional publication target.
- Output: the approved predecessor payload plus review metadata. Laravel stores
  the owner decision, timestamp, and optional comment separately.
- Execution pauses with `waiting_for_approval`; approval requeues the same
  immutable run snapshot with its approved-node list.

## Adding a new agent

Implement the common class, declare schemas/UI definition, register its type, and
add contract/execution tests. The graph engine remains unchanged.
