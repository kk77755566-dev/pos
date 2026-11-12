# InfinityFree deployment package

This is a PHP/MySQL website port foundation for the Girls Bar POS. It is deliberately separate from the FastAPI backend because the InfinityFree free plan is PHP/MySQL website hosting, not a Python application host or a public API host.

## Current scope
- PHP 8 + PDO MySQL connection
- Session login using password_hash/password_verify
- CSRF token on POST forms
- Session ID rotation on successful login
- Store-scoped table list
- MySQL schema copy using utf8mb4_unicode_ci

The current PHP page is only the deployment foundation: login and store-scoped table list. The full POS workflows (orders, set extensions, back allocations, billing, payment/refund, attendance, payroll and daily closing) still need to be ported from `backend/app/main.py` into ordinary browser-facing PHP page/form handlers and tested on the target host. Do not use the FastAPI app or Dockerfile on InfinityFree.

## Prepare hosting
1. Create an InfinityFree hosting account and a free subdomain.
2. In Control Panel, create a MySQL database.
3. Copy `config.example.php` to `config.php` on your own computer and enter the exact MySQL host, database name, username and password from the control panel. Generate a random app_secret of at least 64 characters. Never commit `config.php`.
4. Upload only `index.php` and `.htaccess` to `htdocs`. Also upload your private `config.php` after filling it locally. Do not upload `config.example.php`, `bootstrap_owner.php`, or the `database` folder.
5. Import the local file `database/schema.sql` using phpMyAdmin (this file is not placed in the public website directory).
6. Use HTTPS and confirm the browser shows the secure connection before staff login.
7. Create the first store and owner using a one-time local bootstrap script; do not expose an unauthenticated web setup page on the public site.

## Important
- Never paste hosting passwords, database passwords, FTP passwords or private tokens into chat or GitHub.
- InfinityFree free hosting does not allow remote MySQL access. Database access must be made by PHP code running in the same hosting account.
- InfinityFree states its free hosting is intended for browser-accessed websites and does not allow API hosting. This package uses normal PHP page requests; do not expose a general-purpose JSON API.
- Do not put the site into real-store operation until all POS workflows and permissions have been ported and tested.
