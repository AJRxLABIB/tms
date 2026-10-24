# tests/legacy/ (bucket, not a physical move)

This project's "legacy" test bucket is `tests/manual/` — kept at its
current path rather than physically relocated into `tests/legacy/`.

Why not move it: `tests/manual/round-diagnostics/` holds 90+ one-off,
already-disabled (404-stubbed) QA/session-simulation scripts, each using
`__DIR__`-relative paths back to `backend/config.php` and friends.
Physically moving that many files means rewriting every one of their
relative path counts by hand for files that already do nothing (they're
inert 404 stubs kept only for their audit-trail comments, per Rule 75) --
real risk of a typo breaking one of the handful not yet disabled, for
zero functional benefit. See `TESTING.md`'s "Throwaway QA/diagnostic
scripts" section for the full convention: every new one-off diagnostic
still goes in `tests/manual/round-diagnostics/`, named
`test-round<N>-<description>.php`, and is disabled to a 404 stub
immediately after use with an explanatory comment retained.
