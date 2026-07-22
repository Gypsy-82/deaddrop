#!/usr/bin/env bash
# `make up` — start the stack on Docker OR rootless Podman (Kali's default), no hand-tweaks.
#
# Podman is daemonless: `docker info` (→ podman info) succeeds even when the API socket that
# `docker compose` needs is OFF. So we check the socket FILE directly and start it if missing.
set -u
cd "$(dirname "$0")/.." || exit 1

DC="docker compose --env-file config/settings.env"
uid="$(id -u)"
psock="${XDG_RUNTIME_DIR:-/run/user/$uid}/podman/podman.sock"
case "${DOCKER_HOST:-}" in unix://*) psock="${DOCKER_HOST#unix://}" ;; esac

if command -v podman >/dev/null 2>&1; then
  if [ ! -S "$psock" ]; then
    echo "• Podman detected — starting its API socket (docker compose needs it)…"
    systemctl --user enable --now podman.socket >/dev/null 2>&1 || true
    for _ in 1 2 3; do [ -S "$psock" ] && break; sleep 1; done
  fi
  if [ ! -S "$psock" ]; then
    # systemd --user not usable in this session? run the API service directly.
    ( podman system service --time=0 >/dev/null 2>&1 & )
    for _ in 1 2 3 4 5; do [ -S "$psock" ] && break; sleep 1; done
  fi
  if [ ! -S "$psock" ]; then
    echo "  x  Couldn't start Podman's socket at $psock" >&2
    echo "     Start it once by hand, then retry:  systemctl --user enable --now podman.socket" >&2
    exit 1
  fi
  echo "• Podman socket ready: $psock"
elif ! docker info >/dev/null 2>&1; then
  echo "  x  No container daemon reachable. Start Docker:  sudo systemctl enable --now docker" >&2
  exit 1
fi

$DC up -d --build || exit 1

port="$(grep -E '^HTTPS_PORT=' config/settings.env | cut -d= -f2)"; port="${port:-8443}"
addr="$(grep -E '^SITE_ADDRESS=' config/settings.env | cut -d= -f2)"; addr="${addr:-localhost}"
mode="$(grep -E '^INDEX_TEMPLATE=' config/settings.env | cut -d= -f2)"; mode="${mode:-feedback}"
echo ""
echo "  ✔ Deaddrop is up — open  https://$addr:$port/"
echo "    (localhost uses a self-signed cert; the one-time browser warning is expected)"
if [ "$mode" = "secure-share" ]; then
  echo "    Mode: secure-share  →  next:  make secret   ·   make file FILE=./doc.pdf"
else
  echo "    Mode: feedback  →  next:  make share CLIENT=\"Name\"    (switch modes: make setup)"
fi
