<?php

declare(strict_types=1);

/**
 * Encrypt a FILE to share (secure-share mode).
 * Reads STDIN: line 1 = passphrase, everything after = the raw file bytes (fed by `make file`).
 *
 * The filename + bytes are encrypted together with the passphrase; only ciphertext is stored
 * (on the /data volume, never in git), and the server never learns the filename or contents.
 * The recipient's browser decrypts and downloads it — same zero-knowledge path as a text secret.
 */

require __DIR__ . '/../src/Secrets.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Token.php';
require __DIR__ . '/../src/Crypto.php';

$opts   = getopt('', ['name:', 'ttl:', 'burn:', 'base:']);
$name   = str_replace(["\r", "\n", "/"], '', basename((string) ($opts['name'] ?? 'file'))) ?: 'file';
$ttlStr = ((string) ($opts['ttl'] ?? '')) ?: (getenv('DEFAULT_TTL') ?: '48h');
$burn   = ((int) ($opts['burn'] ?? 1)) === 0 ? 0 : 1;
$base   = rtrim((string) ($opts['base'] ?? 'https://localhost'), '/');
$maxMb  = max(1, (int) (getenv('MAX_FILE_MB') ?: 25));

function parse_ttl(string $s): int
{
    if (preg_match('/^(\d+)\s*([smhd])$/i', trim($s), $m)) {
        return max(1, (int) $m[1]) * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
    }
    return 48 * 3600;
}

$passphrase = rtrim((string) fgets(STDIN), "\r\n");   // first line
$bytes      = (string) stream_get_contents(STDIN);    // the rest = raw file

if ($passphrase === '') {
    fwrite(STDERR, "A passphrase is required.\n");
    exit(1);
}
if ($bytes === '') {
    fwrite(STDERR, "The file is empty or could not be read.\n");
    exit(1);
}
if (strlen($bytes) > $maxMb * 1024 * 1024) {
    fwrite(STDERR, sprintf("File is too big (%.1f MB > %d MB cap). Raise MAX_FILE_MB in config/settings.env.\n",
        strlen($bytes) / 1048576, $maxMb));
    exit(1);
}

$prefix = Secrets::prefix();
if ($prefix === '') {
    fwrite(STDERR, "No active session prefix. Is the server running? (make up)\n");
    exit(1);
}

$exp = time() + parse_ttl($ttlStr);
// Filename travels INSIDE the ciphertext (server never learns it): "<name>\n<bytes>".
$enc = Crypto::sealRaw($name . "\n" . $bytes, $passphrase);

$pdo = Database::pdo();
$pdo->prepare("INSERT INTO secrets (label, kind, salt, iv, blob, iterations, expires_at, burn)
               VALUES (?, 'file', ?, ?, NULL, ?, ?, ?)")
    ->execute([$name, $enc['salt'], $enc['iv'], $enc['iterations'], $exp, $burn]);
$sid = (int) $pdo->lastInsertId();

$dir = '/data/blobs';
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
}
if (file_put_contents("$dir/$sid.bin", $enc['cipher']) === false) {
    $pdo->prepare('DELETE FROM secrets WHERE id = ?')->execute([$sid]);
    fwrite(STDERR, "Could not write the encrypted blob.\n");
    exit(1);
}

$token = Token::create(Secrets::hmacKey(), ['lid' => $sid, 'exp' => $exp]);
$url   = "$base/$prefix/$token";

echo "\n";
echo "  ┌─ encrypted file (zero-knowledge) ─────────────────────────\n";
echo "  │ file    : $name  (" . sprintf('%.1f KB', strlen($bytes) / 1024) . ")\n";
echo "  │ id      : $sid\n";
echo "  │ expires : " . date('Y-m-d H:i:s T', $exp) . "\n";
echo "  │ burn    : " . ($burn ? 'after first successful decrypt' : 'off (until expiry)') . "\n";
echo "  └───────────────────────────────────────────────────────────\n\n";
echo "  1. Send this LINK (no key inside — it looks like a 404 until unlocked):\n";
echo "     $url\n\n";
echo "  2. Send the PASSPHRASE by a SEPARATE channel. The recipient types it on the\n";
echo "     page and the file downloads, decrypted, in their browser.\n\n";
