# Scope Exclusions (Rule 3 — never fabricate real-world data)

This file is the single, permanent place to look for master-prompt items
that are **deliberately not built**, so the decision doesn't stay buried
in a 1,300-line session log. Each entry states what was asked for, why it
isn't built, what exists instead today, and what it would take to build it
for real. Nothing here is an oversight — every item was reviewed and the
exclusion was a deliberate call under Rule 3 ("never fabricate real-world
data").

## Excluded: Visa / Entry Requirement System (master prompt item 18)

**What was asked for:** a data-driven system mapping nationality ×
destination to visa requirements, passport-validity rules, visa duration,
required documents, official application sources, and processing times.

**Why it stays excluded:** this data is (a) real, regulated legal/
immigration information that (b) changes without notice, per government,
and (c) has serious real-world consequences if wrong — a traveler denied
boarding, fined, or deported because a site told them the wrong thing.
This project has no licensed connection to an authoritative source (a
government visa API, or a commercial aggregator like Sherpa, VisaGuide, or
IATA Timatic) and never has. Every one of the roughly 200×200 nationality/
destination combinations this would need is either invented (a direct
Rule 3 violation, and one with meaningfully higher stakes than any other
fabricated content in this project, since it can cause real harm to a real
traveler) or would need continuous, correct, paid data licensing this
project doesn't have.

This is treated differently from the Round 37 hotel/activities
marketplace decision below, even though both started from the same "no
real data" problem. A labeled "Sample Listing" hotel booking is inert if a
visitor ignores the label — worst case, they're confused about a demo
feature. A "Sample Data" visa requirement is not inert the same way: a
traveler who skims past a disclaimer and books a flight based on invented
entry requirements can face real immigration consequences. That
asymmetry, not a difference in principle, is why one shipped as clearly-
labeled sample data and the other did not.

**What exists today instead (already shipped, not new):**
- `includes/chatbot.php`'s visa/passport/document intent already responds
  with general, safe guidance only ("requirements vary by nationality and
  destination," recommends the traveler check official embassy websites,
  offers a human enquiry) — never a specific per-country claim.
- `wallet.php` (Travel Wallet) lets a traveler upload and store their
  *own* visa/passport/insurance documents for their own reference — this
  is personal document storage, not the system generating requirement
  data, so it carries none of the same risk.
- The master prompt's own required disclaimer — "Verify requirements with
  the relevant official authority before travel" — is the standard this
  project already follows by not presenting any invented specifics at all
  (the safest possible reading of that instruction).

**What it would actually take to build:** a paid, licensed data feed from
an authoritative visa-data provider (commercial aggregator or direct
government API access), a support/liability review appropriate for
publishing regulated legal-adjacent content, and a decision from the
project owner to take on that cost and responsibility. None of that exists
today. If that changes, this item should be revisited from scratch rather
than adapted from a placeholder, since there's nothing here to adapt from.

**Status: stays excluded until real licensed data access exists.** Not a
placeholder, not a stub, not a "coming soon" page — there is no
user-facing trace of this feature anywhere in the app, which is itself
the honest choice (Rule 7 — no fake placeholder features).

## Resolved differently: Hotel / Activities Marketplace (items 12/13)

Flagged in earlier rounds as the same "no real data" problem as the visa
system above, but resolved differently in Round 37 after the project
owner was asked directly and chose to proceed with clearly-labeled sample
data rather than skip the feature. See `SESSION_SUMMARY_INDEX.md`'s
Round 37 section and `DATABASE.md`'s schema-debt notes for the full
build. The two decisions aren't in tension — they reflect the asymmetry
described above: a fake hotel booking is a harmless demo; fake entry-
requirement data is not.
