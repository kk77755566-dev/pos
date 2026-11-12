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
- POST writes that can be retried require an `idempotency_key` (8–100 chars).

## Read routes
- `GET api.php?r=tables`
- `GET api.php?r=products`
- `GET api.php?r=sets`
- `GET api.php?r=casts`

## Write routes currently implemented
- `POST api.php?r=sessions`
  - JSON: `{"table_id":1,"customer_count":2,"idempotency_key":"session-unique-0001"}`
  - Locks the table, rejects an occupied table, stores the business date using the store's timezone/day-start, and marks the table occupied.
- `POST api.php?r=sessions/{session_id}/sets`
  - JSON: `{"set_menu_id":1}`
  - Saves the set name, duration and price snapshots and its end time.
- `POST api.php?r=orders`
  - JSON: `{"session_id":1,"idempotency_key":"order-unique-0001","items":[{"product_id":1,"quantity":2,"allocations":[{"cast_id":1,"allocation_rate":60},{"cast_id":2,"allocation_rate":40}]}]}`
  - Saves order/items/back allocations in one transaction. Allocation rates may total at most 100%; any unallocated portion remains store-side.

## Not implemented yet
Checkout/payment/refunds, cast assignment, attendance, payroll confirmation, daily closing, settings/master-data editing, and the complete touch-first POS interface are not yet available in the PHP edition. The Python/FastAPI backend remains the reference implementation while these workflows are ported and tested.

## Additional workflows (in progress / test before production)
- `GET api.php?r=settings` and `PUT api.php?r=settings`: read/update tax, service fee and work-time rounding settings. PUT requires `X-CSRF-Token`.
- `GET api.php?r=sessions/{id}/bill`: current subtotal, tax/service estimate, payments and remaining balance.
- `POST api.php?r=payments`: register cash/card/QR/etc. payment; closes the session and frees the table only when the calculated balance reaches zero.
- `POST api.php?r=payments/{payment_id}/refund`: manager/owner refund record with reason and idempotency key.
- `GET api.php?r=attendance&business_date=YYYY-MM-DD`
- `POST api.php?r=attendance/clock-in` or `attendance/clock-out`: JSON `{"cast_id":1}`.
- `GET api.php?r=payroll&business_date=YYYY-MM-DD`
- `POST api.php?r=casts/{cast_id}/payroll/confirm?business_date=YYYY-MM-DD`: blocks if clock-out is missing; snapshots rounded minutes, hourly wage and back pay.
- `POST api.php?r=closings/run`: JSON `{"business_date":"YYYY-MM-DD"}`; blocks if open sessions, missing clock-outs or unconfirmed calculated payroll remain.

The workflows above are an initial port, not a production release. Tax/service treatment, business-day edge cases, refund reconciliation, payroll statutory treatment, and daily-close totals still require full integration tests and review. The current PHP dashboard is not yet the complete touch-first POS UI.
