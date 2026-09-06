from pathlib import Path

from app.domain import InternalExecutionRequest, WorkflowGraph
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.orchestration.retry import RetryPolicy
from app.services.callbacks import build_execution_events
from tests.test_internal_executions import request_payload
from tests.test_orchestration import graph_payload


def no_wait_policy(delays: list[float] | None = None) -> RetryPolicy:
    captured = delays if delays is not None else []
    return RetryPolicy(
        max_attempts=3,
        base_delay_seconds=0.5,
        max_delay_seconds=5,
        jitter_ratio=0.2,
        sleep_fn=captured.append,
        random_fn=lambda: 0.5,
    )


def transient_graph(failures: int) -> WorkflowGraph:
    payload = graph_payload()
    payload["nodes"][0]["config"]["simulate_transient_failures"] = failures
    return WorkflowGraph.model_validate(payload)


def test_retry_policy_uses_three_total_attempts_with_exponential_backoff() -> None:
    delays: list[float] = []
    policy = no_wait_policy(delays)

    assert policy.wait(1) == 0.55
    assert policy.wait(2) == 1.1
    assert delays == [0.55, 1.1]


def test_transient_failures_retry_twice_and_are_visible_in_callback_order(
    tmp_path: Path,
) -> None:
    payload = InternalExecutionRequest.model_validate(request_payload())
    result = WorkflowOrchestrator(
        AgentRegistry(tmp_path),
        retry_policy=no_wait_policy(),
    ).execute(
        transient_graph(2),
        run_id=payload.run_id,
        user_id=payload.user_id,
        correlation_id=payload.correlation_id,
    )

    assert result.status == "success"
    retry_logs = [item for item in result.logs if item.code == "node_retrying"]
    assert [item.attempt for item in retry_logs] == [2, 3]
    events = build_execution_events(payload, result)
    researcher_events = [
        (item.event_type, item.attempt) for item in events if item.node_key == "researcher_01"
    ]
    assert researcher_events == [
        ("NODE_STARTED", 1),
        ("NODE_RETRYING", 2),
        ("NODE_RETRYING", 3),
        ("NODE_SUCCEEDED", 3),
    ]


def test_cooperative_cancellation_stops_before_a_retry(tmp_path: Path) -> None:
    probes = iter([False, True])
    result = WorkflowOrchestrator(
        AgentRegistry(tmp_path),
        retry_policy=no_wait_policy(),
        cancellation_probe=lambda: next(probes),
    ).execute(
        transient_graph(1),
        run_id="cancelled-run",
        correlation_id="cancelled-correlation",
    )

    assert result.status == "cancelled"
    assert result.outputs == {}
    assert [item.code for item in result.logs if item.code.startswith("run_")][-1] == (
        "run_cancelled"
    )
