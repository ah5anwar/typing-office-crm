<?php
/**
 * AH5 Office - short-lived tokens for printable pages
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * A browser tab cannot send an Authorization header, so the print pages
 * use a separate 30-minute token that only opens one document.
 */

declare(strict_types=1);

final class PrintAuth
{
    private const TTL = 1800; // 30 minutes

    public static function token(string $docType, int $docId): string
    {
        return JWT::encode([
            'sub'   => Auth::userId(),
            'scope' => 'print',
            'doc'   => $docType,
            'did'   => $docId,
        ], self::TTL);
    }

    public static function link(string $docType, int $docId, ?string $script = null): string
    {
        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // /api/v1/index.php -> project root
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $root       = rtrim(str_replace('/api/v1/index.php', '', $scriptName), '/');

        $target = $script !== null ? '/' . ltrim($script, '/') : '/print/' . $docType . '.php';

        return $scheme . '://' . $host . $root . $target . '?id=' . $docId
            . '&t=' . self::token($docType, $docId);
    }

    /**
     * A signed link to one attached file.
     * $inline true opens it in the browser, false makes the browser save it.
     */
    public static function fileLink(int $attachmentId, bool $inline): string
    {
        $token = JWT::encode([
            'sub'   => Auth::userId(),
            'scope' => 'file',
            'aid'   => $attachmentId,
        ], self::TTL);

        return self::siteRoot() . '/file.php?id=' . $attachmentId
            . '&t=' . $token . ($inline ? '&view=1' : '');
    }

    private static function siteRoot(): string
    {
        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $root   = rtrim(str_replace('/api/v1/index.php', '', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return ($https ? 'https://' : 'http://') . $host . $root;
    }

    /** Check a file token. Returns the attachment id. */
    public static function checkFile(int $attachmentId): void
    {
        $token   = (string) ($_GET['t'] ?? '');
        $payload = $token !== '' ? JWT::decode($token) : null;

        $valid = $payload !== null
            && ($payload['scope'] ?? '') === 'file'
            && (int) ($payload['aid'] ?? 0) === $attachmentId;

        if (!$valid) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            exit('<p style="font-family:sans-serif;padding:40px">'
                . 'This link has expired. Open the file again from the panel.</p>');
        }
    }

    /** A signed link to a settings image such as the logo. */
    public static function assetLink(string $settingKey): string
    {
        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $root   = rtrim(str_replace('/api/v1/index.php', '', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');

        return $scheme . '://' . $host . $root . '/asset.php?key=' . rawurlencode($settingKey)
            . '&t=' . JWT::encode(['sub' => Auth::userId(), 'scope' => 'asset', 'key' => $settingKey], 86400);
    }

    /** Halts the request unless the token matches this exact document. */
    public static function check(string $docType, int $docId): void
    {
        $token   = (string) ($_GET['t'] ?? '');
        $payload = $token !== '' ? JWT::decode($token) : null;

        $valid = $payload !== null
            && ($payload['scope'] ?? '') === 'print'
            && ($payload['doc'] ?? '') === $docType
            && (int) ($payload['did'] ?? 0) === $docId;

        if ($valid) {
            // the link proves who asked for it, so the page may read figures
            Auth::asPrintReader((int) ($payload['sub'] ?? 0));
        }

        if (!$valid) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            exit('<p style="font-family:sans-serif;padding:40px">'
                . 'This print link has expired. Please open it again from the panel.</p>');
        }
    }
}
