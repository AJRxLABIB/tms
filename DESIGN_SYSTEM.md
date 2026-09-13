# Design system

## Status today (be honest about this, per the project's own Rule 77)

TMS already has a real, working design system — it just wasn't written
down anywhere as one. `css/modern-theme.css` has carried a documented
token set (`:root` custom properties for color/spacing/shadow/typography)
since Round 20, a light/dark theme convention, and a handful of shared
component classes, all loaded on every page via
`tms_asset_ver('css/modern-theme.css')` (cache-busted by file mtime). This
document is that system written down, plus an honest map of where it is
and isn't actually followed — it does not change any CSS or introduce a
new visual language (Rule 1: never rewrite from scratch; Rule 2: never
break a working page).

The gap this closes is documentation, not implementation: a contributor
opening this project today has no single place to learn "what color is
`--secondary`", "how does dark mode actually get applied", or "is there
already a badge/button/card class for this before I write a new one in a
page's own `<style>` block." That last question matters most — see
**Known inconsistency** below.

## Identity

The file's own header comment states the concept plainly, and it's worth
keeping verbatim rather than re-describing it:

> "Boarding Pass" identity — grounded in the actual artifacts of travel:
> tickets, passport stamps, luggage tags — not a generic SaaS blue
> template.

Fonts follow the same idea: **Fraunces** (display headings — travel-poster
warmth), **Manrope** (body/UI text), and **Space Mono** for anything that
should read like ticket-stock printing — prices, dates, booking references,
verification codes (`.tms-mono`, `.price`, `.badge-price`, `.tms-currency`,
`code.tms-code`).

## Tokens

All defined as CSS custom properties on `:root` in `css/modern-theme.css`
(lines 30–81 as of this writing). Nothing here is a magic number scattered
per-page — every one of these is meant to be referenced with `var(--name)`
rather than re-typed.

| Token | Value | Use |
|---|---|---|
| `--primary` | `#0B2B3C` | Marine ink — the "passport cover" color; primary brand/nav/hero surfaces |
| `--primary-dark` / `--primary-light` | `#071C28` / `#163F54` | Tonal variants of primary |
| `--secondary` | `#0FA3A3` | Lagoon — links, interactive accents, icons |
| `--secondary-light` | `#14C4C4` | Hover/lighter variant |
| `--accent` | `#FF6B45` | Sunset coral — the one warm, energetic color; CTAs and badges only, used sparingly |
| `--accent-dark` | `#E5502B` | Accent hover/pressed state |
| `--brass` | `#C9A227` | Stamp brass — postmark/seal accent, used sparingly |
| `--bg` / `--bg-dark` | `#F4FAFC` / `#E7F2F5` | Page background tint (light theme) |
| `--text` / `--text-muted` / `--text-light` | `#0F2430` / `#5D7481` / `#97ABB6` | Body text, secondary text, disabled/placeholder text |
| `--white` | `#ffffff` | Card/surface white (redefined for dark mode — see below) |
| `--border` | `#DCE6EA` | Default hairline border |
| `--success` / `--success-bg` / `--success-text` | `#16A34A` / `#DCFCE7` / `#14532D` | Status: confirmed/paid/completed |
| `--warning` / `--warning-bg` / `--warning-text` | `#D97706` / `#FEF3C7` / `#78350F` | Status: pending/awaiting review |
| `--danger` / `--danger-bg` / `--danger-text` | `#DC2626` / `#FEE2E2` / `#7F1D1D` | Status: cancelled/failed/rejected |
| `--shadow-sm` / `--shadow-md` / `--shadow-lg` | `0 2px 10px` / `0 10px 32px` / `0 22px 60px rgba(11,43,60,…)` | Elevation scale — pick by how "raised" the element should feel |
| `--radius-sm` / `--radius-md` / `--radius-lg` | `8px` / `16px` / `24px` | Corner radius scale |
| `--transition` | `all 0.3s cubic-bezier(0.4,0,0.2,1)` | The one easing curve everything animates with |
| `--font-display` / `--font-body` / `--font-mono` | Fraunces / Manrope / Space Mono | See Identity above |

