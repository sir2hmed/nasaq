"""Cache completed external effects and lock concurrent duplicates by run and node."""

import json
from collections.abc import Callable
from threading import Lock
from typing import Any, Protocol, TypeVar

from redis import Redis

from app.providers.errors import TransientProviderError

T = TypeVar("T", bound=dict[str, Any])


class IdempotencyStore(Protocol):
    def execute_once(self, key: str, operation: Callable[[], T]) -> tuple[T, bool]: ...


class InMemoryIdempotencyStore:
    def __init__(self) -> None:
        self._values: dict[str, dict[str, Any]] = {}
        self._lock = Lock()

    def execute_once(self, key: str, operation: Callable[[], T]) -> tuple[T, bool]:
        with self._lock:
            cached = self._values.get(key)
            if cached is not None:
                return dict(cached), True
            result = operation()
            self._values[key] = dict(result)
            return result, False


class RedisIdempotencyStore:
    def __init__(self, redis_url: str, ttl_seconds: int = 604800) -> None:
        self.client = Redis.from_url(redis_url, decode_responses=True)
        self.ttl_seconds = ttl_seconds

    @staticmethod
    def result_key(key: str) -> str:
        return f"nasaq:side-effect:{key}:result"

    @staticmethod
    def lock_key(key: str) -> str:
        return f"nasaq:side-effect:{key}:lock"

    def execute_once(self, key: str, operation: Callable[[], T]) -> tuple[T, bool]:
        result_key = self.result_key(key)
        cached = self.client.get(result_key)
        if cached is not None:
            return json.loads(cached), True

        lock_key = self.lock_key(key)
        if not self.client.set(lock_key, "1", nx=True, ex=120):
            cached = self.client.get(result_key)
            if cached is not None:
                return json.loads(cached), True
            raise TransientProviderError("idempotency", "side_effect_in_progress")

        try:
            result = operation()
            self.client.set(
                result_key,
                json.dumps(result, ensure_ascii=False, separators=(",", ":")),
                ex=self.ttl_seconds,
            )
            return result, False
        finally:
            self.client.delete(lock_key)
