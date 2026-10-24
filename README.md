# TourBuddy — Tourism Management System

PHP 8+ · MySQL/MariaDB · plain PHP + Bootstrap 3 + vanilla JS (no framework).

## Quick start (XAMPP)
1. Copy this folder to `htdocs/tourbuddy`, start Apache and MySQL.
2. In phpMyAdmin create a database named `tourbuddy`, open **Import** and import the one file: `database/tourbuddy.sql`.
3. Copy `.env.example` to `.env` and set `DB_NAME`, `DB_USER`, `DB_PASS`.
4. Open `http://localhost/tourbuddy/`.
5. Create an admin: `php tools/create-admin.php` (prints a random password once), then sign in at `/tourbuddy/admin/`.

Windows PHP path: `D:\XAMPP\php\php.exe`. Hosting for free: `docs/DEPLOY_INFINITYFREE.md`.

## Folder structure
```
index.php     Front controller: the only PHP file in the root. It runs the page for URLs such as /hotels.php,
              so every link and payment-callback URL still works.
.htaccess     Security headers, CSP, and the rule that sends page URLs to index.php (needs mod_rewrite).
frontend/     Every customer page (hotels.php, checkout.php, dashboard.php, ...).
admin/        Admin panel.
supplier/     Supplier portal.
backend/      Shared PHP: config, auth, payments, ai/, services/, map code, header/nav/footer, i18n.
api/          JSON endpoints (ai, chat, explore, map, REST).
database/     tourbuddy.sql (the one install file) + migrations/ and migrate.php for later upgrades.
css/ js/ images/ fonts/   Static assets.
scripts/      CLI jobs (backups, expiry, exports, map import, deploy package).
tools/        One-off admin tools (create-admin, scan-secrets).
data/         Seed JSON used to fill sample packages/hotels/activities.
tests/        Automated tests (php tests/run.php).
docs/         Setup, deployment, security, API, AI and other guides.
uploads/      User uploads (protected, not in git).
```

## Database
- **Fresh install:** import `database/tourbuddy.sql` once. It has every table and the public catalogue.
- **Rebuild that file from your local database:** `php scripts/export_install_sql.php` (catalogue only, no customer data).
- **Upgrade an existing database later:** `php database/migrate.php --status`, then `php database/migrate.php`.

## Useful commands
`php scripts/check_env.php` (check settings) · `php tests/run.php` · `php scripts/make_deploy_package.php` · `php scripts/make_brand_assets.php` (logo icons)

Under PHP's built-in server use: `php -S 127.0.0.1:8099 index.php`.
