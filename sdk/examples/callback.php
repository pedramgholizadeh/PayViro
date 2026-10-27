<?php
/**
 * مثال ۲ — صفحه بازگشت از پرداخت (callback_url)
 * آدرس:  https://lahze-app.ir/payment/result
 *
 * گیت‌وی کاربر را با پارامترهای امضاشده به همین آدرس برمی‌گرداند:
 *   token, status, order_id, amount, gateway, ref_no, card_no, message, sig
 */

declare(strict_types=1);

require __DIR__ . '/../PayViro.php';

use WebiroGateway;

$gateway = new PayViro(
    'https://webiro.ir/gateway',
    'wgr_...your-api-key...',
    'wgs_...your-secret...'
);

header('Content-Type: text/html; charset=utf-8');

try {
    // امضای پارامترها بررسی می‌شود؛ دستکاری → استثنا
    $result = $gateway->verifyRedirect();

} catch (PayViroSignatureException $e) {
    http_response_code(400);
    exit('❌ ' . htmlspecialchars($e->getMessage()));
} catch (PayViroException $e) {
    http_response_code(400);
    exit('⚠ ' . htmlspecialchars($e->getMessage()));
}

// ⚠ مهم: هرگز فقط به پارامترهای redirect اعتماد نکنید.
// توصیه‌شده: برای مبلغ‌های بالا، وضعیت را دوباره از گیت‌وی استعلام بگیرید.
$status = $gateway->status($result['token']);
if ($status['status'] !== 'paid') {
    $result['ok'] = false;
}

if ($result['ok']) {
    // اینجا سفارش را در دیتابیس خودتان «پرداخت‌شده» کنید:
    // UPDATE orders SET paid = 1, ref_no = ? WHERE order_id = ? AND paid = 0
    echo "✅ پرداخت موفق — شناسه پیگیری: " . htmlspecialchars($result['ref_no']);
} else {
    echo "❌ پرداخت ناموفق: " . htmlspecialchars($result['message']);
}
echo '<br><br><a href="/">بازگشت</a>';
