<?php
/**
 * AH5 Office - Tiny router
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Supports placeholders: /customers/{id}
 */

declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][] = ['path' => rtrim($path, '/') ?: '/', 'handler' => $handler];
    }

    public function get(string $p, callable $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void   { $this->add('POST', $p, $h); }
    public function put(string $p, callable $h): void    { $this->add('PUT', $p, $h); }
    public function patch(string $p, callable $h): void  { $this->add('PATCH', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    /** Current path relative to /api/v1 */
    public static function currentPath(): string
    {
        $uri = $_GET['_r'] ?? ($_SERVER['PATH_INFO'] ?? '');
        if ($uri === '') {
            $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $pos  = strpos($uri, '/api/v1');
            $uri  = $pos !== false ? substr($uri, $pos + 7) : $uri;
        }
        $uri = '/' . trim((string) $uri, '/');
        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Allow method override for clients that cannot send PUT/DELETE
        $override = strtoupper((string) ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
        if ($method === 'POST' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = $override;
        }

        $path      = self::currentPath();
        $pathFound = false;

        foreach ($this->routes as $routeMethod => $routes) {
            foreach ($routes as $route) {
                $params = self::match($route['path'], $path);
                if ($params === null) {
                    continue;
                }
                $pathFound = true;
                if ($routeMethod === $method) {
                    try {
                        call_user_func_array($route['handler'], $params);
                    } catch (PDOException $e) {
                        DB::rollback();
                        error_log('DB error on ' . $path . ': ' . $e->getMessage());
                        Response::error(APP_DEBUG ? $e->getMessage() : 'Database error', 500);
                    } catch (Throwable $e) {
                        DB::rollback();
                        error_log('Error on ' . $path . ': ' . $e->getMessage());
                        Response::error(APP_DEBUG ? $e->getMessage() : 'Server error', 500);
                    }
                    return;
                }
            }
        }

        if ($pathFound) {
            Response::error('Method ' . $method . ' not allowed for this endpoint', 405);
        }
        Response::notFound('Endpoint not found: ' . $path);
    }

    /** Returns matched params, or null when the pattern does not match. */
    private static function match(string $pattern, string $path): ?array
    {
        $pSeg = explode('/', trim($pattern, '/'));
        $uSeg = explode('/', trim($path, '/'));
        if (count($pSeg) !== count($uSeg)) {
            return null;
        }
        $params = [];
        foreach ($pSeg as $i => $seg) {
            if (strlen($seg) > 1 && $seg[0] === '{' && substr($seg, -1) === '}') {
                if ($uSeg[$i] === '') {
                    return null;
                }
                $params[] = $uSeg[$i];
                continue;
            }
            if ($seg !== $uSeg[$i]) {
                return null;
            }
        }
        return $params;
    }
}
