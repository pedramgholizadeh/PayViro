<?php
/**
 * PayViro — Multi-domain payment switch (open source)
 * Author: Pedram Gholizadeh (WEBIRO) — https://github.com/pedramgholizadeh
 * License: MIT
 */
/**
 * Webiro Gateway — front controller
 * همه‌ی درخواست‌های /gateway/* (به‌جز docs/ و admin/) از اینجا عبور می‌کنند.
 */

declare(strict_types=1);

define('WG_ROOT', __DIR__);

require WG_ROOT . '/src/bootstrap.php';

use Webiro\Controllers\ApiController;
use Webiro\Controllers\CronController;
use Webiro\Controllers\PaymentController;
use Webiro\Core\Http\Request;
use Webiro\Core\Router;

$router = new Router();

/* ---------------- API v1 (server-to-server) ---------------- */
$router->add('GET', '/api/v1/health', [ApiController::class, 'health']);
$router->add('GET', '/api/v1/gateways', [ApiController::class, 'gateways']);
$router->add('POST', '/api/v1/payments', [ApiController::class, 'create']);
$router->add('GET', '/api/v1/payments/{token}', [ApiController::class, 'status']);

/* ---------------- Bank-facing flow (browser) ---------------- */
$router->add('GET', '/go/{token}', [PaymentController::class, 'go']);
$router->add('GET', '/pay/{token}', [PaymentController::class, 'go']);
$router->add('GET', '/callback/{token}', [PaymentController::class, 'callback']);
$router->add('POST', '/callback/{token}', [PaymentController::class, 'callback']);

/* ---------------- Test-driver sandbox (فقط درگاه تست) ---------------- */
$router->add('GET', '/go/{token}/sandbox', [PaymentController::class, 'sandboxPage']);
$router->add('POST', '/go/{token}/sandbox/pay', [PaymentController::class, 'sandboxAction']);
$router->add('POST', '/go/{token}/sandbox/failed', [PaymentController::class, 'sandboxAction']);
$router->add('POST', '/go/{token}/sandbox/cancel', [PaymentController::class, 'sandboxAction']);

/* ---------------- Cron (وبهوک‌های ناموفق + انقضای تراکنش‌ها) ---------------- */
$router->add('GET', '/api/cron/webhooks', [CronController::class, 'webhooks']);

$request  = Request::capture();
$response = $router->dispatch($request);
$response->send();
