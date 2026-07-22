<?php

declare(strict_types=1);

/**
 * Terminal dump of everything clients have left.
 *
 *   php bin/messages.php                 # all messages, grouped by client link
 *   php bin/messages.php --unread        # only messages not yet marked read
 *   php bin/messages.php --link=7        # only link #7
 *   php bin/messages.php --mark-read     # print, then mark everything shown as read
 *
 * Reads the persistent SQLite DB, so it works even after the server (and its
 * session links) have been restarted — the collected data outlives access.
 */

require __DIR__ . '/../src/Database.php';

$opts     = getopt('', ['unread', 'mark-read', 'link:']);
$unread   = isset($opts['unread']);
$markRead = isset($opts['mark-read']);
$linkOnly = isset($opts['link']) ? (int) $opts['link'] : null;

// ANSI colors, disabled when not writing to a TTY.
$tty = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : (getenv('TERM') !== false);
$c = static function (string $code, string $s) use ($tty): string {
    return $tty ? "\033[{$code}m{$s}\033[0m" : $s;
};
$dim  = static fn (string $s) => $c('2', $s);
$bold = static fn (string $s) => $c('1', $s);
$cyan = static fn (string $s) => $c('36', $s);
$yell = static fn (string $s) => $c('33', $s);
$grn  = static fn (string $s) => $c('32', $s);

$pdo = Database::pdo();

$where  = [];
$params = [];
if ($unread) {
    $where[] = 'm.read_at IS NULL';
}
if ($linkOnly !== null) {
    $where[] = 'm.link_id = ?';
    $params[] = $linkOnly;
}
$sql = 'SELECT m.id, m.link_id, m.author, m.body, m.created_at, m.read_at, m.ip,
               l.label, l.expires_at, l.revoked
        FROM messages m JOIN share_links l ON l.id = m.link_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY m.link_id ASC, m.id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

echo "\n";
if (!$rows) {
    echo '  ' . $dim('No messages' . ($unread ? ' unread' : '') . '.') . "\n\n";
    exit(0);
}

$totalUnread = 0;
$currentLink = null;
foreach ($rows as $r) {
    if ($currentLink !== (int) $r['link_id']) {
        $currentLink = (int) $r['link_id'];
        $status = ((int) $r['revoked'] === 1)
            ? $yell('revoked')
            : ((int) $r['expires_at'] < time() ? $yell('expired') : $grn('active'));
        echo '  ' . $bold($cyan('▍ ' . $r['label'])) . '  '
            . $dim('link #' . $r['link_id'] . ' · ' . $status) . "\n";
        echo '  ' . $dim(str_repeat('─', 58)) . "\n";
    }

    $isUnread = $r['read_at'] === null;
    if ($isUnread) {
        $totalUnread++;
    }
    $marker = $isUnread ? $yell('●') : $dim('○');
    $who    = ($r['author'] !== null && $r['author'] !== '') ? $r['author'] : 'Anonymous';
    $meta   = $dim('#' . $r['id'] . ' · ' . $r['created_at'] . ' UTC'
        . ($r['ip'] ? ' · ' . $r['ip'] : ''));

    echo "  $marker " . $bold($who) . "  $meta\n";
    foreach (explode("\n", (string) $r['body']) as $line) {
        echo '      ' . $line . "\n";
    }
    echo "\n";
}

echo '  ' . $dim(sprintf('%d message(s), %d unread.', count($rows), $totalUnread)) . "\n\n";

if ($markRead) {
    $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE messages SET read_at = datetime('now') WHERE read_at IS NULL AND id IN ($in)")
        ->execute($ids);
    echo '  ' . $grn('✓ marked the above as read.') . "\n\n";
}
