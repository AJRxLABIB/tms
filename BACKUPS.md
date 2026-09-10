# Database backups (master prompt item 60)

Honest summary of what exists: automated encrypted local backups with a
retention policy, an optional "offsite" copy to a folder you point at, and a
real restore-test mode. No cloud storage account is configured for this
project, so there is no automatic upload to S3/GCS/etc — see "Offsite copies"
below for what "offsite" actually means here instead of pretending an
integration exists that isn't wired to real credentials.

## What runs, and when

| Script | Schedule | What it does |
|---|---|---|
| `scripts/backup_database.php` | Daily | Dumps the DB, encrypts it, prunes old backups, optionally copies it offsite, logs the result. |
| `scripts/restore_database.php --verify` | Weekly | The actual restore test: decrypts the newest backup into a throwaway temp file and confirms it's a real, complete, restorable dump. Never touches the live database. |

Add both to `SCHEDULED_JOBS.md`'s table and your OS scheduler alongside the
existing jobs — same setup pattern (Windows Task Scheduler / cron), see that
file for the exact commands.

## One-time setup

1. Generate an encryption key:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   ```
2. Put it in `.env` as `BACKUP_ENCRYPTION_KEY=<the hex string>`. Without this,
   `backup_database.php` refuses to run rather than writing an unencrypted
   dump — there's no insecure fallback mode.
3. **Store this key somewhere other than the `backups/` folder itself** (a
   password manager, a secrets vault, printed and locked in a drawer — your
   call). If you lose it, every existing encrypted backup becomes permanently
   unreadable; there is no recovery path, by design (that's what "encrypted"
   means). Losing the key is exactly as bad as losing the backups.
4. If `mysqldump`/`mysql` aren't on your `PATH` (common on Windows/XAMPP),
   set `MYSQLDUMP_PATH` / `MYSQL_PATH` in `.env` to their full paths, e.g.
   `C:\xampp\mysql\bin\mysqldump.exe`.
5. Optional: set `BACKUP_RETENTION_COUNT` (default 14) to keep more or fewer
   backups locally.

## Where backups live

`backups/` in the project root, blocked from the web by its own
`.htaccess` (same `Require all denied` pattern as `database/`) — created
automatically on first run. Each file is named `tms_YYYYMMDD_HHMMSS.sql.enc`.
The plaintext SQL dump is never written to disk on its own; it's piped
through OpenSSL encryption (AES-256-GCM, via PHP's own `openssl_encrypt` —
no external `openssl.exe` needed) before the encrypted file is the only
thing that touches the filesystem.

## Offsite copies — what this actually means

This project has no S3/GCS/Backblaze account configured, and the honest
choice (per this project's Rule 3 — never fabricate a working integration)
is not to pretend one exists. Instead, set `BACKUP_OFFSITE_PATH` in `.env` to
any second folder the backup script can write to — a Dropbox/Google
Drive/OneDrive desktop sync folder, a mapped network share, an external
drive plugged in on a schedule. The script copies the same encrypted file
there after each successful local backup. That folder's own sync/replication
is what makes it "offsite" — the script's job is just getting the encrypted
file into it. If/when this project gets a real cloud storage account, this
is the one place to add an actual upload call.

Leaving `BACKUP_OFFSITE_PATH` blank is completely safe — backups still run
and still get encrypted and retained locally, there's just no second copy.

## Restore testing — proving a backup actually works

"We take backups" and "our backups actually restore" are different claims.
`restore_database.php --verify` is what closes that gap: it decrypts a
backup into a temp file (deleted immediately after), confirms it decrypts
cleanly (AES-GCM's auth tag catches corruption or a wrong key) and looks
like a genuine, non-truncated SQL dump, then exits 0 (pass) or non-zero
(fail) so it can be wired into whatever alerts you on a failed cron job.
Running this weekly is what turns "we have backups" from a hope into a
tested fact.

```
php scripts/restore_database.php --list                    # see what's available
php scripts/restore_database.php --verify                  # test the newest backup
php scripts/restore_database.php --verify --file=tms_20260910_200000.sql.enc  # test a specific one
```

## Actually restoring (the real, destructive thing)

```
php scripts/restore_database.php --restore --file=tms_20260910_200000.sql.enc --confirm
```

- `--file=...` is required (no "restore the newest one" default — naming the
  exact file is the point, since restoring the wrong backup by accident is
  the actual disaster this guards against).
- Without `--confirm`, it prints what it *would* do and changes nothing —
  use this to double-check the file/target database before committing.
- This overwrites the live database named in `.env`. There's no built-in
  "backup before restore" step (that would just be another backup, which you
  should already have run first if you're worried about this).

## Retention policy

`BACKUP_RETENTION_COUNT` (default 14) most-recent local backups are kept;
older ones are deleted automatically after each successful run. This bounds
disk usage without you having to manage it by hand. The offsite folder is
**not** pruned by this script — if you need retention there too, your sync
provider's own versioning/retention settings apply, or prune it yourself.

## Logging

Every run (success or failure) writes a row to `tblbackuplog` (created
automatically on first run) — filename, size, status, whether the offsite
copy succeeded, and a note. Query it directly, or check
`admin/operations-dashboard.php` for a summary if that's been wired up to
read it.

## What this deliberately does NOT do

- No point-in-time / continuous replication — this is periodic full dumps,
  which matches this project's actual scale and traffic (Rule 73: don't
  overengineer). If this app ever has a real production workload where
  losing up to a day of data is unacceptable, that's the point to add
  binlog-based point-in-time recovery — not before.
- No automatic cloud upload (see "Offsite copies" above) — wiring up a real
  S3/GCS client with no actual account/credentials would mean fabricating an
  integration that doesn't work, which is worse than being upfront that it
  isn't there yet.
