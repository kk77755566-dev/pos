# InfinityFree PHP API (development)

This API is a same-origin PHP endpoint for the InfinityFree shared-hosting edition.
It uses the PHP session created by `index.php`; it does not expose DB credentials or accept a client-supplied store ID.

## Setup safety
- Keep `config.php` out of Git and outside any public download.
- Import `database/schema.sql` into the hosting database only after confirming it is empty or backed up.
- Create the first store/owner locally with the CLI-only `bootstrap_owner.php`; never upload that script.
- Do not deploy the PHP app to the public site until the full workflow and live DB tests are complete.

## Request conventions
- JSON request/response, UTF-8.
- Authenticate through `index.php` first.
- Fetch a CSRF token with `GET api.php?r=csrf`, then send it as `X-CSRF-Token` on every POST.
- All database queries are scoped to the authenticated user's `store_id`.
- Retryable sales writes (sessions, sets, orders, payments and refunds) require an `idempotency_key` (8–100 chars).

## Read routes
- `GET api.php?r=tables`
- `GET api.php?r=products`
- `GET api.php?r=sets`
- `GET api.php?r=casts`

## Browser POS workflows
All routes below are same-origin PHP requests and require the authenticated PHP session. Mutating requests require the session CSRF token in `X-CSRF-Token`.

### Read routes
- `GET api.php?r=csrf`
- `GET api.php?r=tables|products|sets|casts`
- `GET api.php?r=settings`
- `GET api.php?r=sessions/{id}/bill`
- `GET api.php?r=attendance`
- `GET api.php?r=payroll` (owner/manager)
- `GET api.php?r=expenses` and `GET api.php?r=closings` (owner/manager)

### Write routes
- `POST api.php?r=sessions`: open a table session; requires `table_id`, `customer_count`, `idempotency_key`.
- `POST api.php?r=sessions/{id}/sets`: add a set/extension; requires `set_menu_id` and `idempotency_key`.
- `POST api.php?r=sessions/{id}/discounts`: owner/manager applies a fixed-yen or percentage discount before service charge and tax; requires `discount_type`, `discount_value`, `reason`, and `idempotency_key`.
- `POST api.php?r=orders`: add product items and split each item's back among casts; each item's allocations may total at most 100%.
- `POST|PUT api.php?r=tables|products|sets|casts`: create a master record (POST) or update `/{id}` (PUT); manager/owner only. Deactivation is soft-disable.
- `PUT api.php?r=settings`: update tax, service fee and work-time rounding settings; manager/owner only.
- `POST api.php?r=payments`: register cash/card/QR/etc. payment; only closes a session when the calculated balance reaches zero.
- `POST api.php?r=payments/{payment_id}/refund`: manager/owner refund record; reason and idempotency key required.
- `POST api.php?r=attendance/clock-in|clock-out`: staff records attendance for a cast using JSON `{"cast_id":1}`.
- `POST api.php?r=casts/{id}/account`: owner/manager creates a linked cast login account; login ID is 4–120 safe characters and password is at least 12 characters.
- Cast self-service: `GET api.php?r=cast/me`, `POST api.php?r=cast/clock-in`, and `POST api.php?r=cast/clock-out`; the cast ID is derived from the authenticated account.
- `POST api.php?r=attendance/break`: manager/owner correction; JSON `{"attendance_id":1,"break_minutes":60}`; only before payroll confirmation.
- `POST api.php?r=casts/{cast_id}/payroll/confirm?business_date=YYYY-MM-DD`: snapshots effective hourly wage, rounded minutes and back; blocks missing clock-out and insufficient recorded statutory break.
- `POST api.php?r=expenses`: manager/owner expense record with business date, category and amount.
- `POST api.php?r=closings/run`: manager/owner daily close. Blocks open sessions, missing clock-outs, unconfirmed payroll and payment reconciliation differences; stores closing breakdown and detail rows.

The touch-friendly PHP page is `index.php`; its browser client and styles are `pos.js` and `pos.css`. All data access is scoped to the logged-in store. Do not expose a user-supplied store ID.

CI runs PHP lint, MySQL 8 schema import, and an end-to-end test through the PHP built-in server covering login, settings, master-data CRUD, table opening, sets, orders, back splits, bill calculation, payment replay, refund, attendance, payroll, expenses, daily closing and post-close write blocking. The full CI suite must still pass after every change.\n\nThis is not yet approved for real-store operation. Live InfinityFree DB/browser acceptance tests are still required. Statutory overtime, late-night premiums, holiday premiums, 60-hour overtime premiums, insurance, withholding and paid-leave calculations are not implemented; the current payroll covers base hourly pay, back, rounding and recorded statutory-break checks only. Tax/service settings are store-wide; applied discounts and the first-payment bill are stored per session as an immutable snapshot. Do not deploy until these limitations are resolved and the user explicitly approves deployment.
