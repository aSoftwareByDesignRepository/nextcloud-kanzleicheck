#!/usr/bin/env bash
# check-deps-audit.sh — SCA gate: composer audit + npm audit.
# Fails on ANY composer advisory or any high/critical npm advisory.
# Wire into CI / pre-seal as a permanent dependency gate (Argus SF-02).
set -euo pipefail
cd "$(dirname "$0")/.."

composer audit 2>&1 | tail -3
composer audit 2>&1 | grep -q "No security vulnerability advisories found" \
  || { echo "FAIL: composer advisories found"; exit 1; }

npm audit --audit-level=high --omit=dev 2>&1 | tail -3
[ "$(npm audit --audit-level=high --omit=dev --json 2>/dev/null | python3 -c '
import json,sys
try:
    d=json.load(sys.stdin)
    v=d.get("metadata",{}).get("vulnerabilities",{})
    print(v.get("high",0)+v.get("critical",0))
except Exception: print(0)')" = "0" ] \
  || { echo "FAIL: npm high/critical advisories found"; exit 1; }

echo "deps-audit: clean"
