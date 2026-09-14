# ✈ TMS — Tourism Management System

**Version:** 6.0 | **Language:** PHP 8+ | **Database:** MySQL | **Framework:** None (pure PHP + Bootstrap 3)

---

## Quick Start
1. Install XAMPP → start Apache + MySQL
2. Copy `tms/` folder to `htdocs/`
3. Import `database/legacy-sql/tms.sql` then `database/legacy-sql/v6_migration.sql` into phpMyAdmin (database name: `tms`)
4. Edit `backend/config.php` with your DB credentials
5. Open `http://localhost/tms/`
6. Admin: `http://localhost/tms/admin/` — user: `admin` / pass: `Test@12345`

**See `INSTALL.html` for the complete step-by-step guide with screenshots and troubleshooting.**

---

## Project Structure

```
tms/
├── frontend/     Real implementation of every customer-facing page
│                 (hotels.php, checkout.php, dashboard.php, ...).
├── backend/      Shared PHP logic used by frontend/ and admin/: config,
│                 session/auth, payments/, ai/ (AI features), phpmailer/,
│                 caching, pricing, i18n, etc.
├── admin/        The admin panel (its own header/footer/config live in
│                 admin/includes/ — a separate, admin-only wrapper, not
│                 to be confused with backend/).
├── database/     Schema migrations (migrate.php + database/migrations/) and
│                 the original pre-migration-system baseline SQL files, kept
│                 for reference (database/legacy-sql/).
├── api/          JSON API endpoints (ai.php, chat.php, explore.php, ...).
├── tests/        Automated test runner (run.php) plus tests/manual/ —
│                 one-off QA/session-simulation helper scripts used while
│                 developing (never linked from the live app).
├── css/ js/ images/ fonts/   Static assets (kept at the project root, not
│                 nested under frontend/, so nothing else has to change).
└── *.php         Thin one-line "shims" — e.g. root hotels.php is just
                  `require __DIR__ . '/frontend/hotels.php';`. They exist
                  so every public URL, redirect, fetch() call, sitemap
                  entry, and robots.txt rule that already points at
                  `/hotels.php` keeps working unchanged, while the real
                  code lives in frontend/. See SESSION_SUMMARY_INDEX-1.md,
                  Round 50, for the full rationale.
```

---

## Default Coupon Codes
| Code | Discount |
|------|----------|
| `WELCOME10` | 10% off |
| `SUMMER20` | 20% off |
| `TMS2026` | 15% off |

## Default seed accounts (local development only)

This project's `database/legacy-sql/tms.sql` seeds one demo admin and one
demo user account so you can log in immediately after a fresh install.
**If this site is public or hosted anywhere outside your own machine,
change both passwords immediately** (admin: Admin > Change Password once
logged in, or `admin/manage-admins.php` as a superadmin; user: Profile >
Change Password) — the credentials that used to be printed here were real,
working passwords for a publicly-documented file, which is a real
credential exposure once this repo or its docs are shared (Rule 5). Check
`database/legacy-sql/tms.sql` directly if you need the exact seeded
username/email for your local copy.