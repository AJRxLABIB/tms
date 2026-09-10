# ✈ TMS — Tourism Management System

**Version:** 6.0 | **Language:** PHP 8+ | **Database:** MySQL | **Framework:** None (pure PHP + Bootstrap 3)

---

## Quick Start
1. Install XAMPP → start Apache + MySQL
2. Copy `tms/` folder to `htdocs/`
3. Import `tms.sql` then `v6_migration.sql` into phpMyAdmin (database name: `tms`)
4. Edit `includes/config.php` with your DB credentials
5. Open `http://localhost/tms/`
6. Admin: `http://localhost/tms/admin/` — user: `admin` / pass: `Test@12345`

**See `INSTALL.html` for the complete step-by-step guide with screenshots and troubleshooting.**

---

## Default Coupon Codes
| Code | Discount |
|------|----------|
| `WELCOME10` | 10% off |
| `SUMMER20` | 20% off |
| `TMS2026` | 15% off |

## Default seed accounts (local development only)

This project's `tms.sql` seeds one demo admin and one demo user account so
you can log in immediately after a fresh install. **If this site is public
or hosted anywhere outside your own machine, change both passwords
immediately** (admin: Admin > Change Password once logged in, or
`admin/manage-admins.php` as a superadmin; user: Profile > Change Password)
— the credentials that used to be printed here were real, working
passwords for a publicly-documented file, which is a real credential
exposure once this repo or its docs are shared (Rule 5). Check `tms.sql`
directly if you need the exact seeded username/email for your local copy.