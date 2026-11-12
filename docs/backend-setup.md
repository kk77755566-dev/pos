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

## Current API coverage (initial implementation; not production-certified)
- Health checks, login, authenticated table listing.
- Table session start, set/extension registration.
- Order registration with item-level cast back allocation and idempotency key.
- Bill preview and payment registration (split payment supported).
- Cast attendance clock-in/out and provisional hourly-pay/back aggregation.
- Daily closing run and closing-result inspection. Closing is blocked when open sessions, missing clock-outs, or closed-session payment mismatches are found.
- Daily closing is currently invoked through the API; an automatic scheduled trigger and store-local business-day timezone handling are not yet configured.
- Unpaid order cancellation with audit trail is implemented. Paid-order refund/reversal remains incomplete and must not be handled by manually changing payment rows. Master-data write screens, payroll finalization, automated scheduled close, and production deployment remain incomplete.\n- A first responsive operator UI is available at `frontend/index.html`; it supports login, table list, session start, set registration, order entry, bill preview, payment, and manual daily close. It currently uses basic controls and requires the API URL to be configured.
- No real MySQL instance has been connected from this GitHub workflow. Schema execution and integration tests remain required before operational use.
- Use HTTPS and a production-grade secret manager in deployment. Do not expose the database directly to client devices.
- The initial role seed is a baseline. Before production, review permissions and add account recovery, rate limiting, CSRF protection if cookie sessions are used, and security monitoring.


## Current endpoints
- `POST /api/sessions` — open a table session.
- `POST /api/sessions/{session_id}/sets` — add a set or extension.
- `POST /api/orders` — register order items and cast back allocations.\n- `POST /api/orders/{order_id}/cancel` — cancel an unpaid order with reason and audit log.\n- `GET/POST /api/products`, `GET/POST /api/set-menus`, `GET/POST /api/casts`, `POST /api/tables` — master data list/create.\n- `PUT /api/settings` — store tax/service/work-time rounding settings.\n- `POST /api/casts/{cast_id}/payroll/confirm?business_date=YYYY-MM-DD` — provisional payroll calculation and confirmation snapshot.
- `GET /api/sessions/{session_id}/bill` — preview bill and balance.
- `POST /api/payments` — record a payment, including split payments.
- `POST /api/attendance/clock-in`, `POST /api/attendance/clock-out` — attendance.
- `GET /api/casts/{cast_id}/payroll?business_date=YYYY-MM-DD` — provisional payroll.
- `POST /api/closings/run` — validate and attempt daily closing.
- `GET /api/closings/{business_date}` — read closing summary and blocking errors.

The API is not yet a complete operational POS. Verify every SQL statement against the exact MySQL schema and test all business flows on a disposable development database before real transactions.


## Frontend
Open `frontend/index.html` through a static HTTPS host or local static server. Enter the backend API origin in the login panel. Set `CORS_ORIGINS` in the backend environment to a comma-separated exact origin allowlist (for example `https://pos.example.com`). The API server does not enable wildcard origins. HTTPS is required outside local development. Never put DB credentials in the frontend.


## Master data and settings endpoints
- Owner/manager only: create products, set menus, casts, tables and update tax/service/work-time rounding settings.
- Read master lists: products, set menus and casts.
- These are initial endpoints. Edit/deactivate workflows, full permission matrix, settings UI and validation against a real store configuration remain to be completed.
- Payroll confirmation is a preliminary snapshot only. It does not yet calculate statutory overtime/night premiums, statutory breaks, social insurance or tax withholding; obtain Japanese labor/payroll professional review before paying wages.
