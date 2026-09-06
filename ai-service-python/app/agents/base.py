"""Standard execution lifecycle shared by every agent."""

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from time import perf_counter
from typing import Any, ClassVar

from app.domain import AgentArtifact, AgentResult, ExecutionContext, ExecutionError
from app.domain.models import AgentMetadata
from app.providers.errors import ProviderError


@dataclass(slots=True)
class AgentExecution:
    """Successful provider payload before it is normalized."""

    output_type: str
    data: dict[str, Any]
    artifacts: list[AgentArtifact] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    provider: str = "demo"
    model: str | None = None


class AgentExecutionError(Exception):
    """A safe, classified provider failure exposed to orchestration."""

    def __init__(
        self,
        code: str,
        message: str,
        *,
        retryable: bool,
        provider: str = "demo",
    ) -> None:
        super().__init__(message)
        self.code = code
        self.safe_message = message
        self.retryable = retryable
        self.provider = provider


class TransientAgentError(AgentExecutionError):
    def __init__(self, code: str, message: str) -> None:
        super().__init__(code, message, retryable=True)


class BaseAgent(ABC):
    """Validate, execute, and normalize one workflow node."""

    agent_type: ClassVar[str]
    input_schema: ClassVar[str] = "structured predecessor outputs"
    output_schema: ClassVar[str] = "standard agent result"

    @abstractmethod
    def validate_config(self, config: dict[str, Any], node_id: str) -> list[ExecutionError]:
        """Return actionable configuration errors without raising."""

    @abstractmethod
    def validate_input(self, input_data: dict[str, Any], node_id: str) -> list[ExecutionError]:
        """Return actionable input errors without raising."""

    @abstractmethod
    def execute(
        self,
        input_data: dict[str, Any],
        config: dict[str, Any],
        execution_context: ExecutionContext,
        node_id: str,
    ) -> AgentExecution:
        """Run the provider implementation."""

    def run(
        self,
        input_data: dict[str, Any],
        config: dict[str, Any],
        execution_context: ExecutionContext,
        node_id: str,
    ) -> AgentResult:
        started = perf_counter()
        errors = self.validate_config(config, node_id) + self.validate_input(input_data, node_id)
        if errors:
            return self._failed_result(
                node_id, errors[0], started, execution_context, provider="validation"
            )

        try:
            execution = self.execute(input_data, config, execution_context, node_id)
        except ProviderError as exc:
            error = ExecutionError(
                code=exc.code,
                message=exc.safe_message,
                retryable=exc.retryable,
                node_id=node_id,
                agent_type=self.agent_type,
                details={"provider": exc.provider},
            )
            return self._failed_result(
                node_id, error, started, execution_context, provider=exc.provider
            )
        except AgentExecutionError as exc:
            error = ExecutionError(
                code=exc.code,
                message=exc.safe_message,
                retryable=exc.retryable,
                node_id=node_id,
                agent_type=self.agent_type,
            )
            return self._failed_result(
                node_id, error, started, execution_context, provider=exc.provider
            )
        except Exception as exc:  # normalized at the trust boundary; details stay server-side
            error = ExecutionError(
                code="agent_execution_failed",
                message=f"{self.agent_type.title()} could not complete its work.",
                node_id=node_id,
                agent_type=self.agent_type,
                details={"exception_type": type(exc).__name__},
            )
            return self._failed_result(
                node_id, error, started, execution_context, provider="internal"
            )

        return AgentResult(
            agent_type=self.agent_type,
            node_id=node_id,
            status="success",
            output_type=execution.output_type,
            data=execution.data,
            artifacts=execution.artifacts,
            metadata=AgentMetadata(
                provider=execution.provider,
                model=execution.model,
                duration_ms=self._elapsed_ms(started),
                demo_mode=execution_context.provider_mode == "demo",
            ),
            warnings=execution.warnings,
        )

    def normalize_output(self, result: AgentResult) -> AgentResult:
        """Expose the explicit normalization hook required by the agent contract."""

        return AgentResult.model_validate(result)

    def _failed_result(
        self,
        node_id: str,
        error: ExecutionError,
        started: float,
        execution_context: ExecutionContext,
        provider: str,
    ) -> AgentResult:
        return AgentResult(
            agent_type=self.agent_type,
            node_id=node_id,
            status="failed",
            output_type="error",
            metadata=AgentMetadata(
                provider=provider,
                duration_ms=self._elapsed_ms(started),
                demo_mode=execution_context.provider_mode == "demo",
            ),
            error=error,
        )

    @staticmethod
    def _elapsed_ms(started: float) -> int:
        return max(0, round((perf_counter() - started) * 1000))


def validation_error(
    code: str,
    message: str,
    node_id: str,
    agent_type: str,
    **details: Any,
) -> ExecutionError:
    return ExecutionError(
        code=code,
        message=message,
        node_id=node_id,
        agent_type=agent_type,
        details=details,
    )
