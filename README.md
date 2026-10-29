# Deaddrop

> **A self-destructing messaging application** — deliver a secret *to* someone, or collect a message
> *from* them, at a link that looks like a dead page and is gone the moment you're done with it.

A self-hosted **dead drop for the web**. Hand someone an unguessable, self-expiring HTTPS link and,
behind it, either **collect a message** from them or **deliver an encrypted secret** to them — no
meeting, no account, no trace once the session ends. To anyone who finds a link without the key, it's
just a dead "404." It burns after reading and dies on restart. Two Docker containers, nothing installed
but Docker, and nothing ever phones home.

**Two modes — one line in the config switches them:**

- **`feedback`** — they leave you a message; only what they deliberately submit is stored.
  Read it with `make messages`.
- **`secure-share`** — you deliver a secret. The page looks like a plain **404** until the recipient
  enters an out-of-band passphrase that decrypts it **in their browser** (zero-knowledge), then it
  self-destructs after three tries.

```
Internet ──▶ :80 / :443 ──▶  Caddy        ← the only thing listening
   (only exposed ports)        │  terminates TLS, reverse-proxies over FastCGI
                               ▼
                             PHP-FPM       ← never published · non-root · read-only · NO egress
                               ├─ app code (mounted read-only)
                               └─ SQLite   ← the one writable thing: your messages / secrets
```

**Fastest path:** `git clone …` → `cd` → `make up` → `make share` (or `make secret`).

---

## ⚠️ Authorized use & legal notice

This is a **dual-use security tool**. It's built for lawful, consensual, and authorized use:

- **Secure sharing** — handing a file, secret, or password to someone over an ephemeral,
  unguessable link that expires on its own.
- **Consensual collection** — gathering feedback or information from people who are using
  your link deliberately. Only what a visitor **submits** is stored.
- **Authorized security testing** — red-team engagements, phishing simulations, and research
  **for which you hold written permission and scope**.

**Do not** use it to collect data from people without their consent, to impersonate a person
or organization, to capture credentials under false pretenses, or to access/probe systems you
don't own or aren't authorized to test. Depending on where you and your visitors are, laws such
as the CFAA, the UK CMA, GDPR/e-Privacy, and wiretap/keystroke-logging statutes apply. **You are
responsible for how you use it.** The authors provide it as-is, for legitimate purposes only.

> Design choices that reflect this: only deliberately-submitted data is recorded (no covert
> keystroke capture), the redirect is operator-configured, and the container has no outbound
> network access.

---

## Requirements

A container runtime — **either works, and `make up` handles both:**

