-- SQLite schema. Applied idempotently on first boot (CREATE ... IF NOT EXISTS),
-- so re-running it is always safe. Lives on the persistent /data volume.

-- One row per share link you mint (`make share`). The signed token in a client's
-- URL references share_links.id; the row lets you attribute messages to a client
-- and revoke a link early.
CREATE TABLE IF NOT EXISTS share_links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    label       TEXT    NOT NULL,                       -- client name / note
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    expires_at  INTEGER NOT NULL,                       -- unix epoch (UTC)
    revoked     INTEGER NOT NULL DEFAULT 0              -- 1 = killed early
);

-- Messages/comments left by clients through a share link.
CREATE TABLE IF NOT EXISTS messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id     INTEGER NOT NULL REFERENCES share_links(id),
    author      TEXT,                                   -- optional name the client typed
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    read_at     TEXT,                                   -- set by `make messages --mark-read`
    ip          TEXT,
    user_agent  TEXT
);

CREATE INDEX IF NOT EXISTS idx_messages_link ON messages (link_id, id);
CREATE INDEX IF NOT EXISTS idx_messages_unread ON messages (read_at);

-- Secure-share mode (INDEX_TEMPLATE=secure-share): one row per secret you mint
-- (`make secret`). Stores ONLY ciphertext — never the passphrase or plaintext.
-- Decryption happens in the recipient's browser (zero-knowledge).
CREATE TABLE IF NOT EXISTS secrets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    label       TEXT    NOT NULL,
    kind        TEXT    NOT NULL DEFAULT 'text',   -- 'text' | 'file'
    salt        TEXT    NOT NULL,   -- base64, 16 bytes (PBKDF2)
    iv          TEXT    NOT NULL,   -- base64, 12 bytes (AES-GCM nonce)
    blob        TEXT,               -- base64 ciphertext||tag for 'text'; NULL for 'file' (on /data/blobs)
    iterations  INTEGER NOT NULL,   -- PBKDF2-SHA256 rounds
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    expires_at  INTEGER NOT NULL,
    burn        INTEGER NOT NULL DEFAULT 1,   -- delete after first successful decrypt
    attempts    INTEGER NOT NULL DEFAULT 0,   -- retrievals used; self-destructs past MAX_ATTEMPTS
    revoked     INTEGER NOT NULL DEFAULT 0
);
