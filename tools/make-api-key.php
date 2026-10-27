<?php
/**
 * Webiro Gateway — تولید کلید API برای پذیرنده‌ی جدید
 *
 *  اجرا (فقط CLI):
 *      php tools/make-api-key.php "lahze-app" "https://lahze-app.ir"
 *
 *  خروجی، تکه‌ی آماده‌ی config.php را چاپ می‌کند — آن را داخل
 *  بخش merchants در config/config.php اضافه کنید.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('فقط از خط فرمان اجرا شود.');
}

define('WG_ROOT', dirname(__DIR__));

require WG_ROOT . '/src/bootstrap.php';

use Webiro\Core\Crypto;

$name = $argv[1] ?? '';
$callbackDomain = rtrim($argv[2] ?? '', '/');

if ($name === '' || !preg_match('/^[a-z0-9\-_]{2,64}$/i', $name)) {
    fwrite(STDERR, "استفاده:  php tools/make-api-key.php \"<نام-پذیرنده>\" [\"https://دامنه.ایر\"]\n");
    exit(1);
}

$apiKey = Crypto::generateApiKey();
$secret = Crypto::generateSecret();

echo "\n✅ کلید پذیرنده «{$name}» ساخته شد.\n\n";
echo "این تکه را در config/config.php داخل 'merchants' اضافه کنید:\n\n";
echo "    '{$name}' => [\n";
echo "        'enabled'         => true,\n";
echo "        'api_key'         => '{$apiKey}',\n";
echo "        'secret'          => '{$secret}',\n";
echo "        'callback_domain' => '{$callbackDomain}',\n";
echo "        'webhook_url'     => '{$callbackDomain}/api/payment/webhook',\n";
echo "        'gateways'        => null,\n",
    "    ],\n\n";
echo "⚠ کلید secret را در دسترسی عمومی قرار ندهید — برای امضای redirect و وبهوک استفاده می‌شود.\n\n";
