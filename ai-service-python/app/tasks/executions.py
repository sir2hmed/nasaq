"""Asynchronous immutable workflow execution task."""

from typing import Any

from app.config import get_settings
from app.domain import InternalExecutionRequest
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.orchestration.retry import RetryPolicy
from app.providers import build_provider_bundle
from app.services.callbacks import publish_execution_events
from app.services.cancellation import CancellationStore
from app.services.idempotency import RedisIdempotencyStore
from app.services.provider_resolution import ProviderResolutionClient
from app.worker import celery_app


@celery_app.task(
    bind=True,
    name="nasaq.executions.run",
    acks_late=True,
    reject_on_worker_lost=True,
)
def execute_workflow_task(self, payload_data: dict[str, Any]) -> dict[str, Any]:
    payload = InternalExecutionRequest.model_validate(payload_data)
    settings = get_settings()
    cancellation = CancellationStore(settings.redis_url, settings.cancellation_ttl_seconds)
    retry_policy = RetryPolicy(
        max_attempts=settings.retry_max_attempts,
        base_delay_seconds=settings.retry_base_delay_seconds,
        max_delay_seconds=settings.retry_max_delay_seconds,
        jitter_ratio=settings.retry_jitter_ratio,
    )
    provider_mode = "demo" if payload.demo_mode else "real"
    resolution = None
    if provider_mode == "real":
        resolution = ProviderResolutionClient(
            settings.laravel_callback_base_url,
            settings.laravel_callback_token,
            settings.provider_resolution_timeout_seconds,
        ).resolve(payload.run_id, payload.correlation_id)
    providers = build_provider_bundle(settings, provider_mode, resolution)
    idempotency = RedisIdempotencyStore(
        settings.redis_url,
        settings.side_effect_idempotency_ttl_seconds,
    )
    result = WorkflowOrchestrator(
        AgentRegistry(
            settings.artifact_root,
            search_provider=providers.search,
            language_model_provider=providers.language_model,
            speech_provider=providers.speech,
            default_tts_voice=settings.tts_voice,
            drive_provider=providers.drive,
            youtube_provider=providers.youtube,
            email_provider=providers.email,
            idempotency_store=idempotency,
        ),
        retry_policy=retry_policy,
        cancellation_probe=lambda: cancellation.is_requested(
            payload.run_id, payload.correlation_id
        ),
    ).execute(
        payload.workflow,
        run_id=payload.run_id,
        user_id=payload.user_id,
        selected_language=payload.selected_language,
        correlation_id=payload.correlation_id,
        provider_mode=provider_mode,
        approved_node_keys=set(payload.approved_node_keys),
    )
    publish_execution_events(payload, result, settings.callback_timeout_seconds)
    cancellation.clear(payload.run_id)
    return {
        "run_id": result.run_id,
        "correlation_id": result.correlation_id,
        "status": result.status,
        "task_id": self.request.id,
    }
