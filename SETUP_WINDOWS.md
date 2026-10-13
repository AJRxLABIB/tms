# Full setup on Windows + XAMPP (this project: `D:\XAMPP\htdocs\tms`)

The error `php is not recognized` only means Windows does not know where PHP is. XAMPP ships PHP at
`D:\XAMPP\php\php.exe`. Either use that full path, or add it to PATH once (step 2).

## 1. Start the services
Open **XAMPP Control Panel** (`D:\XAMPP\xampp-control.exe`) and press **Start** for **Apache** and **MySQL**.
Both must turn green. (If Apache will not start, another program is using port 80 or 443: Skype/IIS/another web server.)

## 2. Make `php` work in the terminal (do once)
In the VS Code terminal (PowerShell), for the current window only:

    $env:Path += ";D:\XAMPP\php;D:\XAMPP\mysql\bin"
    php -v

Permanent: Windows Start -> "Edit the system environment variables" -> Environment Variables -> Path -> New ->
`D:\XAMPP\php`, then another for `D:\XAMPP\mysql\bin`. Close and reopen VS Code. `php -v` must print a version (8.x).
Without changing PATH you can always run `D:\XAMPP\php\php.exe tools\create-admin.php admin`.

## 3. Create the database and load the schema
1. Open http://localhost/phpmyadmin, click **New**, name it `tms`, collation `utf8mb4_general_ci`, **Create**.
2. Select `tms` -> **Import** -> choose `database\legacy-sql\tms.sql` -> Go.
3. Import `database\legacy-sql\v6_migration.sql` the same way.
   (Or in PowerShell from the project folder:
   `cmd /c "mysql -u root tms < database\legacy-sql\tms.sql"` then the same for `v6_migration.sql`.)
The app creates many remaining tables itself the first time it runs (AI, Explore, payments, suppliers, notices, ...),
but a few (for example the API token tables) come from tracked migrations. Run them once, and again after every update:

    php database\migrate.php

If it prints a WARNING about constraints, your old data has orphaned rows (e.g. reviews for a deleted user). Nothing is
deleted for you; fix those rows and re-run.

## 4. Configure `.env` (project root; already open in your editor)
The defaults match a fresh XAMPP, so you can leave the `DB_*` lines **empty**: user `root`, no password, database `tms`.
Only fill these when you need them:

    APP_DEBUG=true                    # fine on your own PC, never on a public server
    WALLET_ENCRYPTION_KEY=<64 hex>    # required for Travel Wallet uploads; generate with:
                                      #   php -r "echo bin2hex(random_bytes(32));"
    GEMINI_API_KEY=                   # optional cloud AI fallback
    # Local AI (recommended, free) - see step 7:
    AI_PROVIDER=ollama
    OLLAMA_BASE_URL=http://127.0.0.1:11434
    OLLAMA_MODEL=qwen2.5:3b-instruct
    # AI_EMBED_MODEL=nomic-embed-text # optional re-ranking
    PAYMENT_PROVIDER=sandbox          # keep sandbox until you have real gateway credentials

**Save the file** (the white dot on the `.env` tab means it is unsaved: press Ctrl+S).
Keep the `WALLET_ENCRYPTION_KEY` safe: if you lose it, uploaded wallet documents cannot be decrypted.

## 5. Check the site opens
Visit http://localhost/tms/ (customer site) and http://localhost/tms/admin/ (admin).
If you see "Database connection failed", MySQL is not started or the `tms` database does not exist (steps 1 and 3).

## 6. Create your admin login
In the project folder (`D:\XAMPP\htdocs\tms`):

    php tools\create-admin.php admin

It prints a random password **once**. Copy it, sign in at http://localhost/tms/admin/, and change it under Change Password.
Running the command again resets that admin's password. Add more admins in Admin -> Manage Admins.

## 7. Local AI with Ollama (optional but recommended)
1. Install Ollama for Windows from https://ollama.com and start it (tray icon).
2. In PowerShell: `ollama pull qwen2.5:3b-instruct` (about 2 GB; works on a CPU, a bit slow).
3. Optional: `ollama pull nomic-embed-text`, then set `AI_EMBED_MODEL=nomic-embed-text` in `.env`.
4. Test: open `http://127.0.0.1:11434` (shows "Ollama is running"), then use the AI Copilot page on the site.
Admin -> AI Safety & Evaluation runs the built-in checks. Never expose port 11434 to the internet.

## 8. Optional pieces
* **Supplier portal:** http://localhost/tms/supplier/register.php, then verify the supplier in Admin -> Suppliers.
* **Travel notices:** Admin -> Travel Notices.
* **Email:** fill `SMTP_*` in `.env` (for example a Gmail app password). Without it, emails are skipped.
* **Scheduled jobs** (price alerts, budget checks, wallet expiry): see `SCHEDULED_JOBS.md`. Example from Task Scheduler:
  `D:\XAMPP\php\php.exe D:\XAMPP\htdocs\tms\scripts\check_alerts.php`.
* **Remove demo data before going live:** `php tools\remove-sample-data.php` and disable demo users.

## 9. Run the tests (all use your real local database and clean up after themselves)

    php tests\ai\run_eval.php
    php tests\ai\rerank_test.php
    php tests\payments\run.php
    php tests\suppliers\run.php
    php tests\notices\run.php
    php tests\security\run.php http://localhost/tms
    php tests\run.php

## Common problems
| Symptom | Fix |
|---|---|
| `php` not recognized | Step 2 (PATH) or use `D:\XAMPP\php\php.exe` |
| Blank page / 500 | Set `APP_DEBUG=true` in `.env`, save, reload; or read `D:\XAMPP\apache\logs\error.log` |
| "Database connection failed" | Start MySQL in XAMPP; make sure database `tms` exists; check `DB_*` in `.env` |
| MySQL will not start | Another MySQL on port 3306; stop it, or change the port in XAMPP |
| Apache will not start | Port 80/443 in use; stop IIS/Skype or change the port in XAMPP |
| CSS/URLs broken | Open the site as `http://localhost/tms/` (the project must sit in `htdocs\tms`) |
| AI says "unavailable" | Ollama not running, or the model name in `.env` does not match `ollama list` |
| Wallet upload refused | Set `WALLET_ENCRYPTION_KEY` and save `.env` |
