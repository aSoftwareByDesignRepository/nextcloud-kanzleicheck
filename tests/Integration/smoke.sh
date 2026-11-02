#!/usr/bin/env bash
# PortalCheck live-instance smoke suite (Docker NC 35 at :8081).
# Replays the full security gauntlet: auth round-trip, replay, CSRF,
# dedupe, uniform-404, companion device flow, outbox delivery.
# Usage: bash tests/Integration/smoke.sh [base_url]
set -euo pipefail
BASE="${1:-http://localhost:8081}"
APP="$BASE/apps/portalcheck"
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); printf '  ✓ %s\n' "$1"; }
bad(){ FAIL=$((FAIL+1)); printf '  ✗ %s — %s\n' "$1" "$2"; }
jget(){ curl -s "$@"; }
jpost(){ curl -s -X POST -H 'Content-Type: application/json' "$@"; }
field(){ python3 -c "import json,sys;d=json.load(sys.stdin);print(eval(\"d$1\"))" 2>/dev/null || echo ""; }

echo "== PortalCheck smoke @ $BASE =="

# 1) public surfaces
[ "$(curl -s -o /dev/null -w '%{http_code}' "$APP/portal/login")" = "200" ] && ok "portal/login renders" || bad "portal/login" "not 200"
B=$(jget "$APP/api/companion/v1/bootstrap")
[ "$(echo "$B" | field "['data']['pushAvailable']")" = "False" ] && ok "bootstrap pushAvailable=false" || bad "bootstrap" "$B"

# 2) unknown e-mail → uniform 202 (discard path, no oracle)
R=$(jpost "$APP/portal/api/auth/send" -d '{"email":"nobody@example.test"}')
[ "$(echo "$R" | field "['data']['sent']")" = "True" ] && ok "send unknown → uniform sent" || bad "send unknown" "$R"

# 3) seed identity+org+member directly in DB (docker mariadb)
cd "$(dirname "$0")/../../../.." 2>/dev/null || true
docker compose exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -e "
DELETE FROM oc_ptl_member WHERE identity_id IN (SELECT id FROM oc_ptl_identity WHERE email='smoke@test.invalid');
DELETE FROM oc_ptl_identity WHERE email='smoke@test.invalid';
DELETE FROM oc_ptl_org WHERE ref='SMOKE-1';
INSERT INTO oc_ptl_org (name,ref,kind,state,app_on,created_by,created_at,updated_at) VALUES ('Smoke Mandat','SMOKE-1','company','active',0,'system:smoke',NOW(),NOW());
INSERT INTO oc_ptl_identity (email,display_name,status,created_at) VALUES ('smoke@test.invalid','Smoke Client','active',NOW());
INSERT INTO oc_ptl_member (identity_id,org_id,role,created_at,created_by) SELECT i.id,o.id,'member',NOW(),'system:smoke' FROM oc_ptl_identity i, oc_ptl_org o WHERE i.email='smoke@test.invalid' AND o.ref='SMOKE-1';" >/dev/null 2>&1 || { bad "seed" "mariadb unreachable"; exit 1; }

# 4) send for known member → code lands in outbox
R=$(jpost "$APP/portal/api/auth/send" -d '{"email":"smoke@test.invalid"}')
[ "$(echo "$R" | field "['data']['sent']")" = "True" ] && ok "send member → sent" || bad "send member" "$R"
CODE=$(docker compose exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(params_json,'\$.code')) FROM oc_ptl_mail_outbox WHERE kind='otp' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d ' \r')
[ -n "$CODE" ] && ok "otp code in outbox" || bad "otp outbox" "no code"

# 5) verify → session + csrf
R=$(jpost "$APP/portal/api/auth/verify" -d "{\"email\":\"smoke@test.invalid\",\"code\":\"$CODE\"}" -c /tmp/ptl_smoke_ck)
[ "$(echo "$R" | field "['data']['csrf']")" != "" ] && ok "verify → session+csrf" || bad "verify" "$R"

# 6) replay same code → INVALID_CODE
R=$(jpost "$APP/portal/api/auth/verify" -d "{\"email\":\"smoke@test.invalid\",\"code\":\"$CODE\"}")
[ "$(echo "$R" | field "['error']")" = "INVALID_CODE" ] && ok "replay → INVALID_CODE" || bad "replay" "$R"

# 7) orgs scoped — capture the smoke org's real id
R=$(curl -s -b /tmp/ptl_smoke_ck "$APP/portal/api/orgs")
echo "$R" | grep -q '"Smoke Mandat"' && ok "orgs scoped to member" || bad "orgs" "$R"
ORGID=$(echo "$R" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['orgs'][0]['id'])")

# 8) CSRF: POST without token → 401; with → 201; same uuid → same id
R=$(curl -s -b /tmp/ptl_smoke_ck -X POST -H 'Content-Type: application/json' "$APP/portal/api/orgs/$ORGID/messages" -d '{"body":"x"}')
[ "$(echo "$R" | field "['error']")" = "UNAUTHORIZED" ] && ok "no-csrf → UNAUTHORIZED" || bad "csrf" "$R"
CSRF=$(grep ptl_csrf /tmp/ptl_smoke_ck | awk '{print $NF}')
R1=$(curl -s -b /tmp/ptl_smoke_ck -X POST -H 'Content-Type: application/json' -H "X-PTL-CSRF: $CSRF" "$APP/portal/api/orgs/$ORGID/messages" -d '{"body":"smoke","clientUuid":"11111111-2222-3333-4444-555555555555"}')
R2=$(curl -s -b /tmp/ptl_smoke_ck -X POST -H 'Content-Type: application/json' -H "X-PTL-CSRF: $CSRF" "$APP/portal/api/orgs/$ORGID/messages" -d '{"body":"smoke","clientUuid":"11111111-2222-3333-4444-555555555555"}')
ID1=$(echo "$R1" | field "['data']['message']['id']"); ID2=$(echo "$R2" | field "['data']['message']['id']")
[ "$ID1" = "$ID2" ] && [ -n "$ID1" ] && ok "uuid dedupe → same id $ID1" || bad "dedupe" "$R1 / $R2"

# 9) foreign org → uniform NOT_FOUND
R=$(curl -s -b /tmp/ptl_smoke_ck "$APP/portal/api/orgs/999/messages")
[ "$(echo "$R" | field "['error']")" = "NOT_FOUND" ] && ok "foreign org → NOT_FOUND" || bad "idor" "$R"

# 10) companion pair → device token → orgs
jpost "$APP/portal/api/auth/send" -d '{"email":"smoke@test.invalid"}' >/dev/null
CODE=$(docker compose exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(params_json,'\$.code')) FROM oc_ptl_mail_outbox WHERE kind='otp' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d ' \r')
R=$(jpost "$APP/api/companion/v1/pair" -d "{\"email\":\"smoke@test.invalid\",\"code\":\"$CODE\",\"deviceName\":\"smoke\",\"platform\":\"android\"}")
TOKEN=$(echo "$R" | field "['data']['deviceToken']")
[ -n "$TOKEN" ] && ok "pair → deviceToken" || bad "pair" "$R"
R=$(jget -H "X-PortalCheck-Device: $TOKEN" "$APP/api/companion/v1/orgs")
echo "$R" | grep -q '"Smoke Mandat"' && ok "device-token orgs" || bad "companion orgs" "$R"

# 11) staff door: anon → 401/403, never data
C=$(curl -s -o /dev/null -w '%{http_code}' "$APP/api/portal/settings")
{ [ "$C" = "401" ] || [ "$C" = "403" ]; } && ok "staff settings anon → $C" || bad "staff door" "$C"

echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" = "0" ]
