<?php

declare(strict_types=1);

/**
 * The single canonical "404 Not Found" page.
 *
 * Served for EVERY dead end — genuine miss, wrong prefix, bad/expired/revoked token, and
 * (in secure-share mode) a VALID secret link before it's unlocked — so they are all
 * byte-for-byte identical. The page is 100% static: nothing from the request is reflected,
 * and no ciphertext is embedded. Two responses differ in nothing.
 *
 * In secure-share mode it carries a fully COVERT unlock: there is no input box, cursor,
 * or typing indicator. Keystrokes are buffered invisibly in memory; the page stays an exact
 * 404 until a correct passphrase + Enter reveals the secret. To anyone watching the screen,
 * nothing changes while the recipient types. The passphrase and plaintext never reach the
 * server; the ciphertext is fetched only on submit, capped at MAX_ATTEMPTS retrievals.
 */
function render_notfound(): never
{
    $secureShare = (getenv('INDEX_TEMPLATE') ?: 'feedback') === 'secure-share';

    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    if ($secureShare) {
        // The page is fully static (nothing reflected), so 'unsafe-inline' has no injection
        // surface and dropping a per-request nonce keeps every 404 byte-for-byte identical.
        header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; "
            . "style-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; form-action 'none'");
    } else {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'");
    }

    echo <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>404 Not Found</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #fff;
         color: #222; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .wrap { text-align: center; max-width: 34rem; padding: 2rem; }
  h1 { font-size: 1.5rem; margin: 0 0 .25rem; font-weight: 600; }
  p { color: #666; margin: .25rem 0; }
  #out { white-space: pre-wrap; word-break: break-word; text-align: left; background: #f6f6f6;
         border: 1px solid #e2e2e2; border-radius: .5rem; padding: 1rem; margin-top: 1.25rem;
         display: none; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  @media (prefers-color-scheme: dark) {
    body { background: #0b0e14; color: #c9ced8; } p { color: #7a8494; }
    #out { background: #131824; border-color: #232b3a; }
  }
</style>
</head>
<body>
  <div class="wrap">
    <h1>404 Not Found</h1>
    <p>The requested URL was not found on this server.</p>
    <div id="out"></div>
  </div>
HTML;

    if ($secureShare) {
        echo <<<'HTML'

  <script>
  (function () {
    // Covert unlock: no input, no cursor, no indicator. Keystrokes are buffered invisibly;
    // the page stays an exact 404 until a correct passphrase + Enter reveals the secret.
    var out = document.getElementById('out');
    var buf = '', tries = 0, locked = false, max = 3, busy = false;
    function b64(s) { return Uint8Array.from(atob(s), function (c) { return c.charCodeAt(0); }); }

    document.addEventListener('keydown', function (e) {
      // Out of tries → stop intercepting keys, so the browser's native behavior resumes.
      // In find-as-you-type browsers (e.g. Firefox) the find bar reappearing is a DENIABLE
      // "locked out" tell — a watcher just sees a browser find bar, never "wrong passphrase".
      // Intentional: do not add preventDefault here.
      if (locked) return;
      if (e.ctrlKey || e.metaKey || e.altKey) return;   // leave real shortcuts (copy, reload, devtools) alone
      if (e.key === 'Enter') { e.preventDefault(); submit(); return; }
      if (e.key === 'Backspace') { e.preventDefault(); buf = buf.slice(0, -1); return; }
      if (e.key.length === 1) { e.preventDefault(); buf += e.key; }   // buffer silently — no scroll/find side effects
    });

    async function submit() {
      if (locked || busy) return;
      var pass = buf; buf = '';
      if (!pass) return;
      busy = true;
      try {
        var r = await fetch(location.pathname + location.search, {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'a=fetch' });
        if (!r.ok) throw 0;                       // no secret / locked → stay a dead 404
        var D = await r.json();
        var enc = new TextEncoder();
        var km = await crypto.subtle.importKey('raw', enc.encode(pass), 'PBKDF2', false, ['deriveKey']);
        var key = await crypto.subtle.deriveKey(
          { name: 'PBKDF2', salt: b64(D.salt), iterations: D.iter, hash: 'SHA-256' },
          km, { name: 'AES-GCM', length: 256 }, false, ['decrypt']);
        var dec = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: b64(D.iv) }, key, b64(D.blob));
        if (D.kind === 'file') {
          // Payload is "<filename>\n<bytes>" — split on the first newline and download.
          var u8 = new Uint8Array(dec), nl = u8.indexOf(10);
          var fname = new TextDecoder().decode(u8.subarray(0, nl)) || 'download';
          var url = URL.createObjectURL(new Blob([u8.subarray(nl + 1)]));
          var a = document.createElement('a');
          a.href = url; a.download = fname;
          document.body.appendChild(a); a.click(); a.remove();
          setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
        } else {
          out.textContent = new TextDecoder().decode(dec);
          out.style.display = 'block';           // the only thing that ever appears (text secrets)
        }
        try { navigator.sendBeacon(location.pathname + location.search,
              new Blob(['a=burn'], { type: 'application/x-www-form-urlencoded' })); } catch (_) {}
      } catch (e) {
        if (++tries >= max) locked = true;       // out of tries → silently ignore all further input
      } finally {
        busy = false;
      }
    }
  })();
  </script>
HTML;
    }

    echo "\n</body>\n</html>\n";
    exit;
}
