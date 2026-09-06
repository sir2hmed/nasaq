"""Human-review gate agent that preserves the approved predecessor output."""

from typing import Any

from app.agents.base import AgentExecution, BaseAgent, validation_error
from app.domain import ExecutionContext, ExecutionError


class ApprovalAgent(BaseAgent):
    agent_type = "approval"
    input_schema = "one or more structured predecessor outputs"
    output_schema = "approved predecessor output with review metadata"

    def validate_config(self, config: dict[str, Any], node_id: str) -> list[ExecutionError]:
        return []

    def validate_input(self, input_data: dict[str, Any], node_id: str) -> list[ExecutionError]:
        if not input_data or not all(isinstance(value, dict) for value in input_data.values()):
            return [
                validation_error(
                    "invalid_input",
                    "Approval requires structured output to review.",
                    node_id,
                    self.agent_type,
                )
            ]
        return []

    def execute(
        self,
        input_data: dict[str, Any],
        config: dict[str, Any],
        execution_context: ExecutionContext,
        node_id: str,
    ) -> AgentExecution:
        predecessor_id, predecessor = next(iter(input_data.items()))
        return AgentExecution(
            output_type="approved",
            data={
                **predecessor,
                "human_review": {
                    "status": "approved",
                    "source_node_id": predecessor_id,
                },
            },
            provider="human",
        )
