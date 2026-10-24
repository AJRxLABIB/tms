# Production deployment checklist

Written from what the code does today. Where something has **not** been verified on a real server, it says so.
Related: `ENVIRONMENT.md` (every variable), `SECURITY.md`, `SCHEDULED_JOBS.md`, `BACKUPS.md`, `PAYMENTS.md`, `HOSTING_GUIDE.md`.

## 1. Configuration

1. Copy `.env.example` to `.env` outside any publicly served folder if your host allows it (the shipped `.htaccess` also denies `.env`). Never commit it.
2. Set at least: `APP_ENV=production`, `APP_URL=https://your-domain`, `APP_DEBUG=false`, `DB_HOST/DB_NAME/DB_USER/DB_PASS` (a dedicated least-privilege user, not `root`), `TMS_2FA_KEY` (16+ random characters), `WALLET_ENCRYPTION_KEY` (`php -r "echo bin2hex(random_bytes(32));"`), `TMS_AUTO_SCHEMA=0`, `HEALTH_TOKEN` (16+ random characters), SMTP settings, `TMS_BOOKING_HOLD_MINUTES`.
3. Run `php scripts/check_env.php`. It prints PASS/WARN/FAIL per setting and **never prints a value**. Exit code 1 means at least one FAIL: fix it before going live.
4. Do not set `TMS_ALLOW_SANDBOX_PAYMENTS` in production. Real gateways are not wired up yet (see `PAYMENTS.md`), so a production site currently cannot collect money; do not take bookings that depend on it.
5. AI: with `AI_PROVIDER` unset and no Gemini key the app uses a local Ollama runtime (keep `OLLAMA_BASE_URL` on localhost or a private network). A configured Gemini/OpenAI key sends customer chat text to that vendor. Decide that deliberately and disclose it in your privacy page.

## 2. Database

1. **Back up first**: `php scripts/backup_database.php`.
2. Apply schema changes explicitly: `php database/migrate.php --status`, then `php database/migrate.php`. With `TMS_AUTO_SCHEMA=0` (the production default) web requests never alter the schema, so a missing migration shows up as missing features, not silent changes.
3. Migrations are additive. Constraints that existing data violates are reported and retried on the next run; the runner never deletes data to make them fit.
4. After each deploy, repeat step 2.

## 3. Scheduled work (cron or Windows Task Scheduler)

| Command | How often |
|---|---|
| `php scripts/run_jobs.php --once --rules` | every 1–5 minutes (event outbox worker and workflow rules) |
| `php scripts/expire_pending_bookings.php --apply` | every 10–15 minutes (releases seats of unpaid bookings after `TMS_BOOKING_HOLD_MINUTES`; try without `--apply` first) |
| `php scripts/check_alerts.php` | every 30–60 minutes |
| `php scripts/check_trip_disruptions.php` | daily |
| `php scripts/check_wallet_expiry.php` | daily |
| `php scripts/backup_database.php` | daily |
| `php scripts/restore_database.php --verify` | weekly (restore-tests the newest backup without touching the live database) |

Each script is CLI-only. Give the cron user a PHP binary with `pdo_mysql`, `mbstring`, `curl`, `openssl`. Supervise `run_jobs.php` if you run it as a long-lived loop (systemd, Supervisor, or Task Scheduler "restart on failure"); cron with `--once` needs no supervisor. Check job health on the admin Operations dashboard (failed or stuck outbox events show there).

## 4. Web server, TLS, headers

- Serve only over HTTPS and redirect HTTP to HTTPS. Terminate TLS at Apache/nginx or your proxy; if a proxy does it, make sure it sends `X-Forwarded-Proto: https` so cookies get the `Secure` flag.
- The shipped `.htaccess` sets `nosniff`, `X-Frame-Options`, `Referrer-Policy`, HSTS and a Content-Security-Policy. Apache needs `AllowOverride All` and `mod_headers`. **Verify the headers on your real host** (`curl -I https://your-domain/`): they were never checked on a production server.
- If you use nginx, `.htaccess` files are ignored. Re-create the deny rules for `.env`, `*.sql`, `*.md`, `*.log`, and the folders `tests/`, `scripts/`, `tools/`, `database/` (migrations), `data/`, and `uploads/wallet/`, and disable PHP execution under `uploads/`.
- `tests/`, `scripts/` and `tools/` ship with a deny-all `.htaccess` because some manual diagnostic scripts in `tests/` have no command-line check. On a real server you may delete `tests/manual/` entirely.
- `/health.php` returns only `{"status":"ok"}` or `"down"` (HTTP 200/503) to the public. Send `X-Health-Token: <HEALTH_TOKEN>` for the per-service detail. Point your uptime monitor at it.
- Remove or protect `phpinfo`-style pages (none are shipped). Keep `display_errors=Off` in `php.ini`.

## 5. Files and permissions

- `uploads/` must be writable by the web user and nothing else; `uploads/.htaccess` already refuses script execution; `uploads/wallet/` denies all direct access (documents are served only through `wallet-document.php` after an ownership check, and are AES-256-GCM encrypted at rest).
- Everything else should be read-only to the web user. Backups go outside the web root (see `BACKUPS.md`); keep the backup encryption key separately from the backups.

## 6. Go-live smoke test

1. `php scripts/check_env.php` → 0 failures.
2. `php database/migrate.php --status` → nothing pending.
3. `php tests/run.php`, `php tests/payments/run.php`, `php tests/integrity/stage16_test.php <url>`, `php tests/security/run.php <url>` against a **staging copy**, never against production data (the tests create and delete their own rows but still write to the database they are pointed at).
4. Sign up, log in, enable 2FA (save the recovery codes), book a package, cancel it, confirm the emails or logs.
5. Stop Ollama (or clear the AI key) and confirm search, booking, account and admin pages still work: AI features degrade to their deterministic fallbacks; no booking, payment or login path calls the AI.

## 7. Rollback

- **Code**: keep the previous release folder; switch back by renaming/symlink. Migrations are additive, so older code keeps working against a newer schema.
- **Data**: `php scripts/restore_database.php --restore --confirm` restores a chosen backup (it overwrites the live database: stop the site first, back up the current state before restoring).
- Practice the restore on staging at least once before launch. A backup that has never been restored is not yet a backup.

## 8. Incident checklist

1. Contain: put the site in maintenance mode (or stop the web server) if data integrity is in doubt; stop the cron jobs.
2. Preserve: copy the PHP/Apache error logs and take a database backup of the current state before changing anything.
3. A secret may have leaked: rotate it at the provider (database user, SMTP, AI keys), do not simply replace `TMS_2FA_KEY` or `WALLET_ENCRYPTION_KEY`: neither has a re-key tool (`scripts/encrypt_2fa_secrets.php` only encrypts rows that are still plain text), so a new key makes existing 2FA secrets and wallet documents unreadable. Plan a key rotation separately. Password changes and resets already end a customer's other sessions and API tokens.
4. Review the Operations dashboard (security events, failed webhooks, stuck jobs) and `activity-log.php`.
5. Write down what happened and add a regression test.

## 9. Known limits (not production-ready until addressed)

- No real payment gateway is connected; webhook reconciliation is not implemented.
- Production headers, TLS, backups and cron behaviour have not been verified on a real server by this project.
- Admin accounts do not have recovery codes yet (customers do).
