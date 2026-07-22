<?php

declare(strict_types=1);

/**
 * Runs once per container boot (from php/docker-entrypoint.sh), before php-fpm starts.
 *
 *   1. Regenerate this session's obfuscated URL prefix + HMAC signing key (ephemeral).
 *   2. Create the SQLite database + schema if they don't exist yet (persistent).
 *
 * Idempotent for the DB; deliberately NOT idempotent for the session secrets — they
 * rotate on every boot so links are bound to a live server session.
 */

require __DIR__ . '/../src/Secrets.php';
require __DIR__ . '/../src/Database.php';

$session = Secrets::newSession();
Database::migrate();
@mkdir('/data/blobs', 0700, true);   // encrypted file blobs (secure-share --file) live here

fwrite(STDERR, "[bootstrap] new session — obfuscated base path: /{$session['prefix']}/\n");
fwrite(STDERR, "[bootstrap] mint a client link with:  make share CLIENT=\"Name\" TTL=48h\n");
