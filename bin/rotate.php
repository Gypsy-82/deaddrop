<?php

declare(strict_types=1);

/**
 * Force a fresh obfuscated prefix + signing key WITHOUT restarting the server.
 * Every existing link dies instantly; mint new ones with `make share`.
 *
 *   php bin/rotate.php
 */

require __DIR__ . '/../src/Secrets.php';

$session = Secrets::newSession();

echo "\n  Rotated. New obfuscated base path: /{$session['prefix']}/\n";
echo "  All previously issued links are now dead. Re-share with: make share CLIENT=\"Name\" TTL=48h\n\n";
