#!/usr/bin/env bash
# `make up` — start the stack. Works on Docker OR rootless Podman (Kali's default),
# with no hand-tweaks: if the daemon is unreachable it starts Podman's API socket for you,
# and the shipped ports (8080/8443) are unprivileged so rootless can bind them.
set -u
cd "$(dirname "$0")/.." || exit 1

DC="docker compose --env-file config/settings.env"
reachable() { docker info >/dev/null 2>&1; }

if ! reachable; then
  # Kali fronts Podman with a `docker` shim; its socket is off by default.
  # (Harmless on real Docker — the unit just won't exist.)
  echo "• container daemon unreachable — trying to start Podman's socket…"
  systemctl --user enable --now podman.socket >/dev/null 2>&1 || true
  for _ in 1 2 3 4 5; do reachable && break; sleep 1; done
fi

if ! reachable; then
  cat >&2 <<'EOF'

  x  No container daemon is reachable. Start one, then re-run `make up`:

       Podman (Kali default):   systemctl --user enable --now podman.socket
       Docker Engine:           sudo apt install -y docker.io docker-compose-v2
                                sudo systemctl enable --now docker
                                sudo usermod -aG docker "$USER"    # then log out/in
EOF
  exit 1
fi

$DC up -d --build || exit 1

port="$(grep -E '^HTTPS_PORT=' config/settings.env | cut -d= -f2)"; port="${port:-8443}"
addr="$(grep -E '^SITE_ADDRESS=' config/settings.env | cut -d= -f2)"; addr="${addr:-localhost}"
echo ""
echo "  ✔ Deaddrop is up — open  https://$addr:$port/"
echo "    (localhost uses a self-signed cert; the one-time browser warning is expected)"
echo "    Next:  make share CLIENT=\"Name\"   ·   make secret   ·   make file FILE=./x"
