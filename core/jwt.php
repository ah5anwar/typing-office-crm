<?php
/**
 * AH5 Office - Minimal JWT (HS256). No Composer needed.
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class JWT
{
    public static function encode(array $payload, ?int $ttl = null): string
    {
        $ttl = $ttl ?? ACCESS_TOKEN_TTL;
        $now = time();

        $header  = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = array_merge($payload, [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ]);

        $segments = [
            self::b64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $signing   = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing, JWT_SECRET, true);
        $segments[] = self::b64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Returns the payload array, or null when the token is invalid/expired.
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $expected = self::b64UrlEncode(hash_hmac('sha256', $h . '.' . $p, JWT_SECRET, true));
        if (!hash_equals($expected, $s)) {
            return null;
        }

        $header = json_decode(self::b64UrlDecode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $payload = json_decode(self::b64UrlDecode($p), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['nbf']) && $now < (int) $payload['nbf'] - 60) {
            return null;
        }
        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    public static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64UrlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
