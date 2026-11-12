# InfinityFree PHP/MySQL POS

This is the PHP/MySQL browser-hosted edition of Girls Bar POS. It is separate from the FastAPI backend because InfinityFree free hosting is not a Python application host. The PHP page calls same-origin PHP endpoints and connects to MySQL through PDO.

## Implemented in this development branch
- PHP session login, password verification, CSRF protection and session ID rotation
- Store-scoped table, product, set-menu and cast master data
- Touch-oriented responsive POS page for iPad, smartphones and PC
- Table session opening, set/extension registration and product orders
- Per-order-item back allocations to one or more casts
- Tax/service-fee settings and bill calculation
- Payment registration, partial payments and refund records
- Cast clock-in/clock-out, personal attendance/payroll portal, linked cast login accounts, break-time correction and attendance listing
- Effective-dated hourly wage history, work-time rounding and back aggregation
- Expense entry, daily closing checks/reconciliation with saved detail rows, and closed-day sales reporting
- CI lint checks, MySQL 8 schema validation and browser JavaScript syntax validation

## Important limitations before live operation
- Do not use for actual payroll until an accountant/labor specialist reviews statutory overtime, late-night premiums, holiday premiums, 60-hour overtime premiums, insurance, withholding, paid leave and the applicable employment rules. The current PHP calculation covers base hourly pay + back and statutory break-recording checks only.
- Tax/service fee configuration is store-wide; per-session discounts are recorded before tax/service calculation and the first payment freezes the final bill snapshot.
- Full integration testing against a live InfinityFree database and iPad/browser acceptance testing are still required.
- Do not deploy or use with real customer payments until all tests and the production checklist pass.

## Prepare hosting (not yet deployed)
1. Create the InfinityFree hosting account and a MySQL database in its control panel.
2. Copy `config.example.php` to `config.php` locally. Enter the DB host/name/user/password from the control panel and generate a random app_secret of at least 64 characters. Never commit this file or paste credentials into chat.
3. Import `database/schema.sql` into the hosting database only after confirming it is empty or backed up.
4. Create the first store and owner account using `bootstrap_owner.php` locally with PHP CLI. Never upload this script.
5. Upload these files to `htdocs`: `index.php`, `api.php`, `api_workflows.php`, `pos.js`, `pos.css`, and `.htaccess`. Upload your private `config.php` separately. Do not upload the `database` folder, `config.example.php`, `bootstrap_owner.php`, schema or docs.
6. Confirm HTTPS, log in with the owner account, add test tables/products/sets/casts, and test the full workflow with dummy data before any real operation.

## Hosting and security
- Keep `config.php` private; root .htaccess denies direct web access to it.
- InfinityFree free hosting does not allow remote MySQL connections. The PHP code must connect from the same hosting account.
- The API is same-origin and uses the PHP session plus CSRF token. It never accepts a client-selected store ID.
- Use prepared statements, store-scoped queries, soft-disable for master data, idempotency keys for retryable sales writes, and transactional payment/payroll/closing operations.
- Keep the main branch and the original `tax.html` untouched until a separately approved release.
