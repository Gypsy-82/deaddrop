<?php

declare(strict_types=1);

/**
 * Secure-share endpoint (INDEX_TEMPLATE=secure-share).
 *
 * Included by public/index.php AFTER both gates pass, with $claims, $k and deny() in scope.
 *
 *   GET          → the canonical 404 (render_notfound). NO ciphertext is embedded, so the
 *                  page is byte-identical to any other dead end on the server.
 *   POST a=fetch → hand the gate-passing holder the ciphertext (JSON) to decrypt in-browser.
 *                  This is the only place the blob is ever served, and only after the
 *                  recipient interacts — never in page source.
 *   POST a=burn  → after a confirmed client-side decrypt, delete the ciphertext (burn-after-read).
 *
 * The server never sees the passphrase or the plaintext.
 */

$pdo = Database::pdo();

/** Delete a secret row and, for files, its ciphertext blob on the /data volume. */
$destroy = static function (PDO $pdo, int $id): void {
    $pdo->prepare('DELETE FROM secrets WHERE id = ?')->execute([$id]);
    @unlink("/data/blobs/$id.bin");
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['a'] ?? '');
    $id     = (int) $claims['lid'];

    if ($action === 'burn') {
        // Only burn-enabled secrets self-delete after a confirmed decrypt.
        $keep = $pdo->prepare('SELECT burn FROM secrets WHERE id = ?');
        $keep->execute([$id]);
        if ((int) ($keep->fetchColumn() ?: 0) === 1) {
            $destroy($pdo, $id);
        }
        http_response_code(204);
        exit;
    }

    if ($action === 'fetch') {
        $max = max(1, (int) (getenv('MAX_ATTEMPTS') ?: 3));

        $stmt = $pdo->prepare('SELECT kind, salt, iv, blob, iterations, expires_at, revoked, attempts FROM secrets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['revoked'] === 1 || (int) $row['expires_at'] < time()) {
            deny();   // returns the 404 page (not JSON) → the browser just stays a dead end
        }

        // Out of tries → self-destruct. A brute-force through the page destroys the secret.
        if ((int) $row['attempts'] >= $max) {
            $destroy($pdo, $id);
            deny();
        }

        // Count this retrieval against the budget BEFORE handing over the ciphertext, so
        // the limit can't be beaten by dropping the response mid-flight.
        $pdo->prepare('UPDATE secrets SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);

        // 'text' ciphertext is base64 in the DB; 'file' ciphertext lives on /data/blobs.
        if ($row['kind'] === 'file') {
            $raw = @file_get_contents("/data/blobs/$id.bin");
            if ($raw === false) {
                deny();
            }
            $blob = base64_encode($raw);
        } else {
            $blob = (string) $row['blob'];
        }

        header('Content-Type: application/json');
        header("Content-Security-Policy: default-src 'none'");
        echo json_encode([
            'salt' => $row['salt'],
            'iv'   => $row['iv'],
            'blob' => $blob,
            'iter' => (int) $row['iterations'],
            'kind' => $row['kind'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    deny();
}

// GET → the identical canonical 404. Nothing here reveals a secret exists.
render_notfound();
