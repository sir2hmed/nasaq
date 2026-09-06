"""Liveness and dependency-aware readiness endpoints."""

from typing import Any

from fastapi import APIRouter, Request, status
from fastapi.responses import JSONResponse

from app.services.redis_health import probe_redis
from app.worker import celery_app

router = APIRouter(tags=["health"])


@router.get("/live")
async def live(request: Request) -> dict[str, str]:
    settings = request.app.state.settings
    return {
        "status": "ok",
        "service": "ai-service",
        "version": settings.app_version,
    }


@router.get("/health")
async def health(request: Request) -> JSONResponse:
    settings = request.app.state.settings
    checks: dict[str, dict[str, Any]] = {}

    if settings.check_dependencies:
        redis_ready, detail = await probe_redis(settings.redis_url)
        checks["redis"] = {"ready": redis_ready, "detail": detail}
        try:
            workers = celery_app.control.ping(timeout=1.0)
            celery_ready = bool(workers)
            checks["celery"] = {
                "ready": celery_ready,
                "detail": "worker responded" if celery_ready else "no worker response",
            }
        except Exception:
            celery_ready = False
            checks["celery"] = {"ready": False, "detail": "worker probe failed"}
    else:
        redis_ready = True
        checks["redis"] = {"ready": True, "detail": "dependency check disabled"}
        celery_ready = True
        checks["celery"] = {"ready": True, "detail": "dependency check disabled"}

    payload = {
        "status": "ok" if redis_ready and celery_ready else "degraded",
        "service": "ai-service",
        "version": settings.app_version,
        "environment": settings.app_env,
        "mode": "demo" if settings.demo_mode else "real",
        "checks": checks,
    }
    return JSONResponse(
        content=payload,
        status_code=status.HTTP_200_OK
        if redis_ready and celery_ready
        else status.HTTP_503_SERVICE_UNAVAILABLE,
    )
