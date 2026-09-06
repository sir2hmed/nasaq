"""Environment-backed settings for the internal AI orchestration service."""

from functools import lru_cache
from pathlib import Path

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "Nasaq AI Orchestrator"
    app_env: str = "local"
    app_version: str = "0.1.0"
    demo_mode: bool = True
    host: str = "0.0.0.0"
    port: int = 8001
    artifact_root: Path = Path("artifacts")
    redis_url: str = "redis://localhost:6379/0"
    check_dependencies: bool = True
    allowed_origins: list[str] = ["http://localhost:5173"]
    ai_service_token: str = "replace-with-a-long-random-token"
    laravel_callback_base_url: str = "http://laravel:8000/api/internal"
    laravel_callback_token: str = "replace-with-a-different-long-random-token"
    provider_resolution_timeout_seconds: float = 10.0
    allow_environment_provider_fallback: bool = False
    callback_timeout_seconds: float = 10.0
    retry_max_attempts: int = 3
    retry_base_delay_seconds: float = 0.5
    retry_max_delay_seconds: float = 5.0
    retry_jitter_ratio: float = 0.25
    cancellation_ttl_seconds: int = 86400
    search_provider: str = "tavily"
    search_api_key: str | None = None
    tavily_base_url: str = "https://api.tavily.com"
    llm_provider: str = "openai"
    llm_api_key: str | None = None
    llm_model: str = "gpt-5.6"
    tts_provider: str = "openai"
    tts_api_key: str | None = None
    tts_model: str = "tts-1"
    tts_voice: str = "alloy"
    openai_base_url: str = "https://api.openai.com/v1"
    provider_timeout_seconds: float = 60.0
    integration_timeout_seconds: float = 15.0
    google_drive_upload_url: str = "https://www.googleapis.com/upload/drive/v3/files"
    youtube_upload_url: str = "https://www.googleapis.com/upload/youtube/v3/videos"
    side_effect_idempotency_ttl_seconds: int = 604800

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    @field_validator("allowed_origins", mode="before")
    @classmethod
    def split_origins(cls, value: object) -> object:
        if isinstance(value, str) and not value.lstrip().startswith("["):
            return [origin.strip() for origin in value.split(",") if origin.strip()]
        return value


@lru_cache
def get_settings() -> Settings:
    return Settings()
