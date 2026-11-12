# Backend local setup and verification

## Prerequisites
- Python 3.11+
- MySQL 8.0+
- An empty development database (do not point this at production data)

## Setup
1. Create a new database using utf8mb4.
2. Apply `database/schema.sql`.
3. Copy `backend/.env.example` to `backend/.env` or the process working directory's `.env`; fill in DB credentials and a random JWT secret of at least 32 characters.
4. Add the one-time INITIAL_* values from `backend/.env.bootstrap.example`.
5. From the repository root, install `backend/requirements.txt`.
6. Run `python -m backend.scripts.bootstrap_store` once to create the initial store and owner.
7. Remove the INITIAL_* variables from the environment after bootstrap.
8. Start with `uvicorn backend.app.main:app --reload`.
9. Verify `GET /health`, `GET /health/db`, login, and authenticated `GET /api/tables`.

## Important limitations in this first backend slice
- The API currently implements health checks, login and read-only table listing.
- Session/order/payment/attendance/payroll/closing write endpoints are not implemented yet.
- No real MySQL instance has been connected from this GitHub workflow; schema execution and integration tests remain required before operational use.
- Use HTTPS and a production-grade secret manager in deployment. Do not expose the database directly to client devices.
- The initial role seed is a baseline. Before production, review permissions and add account recovery, rate limiting, CSRF protection if cookie sessions are used, and security monitoring.
