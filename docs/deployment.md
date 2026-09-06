# Deployment guide

This guide covers the verified single-host production-style Compose topology.
Only the Nginx gateway is public. Laravel, FastAPI, Celery, PostgreSQL, and Redis
remain on private Docker networks; FastAPI and Celery alone receive an outbound
network for configured providers.

## Prerequisites

- Docker Engine or Docker Desktop with Compose v2
- a DNS name and TLS termination at the host proxy or platform load balancer
- at least 4 CPU cores, 8 GB RAM, and storage sized for database and artifacts
- a secret manager or a host-readable `.env.production` file outside backups

## First deployment from a clean clone

1. Copy `.env.production.example` to `.env.production`.
2. Set `PUBLIC_URL` to the final HTTPS origin and `PUBLIC_HOST` to the same host
   without a scheme. Include the port in `PUBLIC_HOST` only when it is nonstandard.
3. Replace every `CHANGE_ME` value. Generate a Laravel key with 32 random bytes
   encoded as `base64:<value>`, and generate different random values for the
   database, Redis, FastAPI request, and Laravel callback secrets. The Redis
   password must be URL-safe because it is embedded in `redis://` URLs.
4. Validate the rendered configuration before creating containers:

   ```bash
   docker compose --env-file .env.production -f docker-compose.prod.yml config --quiet
   ```

5. Build immutable application images and start the private dependencies:

   ```bash
   docker compose --env-file .env.production -f docker-compose.prod.yml build
   docker compose --env-file .env.production -f docker-compose.prod.yml up -d postgres redis
   ```

6. Run migrations as an explicit release step, then start the application:

   ```bash
   docker compose --env-file .env.production -f docker-compose.prod.yml run --rm --no-deps laravel php artisan migrate --force
   docker compose --env-file .env.production -f docker-compose.prod.yml up -d
   ```

7. Verify the public gateway and Laravel dependency health:

   ```bash
   curl --fail http://127.0.0.1/healthz
   curl --fail https://nasaq.example.com/api/health
   docker compose --env-file .env.production -f docker-compose.prod.yml ps
   ```

The gateway receives plain HTTP inside the host. TLS must terminate in front of
it and supply `X-Forwarded-Proto: https`; secure session cookies are enabled by
default. PostgreSQL, Redis, and FastAPI have no host port mappings.

## Controlled demo account

Production does not create a fixed account automatically. On an isolated
evaluation deployment only, seed the deterministic browser fixture:

```bash
docker compose --env-file .env.production -f docker-compose.prod.yml exec laravel php artisan db:seed --class=BrowserAcceptanceSeeder --force
```

- Email: `test@example.com`
- Password: `NasaqDemo2026`

The seeder intentionally restores that password, five editable templates, and a
successful persisted Full Product Demo. Never run it on a deployment where that
email belongs to a real person; remove or rotate the account after evaluation.

## Smoke test after each release

1. Register a new account or use the controlled demo account.
2. Open **Full Product Demo** and confirm seven nodes, the persisted timeline,
   logs, and safe simulated Publisher/Email outputs.
3. Copy **Research to Article**, run it in demo mode, and download Markdown,
   PDF, and DOCX artifacts.
4. Switch to Arabic, confirm `dir="rtl"`, reload, and confirm the preference and
   workflow remain intact.
5. Review service logs using `docker compose ... logs --since 10m` and confirm
   no secret values or unhandled stack traces are exposed to the browser.

The complete operator walkthrough is in `docs/final-demo.md`.

## Real providers and integrations

Real Search/LLM/TTS mode uses server-side values in `.env.production`. Google
Drive, YouTube, and SMTP credentials are entered by each owner in **Integrations**,
encrypted by Laravel, and retrieved by the worker only over the protected
internal endpoint. Test with private/unlisted destinations first. Provider calls
may cost money and are deliberately excluded from the normal automated gate.

## Backups and restore

Back up PostgreSQL before every migration and back up the `artifact_data` volume
on the same retention schedule. A typical PostgreSQL backup is:

```bash
mkdir -p backups
docker compose --env-file .env.production -f docker-compose.prod.yml exec -T postgres \
  pg_dump -U nasaq -d nasaq -Fc > backups/nasaq-$(date +%Y%m%d-%H%M%S).dump
```

Test restores in a separate project name. For a controlled restore, stop Laravel
and Celery, restore into an empty database with `pg_restore --clean --if-exists`,
restore the matching artifact snapshot, run migrations, then start services and
perform the smoke test. Redis queue data is operational state, not the durable
record; PostgreSQL remains the source of truth for runs and outputs.

## Scaling, logs, and rollback

- Scale workers with `docker compose ... up -d --scale celery-worker=3` after
  measuring queue latency and provider limits.
- Send container stdout/stderr to the platform log collector. Correlation IDs
  connect browser errors, Laravel requests, tasks, callbacks, and provider logs.
- Keep the previous image set and database backup. Roll back application images
  first. Restore the database only when a migration is not backward-compatible.
- Rotate `AI_SERVICE_TOKEN` and `INTERNAL_CALLBACK_TOKEN` together across the
  services; rotate provider credentials from the owning integration screen.

## Security checklist

- TLS and secure cookies are active at the public origin.
- `.env.production` is not committed and is readable only by the deploy user.
- Default/example secrets are rejected by the Phase 15 verifier.
- Only the gateway exposes a host port.
- Redis requires authentication; PostgreSQL/Redis/FastAPI remain private.
- Database and artifact backups are encrypted and restore-tested.
- Demo fixtures are absent from real deployments.

## Honest production limitations

The bundled Laravel image uses Laravel's CLI HTTP server so the repository can
be cloned and run without a platform-specific PHP stack. It is verified for the
single-host reference deployment and demos, but high-throughput production
should place the Laravel application in a managed PHP runtime such as PHP-FPM,
FrankenPHP, or an equivalent platform service. Artifact storage is a local
Docker volume; multi-host scaling requires an object-storage disk and shared
artifact policy. OAuth token refresh/re-consent operations depend on provider
configuration and should be monitored. Paid-provider and real delivery paths
require valid user-supplied credentials and are contract-tested automatically,
not invoked in the normal test suite.
