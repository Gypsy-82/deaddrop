<?php

declare(strict_types=1);

/**
 * Create an encrypted secret to share (secure-share mode).
 * Reads three lines from STDIN: label, secret, passphrase (fed by `make secret`,
 * which hides the secret + passphrase with `read -s`).
 *
 * The secret is encrypted with the passphrase; ONLY ciphertext is stored. The
 * passphrase and plaintext never touch disk. The printed link carries NO key —
 * you send the passphrase by a separate channel.
 */

require __DIR__ . '/../src/Secrets.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Token.php';
require __DIR__ . '/../src/Crypto.php';

$opts   = getopt('', ['ttl:', 'burn:', 'base:']);
$ttlStr = ((string) ($opts['ttl'] ?? '')) ?: (getenv('DEFAULT_TTL') ?: '48h');
$burn   = ((int) ($opts['burn'] ?? 1)) === 0 ? 0 : 1;
$base   = rtrim((string) ($opts['base'] ?? 'https://localhost'), '/');

function parse_ttl(string $s): int
{
    if (preg_match('/^(\d+)\s*([smhd])$/i', trim($s), $m)) {
        return max(1, (int) $m[1]) * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
    }
    return 48 * 3600;
}

$label      = rtrim((string) fgets(STDIN), "\r\n");
$secret     = rtrim((string) fgets(STDIN), "\r\n");
$passphrase = rtrim((string) fgets(STDIN), "\r\n");

if ($secret === '' || $passphrase === '') {
    fwrite(STDERR, "Secret and passphrase are both required.\n");
    exit(1);
}

$prefix = Secrets::prefix();
if ($prefix === '') {
    fwrite(STDERR, "No active session prefix. Is the server running? (make up)\n");
    exit(1);
}

$exp = time() + parse_ttl($ttlStr);
$enc = Crypto::encrypt($secret, $passphrase);   // only ciphertext leaves this line

$pdo = Database::pdo();
$pdo->prepare('INSERT INTO secrets (label, salt, iv, blob, iterations, expires_at, burn)
               VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$label ?: 'secret', $enc['salt'], $enc['iv'], $enc['blob'], $enc['iterations'], $exp, $burn]);
$sid = (int) $pdo->lastInsertId();

$token = Token::create(Secrets::hmacKey(), ['lid' => $sid, 'exp' => $exp]);
$url   = "$base/$prefix/$token";

echo "\n";
echo "  ┌─ encrypted secret (zero-knowledge) ───────────────────────\n";
echo "  │ label   : " . ($label ?: 'secret') . "\n";
echo "  │ id      : $sid\n";
echo "  │ expires : " . date('Y-m-d H:i:s T', $exp) . "\n";
echo "  │ burn    : " . ($burn ? 'after first successful decrypt' : 'off (until expiry)') . "\n";
echo "  └───────────────────────────────────────────────────────────\n\n";
echo "  1. Send this LINK (no key inside — it looks like a 404 until unlocked):\n";
echo "     $url\n\n";
echo "  2. Send the PASSPHRASE by a SEPARATE channel (Signal, in person…).\n";
echo "     Never put the link and the passphrase in the same message.\n\n";
