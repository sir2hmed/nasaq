# Container support

Service-specific immutable images live beside each service. Development uses
`docker-compose.yml`; the isolated production-style topology uses
`docker-compose.prod.yml`, a single public gateway, private data services,
authenticated Redis, persistent database/queue/artifact volumes, health checks,
and restart policies. See `docs/deployment.md` for secrets, backups, upgrades,
rollback, and the documented runtime limitation of the bundled Laravel server.
