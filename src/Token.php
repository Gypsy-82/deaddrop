<?php

declare(strict_types=1);

/**
 * Self-expiring, tamper-proof link tokens.
 *
 *   TOKEN = base64url(payload) ~ base64url( HMAC-SHA256(key, base64url(payload)) )
 *
 * The payload carries the share-link id and an expiry timestamp. Any change to the
 * payload (e.g. pushing `exp` further out) breaks the signature, so tokens cannot be
 * forged or extended without the per-session HMAC key. base64url (RFC 4648) plus a
 * `~` separator are all URL-unreserved, so the token drops straight into a PATH
 * segment (no query string, no extension ambiguity, no extra encoding).
 */
final class Token
{
    /** Create a signed token from claims (must include an `exp` unix timestamp). */
    public static function create(string $key, array $claims): string
    {
        $payload = self::b64urlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig     = self::b64urlEncode(hash_hmac('sha256', $payload, $key, true));

        return $payload . '~' . $sig;
    }

    /** Verify signature + expiry. Returns the claims array, or null if invalid/expired. */
    public static function verify(string $key, string $token): ?array
    {
        $parts = explode('~', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;

        $expected = self::b64urlEncode(hash_hmac('sha256', $payload, $key, true));
        if (!hash_equals($expected, $sig)) {   // constant-time comparison
            return null;
        }

        $claims = json_decode(self::b64urlDecode($payload), true);
        if (!is_array($claims) || !isset($claims['exp'])) {
            return null;
        }
        if ((int) $claims['exp'] < time()) {   // expired
            return null;
        }

        return $claims;
    }

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
