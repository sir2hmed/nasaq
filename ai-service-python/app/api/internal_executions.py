"""Protected Laravel-to-FastAPI task submission and cancellation contracts."""

from secrets import compare_digest
from uuid import uuid4

from fastapi import APIRouter, Header, HTTPException, Request, status
from redis.exceptions import RedisError

from app.domain import (
    AcceptedCancellation,
    AcceptedExecution,
    InternalCancellationRequest,
    InternalExecutionRequest,
)
from app.services.cancellation import CancellationStore
from app.tasks.executions import execute_workflow_task

router = APIRouter(prefix="/internal/executions", tags=["internal"])


@router.post("", response_model=AcceptedExecution, status_code=status.HTTP_202_ACCEPTED)
def start_execution(
    payload: InternalExecutionRequest,
    request: Request,
    service_token: str | None = Header(default=None, alias="X-Nasaq-Service-Token"),
) -> AcceptedExecution:
    settings = request.app.state.settings
    if service_token is None or not compare_digest(service_token, settings.ai_service_token):
        raise HTTPException(status_code=401, detail="Invalid internal service token.")
    if payload.callback.base_url.rstrip("/") != settings.laravel_callback_base_url.rstrip("/"):
        raise HTTPException(status_code=422, detail="Callback base URL is not allowed.")
    if not compare_digest(payload.callback.token, settings.laravel_callback_token):
        raise HTTPException(status_code=422, detail="Callback token does not match configuration.")

    task_id = str(uuid4())
    try:
        execute_workflow_task.apply_async(
            args=[payload.model_dump(mode="json")],
            task_id=task_id,
            headers={"correlation_id": payload.correlation_id, "run_id": payload.run_id},
        )
    except Exception as exc:
        raise HTTPException(status_code=503, detail="Execution queue is unavailable.") from exc
    return AcceptedExecution(
        task_id=task_id,
        run_id=payload.run_id,
        correlation_id=payload.correlation_id,
    )


@router.post(
    "/{run_id}/cancel",
    response_model=AcceptedCancellation,
    status_code=status.HTTP_202_ACCEPTED,
)
def cancel_execution(
    run_id: str,
    payload: InternalCancellationRequest,
    request: Request,
    service_token: str | None = Header(default=None, alias="X-Nasaq-Service-Token"),
) -> AcceptedCancellation:
    settings = request.app.state.settings
    if service_token is None or not compare_digest(service_token, settings.ai_service_token):
        raise HTTPException(status_code=401, detail="Invalid internal service token.")

    try:
        CancellationStore(settings.redis_url, settings.cancellation_ttl_seconds).request(
            run_id, payload.correlation_id
        )
    except RedisError as exc:
        raise HTTPException(status_code=503, detail="Cancellation store is unavailable.") from exc

    return AcceptedCancellation(
        run_id=run_id,
        correlation_id=payload.correlation_id,
        task_id=payload.task_id,
    )
