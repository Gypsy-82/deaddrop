<?php

declare(strict_types=1);

/**
 * Zero-knowledge encryption for secure-share mode.
 *
 * The secret is encrypted here (at creation time, in the CLI) with a key derived
 * from a passphrase. Only ciphertext is stored; the passphrase and plaintext never
 * touch disk. Decryption happens later in the recipient's browser via WebCrypto —
 * this class has NO decrypt method, by design.
 *
 * Layout (must stay identical to the browser side in src/view_secret.php):
 *   key  = PBKDF2-HMAC-SHA256(passphrase, salt, iterations, 32 bytes)
 *   blob = AES-256-GCM(key, iv, plaintext) → ciphertext || 16-byte tag
 * Verified against a WebCrypto-equivalent decrypt (AES-GCM expects the tag appended).
 */
final class Crypto
{
    public const ITERATIONS = 210000;   // PBKDF2 rounds (OWASP-tier for SHA-256)
    private const KEYLEN = 32;           // AES-256
    private const IVLEN  = 12;           // GCM nonce
    private const TAGLEN = 16;           // GCM tag

    /**
     * Encrypt to base64 fields (used for small text secrets stored in SQLite).
     *
     * @return array{salt:string,iv:string,blob:string,iterations:int} base64 fields
     */
    public static function encrypt(string $plaintext, string $passphrase): array
    {
        $s = self::seal($plaintext, $passphrase);

        return [
            'salt'       => base64_encode($s['salt']),
            'iv'         => base64_encode($s['iv']),
            'blob'       => base64_encode($s['cipher']),   // ciphertext || tag → what WebCrypto decrypts
            'iterations' => $s['iterations'],
        ];
    }

    /**
     * Encrypt to RAW bytes (used for files, whose ciphertext is written to /data/blobs
     * rather than base64'd into the database). `cipher` is the raw ciphertext||tag.
     *
     * @return array{salt:string,iv:string,cipher:string,iterations:int} salt/iv base64, cipher raw
     */
    public static function sealRaw(string $plaintext, string $passphrase): array
    {
        $s = self::seal($plaintext, $passphrase);

        return [
            'salt'       => base64_encode($s['salt']),
            'iv'         => base64_encode($s['iv']),
            'cipher'     => $s['cipher'],   // raw ciphertext || tag
            'iterations' => $s['iterations'],
        ];
    }

    /** @return array{salt:string,iv:string,cipher:string,iterations:int} all raw bytes (salt/iv/cipher) */
    private static function seal(string $plaintext, string $passphrase): array
    {
        $salt = random_bytes(16);
        $iv   = random_bytes(self::IVLEN);
        $key  = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, self::KEYLEN, true);

        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAGLEN);
        if ($ct === false) {
            throw new RuntimeException('encryption failed');
        }

        return ['salt' => $salt, 'iv' => $iv, 'cipher' => $ct . $tag, 'iterations' => self::ITERATIONS];
    }
}
