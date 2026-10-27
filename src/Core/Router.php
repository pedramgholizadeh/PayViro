<?php

declare(strict_types=1);

namespace Webiro\Core;

use Webiro\ApiException;
use Webiro\Core\Http\Request;
use Webiro\Core\Http\Response;

/**
 * Minimal pattern router:  '/api/v1/payments/{token}'
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:array}> */
    private $routes = [];

    public function add(string $method, string $pattern, array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => '/' . trim($pattern, '/'),
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        // CORS preflight for API routes (only if origins are configured)
        if ($request->method === 'OPTIONS' && $request->isApi()) {
            $response = Response::noContent();
            $this->applyCors($request, $response);
            return $response;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $regex = $this->toRegex($route['pattern']);
            if (preg_match($regex, $request->path, $matches) !== 1) {
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            try {
                [$class, $method] = $route['handler'];
                $controller = new $class();
                $response = $controller->{$method}($request, $params);
            } catch (ApiException $e) {
                $response = $this->errorResponse($request, $e->httpStatus(), [
                    'code'    => $e->errorCode(),
                    'message' => $e->getMessage(),
                ] + $e->extra());
            } catch (\Throwable $e) {
                Logger::error('unhandled', [
                    'path'    => $request->path,
                    'method'  => $request->method,
                    'error'   => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);
                $response = $this->errorResponse($request, 500, [
                    'code'    => 'internal_error',
                    'message' => self::debugEnabled() ? $e->getMessage() : 'خطای داخلی سرور. لطفاً بعداً تلاش کنید.',
                ]);
            }

            if ($request->isApi()) {
                $this->applyCors($request, $response);
            }
            return $response;
        }

        // No route matched
        if ($request->isApi()) {
            return $this->errorResponse($request, 404, [
                'code'    => 'not_found',
                'message' => 'مسیر مورد نظر یافت نشد.',
            ]);
        }

        return wg_error_page(
            404,
            'صفحه پیدا نشد',
            'آدرس وارد شده در گیت‌وی پرداخت وبیرو وجود ندارد.'
        );
    }

    private function toRegex(string $pattern): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    private function errorResponse(Request $request, int $status, array $error): Response
    {
        if ($request->isApi()) {
            return Response::json(['status' => 'error', 'error' => $error], $status);
        }
        $code = (string) ($error['code'] ?? '');
        $statusText = $status === 404 ? 'مسیر یافت نشد' : 'خطا در گیت‌وی';
        return wg_error_page($status, $statusText, (string) ($error['message'] ?? ''), $code);
    }

    private function applyCors(Request $request, Response $response): void
    {
        try {
            $origins = (array) Config::get('app.cors_origins', []);
        } catch (\Throwable $e) {
            return; // config unavailable (e.g. setup error) — skip CORS silently
        }
        if ($origins === []) {
            return;
        }
        $origin = $request->header('origin');
        if ($origin === '' || !in_array($origin, $origins, true)) {
            return;
        }
        $response->withHeader('Access-Control-Allow-Origin', $origin);
        $response->withHeader('Vary', 'Origin');
        $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key, Authorization');
        $response->withHeader('Access-Control-Max-Age', '600');
    }

    /**
     * Debug flag that never throws (safe to call from error paths).
     */
    private static function debugEnabled(): bool
    {
        try {
            return (bool) Config::get('app.debug', false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