- **Docker Engine + Compose v2** — smoothest path. [Install Docker](https://docs.docker.com/engine/install/)
  (Linux/Kali) or [Docker Desktop](https://www.docker.com/products/docker-desktop/) (macOS/Windows).
- **Podman** — Kali ships this by default (behind a `docker` shim). Also fine: `make up` starts
  Podman's rootless socket for you, and the shipped ports (8080/8443) are unprivileged so rootless
  can bind them.

Nothing else is installed — Caddy, PHP, and every extension are pulled/built on first run.
Check your runtime: `docker compose version`

---

## Quick start

```bash
git clone https://github.com/Gypsy-82/deaddrop.git
cd deaddrop

make up
```

`make up` pulls Caddy, builds the PHP image, generates this session's secret URL, creates the
SQLite database — and, on Podman, starts the socket for you. No files to edit. It starts in
**feedback** mode by default; run `make setup` to choose it (or switch to secure-share).

The server is now live at **https://localhost:8443/** — but nothing is reachable yet without a
**secret link**, which you mint next.

> **Local HTTPS note:** on `localhost`, Caddy uses its own internal certificate authority,
> so your browser shows a one-time "not secure" warning. That's expected offline — click
> through it, or trust Caddy's local root CA. On a real domain you get a normal Let's
> Encrypt certificate with no warning.

---

## Configuration

**Easiest — run `make setup`:** a short wizard asks for your mode, address, and link lifetime,
then writes the config for you (validated, no typos). Re-run it any time; `make config` prints the
current settings.

```
$ make setup
  Mode
    1) feedback      — visitors leave YOU a message
    2) secure-share  — deliver an encrypted secret to THEM, disguised as a 404
  choose 1/2 [current: feedback]: 2
  Public address (blank = localhost) [localhost]:
  Default link lifetime (30m, 12h, 7d…) [48h]: 24h
  Passphrase tries before a secret self-destructs [3]:
  ✔ wrote config/settings.env
  Apply it:  make up
```

Rather edit by hand? It's all one file — **[config/settings.env](config/settings.env)** — change a
value and `make up` (which recreates the containers so changes take effect):

```ini
SITE_ADDRESS=localhost      # localhost (self-signed) or a domain (Let's Encrypt)
HTTP_PORT=80
HTTPS_PORT=443
INDEX_TEMPLATE=feedback     # feedback (collect messages) or secure-share (deliver a secret)
DEFAULT_TTL=48h             # default link lifetime: 30m, 12h, 7d …
REDIRECT_URL=               # absolute http(s) URL to send the visitor after submit (blank = stay)
```

It ships with working defaults, so a fresh clone runs with no edits. No secrets live in it —
the obfuscated prefix and signing key are generated fresh at runtime.

**Link shape:** `https://<SITE_ADDRESS>/<rotating-prefix>/<token>` — the token rides in the path
(no `?k=`), so it reads like an ordinary deep link rather than an obvious capability URL. Point
`SITE_ADDRESS` at a domain you own (with DNS + open 80/443) for a real Let's Encrypt cert. For a
nicer **local** name, use a `.localhost` host: add `127.0.0.1  acmetools.localhost` to `/etc/hosts`
and set `SITE_ADDRESS=acmetools.localhost` — Caddy serves `.localhost` names with its internal cert
automatically, no public DNS or ACME needed.

## Send a client a link

```bash
make share CLIENT="Acme Corp" TTL=48h
```

```
  ┌─ secret link (session-bound) ─────────────────────────────
  │ client  : Acme Corp
  │ link #  : 1
  │ expires : 2026-07-20 14:30:00 UTC
  │ dies if : the server restarts, or you run `make revoke ID=1`
  └───────────────────────────────────────────────────────────

  https://localhost/9f3a1c7e5b2d4f6a/eyJsaWQiOjEsImV4cCI6MTc1...~kQ9fZ2
```

Send that URL to your client. `TTL` accepts `30m`, `12h`, `7d`, etc.
(Serving a client on another device? Pass your reachable host, e.g.
`make share CLIENT="Acme" BASE="https://192.168.1.50"`.)

## Read what they left

```bash
make messages          # everything, grouped by client, unread flagged
make unread            # only new messages
make read              # print, then mark as read
```

```
  ▍ Acme Corp  link #1 · active
  ──────────────────────────────────────────────────────────
  ● Dana  #1 · 2026-07-18 15:02:11 UTC · 203.0.113.9
      Love the new dashboard. The export button is hard to find though.

  1 message(s), 1 unread.
```

## Manage links

```bash
make links             # list every link, message counts, status
make revoke ID=1       # kill one link immediately
make rotate            # new secret URL now — kills ALL links, no restart
make url               # print this session's base URL
```

---

## Secure-share mode (deliver a secret)

Set `INDEX_TEMPLATE=secure-share` in `config/settings.env`, `make up`, and the tool
delivers **encrypted secrets** instead of collecting feedback — zero-knowledge, with an
out-of-band passphrase.

```bash
make secret TTL=24h        # prompts (hidden) for the secret + a passphrase
```
```
  ┌─ encrypted secret (zero-knowledge) ───────────────────────
  │ id      : 1
  │ expires : 2026-07-20 14:30:00 UTC
  │ burn    : after first successful decrypt
  └───────────────────────────────────────────────────────────
  1. Send this LINK (looks like a 404 until unlocked):
     https://localhost/9f3a…/eyJ…~kQ9f…
  2. Send the PASSPHRASE by a SEPARATE channel.
```

How it protects the secret:

- **Encrypted on your machine, at creation.** Only ciphertext (AES-256-GCM, key via
  PBKDF2-SHA256) lands in SQLite — never the passphrase or plaintext. Seize the box, get nothing.
- **The link carries no key.** You send the passphrase separately, so one intercepted
  message is useless.
- **Decryption happens in the recipient's browser** (WebCrypto). The server never sees the
  passphrase or the decrypted secret.
- **It looks like a 404 — byte-for-byte.** Every dead end on the server returns the exact
  same page, and the ciphertext isn't in the source at all: it's fetched only after the
  recipient submits a passphrase. A genuine miss and a secret link are indistinguishable,
  even under view-source or side-by-side.
- **Invisible entry — no box, no cursor, no indicator.** There's no input field. The recipient
  (told the passphrase out-of-band) just types it *blind* and presses Enter; keystrokes are
  buffered in memory and never rendered. To anyone watching the screen, nothing changes while
  they type — the secret only appears if the passphrase is right.
- **Burn-after-read.** Once the browser confirms a successful decrypt, the ciphertext is
  deleted — on top of the usual TTL + session rotation.
- **Three strikes → self-destruct.** After `MAX_ATTEMPTS` passphrase tries (default 3, set in
  `config/settings.env`) the ciphertext is deleted. It's enforced on the server by counting
  retrievals, so reloading the page doesn't reset the count. *(Caveat: because decryption is
  client-side, a determined attacker who captures the blob can brute-force it offline — there
  the real cost is the PBKDF2 work factor, not the counter. The lockout stops casual/online
  guessing and destroys the secret on abuse.)*
- **The "locked" cue is deniable — or absent.** Nothing ever prints "wrong" or "locked." In
  find-as-you-type browsers (e.g. Firefox), once the tries are spent the page stops swallowing
  keystrokes, so the browser's own find bar reappears — a signal only someone expecting it reads as
  "locked out." Other browsers just go silent. Either way, the secret is already gone.

> Wrong passphrase → the GCM tag fails and the page stays a dead 404. No error, no signal.

### Sharing a file

Same rails, a file instead of text:

```bash
make file FILE=./contract.pdf TTL=24h
```

You're prompted (hidden) for a passphrase; the file's bytes **and its name** are encrypted together,
and only the ciphertext is stored — on the `/data` volume, never in git, and the server never learns
the filename or contents. The recipient types the passphrase on the disguised page and the file
**downloads, decrypted, in their browser**. Everything else — zero-knowledge, disguise, 3-try
self-destruct, session rotation — is identical to a text secret. Size is capped by `MAX_FILE_MB`
(default 25) since the whole file is decrypted in memory.

## Session-bound access (the important part)

**The obfuscated URL and its signing key are regenerated every time the server starts,
held in RAM (tmpfs), never written to disk.** Consequences, by design:

| Event | What happens to existing links |
|---|---|
| `docker compose up` | fresh secret URL for the session |
| `docker compose restart` / reboot | **all previous links die**; mint + resend new ones |
| `docker compose down` / `stop` | the URL refuses connection — nothing is listening |
| `make rotate` | all links die instantly, without a restart |
| a link's `TTL` elapses | that link dies on its own |
| `make revoke ID=n` | that one link dies now |

**What persists:** only the SQLite database (a Docker volume). So messages a client
already left are still there for `make messages` after any restart — access is
ephemeral, the collected data is not.

Yes: **the link is reachable only while this server session is running.** There is no
other listener, no cache, and PHP-FPM has no internet egress — the sole path in is
through the live Caddy proxy.

---

## Going to production (optional)

Still fully local-first, but to expose it on a real domain with a real certificate:

```bash
# in config/settings.env set:
#   SITE_ADDRESS=yourdomain.com
#   HTTP_PORT=80
#   HTTPS_PORT=443       # Let's Encrypt's challenge needs 80/443 reachable
make up
```

Then point the domain's DNS `A`/`AAAA` at the host and allow inbound `80` + `443`. Caddy fetches a
Let's Encrypt certificate automatically. (Binding 80/443 needs rootful Docker, or under rootless:
`sudo sysctl net.ipv4.ip_unprivileged_port_start=80`.)

