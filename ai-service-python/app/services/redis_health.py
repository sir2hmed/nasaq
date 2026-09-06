"""Small, isolated Redis readiness probe."""

from redis.asyncio import Redis


async def probe_redis(redis_url: str) -> tuple[bool, str]:
    client = Redis.from_url(redis_url, socket_connect_timeout=1, socket_timeout=1)
    try:
        reply = await client.ping()
        return bool(reply), "ready" if reply else "unexpected response"
    except Exception as exc:  # infrastructure errors are normalized for health output
        return False, exc.__class__.__name__
    finally:
        await client.aclose()
