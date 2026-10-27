<?php
/**
 * Webiro Gateway — bootstrap
 * Central Payment Gateway Service for webiro.ir
 *
 * PHP 7.4+ / cPanel compatible, zero Composer dependencies.
 */

declare(strict_types=1);

if (!defined('WG_ROOT')) {
    exit('No direct access.');
}

if (!defined('WG_START')) {
    define('WG_START', microtime(true));
}

define('WG_VERSION', '1.0.0');

/* ------------------------------------------------------------------
 |  PSR-4 style autoloader:  Webiro\Core\Config  →  src/Core/Config.php
 * ------------------------------------------------------------------ */
spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Webiro\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('Webiro\\'));
    $file = WG_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

mb_internal_encoding('UTF-8');

require WG_ROOT . '/src/helpers.php';

/* ------------------------------------------------------------------
 |  Fatal-error safety net: never leak a stack trace to the client.
 * ------------------------------------------------------------------ */
ini_set('display_errors', '0');
error_reporting(E_ALL);

\register_shutdown_function(static function (): void {
    $err = \error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    \Webiro\Core\Logger::error('fatal', [
        'message' => $err['message'],
        'file'    => $err['file'],
        'line'    => $err['line'],
    ]);
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (!\headers_sent()) {
        \http_response_code(500);
        \header('Content-Type: application/json; charset=utf-8');
    }
    echo \json_encode([
        'status' => 'error',
        'error'  => ['code' => 'internal_error', 'message' => 'خطای داخلی سرور'],
    ], JSON_UNESCAPED_UNICODE);
});

/* ------------------------------------------------------------------
 |  Force HTTPS when configured (skipped for CLI and local dev).
 |  Config errors here are ignored on purpose — the router renders
 |  a friendly setup message instead.
 * ------------------------------------------------------------------ */
try {
    if (PHP_SAPI !== 'cli'
        && \Webiro\Core\Config::get('app.https_only', true)
        && strpos((string) \Webiro\Core\Config::get('app.base_url', ''), 'https://') === 0
        && empty($_SERVER['HTTPS'])
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) !== 'https'
    ) {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($host !== '') {
            \header('Location: https://' . $host . $uri, true, 301);
            exit;
        }
    }
} catch (\Throwable $e) {
    // fall through — dispatcher will surface the setup error properly
}
