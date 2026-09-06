from typing import Any

from pydantic import BaseModel, Field


class NormalizedLLMResponse(BaseModel):
    provider: str
    model: str
    content: str = ""
    structured_output: dict[str, Any] = Field(default_factory=dict)
    usage: dict[str, int] = Field(
        default_factory=lambda: {"input_tokens": 0, "output_tokens": 0, "total_tokens": 0}
    )
    finish_reason: str = "completed"
    request_id: str | None = None
