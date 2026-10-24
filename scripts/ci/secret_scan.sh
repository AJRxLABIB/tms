#!/usr/bin/env bash
# Secret scan for CI. Prints only file:line and the rule name, never a matched value.
# 1) tools/scan-secrets.php (provider key formats, PEM blocks, long hex secrets)
# 2) gitleaks-style generic assignments: NAME=<value> for secret-looking names with a non-empty, non-placeholder value
# 3) fails if a real .env or private key file is tracked.
set -u
cd "$(dirname "$0")/../.."
status=0

php tools/scan-secrets.php || status=1

tracked=$(git ls-files 2>/dev/null || true)
if [ -n "$tracked" ]; then
  if echo "$tracked" | grep -E '(^|/)\.env$|\.pem$|\.key$|id_rsa$|\.p12$|\.pfx$' | grep -v -E '\.env\.example$'; then
    echo "FAIL: a secret-bearing file is tracked (names listed above)"; status=1
  fi
  # NAME = "value" where NAME looks secret and the value is 16+ chars, not a placeholder/env lookup. -o would print the value, so only list file:line.
  hits=$(echo "$tracked" | grep -E '\.(php|js|json|ya?ml|ini|sh|md)$' | grep -v -E '^(tests/|\.env\.example|node_modules/|vendor/|backend/phpmailer/)' \
    | xargs -r grep -n -I -E "(password|passwd|secret|api[_-]?key|token|private[_-]?key)[A-Za-z_]*['\"]?[[:space:]]*(=>|=|:)[[:space:]]*['\"][A-Za-z0-9+/_=\-]{20,}['\"]" 2>/dev/null \
    | grep -v -i -E "tms_env|getenv|\\\$_ENV|\\\$_SERVER|example|placeholder|changeme|your[_-]|xxxx|<|\\\$[a-z_]+\\)|hash\(|sha256|csrf|nonce|Bearer|bin2hex|random_bytes" \
    | cut -d: -f1,2 | sed 's/$/  generic-secret-assignment/' || true)
  if [ -n "$hits" ]; then echo "$hits"; echo "FAIL: possible hard-coded secrets (file:line only; values not shown)"; status=1; fi
fi
[ $status -eq 0 ] && echo "secret scan: clean"
exit $status