---

## Security model

| Surface | Mode | Why |
|---|---|---|
| App code (`/var/www/html`) | **read-only** | no webshell, no code tampering |
| Container root filesystem | **read-only** | no place to drop a persistent payload |
| **SQLite DB (`/data`)** | **writable** | the *only* writable target — your messages / secret ciphertext |
| PHP-FPM | non-root (uid 82), `cap_drop: ALL`, `no-new-privileges` | minimal privilege |
| PHP network | `internal: true` | **no outbound internet** — can't phone home |
| PHP ports | none published | reachable only via Caddy over FastCGI |
| Caddy | `cap_drop: ALL` + `NET_BIND_SERVICE`, read-only rootfs | minimal edge |
| Access gate 1 | obfuscated per-session URL prefix | invisible to scanners |
| Access gate 2 | HMAC-signed, self-expiring token | tamper-proof, time-boxed |
| Fingerprinting | `Server`/`X-Powered-By` stripped, `expose_php=Off`, generic 404s | little to profile |

Every gate failure — wrong prefix, bad signature, expired, revoked — returns the **same
blank 404**, so probing reveals nothing.

> Obfuscation is one layer, not the whole defense: the signed token provides the real
> integrity + expiry, TLS protects transport, and session rotation bounds exposure in time.

### Anti-fingerprint

