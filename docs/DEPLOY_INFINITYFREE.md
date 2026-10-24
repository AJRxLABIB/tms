# Deploying TourBuddy on InfinityFree (free), step by step

Your panel showed two messages. "**No index file was found**" means the site files are not in the `htdocs` folder yet. That is why the site is empty, and this guide fixes it. "**It can take up to 72 hours**" is DNS: new domains need time to spread, and nothing you do speeds it up.

You will do three things: (A) build two things on your own computer, (B) set up the host, (C) upload and test.

---

## A. On your computer (XAMPP running: Apache and MySQL green)

Open the **XAMPP Shell** (or VS Code terminal) in the project folder. In VS Code's PowerShell, call PHP by its full path:

```
cd D:\XAMPP\htdocs\tourbuddy
D:\XAMPP\php\php.exe scripts/export_install_sql.php
D:\XAMPP\php\php.exe scripts/make_deploy_package.php
```

1. The first command creates **one SQL file**: `database\tourbuddy.sql`. It contains every table and your public catalogue (packages, hotels, activities, blog, pages, map places). It does **not** contain users, bookings, payments or passwords.
   - If it prints a warning that the file is over 8 MB, run it again as `D:\XAMPP\php\php.exe scripts/export_install_sql.php --split-mb=6`. You then get `tms_install_part01.sql`, `..._part02.sql` and so on. Import them in number order.
   - The script has been tested only on its text-formatting parts, not against a real MySQL. Look at the table list it prints: you should see all your tables and row counts for the catalogue tables.
2. The second command builds a clean copy of the site in `deploy\htdocs\`: no `.env`, no tests, no uploads. Expect roughly 570 files and 12 MB.

Optional sanity check of your settings: `D:\XAMPP\php\php.exe scripts/check_env.php`. It checks your **local** `.env`. On the host you cannot run it (no command line), and in production mode it insists on `https://` in `APP_URL`, so ignore that line until SSL is on.

---

## B. On InfinityFree (client area)

1. **PHP version.** Open your hosting account, then the PHP version setting (Hosting Account, then "PHP Version" or "Select PHP version"). Choose **8.1 or newer**. TourBuddy will not run on PHP 7.
2. **Create the database.** Client area → your hosting account → **MySQL Databases** → create one named `tourbuddy`. Write down four things the panel shows:
   - **MySQL hostname** (like `sql123.infinityfree.com`, never `localhost`)
   - **Database name** (like `if0_12345678_tms`)
   - **Username** (like `if0_12345678`)
   - **Password** (your hosting account password)
3. **Import the SQL.** On the MySQL Databases page click **phpMyAdmin** next to your database. Select the database on the left, open the **Import** tab, choose `database\tourbuddy.sql`, leave the defaults and click **Import**.
   - Do **not** tick "ignore errors". A clean import ends with a green "Import has been successfully finished". If it shows a red error, read the sentence after "#1xxx": it names the table or line. Re-importing is safe because the file uses `IF NOT EXISTS` and `INSERT IGNORE`.
   - If the import times out, re-run step A with `--split-mb=3` and import the parts one at a time, in order.
4. **Remove the placeholder.** In File Manager (or FTP), open `htdocs` and delete any default file like `index2.html` or `default.php` that InfinityFree placed there.

---

## C. Upload, configure, test

