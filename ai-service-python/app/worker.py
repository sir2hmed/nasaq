"""Celery application and production task boundary."""

from celery import Celery

from app.config import get_settings

settings = get_settings()
celery_app = Celery(
    "nasaq_ai",
    broker=settings.redis_url,
    backend=settings.redis_url,
    include=["app.tasks.executions"],
)
celery_app.conf.update(
    task_serializer="json",
    accept_content=["json"],
    result_serializer="json",
    timezone="UTC",
    enable_utc=True,
    task_track_started=True,
    task_acks_late=True,
    task_reject_on_worker_lost=True,
    worker_prefetch_multiplier=1,
    broker_connection_retry_on_startup=True,
)
