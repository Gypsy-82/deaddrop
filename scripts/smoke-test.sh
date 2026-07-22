#!/usr/bin/env bash
# Deaddrop smoke test — run on a machine WITH Docker (e.g. your Kali box), from the repo root:
#   bash scripts/smoke-test.sh
#
# Boots the stack and checks the security-critical guarantees automatically. The one thing it
# can't check is the in-browser decrypt/download (that needs a real browser) — see the notes
# it prints at the end.
set -u
cd "$(dirname "$0")/.." || exit 1

DC="docker compose --env-file config/settings.env"
pass=0; fail=0
ok(){ printf '  \033[32m✔\033[0m %s\n' "$1"; pass=$((pass+1)); }
no(){ printf '  \033[31mx\033[0m %s\n' "$1"; fail=$((fail+1)); }
chk(){ [ "$2" = "$3" ] && ok "$1 ($2)" || no "$1 (got '$2', want '$3')"; }

command -v docker >/dev/null || { echo "docker not found — run this on the Kali box"; exit 1; }

echo "== build + boot =="
$DC up -d --build || { echo "compose up failed"; exit 1; }
printf "waiting for health"; for i in $(seq 1 30); do
  h=$($DC ps 2>/dev/null | grep -c healthy)
  [ "${h:-0}" -ge 2 ] && break; printf .; sleep 2
done; echo
$DC ps

BASE="https://localhost"
code(){ curl -sk -o /dev/null -w '%{http_code}' "$1"; }
MODE=$(grep -E '^INDEX_TEMPLATE=' config/settings.env | cut -d= -f2)
echo "== mode: $MODE =="

echo "== obfuscation: every unknown path is a 404 =="
chk "GET /            → 404" "$(code $BASE/)" 404
chk "GET /admin       → 404" "$(code $BASE/admin)" 404
chk "GET /.env        → 404" "$(code $BASE/.env)" 404
chk "GET /wp-login.php→ 404" "$(code $BASE/wp-login.php)" 404

echo "== FastCGI wiring (token in path must resolve index.php) =="
if $DC logs php 2>&1 | grep -qi "primary script unknown"; then no "no 'Primary script unknown' in php logs"; else ok "no FastCGI 'Primary script unknown' errors"; fi

echo "== hardening =="
chk "php runs non-root (uid 82)" "$($DC exec -T php id -u | tr -d '\r')" 82
chk "rootfs read-only" "$($DC exec -T php sh -c 'touch /nope 2>/dev/null && echo rw || echo ro')" ro
chk "/data writable"   "$($DC exec -T php sh -c 'touch /data/.smoke 2>/dev/null && rm -f /data/.smoke && echo rw || echo ro')" rw
chk "php has NO internet egress" "$($DC exec -T php php -r '$f=@fsockopen("example.com",443,$e,$s,3); echo $f?"egress":"blocked";')" blocked

echo "== a valid link works; a tampered one does not =="
if [ "$MODE" = "secure-share" ]; then
  URL=$(printf 'smoke\ntop-secret-value\ncorrecthorsebatterystaple\n' \
        | $DC exec -T php php /var/www/html/bin/secret.php --ttl=1h --base="$BASE" | grep -oE 'https://[^ ]+' | head -1)
  chk "secret link renders the disguised 404" "$(code "$URL")" 404
  body=$(curl -sk -X POST -d 'a=fetch' "$URL")
  echo "$body" | grep -q '"blob"' && ok "POST a=fetch returns ciphertext JSON (server half of decrypt)" || no "a=fetch returned a blob"
  echo "$body" | grep -q 'top-secret-value' && no "ciphertext must NOT contain plaintext" || ok "response carries no plaintext (only ciphertext)"
else
  URL=$($DC exec -T php php /var/www/html/bin/share.php --client=smoke --ttl=1h --base="$BASE" | grep -oE 'https://[^ ]+' | head -1)
  chk "feedback link renders the board (200)" "$(code "$URL")" 200
  chk "tampered token → 404" "$(code "${URL%?}X")" 404
fi
chk "loopback /health (in-container)" "$($DC exec -T caddy wget -q -O- http://127.0.0.1:80/health 2>/dev/null)" ok

echo ""
echo "== summary: $pass passed, $fail failed =="
echo ""
echo "Manual checks (need a browser — open the printed link in Firefox):"
echo "  secure-share:  type the passphrase blind + Enter → secret shows / file downloads;"
echo "                 wrong ×3 → goes dead (find-bar tell in Firefox)."
echo "  file:          make file FILE=./README.md TTL=1h  → open link, unlock, it downloads."
echo "  rotation:      $DC restart  → the old link now 404s; mint a fresh one."
[ "$fail" -eq 0 ] && exit 0 || exit 1