1. **Upload with FTP** (File Manager cannot unzip and is slow for hundreds of files).
   - Install FileZilla. In the client area open your account and copy the **FTP hostname** (`ftpupload.net`), **FTP username** and password.
   - Connect, open the `htdocs` folder on the remote side.
   - On your computer open `D:\XAMPP\htdocs\tourbuddy\deploy\htdocs\`, select **everything inside it** and drag it into remote `htdocs`.
   - Correct result: `htdocs/index.php`, `htdocs/.htaccess`, `htdocs/backend/`, `htdocs/frontend/`. Wrong result: `htdocs/htdocs/index.php` or `htdocs/tourbuddy/index.php` (this is what gives "No index file").
   - Make sure hidden files show up: in FileZilla use Server → "Force showing hidden files". The `.htaccess` file must be there.
   - Site images (`images/`) and package photos (`admin/pacakgeimages/`) are part of the package. Anything customers or admins uploaded later into `uploads/` (wallet documents, review photos, map-place photos) is deliberately **not** packaged, so upload those files separately if you need them.
2. **Create `.env` on the server.** File Manager → `htdocs` → **New File** → name it `.env` → edit. Paste the contents of `.env.infinityfree.example` (it is in the package) and fill in `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `TMS_BOOTSTRAP_ADMIN_PASSWORD` (12+ characters) and the two secret keys (64 hex characters each from any password generator that can output hex). The file is blocked from the web by `.htaccess`.
3. **Wait for DNS** (up to 72 hours). Until it works, the panel's temporary address for your account may open the site. Check with `http://tourbuddy.kesug.com`.
4. **SSL.** In the client area use **Free SSL Certificates**, request one for your domain and follow the steps. When the certificate is active, change `APP_URL` in `.env` to `https://tourbuddy.kesug.com`. The site sends an HSTS header, which only has an effect over https.
5. **First load and admin.** Open the site once. Because `TMS_AUTO_SCHEMA=1`, it checks the tables and creates the admin user `admin` with your `TMS_BOOTSTRAP_ADMIN_PASSWORD`. Sign in at `/admin/`, then **delete the `TMS_BOOTSTRAP_ADMIN_PASSWORD` line** from `.env`.
6. **What to test:** home page and CSS look right, Destinations, a package page, register and log in as a customer, a booking with the demo payment, the map page (`/tour-map.php`), and the admin dashboard. If something looks wrong, open `/health.php` (shows only "ok" or not).

---

## Limits of free hosting (honest list)

I could not test on InfinityFree itself, so treat the uncertain items as "check on your account".

| Topic | What to expect |
|---|---|
| No command line, no cron | The scheduled scripts (`expire_pending_bookings.php`, `run_jobs.php`, `expire_inventory_holds.php`, backups) cannot run on the host. Unpaid pending bookings therefore keep holding seats until an admin cancels them. Take backups yourself from phpMyAdmin (Export). There is no safe web endpoint for these jobs, and I did not add one. |
| Outbound web requests | InfinityFree restricts what your site can call. Likely to fail or be slow: AI services (and any Ollama, which cannot be reached anyway), Overpass places, Nominatim search, routing, weather, exchange rates. TourBuddy degrades gracefully: AI answers come from the catalogue, "nearby" uses saved places, routes show a clear message. The map itself loads from the visitor's browser, so it is not affected. |
| AI | `AI_PROVIDER=none`. Chat and Explore work from catalogue data only. |
| Email | No SMTP is configured, so confirmation and password-reset mails are only logged, not delivered. Password reset therefore will not work for customers. |
| Payments | Demo only. The sandbox payment moves no money. |
| Uploads | Limited file size and storage (a few hundred MB, check your plan). Large photos should be resized first. |
| Traffic | Daily hit and CPU limits apply. Heavy pages may show a 508 error at busy times. |
| Database size | Free MySQL is small. The catalogue export is well under it. |

---

## Troubleshooting

| What you see | Likely cause and fix |
|---|---|
| "No index file was found" in the panel, or a 404 | Files are in a subfolder. `index.php` must be directly inside `htdocs`. Move the contents up one level. |
| Page opens but has no CSS or images | Same cause, or the `css/`, `js/`, `images/` folders were not uploaded. Re-upload them. |
| 500 Internal Server Error | Most often PHP is below 8.1: set 8.1+ in the panel. Otherwise look at the `error_log` file in `htdocs` (File Manager) and check `.htaccess` was uploaded unmodified. |
| Blank white page | Set `APP_DEBUG=true` in `.env` for one minute to see the message, then set it back to `false`. |
| "Access denied for user" or "Unknown database" | `DB_HOST`, `DB_NAME`, `DB_USER` or `DB_PASS` in `.env` is wrong. Copy them again from the MySQL Databases page. `DB_HOST` is never `localhost` here. |
| "Table ... doesn't exist" | The SQL import did not finish. Re-import (safe to repeat) and read any red error. |
| Cannot sign in as admin | `TMS_BOOTSTRAP_ADMIN_PASSWORD` was shorter than 12 characters or the admin table already had a row. Check the `admin` table in phpMyAdmin. |
| Site works over http but not https | The SSL certificate is not active yet. Keep `APP_URL` as `http://` until it is. |
| Map is grey | Your visitor's browser could not reach the map service. Reload; if it persists, the free OpenFreeMap service may be down (it has no uptime guarantee). |
