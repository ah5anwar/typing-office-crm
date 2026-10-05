<?php
/**
 * AH5 Office - JSON response helper
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class Response
{
    /**
     * When set, ok() hands the payload here instead of ending the request.
     * The print pages use this to reuse a controller's own figures.
     */
    public static $capture = null;

    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, ALLOWED_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function json($payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Remove cost/profit fields when the signed-in user may not see them. */
    private static function scrub($data)
    {
        if (class_exists('Auth', false) && class_exists('Helper', false)) {
            return Helper::scrubCosts($data);
        }
        return $data;
    }

    public static function ok($data = null, ?string $message = null, array $extra = []): void
    {
        $data  = self::scrub($data);
        $extra = self::scrub($extra);
        $body  = ['success' => true];
        if ($message !== null) {
            $body['message'] = $message;
        }
        $body['data'] = $data;
        if ($extra) {
            $body = array_merge($body, $extra);
        }

        // a print page asked for the figures rather than a response
        if (self::$capture !== null) {
            (self::$capture)($body);
            return;
        }

        self::json($body, 200);
    }

    public static function created($data = null, ?string $message = null): void
    {
        self::json(['success' => true, 'message' => $message, 'data' => self::scrub($data)], 201);
    }

    /** Paginated list response. */
    public static function paginated(array $rows, int $total, int $page, int $perPage, array $extra = []): void
    {
        $body = [
            'success' => true,
            'data'    => self::scrub($rows),
            'meta'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
        if ($extra) {
            $body['summary'] = self::scrub($extra);
        }
        self::json($body, 200);
    }

    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors) {
            $body['errors'] = $errors;
        }
        self::json($body, $status);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::error($message, 404);
    }

    public static function validation(array $errors, string $message = 'Validation failed'): void
    {
        self::error($message, 422, $errors);
    }
}
