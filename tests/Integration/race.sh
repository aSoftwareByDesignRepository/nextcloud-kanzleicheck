#!/usr/bin/env bash
# race.sh — concurrency regression: OTP single-use + clientUuid dedupe under parallel fire.
# Proves the two contested-write paths stay atomic:
#   RACE 1: two parallel /auth/verify of one code  → exactly ONE OK, one INVALID_CODE
#   RACE 2: two parallel messages, same clientUuid → same id both times, ONE db row
# Requires the seeded smoke fixture from smoke.sh (identity+member) — re-seeds defensively.
set -euo pipefail
BASE=${BASE:-http://localhost:8081}; APP=$BASE/apps/portalcheck
ROOT=$(cd "$(dirname "$0")/../../../.." && pwd)   # nextcloud/ dir holding docker-compose.yml
DC="docker compose -f $ROOT/docker-compose.yml"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
jpost(){ curl -s -X POST -H 'Content-Type: application/json' "$@"; }
bad(){ echo "RACE FAIL: $1"; exit 1; }

# seed smoke member (idempotent — mirrors smoke.sh fixture)
$DC exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -e "
INSERT IGNORE INTO oc_ptl_org (ref,name,kind,state,created_at) VALUES ('SMOKE-1','Smoke Org','company','active',NOW());
DELETE FROM oc_ptl_member WHERE identity_id IN (SELECT id FROM oc_ptl_identity WHERE email='smoke@test.invalid');
DELETE FROM oc_ptl_identity WHERE email='smoke@test.invalid';
DELETE FROM oc_ptl_otp WHERE email='smoke@test.invalid';
INSERT INTO oc_ptl_identity (email,display_name,status,created_at) VALUES ('smoke@test.invalid','Smoke Client','active',NOW());
INSERT INTO oc_ptl_member (identity_id,org_id,role,created_at,created_by) SELECT i.id,o.id,'member',NOW(),'system:race' FROM oc_ptl_identity i, oc_ptl_org o WHERE i.email='smoke@test.invalid' AND o.ref='SMOKE-1';" >/dev/null 2>&1 \
  || bad "seed: mariadb unreachable (docker compose running?)"

# send is anti-oracle (always {sent:true}) — throttled calls mint nothing.
# Confirm a FRESH unused ptl_otp row exists before trusting the outbox code.
CODE=""
for i in 1 2 3 4 5 6; do
  jpost "$APP/portal/api/auth/send" -d '{"email":"smoke@test.invalid"}' >/dev/null
  sleep 1
  FRESH=$($DC exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e \
    "SELECT COUNT(*) FROM oc_ptl_otp WHERE email='smoke@test.invalid' AND used_at IS NULL AND attempts=0 AND created_at > NOW() - INTERVAL 20 SECOND;" | tr -d ' \r')
  [ "$FRESH" = "1" ] && { CODE=$($DC exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e \
    "SELECT JSON_UNQUOTE(JSON_EXTRACT(params_json,'\$.code')) FROM oc_ptl_mail_outbox WHERE kind='otp' ORDER BY id DESC LIMIT 1;" | tr -d ' \r'); break; }
  sleep 10   # throttle decay before retry
done
[ -n "$CODE" ] || bad "send throttled 6× — no fresh OTP minted"

# RACE 1 — same code, two parallel verifies
jpost "$APP/portal/api/auth/verify" -d "{\"email\":\"smoke@test.invalid\",\"code\":\"$CODE\"}" -c "$TMP/ck1" > "$TMP/r1.json" &
P1=$!
jpost "$APP/portal/api/auth/verify" -d "{\"email\":\"smoke@test.invalid\",\"code\":\"$CODE\"}" -c "$TMP/ck2" > "$TMP/r2.json" &
P2=$!
wait $P1 $P2
S1=$(python3 -c "import json;d=json.load(open('$TMP/r1.json'));print('OK' if d.get('success') else d.get('error','ERR'))")
S2=$(python3 -c "import json;d=json.load(open('$TMP/r2.json'));print('OK' if d.get('success') else d.get('error','ERR'))")
echo "verify race: $S1 / $S2"
[ "$S1$S2" != "OKOK" ] || bad "single-use OTP admitted BOTH verifies — race window open"
{ [ "$S1" = "OK" ] || [ "$S2" = "OK" ]; } || bad "neither verify succeeded — OTP broke, not raced"

SESS="$TMP/r1.json"; [ "$S1" = "OK" ] || SESS="$TMP/r2.json"
CSRF=$(python3 -c "import json;print(json.load(open('$SESS'))['data']['csrf'])")
[ -n "$CSRF" ] || bad "no csrf minted by winning session"
SESS="$TMP/ck1"; WIN="$TMP/r1.json"; [ "$S1" = "OK" ] || { SESS="$TMP/ck2"; WIN="$TMP/r2.json"; }
CSRF=$(python3 -c "import json;print(json.load(open('$WIN'))['data']['csrf'])")
[ -n "$CSRF" ] || bad "no csrf token minted by winning session"
ORGID=$(curl -s -b "$SESS" "$APP/portal/api/orgs" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['orgs'][0]['id'])")

# RACE 2 — same clientUuid, two parallel message posts (must be a real uuid)
U=$(python3 -c "import uuid;print(uuid.uuid4())")
curl -s -b "$SESS" -X POST -H 'Content-Type: application/json' -H "X-PTL-CSRF: $CSRF" "$APP/portal/api/orgs/$ORGID/messages" -d "{\"body\":\"race\",\"clientUuid\":\"$U\"}" > "$TMP/m1.json" &
Q1=$!
curl -s -b "$SESS" -X POST -H 'Content-Type: application/json' -H "X-PTL-CSRF: $CSRF" "$APP/portal/api/orgs/$ORGID/messages" -d "{\"body\":\"race\",\"clientUuid\":\"$U\"}" > "$TMP/m2.json" &
Q2=$!
wait $Q1 $Q2
python3 -c "
import json
ids=[]
for f in ('$TMP/m1.json','$TMP/m2.json'):
    d=json.load(open(f)); ids.append((d.get('data') or {}).get('message',{}).get('id') or d.get('error','ERR'))
print('msg race ids:',ids)
assert len(ids)==2 and len(set(ids))==1 and isinstance(ids[0],int), f'dedupe broke: {ids}'
"
CNT=$($DC exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e \
  "SELECT COUNT(*) FROM oc_ptl_message WHERE client_uuid='$U';" | tr -d ' \r')
[ "$CNT" = "1" ] || bad "db row count for uuid = $CNT (expected 1)"
echo "db rows for uuid: 1  |  RACE PASS — OTP single-use + clientUuid dedupe both atomic"
