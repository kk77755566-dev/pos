# Cloud Run deployment (API)

This repository includes a container build for the FastAPI backend. This document prepares the service; it does not deploy or publish anything automatically.

## Required managed resources
- Cloud Run service for the API container.
- MySQL 8 database reachable privately from the service (Cloud SQL for MySQL is recommended).
- Secret Manager entries for DB_PASSWORD and JWT_SECRET.
- A static HTTPS host for `frontend/index.html` (or another frontend host).

## Container
Build from repository root:

```sh
docker build -t girlsbar-pos-api .
docker run --rm -p 8080:8080 \
  -e DB_HOST=host.docker.internal \
  -e DB_PORT=3306 \
  -e DB_NAME=girlsbar_pos \
  -e DB_USER=pos_app \
  -e DB_PASSWORD=replace-me \
  -e JWT_SECRET=replace-with-at-least-32-random-characters \
  -e APP_ENV=production \
  -e CORS_ORIGINS=https://your-pos-frontend.example.com \
  girlsbar-pos-api
```

Do not use the example credentials in production. Do not bake secrets into the image.

## Cloud Run environment
Set:
- `APP_ENV=production`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`
- `DB_PASSWORD` from Secret Manager
- `JWT_SECRET` from Secret Manager (at least 32 random characters)
- `JWT_ISSUER=girlsbar-pos`
- `CORS_ORIGINS` to the exact HTTPS frontend origin(s), comma-separated

The application rejects production startup when JWT_SECRET is too short or CORS_ORIGINS is empty.

## Database initialization
1. Create a new MySQL 8 database.
2. Apply `database/schema.sql` once.
3. Bootstrap the first store and owner from a controlled one-time environment with the `INITIAL_*` variables.
4. Remove the bootstrap variables immediately after success.
5. Create a least-privilege database account for the runtime API.

The schema file is for a new database. For an existing database, take a backup and write/run a reviewed migration; do not apply the full schema blindly.

## Frontend
Host `frontend/index.html` on a static HTTPS origin. Enter the Cloud Run API URL in the login panel. Add that exact frontend origin to `CORS_ORIGINS`.

## Before real operations
- Configure database backups, retention, monitoring and alerting.
- Confirm TLS/private networking from Cloud Run to MySQL.
- Run an end-to-end acceptance test on iPad, phone and PC.
- Validate payroll with a Japanese labor/payroll specialist, including minimum wage, night work, overtime, statutory breaks and deductions.
- Validate payment provider refund procedures. The POS refund API records accounting; it does not call the card/QR processor.
- Configure a scheduler to call daily close only after the store's actual business-day policy and recovery procedures have been approved. The current API supports a manual close request; a production scheduler is not configured by this repository.
