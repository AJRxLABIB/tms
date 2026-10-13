# Redesign and self-hosted AI: what was verified, what was not

Branch: `redesign-work`. Written at the end of the redesign work; update it as things change.

## How things were tested

All automated checks ran on a **cloud test copy** of this project (PHP 8.3, MariaDB, headless
Chromium, axe-core), not on this XAMPP install. Results below are from that copy.

| Area | Result | How |
|---|---|---|
| AI safety/retrieval evaluation | 41 of 41 cases pass | `php tests/ai/run_eval.php` (fixture data, no model) |
| Re-ranker logic | 11 of 11 pass | `php tests/ai/rerank_test.php` (stub embedder) |
| Payments logic | 18 of 18 pass | `php tests/payments/run.php` (simulated callbacks) |
| Accessibility (WCAG 2.2 AA, axe-core) | no violations on every page checked, light, dark and 390px mobile | see "Pages checked" |

Automated axe checks find roughly a third of real accessibility problems. A pass is not a
guarantee of WCAG conformance; keyboard and screen-reader testing by a person is still needed.

### Pages checked (axe, light + dark + mobile)
Home, login, register, forgot-password, package list and details, hotels, activities, blog,
compare, about, contact, enquiry, reviews, destinations, emergency, cart, checkout, payment,
wallet, loyalty, loyalty history, profile, tour history, wishlist, my trip, notifications,
privacy settings, two-factor setup, terms, privacy policy, dashboard, referral, budget planner,
expense tracker, weather, travel memory, heatmap, tour map, flight status, explore, AI planner,
AI copilot, AI recommender, AI packages, virtual guide, transport compare, packing assistant,
image analyzer, and 33 admin pages (colour contrast only for admin).

Several of the listed pages (login, register, forgot-password, hotels, activities, blog, compare, about, contact, enquiry, destinations, emergency, wishlist, my trip, notifications, custom trip, home when signed in) were checked for **colour contrast only**, not the full rule set.

Not checked: pages that need specific data to render (ticket, group-trip detail, blog post
with real content, explore-view results, wallet-document), and any page reached only after a
real payment.

## NOT tested at all

1. **No real language model has run.** The sandbox has no Ollama. Answer quality, speed on your
   CPU/GPU and the chat/planner behaviour with real model output are unmeasured. To test:
   `ollama pull qwen2.5:3b-instruct`, set `AI_PROVIDER=ollama` in `.env`, run
   `php tests/ai/live_ollama.php`.
2. **The embeddings re-ranker is untested with a real embedding model.** It is off by default
   (`AI_EMBED_MODEL` empty). Its blending and fallbacks are unit-tested; whether it improves
   answers is unknown.
3. **No real payment gateway.** bKash, Nagad and SSLCommerz need merchant credentials; only
   simulated callbacks and the payment-state logic were tested.
4. **The itinerary planner was rendered only from a labelled test fixture**, never from a
   real model response.
5. **`.htaccess` compression/caching, HTTPS redirects and admin icons/images** were not
   verifiable on the PHP built-in server. Check them in XAMPP.
6. No load testing, no cross-browser testing beyond Chromium, no real-device mobile testing,
   no Bengali-language review by a native speaker.

## Things you must do or decide

- Run `php tools/create-admin.php admin` on the real database (the hard-coded default admin was removed).
- Disable demo users: `UPDATE tblusers SET Password='!disabled-demo-account' WHERE EmailId IN (...)`.
- Set `WALLET_ENCRYPTION_KEY` in `.env` (64 hex characters). Without it, wallet uploads are refused.
  Admin > System Health now warns about this.
- **Sample data:** about 400 generated tour packages were auto-created with randomly generated
  ratings. They are no longer created unless `TMS_SEED_SAMPLE_PACKAGES=1`. Existing ones stay until
  you run `php tools/remove-sample-data.php` (dry run) and `--apply` after a database backup.
  Packages with bookings or reviews are kept. Hotels and activities are already labelled as sample data.
- **Terms and Privacy pages** are plain-language drafts written from what the software does. They
  replaced the copied "Yahoo! India" terms and lorem-ipsum privacy text, only where that placeholder
  text was still present. Have them reviewed (ideally by a lawyer) and edit them in Admin > Pages.
- **Cancellation tiers** shown at checkout are hardcoded placeholders. Confirm the real refund policy.
- **Hero photo** needs a licensed image of your own.
- Consider deleting `_deprecated/`.

## Known limitations

- Bootstrap 3, Font Awesome 4 and jQuery were kept on purpose (no rebuild). Some legacy CSS still
  fights the new theme; a few pages use `!important` overrides.
- Dark mode is a token override plus targeted fixes. Pages not listed above may still contain
  hard-coded colours.
- The assistant can only answer from the catalogue and tools it is given. With the generated sample
  packages present it may cite them as if real; remove them before launch.
- The AI evaluation measures guardrails (injection blocking, tool validation, retrieval ranking on
  fixture data), not answer quality.
- Gemini and OpenAI remain optional fallbacks. If `AI_PROVIDER` is not `ollama`, prompts go to a
  third party; the privacy page says so.

## Supplier marketplace (Phase 9)
Built and tested (72 service-level checks, a real browser run of the portal and the customer blackout rule, axe clean on the portal). See `SUPPLIERS.md`. Not done: automated payouts (settlements are bookkeeping only), supplier email verification / CAPTCHA / password reset, supplier document upload, Bengali for the supplier portal, and a run on this Windows/XAMPP install.

## Unverifiable features: foundation only
Live disruption alerts, sustainability scores, visa/entry rules and real-time routing need real data sources or licences, so none is simulated. Built as a foundation only: staff-entered, sourced and dated travel notices (`TRAVEL_NOTICES.md`, `/travel-notices.php`, 27 tests, browser-checked). Still not built: any live feed, any sustainability score, real-time routing, The AI assistant now reads the notices through a read-only tool and is instructed to cite the source/date or say it cannot confirm; how well a real model obeys that is untested (no Ollama run).
