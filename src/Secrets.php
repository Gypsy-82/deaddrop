<?php

declare(strict_types=1);

/**
 * Per-session secrets: the obfuscated URL prefix and the HMAC signing key.
 *
 * These live in tmpfs (/tmp/session) and are regenerated on every container boot
 * by bin/bootstrap.php. That makes them EPHEMERAL by construction:
 *   - each server session gets a fresh, unguessable prefix + signing key;
 *   - every link minted in a previous session is invalidated on restart;
 *   - stopping the server wipes them entirely.
 *
 * Nothing here is persisted — only the SQLite database (see Database) survives.
 */
final class Secrets
{
    private const DIR = '/tmp/session';

    /** Regenerate this session's prefix + signing key. Called once per boot. */
    public static function newSession(): array
    {
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0700, true);
        }
        $prefix = bin2hex(random_bytes(8));   // 16 hex chars — the secret URL segment
        $key    = bin2hex(random_bytes(32));  // HMAC-SHA256 key for signed links
        self::put('prefix', $prefix);
        self::put('hmac.key', $key);

        return ['prefix' => $prefix, 'key' => $key];
    }

    public static function prefix(): string
    {
        return trim((string) @file_get_contents(self::DIR . '/prefix'));
    }

    public static function hmacKey(): string
    {
        return trim((string) @file_get_contents(self::DIR . '/hmac.key'));
    }

    private static function put(string $file, string $value): void
    {
        $path = self::DIR . '/' . $file;
        file_put_contents($path, $value);
        @chmod($path, 0600);
    }
}
