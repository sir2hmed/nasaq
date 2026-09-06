from pathlib import Path

from starlette.testclient import TestClient

import app.api.internal_executions as execution_api
from app.config import Settings
from app.domain import InternalExecutionRequest, WorkflowGraph
from app.main import create_app
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.services.callbacks import build_execution_events
from tests.test_orchestration import graph_payload


def settings(tmp_path: Path) -> Settings:
    return Settings(
        app_env="testing",
        artifact_root=tmp_path,
        check_dependencies=False,
        ai_service_token="phase-six-service-token",
        laravel_callback_base_url="http://laravel.test/api/internal",
        laravel_callback_token="phase-six-callback-token",
    )


def request_payload() -> dict:
    return {
        "run_id": "11111111-1111-4111-8111-111111111111",
        "correlation_id": "22222222-2222-4222-8222-222222222222",
        "user_id": "42",
        "selected_language": "en",
        "workflow": graph_payload(),
        "demo_mode": True,
        "callback": {
            "base_url": "http://laravel.test/api/internal",
            "token": "phase-six-callback-token",
        },
    }


def test_internal_execution_requires_the_service_token(tmp_path: Path) -> None:
    with TestClient(create_app(settings(tmp_path))) as client:
        response = client.post("/internal/executions", json=request_payload())

    assert response.status_code == 401
    assert response.json()["detail"] == "Invalid internal service token."


def test_internal_execution_accepts_and_dispatches_an_immutable_snapshot(
    tmp_path: Path, monkeypatch
) -> None:
    submitted = []

    def fake_apply_async(*, args, task_id, headers) -> None:
        submitted.append({"args": args, "task_id": task_id, "headers": headers})

    monkeypatch.setattr(execution_api.execute_workflow_task, "apply_async", fake_apply_async)
    with TestClient(create_app(settings(tmp_path))) as client:
        response = client.post(
            "/internal/executions",
            headers={"X-Nasaq-Service-Token": "phase-six-service-token"},
            json=request_payload(),
        )

    assert response.status_code == 202
    body = response.json()
    assert body["accepted"] is True
    assert body["run_id"] == request_payload()["run_id"]
    assert body["correlation_id"] == request_payload()["correlation_id"]
    assert len(body["task_id"]) == 36
    assert len(submitted) == 1
    assert submitted[0]["task_id"] == body["task_id"]
    assert submitted[0]["args"][0]["workflow"]["nodes"][0]["config"]["topic"] == (
        "Visual AI workflows"
    )
    assert submitted[0]["headers"] == {
        "correlation_id": request_payload()["correlation_id"],
        "run_id": request_payload()["run_id"],
    }


def test_internal_execution_accepts_real_provider_mode(tmp_path: Path, monkeypatch) -> None:
    submitted = []

    def fake_apply_async(*, args, task_id, headers) -> None:
        submitted.append(args[0])

    monkeypatch.setattr(execution_api.execute_workflow_task, "apply_async", fake_apply_async)
    payload = request_payload()
    payload["demo_mode"] = False
    with TestClient(create_app(settings(tmp_path))) as client:
        response = client.post(
            "/internal/executions",
            headers={"X-Nasaq-Service-Token": "phase-six-service-token"},
            json=payload,
        )

    assert response.status_code == 202
    assert submitted[0]["demo_mode"] is False


def test_internal_execution_rejects_an_unapproved_callback_target(tmp_path: Path) -> None:
    payload = request_payload()
    payload["callback"]["base_url"] = "http://untrusted.test/callback"

    with TestClient(create_app(settings(tmp_path))) as client:
        response = client.post(
            "/internal/executions",
            headers={"X-Nasaq-Service-Token": "phase-six-service-token"},
            json=payload,
        )

    assert response.status_code == 422
    assert response.json()["detail"] == "Callback base URL is not allowed."


def test_execution_result_translates_to_ordered_callback_events(tmp_path: Path) -> None:
    payload = InternalExecutionRequest.model_validate(request_payload())
    result = WorkflowOrchestrator(AgentRegistry(tmp_path)).execute(
        WorkflowGraph.model_validate(graph_payload()),
        run_id=payload.run_id,
        user_id=payload.user_id,
        correlation_id=payload.correlation_id,
    )

    events = build_execution_events(payload, result)

    assert [item.event_type for item in events] == [
        "RUN_STARTED",
        "NODE_STARTED",
        "NODE_SUCCEEDED",
        "NODE_STARTED",
        "NODE_SUCCEEDED",
        "NODE_STARTED",
        "NODE_SUCCEEDED",
        "RUN_SUCCEEDED",
    ]
    assert [item.node_key for item in events if item.event_type == "NODE_SUCCEEDED"] == [
        "researcher_01",
        "writer_01",
        "export_01",
    ]
    export_result = events[-2].data["result"]
    assert {artifact["storage_key"] for artifact in export_result["artifacts"]} == {
        f"{payload.run_id}/export_01/demo-output.md",
        f"{payload.run_id}/export_01/demo-output.pdf",
        f"{payload.run_id}/export_01/demo-output.docx",
    }
    assert events[-1].data["execution_order"] == [
        "researcher_01",
        "writer_01",
        "export_01",
    ]


def test_cancellation_requires_authentication_and_sets_the_correlated_flag(
    tmp_path: Path, monkeypatch
) -> None:
    requested = []

    class FakeCancellationStore:
        def __init__(self, redis_url: str, ttl_seconds: int) -> None:
            assert redis_url == settings(tmp_path).redis_url
            assert ttl_seconds == settings(tmp_path).cancellation_ttl_seconds

        def request(self, run_id: str, correlation_id: str) -> None:
            requested.append((run_id, correlation_id))

    monkeypatch.setattr(execution_api, "CancellationStore", FakeCancellationStore)
    body = {
        "correlation_id": request_payload()["correlation_id"],
        "task_id": "33333333-3333-4333-8333-333333333333",
    }
    path = f"/internal/executions/{request_payload()['run_id']}/cancel"
    with TestClient(create_app(settings(tmp_path))) as client:
        unauthorized = client.post(path, json=body)
        accepted = client.post(
            path,
            headers={"X-Nasaq-Service-Token": "phase-six-service-token"},
            json=body,
        )

    assert unauthorized.status_code == 401
    assert accepted.status_code == 202
    assert accepted.json() == {
        "accepted": True,
        "run_id": request_payload()["run_id"],
        "correlation_id": request_payload()["correlation_id"],
        "task_id": body["task_id"],
    }
    assert requested == [(request_payload()["run_id"], request_payload()["correlation_id"])]
