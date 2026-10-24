#!/usr/bin/env bash
# Migration hygiene for CI: files are numbered, non-empty, additive (no DROP TABLE/DATABASE/COLUMN, no TRUNCATE, no DELETE without WHERE),
# and every number is unique unless the file name is already in the known-duplicate list (005 exists twice from an earlier round).
set -u
cd "$(dirname "$0")/../../database/migrations"
status=0
# Already-applied migrations that predate this rule (tests/run.php carries the same exemption): one reviewed DROP of a dead table.
GRANDFATHERED="009_index_and_dead_table_cleanup.sql"
for f in *.sql; do
  [ -s "$f" ] || { echo "FAIL $f: empty"; status=1; continue; }
  echo "$f" | grep -Eq '^[0-9]{3}_[a-z0-9_]+\.sql$' || { echo "FAIL $f: name must be NNN_snake_case.sql"; status=1; }
  if ! echo " $GRANDFATHERED " | grep -q " $f " && grep -v -E '^\s*--' "$f" | grep -i -E 'DROP[[:space:]]+(TABLE|DATABASE|COLUMN|SCHEMA)|TRUNCATE[[:space:]]|DELETE[[:space:]]+FROM[^;]*;' | grep -v -i 'WHERE' >/dev/null; then
    echo "FAIL $f: destructive statement (DROP/TRUNCATE/unfiltered DELETE)"; status=1
  fi
done
dups=$(ls *.sql | cut -c1-3 | sort | uniq -d | grep -v '^005$' || true)
[ -z "$dups" ] || { echo "FAIL duplicate migration numbers: $dups"; status=1; }
[ $status -eq 0 ] && echo "migrations: $(ls *.sql | wc -l) files OK"
exit $status
