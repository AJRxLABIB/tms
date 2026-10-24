# tests/fixtures/

**No shared fixture files exist yet.** `tests/run.php` deliberately has
no mocking/fixture layer — its tests query the real local database
directly (see `TESTING.md`'s "WHY NOT PHPUNIT" note: this project has no
Composer setup, and building a fixture/mocking layer for a handful of
assertions would itself be overengineering for its current size, per
Rule 73). This folder is reserved for the day a real fixture need
appears (e.g. E2E test seed data), rather than populated speculatively.
