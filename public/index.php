<?php

declare(strict_types=1);

/**
 * Front controller = the whole app surface.
 *
 * Two gates must both pass before anything is served; any failure returns the exact
 * same blank 404 a random scanner would get, so nothing distinguishes "wrong prefix",
 * "bad token", "expired", or "revoked":
 *
 *   Gate 1 — obfuscated prefix : the request path must start with this session's secret prefix.
 *   Gate 2 — signed token      : the 2nd path segment must be a valid, unexpired HMAC token,
 *                                and reference a live (non-revoked, unexpired) share link.
 *
 * Past the gates: GET renders the comment board + thread; POST stores a message.
 */

require __DIR__ . '/../src/Secrets.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Token.php';
require __DIR__ . '/../src/notfound.php';

// ── helpers ──────────────────────────────────────────────────────────────────
// Every dead end — wrong prefix, bad token, expired, revoked, and (in secure-share
// mode) a valid secret link before it's unlocked — renders the SAME canonical 404,
// so a genuine miss and a secret link are byte-identical. See src/notfound.php.
function deny(): never
{
    render_notfound();
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Gate 1: obfuscated per-session prefix ─────────────────────────────────────
$prefix  = Secrets::prefix();
$hmacKey = Secrets::hmacKey();

$path     = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));

// Constant-time compare so the secret prefix leaks no timing signal.
if ($prefix === '' || !hash_equals($prefix, (string) ($segments[0] ?? ''))) {
    deny();
}

// ── Gate 2: signed, self-expiring token (second path segment, not a ?query) ───
// Carrying the token in the path keeps the URL looking like an ordinary deep link
// rather than an obvious capability URL.
$k      = (string) ($segments[1] ?? '');
$claims = $k !== '' ? Token::verify($hmacKey, $k) : null;
if ($claims === null || !isset($claims['lid'])) {
    deny();
}

// ── Dispatch by mode (config/settings.env: INDEX_TEMPLATE) ────────────────────
if ((getenv('INDEX_TEMPLATE') ?: 'feedback') === 'secure-share') {
    require __DIR__ . '/../src/view_secret.php';   // fetch + render disguised viewer, or burn — then exits
    exit;
}

// ── Feedback mode (default): visitors leave you messages ──────────────────────
$pdo  = Database::pdo();
$stmt = $pdo->prepare('SELECT * FROM share_links WHERE id = ? AND revoked = 0');
$stmt->execute([(int) $claims['lid']]);
$link = $stmt->fetch();
if (!$link || (int) $link['expires_at'] < time()) {   // belt-and-suspenders vs. the token's own exp
    deny();
}

// ── POST: store a message ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // The secret token doubles as the CSRF guard (an attacker can't know $k); the
    // hidden "website" field is a spam trap that naive bots fill in.
    if (!hash_equals($k, (string) ($_POST['k'] ?? '')) || ($_POST['website'] ?? '') !== '') {
        deny();
    }

    $author = trim((string) ($_POST['author'] ?? ''));
    $body   = trim((string) ($_POST['body'] ?? ''));
    // Byte-cap to keep storage bounded; htmlspecialchars(ENT_SUBSTITUTE) makes any
    // truncated multibyte tail safe on output.
    $author = substr($author, 0, 80);
    $body   = substr($body, 0, 4000);

    if ($body !== '' && $author !== '') {   // both name and message are required
        $ins = $pdo->prepare(
            'INSERT INTO messages (link_id, author, body, ip, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([
            (int) $link['id'],
            $author,
            $body,
            $_SERVER['REMOTE_ADDR'] ?? null,   // Caddy-set authoritative client IP
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    // Where to send the visitor after submitting (operator-configured, not request-derived).
    $redirect = str_replace(["\r", "\n"], '', trim((string) getenv('REDIRECT_URL')));
    if ($redirect !== '' && preg_match('#^https?://#i', $redirect)) {
        header('Location: ' . $redirect, true, 303);
    } else {
        // Default: Post/Redirect/Get back to the same secret URL.
        header('Location: /' . $prefix . '/' . $k, true, 303);
    }
    exit;
}

// ── GET: render the board ─────────────────────────────────────────────────────
$msgs = $pdo->prepare('SELECT author, body, created_at FROM messages WHERE link_id = ? ORDER BY id ASC');
$msgs->execute([(int) $link['id']]);
$messages = $msgs->fetchAll();

$expiresHuman = gmdate('M j, Y H:i', (int) $claims['exp']) . ' UTC';
$action       = '/' . $prefix . '/' . $k;

header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'");
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Feedback</title>
<style>
  :root {
    --bg: #0b0e14; --panel: #131824; --panel-2: #1a2130; --border: #232b3a;
    --text: #e6e9ef; --muted: #8b95a7; --accent: #5b8def; --accent-ink: #fff;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; background: var(--bg); color: var(--text);
    font: 16px/1.6 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    display: flex; justify-content: center; padding: 24px;
  }
  .wrap { width: 100%; max-width: 640px; }
  header { margin-bottom: 20px; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  .meta { color: var(--muted); font-size: 0.85rem; }
  .card {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: 14px; padding: 18px; margin-bottom: 16px;
  }
  label { display: block; font-size: 0.85rem; color: var(--muted); margin: 0 0 6px; }
  input[type=text], textarea {
    width: 100%; background: var(--panel-2); border: 1px solid var(--border);
    color: var(--text); border-radius: 10px; padding: 12px 14px;
    font: inherit; margin-bottom: 14px; resize: vertical;
  }
  input:focus, textarea:focus { outline: 2px solid var(--accent); border-color: transparent; }
  textarea { min-height: 120px; }
  .hp { position: absolute; left: -9999px; }         /* spam trap (hidden) */
  button {
    background: var(--accent); color: var(--accent-ink); border: 0;
    border-radius: 10px; padding: 12px 20px; font: inherit; font-weight: 600;
    cursor: pointer; min-height: 44px;
  }
  button:hover { filter: brightness(1.08); }
  .thread h2 { font-size: 0.95rem; color: var(--muted); font-weight: 600; margin: 4px 2px 12px; }
  .msg { border-top: 1px solid var(--border); padding: 14px 2px; }
  .msg:first-of-type { border-top: 0; }
  .msg .who { font-weight: 600; font-size: 0.9rem; }
  .msg .when { color: var(--muted); font-size: 0.78rem; }
  .msg .body { white-space: pre-wrap; word-wrap: break-word; margin-top: 4px; }
  .empty { color: var(--muted); font-size: 0.9rem; padding: 8px 2px; }
</style>
</head>
<body>
  <div class="wrap">
    <header>
      <h1>Leave a message</h1>
      <div class="meta">This private link expires <?= e($expiresHuman) ?>.</div>
    </header>

    <form class="card" method="post" action="<?= e($action) ?>">
      <input type="hidden" name="k" value="<?= e($k) ?>">
      <div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <label for="author">Your name</label>
      <input id="author" type="text" name="author" maxlength="80" autocomplete="name" required>
      <label for="body">Message</label>
      <textarea id="body" name="body" maxlength="4000" required></textarea>
      <button type="submit">Send</button>
    </form>

    <div class="thread card">
      <h2><?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?></h2>
      <?php if (!$messages): ?>
        <div class="empty">No messages yet — you're first.</div>
      <?php else: ?>
        <?php foreach ($messages as $m): ?>
          <div class="msg">
            <span class="who"><?= e($m['author'] !== null && $m['author'] !== '' ? $m['author'] : 'Anonymous') ?></span>
            <span class="when">· <?= e((string) $m['created_at']) ?> UTC</span>
            <div class="body"><?= e((string) $m['body']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
