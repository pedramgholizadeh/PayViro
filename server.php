<?php
/**
 * ------------------------------------------------------------------
 *  PayViro — development router (فقط برای تست لوکال)
 * ------------------------------------------------------------------
 *  اجرا:   php -S 127.0.0.1:8098 server.php
 *
 *  رفتار .htaccess آپاچی را شبیه‌سازی می‌کند:
 *   - فایل/پوشه‌ی واقعی (docs/, admin/) مستقیم سرو می‌شود
 *   - پوشه‌های داخلی مسدودند
 *   - بقیه‌ی مسیرها به front controller می‌روند
 *  روی هاست سی‌پنل این فایل نقشی ندارد؛ .htaccess حاکم است.
 */

declare(strict_types=1);

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// Blocks that .htaccess denies on production
if (preg_match('#^/(config|data|logs|src|sdk|tools|tests)(/|$)#', $path) === 1) {
    http_response_code(403);
    exit('Forbidden');
}

$file = __DIR__ . $path;
if ($path !== '/' && (is_file($file) || is_dir($file))) {
    return false; // let the built-in server handle docs/, admin/, etc.
}

require __DIR__ . '/index.php';
