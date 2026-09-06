"""Redis-backed cooperative workflow cancellation."""

from redis import Redis


class CancellationStore:
    def __init__(self, redis_url: str, ttl_seconds: int = 86400) -> None:
        self.client = Redis.from_url(redis_url, decode_responses=True)
        self.ttl_seconds = ttl_seconds

    @staticmethod
    def key(run_id: str) -> str:
        return f"nasaq:execution:{run_id}:cancel"

    def request(self, run_id: str, correlation_id: str) -> None:
        self.client.set(self.key(run_id), correlation_id, ex=self.ttl_seconds)

    def is_requested(self, run_id: str, correlation_id: str) -> bool:
        return self.client.get(self.key(run_id)) == correlation_id

    def clear(self, run_id: str) -> None:
        self.client.delete(self.key(run_id))
