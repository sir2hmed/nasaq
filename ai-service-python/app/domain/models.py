"""Strict data contracts for workflow execution and agent handoffs."""

from datetime import UTC, datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator


class ContractModel(BaseModel):
    """Reject undocumented fields at service boundaries."""

    model_config = ConfigDict(extra="forbid")


class NodePosition(ContractModel):
    x: float
    y: float


class WorkflowNode(ContractModel):
    id: str = Field(min_length=1, max_length=120)
    type: str = Field(min_length=1, max_length=40)
    position: NodePosition | None = None
    config: dict[str, Any] = Field(default_factory=dict)

    @field_validator("id", "type")
    @classmethod
    def strip_identifier(cls, value: str) -> str:
        return value.strip()


class WorkflowEdge(ContractModel):
    id: str = Field(min_length=1, max_length=120)
    source: str = Field(min_length=1, max_length=120)
    target: str = Field(min_length=1, max_length=120)

    @field_validator("id", "source", "target")
    @classmethod
    def strip_identifier(cls, value: str) -> str:
        return value.strip()


class WorkflowGraph(ContractModel):
    version: int = Field(ge=1)
    name: str | None = Field(default=None, max_length=160)
    description: str | None = Field(default=None, max_length=2000)
    nodes: list[WorkflowNode]
    edges: list[WorkflowEdge]


class ExecutionError(ContractModel):
    code: str
    message: str
    retryable: bool = False
    node_id: str | None = None
    agent_type: str | None = None
    details: dict[str, Any] = Field(default_factory=dict)


class AgentArtifact(ContractModel):
    id: str
    owner_id: str | None
    workflow_run_id: str
    node_id: str
    path: str
    storage_key: str
    mime_type: str
    file_name: str
    file_size: int = Field(ge=0)
    checksum_sha256: str
    created_at: datetime = Field(default_factory=lambda: datetime.now(UTC))


class AgentMetadata(ContractModel):
    provider: str
    model: str | None = None
    duration_ms: int = Field(ge=0)
    demo_mode: bool


class AgentResult(ContractModel):
    agent_type: str
    node_id: str
    status: Literal["success", "failed"]
    output_type: str
    data: dict[str, Any] = Field(default_factory=dict)
    artifacts: list[AgentArtifact] = Field(default_factory=list)
    metadata: AgentMetadata
    warnings: list[str] = Field(default_factory=list)
    error: ExecutionError | None = None


class ExecutionContext(ContractModel):
    workflow_run_id: str
    user_id: str | None = None
    prior_node_outputs: dict[str, AgentResult] = Field(default_factory=dict)
    selected_language: Literal["en", "ar"] = "en"
    provider_mode: Literal["demo", "real"] = "demo"
    correlation_id: str
    cancellation_requested: bool = False
    retry_attempt: int = Field(default=1, ge=1)


class ExecutionLog(ContractModel):
    sequence: int = Field(ge=1)
    level: Literal["info", "warning", "error"]
    code: str
    message: str
    node_id: str | None = None
    agent_type: str | None = None
    attempt: int = Field(default=1, ge=1)


class WorkflowExecutionResult(ContractModel):
    run_id: str
    correlation_id: str
    status: Literal["success", "failed", "cancelled", "waiting_for_approval"]
    demo_mode: bool
    execution_order: list[str] = Field(default_factory=list)
    outputs: dict[str, AgentResult] = Field(default_factory=dict)
    artifacts: list[AgentArtifact] = Field(default_factory=list)
    logs: list[ExecutionLog] = Field(default_factory=list)
    errors: list[ExecutionError] = Field(default_factory=list)
    duration_ms: int = Field(ge=0)


class DemoExecutionRequest(ContractModel):
    run_id: str = Field(min_length=1, max_length=120)
    workflow: WorkflowGraph
    user_id: str | None = Field(default=None, max_length=120)
    selected_language: Literal["en", "ar"] = "en"
    correlation_id: str | None = Field(default=None, max_length=160)


class CallbackConfiguration(ContractModel):
    base_url: str = Field(min_length=8, max_length=500)
    token: str = Field(min_length=16, max_length=500)


class InternalExecutionRequest(ContractModel):
    run_id: str = Field(min_length=1, max_length=120)
    correlation_id: str = Field(min_length=1, max_length=160)
    workflow: WorkflowGraph
    user_id: str | None = Field(default=None, max_length=120)
    selected_language: Literal["en", "ar"] = "en"
    demo_mode: bool
    approved_node_keys: list[str] = Field(default_factory=list, max_length=100)
    callback: CallbackConfiguration

    @field_validator("approved_node_keys")
    @classmethod
    def unique_approved_nodes(cls, value: list[str]) -> list[str]:
        if len(value) != len(set(value)):
            raise ValueError("approved_node_keys must not contain duplicates")
        return value


class AcceptedExecution(ContractModel):
    accepted: Literal[True] = True
    task_id: str
    run_id: str
    correlation_id: str


class InternalCancellationRequest(ContractModel):
    correlation_id: str = Field(min_length=1, max_length=160)
    task_id: str = Field(min_length=1, max_length=160)


class AcceptedCancellation(ContractModel):
    accepted: Literal[True] = True
    run_id: str
    correlation_id: str
    task_id: str
