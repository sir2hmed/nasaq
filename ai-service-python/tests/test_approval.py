from pathlib import Path

from app.domain import InternalExecutionRequest, WorkflowGraph
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.services.callbacks import build_execution_events
from tests.test_internal_executions import request_payload
from tests.test_orchestration import graph_payload


def approval_graph() -> WorkflowGraph:
    payload = graph_payload()
    payload["nodes"].insert(
        2,
        {
            "id": "approval_01",
            "type": "approval",
            "position": {"x": 680, "y": 180},
            "config": {},
        },
    )
    payload["edges"] = [
        {"id": "research_writer", "source": "researcher_01", "target": "writer_01"},
        {"id": "writer_approval", "source": "writer_01", "target": "approval_01"},
        {"id": "approval_export", "source": "approval_01", "target": "export_01"},
    ]
    return WorkflowGraph.model_validate(payload)


def test_approval_gate_pauses_with_preview_then_resumes(tmp_path: Path) -> None:
    orchestrator = WorkflowOrchestrator(AgentRegistry(tmp_path))
    paused = orchestrator.execute(
        approval_graph(),
        run_id="approval-run",
        correlation_id="approval-correlation",
    )

    assert paused.status == "waiting_for_approval"
    assert list(paused.outputs) == ["researcher_01", "writer_01"]
    assert not tmp_path.joinpath("approval-run", "export_01").exists()
    approval_log = next(log for log in paused.logs if log.code == "approval_required")
    assert approval_log.node_id == "approval_01"
    assert approval_log.agent_type == "approval"

    payload = request_payload()
    payload["workflow"] = approval_graph().model_dump(mode="json")
    request = InternalExecutionRequest.model_validate(payload)
    events = build_execution_events(request, paused)
    assert events[-1].event_type == "APPROVAL_REQUIRED"
    assert events[-1].node_key == "approval_01"

    resumed = orchestrator.execute(
        approval_graph(),
        run_id="approval-run",
        correlation_id="approval-correlation",
        approved_node_keys={"approval_01"},
    )

    assert resumed.status == "success"
    assert resumed.outputs["approval_01"].metadata.provider == "human"
    assert resumed.outputs["approval_01"].data["human_review"]["status"] == "approved"
    assert resumed.outputs["export_01"].status == "success"
    assert len(resumed.artifacts) == 3


def test_configured_side_effect_node_can_use_the_same_gate(tmp_path: Path) -> None:
    payload = graph_payload()
    payload["nodes"][2]["config"]["require_approval"] = True
    graph = WorkflowGraph.model_validate(payload)
    orchestrator = WorkflowOrchestrator(AgentRegistry(tmp_path))

    paused = orchestrator.execute(graph, run_id="configured-gate")
    resumed = orchestrator.execute(
        graph,
        run_id="configured-gate",
        approved_node_keys={"export_01"},
    )

    assert paused.status == "waiting_for_approval"
    assert paused.logs[-1].node_id == "export_01"
    assert resumed.status == "success"
