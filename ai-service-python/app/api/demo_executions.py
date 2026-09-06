"""Development-only synchronous workflow execution endpoint."""

from fastapi import APIRouter, Request

from app.domain import DemoExecutionRequest, WorkflowExecutionResult
from app.orchestration import AgentRegistry, WorkflowOrchestrator

router = APIRouter(prefix="/internal/demo-executions", tags=["development"])


@router.post("", response_model=WorkflowExecutionResult)
def execute_demo(payload: DemoExecutionRequest, request: Request) -> WorkflowExecutionResult:
    settings = request.app.state.settings
    orchestrator = WorkflowOrchestrator(AgentRegistry(settings.artifact_root))
    return orchestrator.execute(
        payload.workflow,
        run_id=payload.run_id,
        user_id=payload.user_id,
        selected_language=payload.selected_language,
        correlation_id=payload.correlation_id,
    )
