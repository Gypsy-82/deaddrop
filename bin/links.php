<?php

declare(strict_types=1);

/**
 * List every share link you've minted, or revoke one.
 *
 *   php bin/links.php                # list all links + message counts + status
 *   php bin/links.php --revoke=7     # kill link #7 immediately (its URL 404s at once)
 *
 * Note: links from a previous server session already can't authenticate (the signing
 * key rotated), but revoking is useful to kill a link within the current session.
 */

require __DIR__ . '/../src/Database.php';

$opts = getopt('', ['revoke:']);
$pdo  = Database::pdo();

if (isset($opts['revoke'])) {
    $id = (int) $opts['revoke'];
    $pdo->prepare('UPDATE share_links SET revoked = 1 WHERE id = ?')->execute([$id]);
    echo "  Link #$id revoked.\n";
    exit(0);
}

$tty  = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : false;
$dim  = static fn (string $s) => $tty ? "\033[2m{$s}\033[0m" : $s;
$bold = static fn (string $s) => $tty ? "\033[1m{$s}\033[0m" : $s;

$rows = $pdo->query(
    'SELECT l.id, l.label, l.created_at, l.expires_at, l.revoked,
            (SELECT COUNT(*) FROM messages m WHERE m.link_id = l.id) AS msgs
     FROM share_links l ORDER BY l.id DESC'
)->fetchAll();

echo "\n";
if (!$rows) {
    echo '  ' . $dim('No links yet. Mint one with: make share CLIENT="Name" TTL=48h') . "\n\n";
    exit(0);
}

printf("  %-4s  %-22s  %-8s  %-6s  %s\n", $bold('#'), $bold('client'), $bold('msgs'), $bold('state'), $bold('expires (UTC)'));
echo '  ' . $dim(str_repeat('─', 68)) . "\n";
foreach ($rows as $r) {
    $state = (int) $r['revoked'] === 1
        ? 'revoked'
        : ((int) $r['expires_at'] < time() ? 'expired' : 'active');
    printf(
        "  %-4d  %-22s  %-8d  %-6s  %s\n",
        (int) $r['id'],
        substr((string) $r['label'], 0, 22),
        (int) $r['msgs'],
        $state,
        gmdate('Y-m-d H:i', (int) $r['expires_at'])
    );
}
echo "\n";
