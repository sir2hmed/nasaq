"""Retry classification and bounded exponential backoff."""

from collections.abc import Callable
from random import random
from time import sleep

from app.domain import ExecutionError


class RetryPolicy:
    def __init__(
        self,
        max_attempts: int = 3,
        base_delay_seconds: float = 0.5,
        max_delay_seconds: float = 5.0,
        jitter_ratio: float = 0.25,
        sleep_fn: Callable[[float], None] = sleep,
        random_fn: Callable[[], float] = random,
    ) -> None:
        self.max_attempts = max_attempts
        self.base_delay_seconds = base_delay_seconds
        self.max_delay_seconds = max_delay_seconds
        self.jitter_ratio = jitter_ratio
        self.sleep_fn = sleep_fn
        self.random_fn = random_fn

    def should_retry(self, error: ExecutionError, attempt: int) -> bool:
        return error.retryable and attempt < self.max_attempts

    def delay_seconds(self, attempt: int) -> float:
        base = min(self.max_delay_seconds, self.base_delay_seconds * (2 ** (attempt - 1)))
        return base + (base * self.jitter_ratio * self.random_fn())

    def wait(self, attempt: int) -> float:
        delay = self.delay_seconds(attempt)
        self.sleep_fn(delay)
        return delay
