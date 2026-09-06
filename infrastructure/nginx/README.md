# Reverse proxy

`nasaq.conf` is the production Compose gateway. It exposes the React app,
forwards only `/api/*` and `/sanctum/*` to Laravel, and keeps FastAPI, Redis,
and PostgreSQL off the public network. TLS should terminate at the platform load
balancer or at a host proxy in front of this container; forwarded scheme and
client headers are preserved.
