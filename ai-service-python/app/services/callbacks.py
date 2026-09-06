"""Translate worker execution logs into ordered protected Laravel events."""

from datetime import UTC, datetime
from typing import Any, Literal
from uuid import uuid4

import httpx
from pydantic import Field

from app.domain import InternalExecutionRequest, WorkflowExecutionResult
from app.domain.models import ContractModel

EventType = Literal[
    "RUN_STARTED",
    "NODE_STARTED",
    "NODE_RETRYING",
    "NODE_SUCCEEDED",
    "NODE_FAILED",
    "APPROVAL_REQUIRED",
    "RUN_SUCCEEDED",
    "RUN_FAILED",
    "RUN_CANCELLED",
]

EVENT_TYPE_BY_LOG_CODE: dict[str, EventType] = {
    "run_started": "RUN_STARTED",
    "node_started": "NODE_STARTED",
    "node_retrying": "NODE_RETRYING",
    "node_succeeded": "NODE_SUCCEEDED",
    "approval_required": "APPROVAL_REQUIRED",
    "run_succeeded": "RUN_SUCCEEDED",
    "run_failed": "RUN_FAILED",
    "run_cancelled": "RUN_CANCELLED",
}


class CallbackEvent(ContractModel):
    event_id: str
    event_type: EventType
    correlation_id: str
    node_key: str | None = None
    occurred_at: datetime
    attempt: int = Field(default=1, ge=1)
    message: str
    data: dict[str, Any] = Field(default_factory=dict)


def build_execution_events(
    request: InternalExecutionRequest,
    result: WorkflowExecutionResult,
) -> list[CallbackEvent]:
    events: list[CallbackEvent] = []
    for execution_log in result.logs:
        event_type = EVENT_TYPE_BY_LOG_CODE.get(execution_log.code)
        if event_type is None:
            if execution_log.level == "error" and execution_log.node_id is not None:
                event_type = "NODE_FAILED"
            else:
                continue

        data: dict[str, Any] = {}
        if event_type in {"NODE_SUCCEEDED", "NODE_FAILED"} and execution_log.node_id:
            output = result.outputs.get(execution_log.node_id)
            if output is not None:
                data["result"] = output.model_dump(mode="json")
        if event_type in {"RUN_SUCCEEDED", "RUN_FAILED", "RUN_CANCELLED"}:
            data = {
                "duration_ms": result.duration_ms,
                "errors": [error.model_dump(mode="json") for error in result.errors],
                "execution_order": result.execution_order,
            }

        events.append(
            event(
                request,
                event_type,
                execution_log.message,
                node_id=(
                    execution_log.node_id
                    if event_type.startswith("NODE_") or event_type == "APPROVAL_REQUIRED"
                    else None
                ),
                data=data,
                attempt=execution_log.attempt,
            )
        )
    return events


def publish_execution_events(
    request: InternalExecutionRequest,
    result: WorkflowExecutionResult,
    timeout_seconds: float,
) -> None:
    endpoint = f"{request.callback.base_url.rstrip('/')}/executions/{request.run_id}/events"
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Nasaq-Service-Token": request.callback.token,
        "X-Correlation-ID": request.correlation_id,
    }
    with httpx.Client(timeout=timeout_seconds) as client:
        for callback_event in build_execution_events(request, result):
            response = client.post(
                endpoint,
                headers=headers,
                json=callback_event.model_dump(mode="json"),
            )
            response.raise_for_status()


def event(
    request: InternalExecutionRequest,
    event_type: EventType,
    message: str,
    node_id: str | None = None,
    data: dict[str, Any] | None = None,
    attempt: int = 1,
) -> CallbackEvent:
    return CallbackEvent(
        event_id=str(uuid4()),
        event_type=event_type,
        correlation_id=request.correlation_id,
        node_key=node_id,
        occurred_at=datetime.now(UTC),
        attempt=attempt,
        message=message,
        data=data or {},
    )