The status tokens (`success`/`warning`/`danger`) each ship as a
solid/`-bg`/`-text` triple specifically for the light-chip-with-dark-text
badge pattern used across booking and payment status pills — that triple
is the intended way to build a new status badge, rather than inventing
new hex values per page (see **Known inconsistency** for where that
hasn't happened yet).

## Dark mode

Applied via `html[data-theme="dark"]` attribute selectors (`includes/header.php`
sets the attribute; nothing in the CSS itself decides light vs. dark). The
convention, established at Round 20 (master prompt item 33) and followed
11 times over in this file today: **every token or component that has a
visible color redefines itself under `html[data-theme="dark"] .the-class`
rather than being patched ad hoc**. When adding a new colored component,
the pattern to copy is the sample-data badge below — define the light
version normally, then add one `html[data-theme="dark"] .your-class { … }`
block immediately after it with the same properties re-pointed to
dark-safe values. Never rely on a raw hex value inside a component style
without checking whether it needs a dark-mode twin.

## Component classes

These are the pieces actually centralized in `css/modern-theme.css` today
(selector name → what it's for → where it's used):

- **`.tms-glass` / `.tms-glass-dark`** — frosted-glass surface (backdrop-filter
  blur+saturate over a solid fallback background, so it degrades cleanly on
  browsers without `backdrop-filter` support). Used on the sticky navbar
  once scrolled and the hero search bar.
- **`.btn-primary-tms`** — the filled brand button (primary→secondary
  gradient, white text, lift-on-hover). The default call-to-action button.
- **`.btn-outline-tms`** — the outlined counterpart (transparent, primary
  border/text, fills solid on hover). Secondary actions next to a primary
  CTA.
- **`.tms-mono` / `.price` / `.badge-price` / `.tms-currency` / `code.tms-code`**
  — ticket-stock monospace treatment for anything numeric/coded (see
  Identity above).
- **`.tms-sample-badge` / `.tms-sample-banner`** (Round 37, Rule 3) — the
  standard way to disclose that a listing is sample/demo data rather than
  a real bookable inventory feed (used on `hotels.php`/`activities.php` and
  their detail pages, since this project has no real hotel/activity
  supplier integration). Any future feature that shows placeholder or
  seed data belongs behind this class, not a bare "Coming soon" or, worse,
  no disclosure at all.
- **`.tms-breadcrumb`** (Round 81, master prompt item 53) — SEO breadcrumb
  trail; pairs with `tms_render_breadcrumb_nav()` in `backend/config.php`/
  `includes/config.php`, which emits exactly this markup shape. Don't hand-rolled
  a different breadcrumb markup on a new page — call that function.
- **Utility classes** — `.text-center`, `.mt-40`, `.mb-40`, `.anim-fadeup`
  (the one `fadeInUp` keyframe animation used project-wide, paired with the
  WOW.js `data-wow-delay` attribute convention seen throughout the
  templates).
- **Structural sections** (topbar, main navbar, mega dropdown, hero,
  promo strip, packages grid, destinations, stats banner, reviews, blog
  grid, weather widget, CTA section, footer) are each their own named
  section in the file, used by the handful of top-level marketing pages
  that share that layout (the homepage and similar landing-style pages).
  These are page-layout styles, not a general-purpose component library —
  don't reuse `.tms-hero` conventions expecting them to fit an admin or
  form page.

## Known inconsistency (honest scope note, Rule 77)

Not everything visual in this project draws from this file. A large
number of pages — `frontend/tour-history.php` is a representative example,
and it is far from the only one — define their own page-scoped classes in
a local `<style>` block for things this system already has an answer for:
custom card shadows/radii instead of `--shadow-sm`/`--radius-md`, one-off
status badge colors (`.refund-badge.refund-requested { background:#fef3c7;
color:#92400e; }`) instead of the `--warning`/`--warning-bg`/`--warning-text`
triple, and bespoke modal/timeline styles that don't exist anywhere in
`modern-theme.css` at all.

This document does not attempt to consolidate those page-local styles
into `modern-theme.css` — doing that safely across dozens of live pages,
each already visually correct and shipped, is a large, separate,
CSS-behavior-risking project of its own (Rule 1/2/73: no rewrites, never
casually break something that already works, don't take on more than one
round can actually verify). What this document does is give the next
person touching any of those pages a concrete reference for "this already
exists centrally, reuse it" instead of continuing to invent one-off hex
values and radii per page. Consolidating an existing page onto these
tokens, one page at a time, the same way `BookingRepository` was adopted by
one call site rather than rewritten everywhere at once (see
`backend/repositories/BookingRepository.php`), is the natural next step
whenever one of those pages is touched for another reason anyway.

## Where it's loaded

Every page includes it the same way:

```php
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
```

`tms_asset_ver()` (backend/config.php / includes/config.php) appends the
file's own mtime as a `?v=` query param, so a deployed CSS change busts
browser cache automatically without a manual version bump anywhere.
