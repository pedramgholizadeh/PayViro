<?php
/**
 * مثال ۳ — دریافت وبهوک (webhook_url)
 * آدرس:  https://lahze-app.ir/api/payment/webhook
 *
 * گیت‌وی وضعیت نهایی را با امضای HMAC به این آدرس POST می‌کند.
 * پاسخ باید HTTP 2xx باشد؛ در غیر این صورت گیت‌وی با backoff دوباره تلاش می‌کند.
 */

declare(strict_types=1);

require __DIR__ . '/../WebiroGateway.php';

use WebiroGateway;

$gateway = new WebiroGateway(
    'https://webiro.ir/gateway',
    'wgr_...your-api-key...',
    'wgs_...your-secret...'
);

try {
    $hook = $gateway->readWebhook();   // امضا روی بدنه‌ی خام بررسی می‌شود
} catch (WebiroSignatureException $e) {
    http_response_code(400);
    exit('invalid signature');
} catch (WebiroGatewayException $e) {
    http_response_code(400);
    exit('bad request');
}

$event = $hook['event'] ?? '';
$data  = $hook['data']  ?? [];

switch ($event) {
    case 'payment.succeeded':
        // UPDATE orders SET paid = 1, ref_no = ? WHERE order_id = ?
        break;

    case 'payment.failed':
    case 'payment.canceled':
        // سفارش را «ناموفق» علامت بزنید
        break;
}

// 200 OK → گیت‌وی متوجه تحویل موفق می‌شود
http_response_code(200);
echo 'ok';
