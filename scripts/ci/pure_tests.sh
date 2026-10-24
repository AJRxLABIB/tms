#!/usr/bin/env bash
# Tests that need no database, no network and no model. Safe to run anywhere PHP 8.1+ and Node are installed.
set -u
cd "$(dirname "$0")/../.."
status=0
run() { echo "== $*"; "$@" || { echo "FAILED: $*"; status=1; }; }
run php tests/api/contract_test.php
run php tests/ai/run_eval.php
run php tests/ai/banglish_test.php
run php tests/ai/budget_parse_test.php
run php tests/ai/query_parser_test.php
run php tests/ai/rerank_test.php
run php tests/ai/quality_scorer_test.php
run php tests/unit/business_metrics_test.php
run php tests/suppliers/quality_test.php
run node tests/pwa/sw_test.js
exit $status
