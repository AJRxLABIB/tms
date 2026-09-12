# Getting TMS live on the public internet — and into Google

This is the step-by-step path from "runs on my computer" to "shows up when someone
searches Google." It's written for the free-hosting route (InfinityFree), since
that's what you picked, but the DB/API-key steps are the same on any host.

## What I already fixed, before we host anything

Hosting a broken site just publishes the brokenness, so I fixed the two things
that would have made this pointless first:

1. **Almost every page redirected anonymous visitors — including Google's
   crawler — straight back to the homepage.** Your own `robots.txt` and
   `sitemap.php` already listed package pages, blog posts, the tour map,
   weather, reviews etc. as meant to be public; a later "require login
   everywhere" change had silently gated all of them too, so no matter where
   you hosted this, Google could only ever have indexed your homepage. That's
   fixed — browsing/marketing pages are public again, booking/account pages
   (cart, checkout, profile, wallet, wishlist, tickets, dashboard...) still
   require sign-in exactly as before.
2. **Your real API keys were sitting in `.env.example` instead of `.env`.**
   `.env.example` is a template — it's supposed to hold blank placeholders,
   and `.env` is the file that actually gets read (and is the one blocked
   from being downloaded and kept out of git). I moved your real Gemini,
   OpenWeatherMap and Google Maps keys into `.env` and reset `.env.example`
   back to blank placeholders. I confirmed live that the AI chatbot is now
   giving real Gemini answers instead of its canned fallback.
3. I also added `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` to `.env` (they
   were missing) — this is the **only thing you'll need to change** to point
   the app at a new host's database instead of your local XAMPP one. Left
   blank, everything still behaves exactly like it does now.

## Step 1 — Export your database

You'll move this project's data with a plain `.sql` file.

1. With XAMPP running, open **http://localhost/phpmyadmin**
2. Click the **`tms`** database in the left sidebar
3. Click the **Export** tab at the top
4. Leave it on **Quick** / format **SQL**, click **Go**
5. Save the downloaded file somewhere you can find it (e.g. `tms_export.sql`)

That file is your entire database — packages, users, bookings, everything.

## Step 2 — Sign up for free hosting

**[InfinityFree](https://www.infinityfree.com)** — free forever, no credit
card, PHP 8.3, MySQL, 5GB storage (this project is 13MB, so that's plenty),
free SSL, and either a free subdomain or your own domain if you already have
one.

1. Go to infinityfree.com and create an account
2. Create a new hosting account/website — it'll ask you to pick a subdomain
   like `yourname.infinityfreeapp.com` (or connect a domain you own)
3. Wait for it to finish provisioning (usually a few minutes)
4. In the control panel, find:
   - **File Manager** (or FTP details, if you'd rather use an FTP client)
   - **MySQL Databases** — create one, note the host/database name/username/
     password it gives you (often *not* `localhost` — something like
     `sqlXXX.infinityfree.com`)
   - **phpMyAdmin** (for importing your `.sql` file)

**Send me:** the FTP host/username/password (or tell me you'll upload
yourself) and the MySQL host/database name/username/password. I don't need
your account login itself, just those two sets of connection details.

## Step 3 — Deploy

Once I have those details, or once you've done it yourself, here's what needs
to happen (I can walk you through any of it, or do the file-editing parts
directly):

1. **Upload the project files** — everything in this folder except `.git`,
   `uploads/*` (leave the folder, skip its contents), and `.env` itself stays
   local for now — via File Manager (zip it, upload, extract) or FTP, into
   the host's `htdocs`/`public_html` folder.
2. **Import the database** — in the new host's phpMyAdmin, create/select the
   database, Import tab, choose your `tms_export.sql`, Go.
3. **Create `.env` on the new host** — copy `.env.example` to `.env` there
   and fill in:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — from the host's MySQL panel
   - `GEMINI_API_KEY` and the others — your existing keys (I have them ready
     to hand over securely, or you can copy them from your local `.env`)
4. **Set folder permissions** on `uploads/` if the host requires it (usually
   755, InfinityFree's File Manager has a permissions option per folder).
5. Visit the new URL and click through: homepage, a package page, the tour
   map, weather, the chatbot.

## Step 4 — Get it into Google

Once the site is live at its new public URL:

1. Go to **[Google Search Console](https://search.google.com/search-console)**,
   sign in with any Google account, and add your site (the "URL prefix"
   method — paste your full site URL).
2. Verify ownership. Search Console will offer a few methods; the simplest
   here is **"HTML tag"** — it gives you a `<meta name="google-site-
   verification" ...>` snippet. Send it to me and I'll add it to
   `backend/header.php` so it's on every page, or you can paste it in
   yourself if you're doing the file editing.
3. Once verified, go to **Sitemaps** in the left sidebar and submit:
   `sitemap.php` (your project already generates this dynamically — package
   pages, blog posts and CMS pages are all included automatically, and it
   updates itself as your catalogue changes, no rebuild needed).
4. That's it from your side. Google typically crawls a new sitemap within a
   few days, and full indexing of hundreds of pages can take anywhere from
   days to a few weeks. You can check progress any time by searching
   `site:yourdomain.com` on Google, or from the Search Console "Pages" report.

## Realistic expectations for the free tier

- **No SSH** on InfinityFree — file changes go through their File Manager or
  FTP, not a terminal. I can prepare files, but pushing them to the live
  server needs your FTP credentials or you doing the upload.
- **Outgoing email is usually blocked** on free shared hosts. Right now this
  app already degrades gracefully when there's no email set up (it logs
  instead of sending), so nothing breaks — booking confirmations just won't
  arrive by email unless you later add a real SMTP provider (a free-tier
  transactional email service like Brevo or Resend works over port 587 even
  where PHP's built-in `mail()` is blocked).
- **CPU/execution-time limits** exist on free shared hosting to stop abuse.
  This app's heaviest operations are external API calls (Gemini, weather,
  map data), not local computation, so it should be fine — but if a free
  host ever throttles or suspends the account, that's the usual reason on
  these plans, and it's a hosting-tier limitation, not a bug in the code.
- **A free subdomain is completely fine for Google** — indexing doesn't care
  whether it's `yourname.infinityfreeapp.com` or `yourbrand.com`. A custom
  domain (~$8–12/year from any registrar) helps branding and trust, not
  search-engine eligibility.
- Free hosts can be slower and occasionally less reliable than paid hosting.
  If this becomes a real business rather than a demo, revisiting hosting is
  worth it later — but free is a perfectly good way to get it live and
  indexed today.
