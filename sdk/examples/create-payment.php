<?php
/**
 * مثال ۱ — ایجاد پرداخت و هدایت کاربر به گیت‌وی
 * این فایل روی دامنه‌ی پذیرنده است، مثلاً:  https://lahze-app.ir/payment/start
 */

declare(strict_types=1);

require __DIR__ . '/../WebiroGateway.php';

use WebiroGateway;

$gateway = new WebiroGateway(
    'https://webiro.ir/gateway',          // آدرس گیت‌وی
    'wgr_...your-api-key...',             // X-API-Key پذیرنده
    'wgs_...your-secret...'               // کلید امضا
);

// داده سفارش خودتان — مبلغ از دیتابیس خودتان خوانده شود، نه از ورودی کاربر!
$orderId = 'ORD-' . date('Ymd') . '-' . random_int(1000, 9999);
$amountRial = 2500000; // ۲۵۰ هزار تومان

try {
    $payment = $gateway->requestPayment([
        'amount'        => $amountRial,
        'currency'      => 'rial',                          // یا 'toman'
        'callback_url'  => 'https://lahze-app.ir/payment/result',
        'order_id'      => $orderId,
        'description'   => 'خرید اشتراک یک‌ساله',
        'mobile'        => '09121234567',
        'gateway'       => 'auto',                          // یا zarinpal / zibal / saman / bluebank
        // 'webhook_url' => 'https://lahze-app.ir/api/payment/webhook',
        // 'idempotency_key' => $orderId,
    ]);

    // توکن تراکنش را کنار سفارش خودتان ذخیره کنید
    // UPDATE orders SET gateway_token = ? WHERE id = ?
    file_put_contents(__DIR__ . '/last-token.txt', $payment['token'] . "\n" . $orderId);

    header('Location: ' . $payment['payment_url']);
    exit;

} catch (WebiroGatewayException $e) {
    http_response_code(500);
    echo 'خطا در ایجاد پرداخت: ' . htmlspecialchars($e->getMessage());
}
