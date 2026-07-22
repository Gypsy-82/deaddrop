<?php

declare(strict_types=1);

/**
 * Mint a secret, self-expiring link for one client and print it.
 *
 *   php bin/share.php --client="Acme Corp" --ttl=48h [--base=https://localhost]
 *
 * TTL accepts <n>{s|m|h|d}. The token is signed with THIS session's key, so the link
 * is valid only until it expires OR the server restarts (whichever comes first).
 */

require __DIR__ . '/../src/Secrets.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Token.php';

$opts   = getopt('', ['client:', 'ttl:', 'base:']);
$client = trim((string) ($opts['client'] ?? '')) ?: 'client';
$ttlStr = (string) ($opts['ttl'] ?? (getenv('DEFAULT_TTL') ?: '48h'));   // config/settings.env default
$base   = rtrim((string) ($opts['base'] ?? (getenv('PUBLIC_BASE') ?: 'https://localhost')), '/');

function parse_ttl(string $s): int
{
    if (preg_match('/^(\d+)\s*([smhd])$/i', trim($s), $m)) {
        $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
        return max(1, (int) $m[1]) * $unit;
    }
    fwrite(STDERR, "Unrecognized --ttl '$s'; using 48h.\n");
    return 48 * 3600;
}

$prefix = Secrets::prefix();
if ($prefix === '') {
    fwrite(STDERR, "No active session prefix. Is the server running? (docker compose up -d)\n");
    exit(1);
}

$exp = time() + parse_ttl($ttlStr);

$pdo = Database::pdo();
$pdo->prepare('INSERT INTO share_links (label, expires_at) VALUES (?, ?)')
    ->execute([$client, $exp]);
$lid = (int) $pdo->lastInsertId();

$token = Token::create(Secrets::hmacKey(), ['lid' => $lid, 'exp' => $exp]);
$url   = "$base/$prefix/$token";

$expLocal = date('Y-m-d H:i:s T', $exp);

echo "\n";
echo "  ┌─ secret link (session-bound) ─────────────────────────────\n";
echo "  │ client  : $client\n";
echo "  │ link #  : $lid\n";
echo "  │ expires : $expLocal\n";
echo "  │ dies if : the server restarts, or you run `make revoke ID=$lid`\n";
echo "  └───────────────────────────────────────────────────────────\n";
echo "\n  $url\n\n";
