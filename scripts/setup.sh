#!/usr/bin/env bash
# Deaddrop guided setup — writes config/settings.env.
# Re-prompts on bad input (never dies on a typo). Blank answer keeps the current value.
set -u

cd "$(dirname "$0")/.." || { echo "cannot find project root"; exit 1; }
CONFIG="config/settings.env"

cur() { grep -E "^$1=" "$CONFIG" 2>/dev/null | head -1 | cut -d= -f2-; }

echo
echo "  ┌─ Deaddrop setup ──────────────────────────────────────────"
echo "  │ Writes $CONFIG. Re-run any time; blank keeps the current"
echo "  │ value. Applying with 'make up' restarts the server, which"
echo "  │ rotates the obfuscated URL and kills any live links."
echo "  └───────────────────────────────────────────────────────────"
echo

# ── mode ──────────────────────────────────────────────────────────────────────
cur_mode="$(cur INDEX_TEMPLATE)"; cur_mode="${cur_mode:-feedback}"
echo "  Mode"
echo "    1) feedback      — visitors leave YOU a message (inward)"
echo "    2) secure-share  — deliver an encrypted secret/file to THEM, disguised as a 404 (outward)"
while true; do
  read -r -p "  choose 1 or 2 [current: $cur_mode]: " m
  case "${m:-}" in
    1)  MODE=feedback;      break ;;
    2)  MODE=secure-share;  break ;;
    "") MODE="$cur_mode";   break ;;
    *)  echo "    ↳ type 1 or 2 (or press Enter to keep '$cur_mode')" ;;
  esac
done

# ── address ───────────────────────────────────────────────────────────────────
cur_addr="$(cur SITE_ADDRESS)"; cur_addr="${cur_addr:-localhost}"
echo
while true; do
  read -r -p "  Public address — 'localhost' for local use, or a domain you own [$cur_addr]: " a
  a="${a:-$cur_addr}"
  case "$a" in
    *" "*)                 echo "    ↳ a hostname can't contain spaces" ;;
    localhost|*.localhost) ADDR="$a"; break ;;
    *.*)                   ADDR="$a"; break ;;   # has a dot → a domain or IP
    *)                     echo "    ↳ '$a' isn't reachable as-is — use 'localhost', a '*.localhost' name, or a real domain (with a dot)" ;;
  esac
done

# ── link lifetime ─────────────────────────────────────────────────────────────
cur_ttl="$(cur DEFAULT_TTL)"; cur_ttl="${cur_ttl:-48h}"
echo
while true; do
  read -r -p "  Default link lifetime — a number + s/m/h/d, e.g. 30m, 12h, 7d [$cur_ttl]: " t
  t="${t:-$cur_ttl}"
  if printf '%s' "$t" | grep -qE '^[0-9]+[smhd]$'; then TTL="$t"; break; fi
  echo "    ↳ '$t' isn't valid — use a number then s/m/h/d, like 30m or 12h"
done

# ── passphrase attempts (secure-share only) ───────────────────────────────────
cur_att="$(cur MAX_ATTEMPTS)"; cur_att="${cur_att:-3}"
ATT="$cur_att"
if [ "$MODE" = "secure-share" ]; then
  echo
  while true; do
    read -r -p "  How many WRONG passphrase guesses before a secret self-destructs? (a number) [$cur_att]: " at
    at="${at:-$cur_att}"
    if printf '%s' "$at" | grep -qE '^[1-9][0-9]*$'; then ATT="$at"; break; fi
    echo "    ↳ this is a COUNT, not the passphrase — enter a whole number like 3"
  done
fi

# Preserve advanced values not prompted for.
PORT_H="$(cur HTTP_PORT)";    PORT_H="${PORT_H:-8080}"   # rootless-safe defaults (Podman/Kali)
PORT_S="$(cur HTTPS_PORT)";   PORT_S="${PORT_S:-8443}"
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

# Host ports Caddy publishes. Defaults are HIGH ports so it runs rootless out of the box
# (Docker, rootless Docker, or Podman on Kali). For a PUBLIC domain with Let's Encrypt, use 80/443.
HTTP_PORT=$PORT_H
HTTPS_PORT=$PORT_S

# ── Mode ─────────────────────────────────────────────────────
#   feedback      → visitors leave you messages, stored in SQLite (inward)
#   secure-share  → deliver an encrypted secret/file; recipient decrypts in-browser (outward)
INDEX_TEMPLATE=$MODE

# ── Link lifetime ────────────────────────────────────────────
# Default validity of a minted link. Accepts: 30m, 12h, 7d …
# Override per link:  make share CLIENT="Acme" TTL=2h
DEFAULT_TTL=$TTL

# Secure-share: wrong passphrase guesses before a secret self-destructs.
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
  echo "  Then:       make secret TTL=$TTL      (or: make file FILE=./doc.pdf)"
fi
echo
