#!/usr/bin/env bash
# Deaddrop guided setup — writes config/settings.env from a few prompts.
# Run:  make setup   (or  ./scripts/setup.sh)
set -u

cd "$(dirname "$0")/.." || { echo "cannot find project root"; exit 1; }
CONFIG="config/settings.env"

# Current value of KEY= in the config (empty if unset).
cur() { grep -E "^$1=" "$CONFIG" 2>/dev/null | head -1 | cut -d= -f2-; }

echo
echo "  ┌─ Deaddrop setup ──────────────────────────────────────────"
echo "  │ Configures $CONFIG. Re-run any time."
echo "  │ Applying it with 'make up' restarts the server, which"
echo "  │ rotates the obfuscated URL and kills any live links."
echo "  └───────────────────────────────────────────────────────────"
echo

# ── mode ──────────────────────────────────────────────────────────────────────
cur_mode="$(cur INDEX_TEMPLATE)"; cur_mode="${cur_mode:-feedback}"
echo "  Mode"
echo "    1) feedback      — visitors leave YOU a message (inward)"
echo "    2) secure-share  — deliver an encrypted secret to THEM, disguised as a 404 (outward)"
read -r -p "  choose 1/2 [current: $cur_mode]: " m
case "${m:-}" in
  1)  MODE=feedback ;;
  2)  MODE=secure-share ;;
  "") MODE="$cur_mode" ;;
  *)  echo "  ✗ invalid choice '$m'"; exit 1 ;;
esac

# ── address ───────────────────────────────────────────────────────────────────
cur_addr="$(cur SITE_ADDRESS)"; cur_addr="${cur_addr:-localhost}"
read -r -p "  Public address (blank = localhost) [$cur_addr]: " a
ADDR="${a:-$cur_addr}"; ADDR="${ADDR:-localhost}"
case "$ADDR" in *" "*) echo "  ✗ address can't contain spaces"; exit 1 ;; esac

# ── link lifetime ─────────────────────────────────────────────────────────────
cur_ttl="$(cur DEFAULT_TTL)"; cur_ttl="${cur_ttl:-48h}"
read -r -p "  Default link lifetime (30m, 12h, 7d…) [$cur_ttl]: " t
TTL="${t:-$cur_ttl}"
if ! printf '%s' "$TTL" | grep -qE '^[0-9]+[smhd]$'; then
  echo "  ✗ bad lifetime '$TTL' — use a number + s/m/h/d, e.g. 24h"; exit 1
fi

# ── passphrase attempts (secure-share only) ───────────────────────────────────
cur_att="$(cur MAX_ATTEMPTS)"; cur_att="${cur_att:-3}"
ATT="$cur_att"
if [ "$MODE" = "secure-share" ]; then
  read -r -p "  Passphrase tries before a secret self-destructs [$cur_att]: " at
  ATT="${at:-$cur_att}"
  if ! printf '%s' "$ATT" | grep -qE '^[1-9][0-9]*$'; then
    echo "  ✗ attempts must be a positive whole number"; exit 1
  fi
fi

# Preserve advanced values not prompted for.
PORT_H="$(cur HTTP_PORT)";  PORT_H="${PORT_H:-80}"
PORT_S="$(cur HTTPS_PORT)"; PORT_S="${PORT_S:-443}"
MAX_FMB="$(cur MAX_FILE_MB)"; MAX_FMB="${MAX_FMB:-25}"
REDIR="$(cur REDIRECT_URL)"

# ── write config ──────────────────────────────────────────────────────────────
if ! cat > "$CONFIG" <<EOF
# ============================================================
#   Deaddrop settings — the one file you edit.
#   Re-run the wizard:  make setup      Apply changes:  make up
# ============================================================
# No secrets live here: the obfuscated prefix + signing key are generated at runtime.

# ── Access / TLS ─────────────────────────────────────────────
# localhost      → Caddy's internal self-signed CA (offline; expected browser warning)
# yourdomain.com → automatic Let's Encrypt certificate (needs public DNS + open 80/443)
SITE_ADDRESS=$ADDR

# Host ports Caddy publishes. If 80/443 are taken, e.g. 8080 / 8443,
# then also share links with:  make share BASE="https://localhost:8443"
HTTP_PORT=$PORT_H
HTTPS_PORT=$PORT_S

# ── Mode ─────────────────────────────────────────────────────
#   feedback      → visitors leave you messages, stored in SQLite (inward)
#   secure-share  → deliver an encrypted secret; recipient decrypts in-browser (outward)
INDEX_TEMPLATE=$MODE

# ── Link lifetime ────────────────────────────────────────────
# Default validity of a minted link. Accepts: 30m, 12h, 7d …
# Override per link:  make share CLIENT="Acme" TTL=2h
DEFAULT_TTL=$TTL

# Secure-share: passphrase tries before a secret self-destructs.
MAX_ATTEMPTS=$ATT

# Secure-share: max size (MB) for a shared file (make file).
MAX_FILE_MB=$MAX_FMB

# ── After submit (feedback mode) ─────────────────────────────
# Where a visitor is sent when they click submit. Absolute http(s):// URL, or BLANK to stay.
REDIRECT_URL=$REDIR
EOF
then
  echo "  ✗ could not write $CONFIG"; exit 1
fi

echo
echo "  ✔ wrote $CONFIG"
printf '    mode: %s · address: %s · lifetime: %s' "$MODE" "$ADDR" "$TTL"
[ "$MODE" = "secure-share" ] && printf ' · attempts: %s' "$ATT"
echo; echo
echo "  Apply it:   make up      (restarts; live links rotate)"
if [ "$MODE" = "feedback" ]; then
  echo "  Then:       make share CLIENT=\"Name\" TTL=$TTL"
else
  echo "  Then:       make secret TTL=$TTL"
fi
echo
