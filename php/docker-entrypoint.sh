#!/bin/sh
# First-boot + every-boot setup, then hand off to php-fpm.
#
#   - Regenerates the obfuscated URL prefix + HMAC signing key for THIS session
#     (ephemeral, held in tmpfs) → every server session gets a fresh secret URL,
#     and every link minted in a previous session is invalidated on restart.
#   - Creates the SQLite database + schema on first boot only (it persists).
#
# Runs as the non-root container user (uid 82). All writes target the writable
# tmpfs (/tmp) and the persistent data volume (/data) — never the read-only rootfs.
set -e

php /var/www/html/bin/bootstrap.php

exec "$@"
