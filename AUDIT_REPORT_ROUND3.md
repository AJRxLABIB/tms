# TMS Audit — Round 3 (verified with a real live server this time)

This round I actually stood up a full test environment — MariaDB + PHP,
imported `tms.sql`, let the app's own self-healing migration run, created a
real logged-in session, and hit the exact URLs from your screenshots with
`curl`, reading the real PHP error log — instead of only reading code. That
caught one genuine new bug and clarified the rest.

## Real bug found and fixed: package images

Your Muscat screenshot showed "Image Not Available." Investigating why led to
an actual bug I introduced: the themed photo feature I added assigns a full
Unsplash URL to `PackageImage` for the 404 seeded packages, but every page
that displays a package image (`package-list.php`, `package-details.php`,
`destinations.php`, `index.php`, `compare.php`, `wishlist.php`,
`ai-recommend.php`, and `admin/change-image.php`) always prepended
`admin/pacakgeimages/` to it — correct for a locally-uploaded filename, but
wrong for a full URL, producing a broken path like
`admin/pacakgeimages/https://images.unsplash.com/...`, which just tripped the
"image not available" fallback again.

**Fixed:** added `tms_package_image_src()` in `includes/config.php` — it
returns the URL as-is if it's already absolute (`http(s)://...`), otherwise
prepends the local uploads folder like before. Every page above now uses it.
Verified live: `package-details.php?pkgid=409` (Muscat) now renders
`src="https://images.unsplash.com/photo-1548013146-72479768bada?w=800&q=80"`
— a real heritage-site photo, not the placeholder icon.

While fixing this I also went ahead and gave every one of the 404 seeded
packages a real, category-matched photo (beach packages get a beach photo,
heritage sites get a heritage photo, etc.) instead of the generic "no image"
icon, via a new `tms_category_image()` map keyed by `PackageType`, applied
both to new seed inserts and backfilled onto your current data automatically.
The original 9 demo packages (whose real uploaded photos are missing from
`admin/pacakgeimages/`, as noted in Round 1) are untouched by this — if you'd
like those switched to themed photos too instead of re-uploading real ones,
say so and I'll extend the backfill to cover them as well.

## `page.php?type=aboutus` / `tour-history.php`: could not reproduce blank page

I logged in as a real test user and requested these exact URLs against the
current codebase + a freshly-migrated database:

```
page.php?type=aboutus       -> HTTP 200, full About Us content rendered
page.php?type=contact       -> HTTP 200, full Contact form rendered
tour-history.php (logged in) -> HTTP 200, "My Tour History" rendered
```

No errors in the PHP error log for any of these. Given the header/footer in
your Image 4 screenshot are correctly showing the Round 2 Bengali
translations, your server clearly has the updated `includes/config.php` and
`includes/i18n.php` — so if `page.php` or `tour-history.php` are still blank
for you specifically, the most likely explanations, in order of likelihood:

1. **Those two specific files weren't actually overwritten** on your server
   with the Round 2 versions (e.g. if files were copied individually rather
   than replacing the whole folder). Please re-extract the attached zip over
   your XAMPP `htdocs/tms` folder completely, don't merge.
2. **PHP opcache serving a stale compiled copy.** XAMPP enables opcache by
   default on some installs; overwriting a `.php` file on disk doesn't always
   invalidate the cached compiled version until Apache restarts. Restart
   Apache in the XAMPP control panel after updating files.
3. **Browser cache** — do a hard refresh (Ctrl+Shift+R) on these pages.

If it's still blank after all three, please check
`C:\xampp\apache\logs\error.log` (or wherever your PHP error log points) right
after reproducing it and share what's there — Round 1 turned real error
logging back on, so whatever it is will be in that file now, and I can go
straight to the line number instead of guessing.

## Language: main nav bar now fully translated

Your screenshot showed the top bar (Home, My Profile, Wishlist, etc.)
correctly translated but the main nav row (About, Tour Packages,
Destinations, Explore, Features, Blog, Compare, Contact) and the footer's
"Quick Links" still in English — that's exactly the boundary of what Round 2
wired up. Extended `includes/i18n.php` and `includes/header.php`/`footer.php`
to cover all of those too. Verified live: switching to `bn` now translates
the entire main navigation bar and footer quick links, e.g. "Destinations" →
"গন্তব্যসমূহ", "Explore" → "এক্সপ্লোর", persisted via the same
session+cookie+DB mechanism from Round 2.

Scope reminder from Round 2 still applies: this covers site-wide chrome
(header/nav/footer), not the full text of every page in all 8 languages —
that remains a larger content-translation project.

## Files changed this round

`includes/config.php` (new `tms_package_image_src()`, `tms_category_image()`,
`tms_backfill_package_images()`), `includes/i18n.php` (more nav keys),
`includes/header.php` / `includes/footer.php` (remaining nav labels wired to
`tms_t()`), and the image-`src` fix in `package-list.php`, `index.php`,
`compare.php`, `ai-recommend.php`, `destinations.php`, `package-details.php`,
`wishlist.php`, `admin/change-image.php`.