The real app is invisible and every unauthorized request gets the identical blank 404 —
no server banner, no `X-Powered-By`, no distinct status codes, no directory listings, and
`/health` answers **only to loopback** so it can't be fingerprinted from outside. Nothing
about a failed request distinguishes "wrong prefix" from "bad token" from a random guess.

The one thing you can't hide: that *a* TLS server responds 404 to everything. What it is,
what runs on it, and that a real app hides behind a secret door — all invisible.

---

## Project structure

```
config/settings.env        the one file you edit (mode, TLS, TTL, attempts, redirect)
docker-compose.yml         two services, networks, volumes, hardening
caddy/Caddyfile            TLS + reverse proxy + stealth headers + loopback-only /health
php/
  Dockerfile               php:8.3-fpm-alpine + pdo_sqlite, opcache, fcgi; non-root
  docker-entrypoint.sh     regenerate session secrets + init DB, then run php-fpm
  conf.d/zz-hardening.ini   PHP hardening (open_basedir, disabled funcs, no error leak)
  php-fpm.d/www.conf        pool: non-root, :9000, /ping healthcheck, logs to stderr
db/schema.sql              share_links + messages + secrets (idempotent)
src/                       Secrets (ephemeral), Database (PDO), Token (HMAC), Crypto (zero-knowledge)
src/notfound.php           the single canonical 404 — byte-identical for every dead end
src/view_secret.php        secure-share endpoint: serves the blob on interaction, burns, self-destructs
public/index.php           front controller: gates → feedback board OR secure-share viewer
bin/                       bootstrap · share · secret · messages · links · rotate
Makefile                   the commands above
```

Building your own tool on top? Replace `public/index.php` / `src/` — the hardening,
gating, session rotation, and SQLite wiring stay in place.

---

## Data & backups

The database is the `app_data` Docker volume. Back it up with:

```bash
docker compose exec -T php cat /data/app.sqlite > backup.sqlite
```

`make destroy` removes the containers **and** the data (SQLite + certificates). Irreversible.

---

## Troubleshooting

- **Podman / Kali — "Cannot connect to the Docker daemon … podman.sock"** — Podman's socket is off
  by default. `make up` tries to start it; if it can't, run `systemctl --user enable --now
  podman.socket` once. Rootless Podman also can't bind 80/443 — that's why ports default to
  8080/8443. Want zero surprises? Install Docker Engine: `sudo apt install -y docker.io docker-compose-v2`.
- **Browser cert warning on localhost** — expected (Caddy's local CA); click through or trust it.
- **Ports already in use** — change `HTTP_PORT`/`HTTPS_PORT` in `config/settings.env` and run
  `make up`; `make share` follows the new port automatically.
- **A link 404s** — it expired, was revoked, or the server restarted since you minted it. Mint a new one.
- **See what's happening** — `make logs`.

## License

MIT. See [LICENSE](LICENSE).
