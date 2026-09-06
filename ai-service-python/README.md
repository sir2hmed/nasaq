# Python AI service boundary

FastAPI exposes `/live`, dependency-aware `/health`, and a non-production-only
`POST /internal/demo-executions` acceptance endpoint. The execution engine validates
and topologically sorts DAGs, performs structured predecessor handoffs through a
registered Researcher, Writer, and Export agent set, and returns standardized
results, logs, errors, and artifacts. Export writes real Markdown, PDF, and DOCX
files beneath `ARTIFACT_ROOT` with SHA-256 metadata. Product runs are submitted
to Celery through Redis, retry classified transient failures up to three total
attempts with exponential backoff and jitter, and support Redis-backed cooperative
cancellation between attempts and nodes.

```bash
python -m pip install -r requirements-dev.txt
ruff check .
ruff format --check .
pytest
uvicorn app.main:app --host 0.0.0.0 --port 8001
```
