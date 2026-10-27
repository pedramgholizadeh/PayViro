# Webiro Gateway

**درگاه پرداخت مرکزی وبیرو** — یک نقطه‌ی اتصال روی `webiro.ir/gateway` که درگاه‌های پرداخت وبیرو (بانک سامان، بلوبانک، زرین‌پال، زیبال) را برای همه‌ی دامنه‌های شما فراهم می‌کند.

دامنه‌های دیگر (مثل `lahze-app.ir` و `webirox.ir`) با **API Key** به این سرویس درخواست می‌زنند؛ گیت‌وی پرداخت را با درگاه بانکی انجام می‌دهد، نتیجه را سمت سرور تایید می‌کند و نتیجه‌ی امضاشده را به دامنه‌ی مبدا برمی‌گرداند (ریدایرکت + وبهوک).

## ویژگی‌ها

- یک API واحد برای ۱۲+ درگاه (بانکی + کریپتو) + درگاه تست داخلی (شبیه‌ساز بانک برای تست بدون پول)
- **درگاه‌های بانکی:** زرین‌پال، زیبال، بانک سامان، آیدی‌پی، نکست‌پی، پی‌پینگ، سپرده، Pay.ir، آقای پرداخت، وندار، بلوبانک
- **درگاه کریپتو:** OxaPay — پرداخت با تتر/بیت‌کوین و… با وب‌هوک امضاشده‌ی HMAC-SHA512 و تشخیص کم‌پردازی
- احراز هویت هر دامنه با API Key مستقل + قفل دامنه‌ی callback
- تایید پرداخت سمت سرور (Verify) با تطبیق دقیق مبلغ
- ریدایرکت بازگشت و وبهوک با امضای **HMAC-SHA256**
- وبهوک با Retry خودکار (backoff) + endpoint کرون برای هاست اشتراکی
- پنل مدیریت: آمار، لیست تراکنش‌ها، جزئیات، ارسال مجدد وبهوک، خروجی CSV
- بدون Composer / بدون فریمورک — فقط PHP 7.4+ با cURL و PDO (SQLite پیش‌فرض، MySQL اختیاری)
- مستندات کامل فارسی در `docs/` (RTL، تم روشن/تیره، آماده‌ی آپلود روی سی‌پنل)

## نصب روی سی‌پنل (خلاصه)

راهنمای کامل: [`docs/getting-started.html`](docs/getting-started.html)

```bash
# ۱) محتویات این پوشه را در public_html/gateway/ آپلود کنید

# ۲) کانفیگ را بسازید و مقادیر CHANGE_ME را پر کنید
cp config/config.example.php config/config.php

# ۳) برای هر دامنه‌ی پذیرنده کلید بسازید
php tools/make-api-key.php "lahze-app" "https://lahze-app.ir"

# ۴) با درگاه تست بدون پول، چرخه را کامل تست کنید
#    (در درخواست: "gateway": "test")

# ۵) کرون سی‌پنل — هر ۵ دقیقه:
wget -q -O /dev/null "https://webiro.ir/gateway/api/cron/webhooks?key=YOUR_CRON_KEY"
```

## پیش‌نیازها

| نیاز | مقدار |
|---|---|
| PHP | 7.4+ (تست‌شده روی 8.x) |
| اکستنشن‌ها | `curl`, `PDO` + (`pdo_sqlite` یا `pdo_mysql`) |
| اکستنشن اختیاری | `soap` — فقط برای درگاه سامان |
| وب‌سرور | Apache / LiteSpeed با `mod_rewrite` (پیش‌فرض سی‌پنل) |

## استفاده از دامنه‌ی پذیرنده

فایل `sdk/WebiroGateway.php` را در پروژه‌ی دامنه‌ی دیگر کپی کنید:

```php
require 'WebiroGateway.php';

$gateway = new WebiroGateway(
    'https://webiro.ir/gateway',
    'wgr_...',   // API Key این دامنه
    'wgs_...'    // Secret (امضا)
);

// شروع پرداخت
$payment = $gateway->requestPayment([
    'amount'       => 2500000,      // ریال (یا 'currency' => 'toman')
    'callback_url' => 'https://lahze-app.ir/payment/result',
    'order_id'     => 'ORD-101',
    'gateway'      => 'auto',
]);
header('Location: ' . $payment['payment_url']);

// در صفحه‌ی بازگشت:
$result = $gateway->verifyRedirect();   // امضا تایید می‌شود
if ($result['ok']) { /* سفارش را پرداخت‌شده کنید */ }

// وبهوک:
$hook = $gateway->readWebhook();        // event + data
```

نمونه‌های کامل: `sdk/examples/`

## مسیرهای مهم

| مسیر | توضیح |
|---|---|
| `POST /api/v1/payments` | ایجاد پرداخت — `gateway`: zarinpal · zibal · saman · idpay · nextpay · payping · sepordeh · payir · aqayepardakht · vandar · bluebank · oxapay · test · auto |
| `GET /api/v1/payments/{token}` | استعلام وضعیت |
| `GET /api/v1/gateways` | درگاه‌های فعال |
| `GET /api/v1/health` | سلامت سرویس |
| `GET /go/{token}` | هدایت کاربر به بانک |
| `GET|POST /callback/{token}` | بازگشت بانک |
| `GET /api/cron/webhooks?key=…` | کرون وبهوک/انقضا |
| `/admin` | پنل مدیریت |
| `/docs/` | مستندات |

## ساختار پروژه

```
gateway/
├── index.php            ← front controller
├── .htaccess            ← روتینگ + مسدودسازی مسیرهای داخلی
├── admin/               ← پنل مدیریت (عمومی، با رمز)
├── docs/                ← مستندات استاتیک (عمومی)
├── config/              ← کانفیگ (مسدود)
├── data/                ← دیتابیس SQLite (مسدود)
├── logs/                ← لاگ‌ها (مسدود)
├── src/                 ← کد منبع (مسدود)
│   ├── Core/            ← Router, Database, HttpClient, Crypto, …
│   ├── Controllers/     ← Api, Payment, Cron
│   ├── Drivers/         ← ۱۲ درگاه بانکی/کریپتو + درگاه تست
│   ├── Models/          ← Transaction
│   └── Services/        ← PaymentService, WebhookService
├── sdk/                 ← SDK پذیرنده + نمونه‌ها (مسدود)
└── tools/               ← make-api-key.php (مسدود)
```

## امنیت

- امضای HMAC-SHA256 روی ریدایرکت (`sig`) و بدنه‌ی وبهوک (`X-Webiro-Signature`)
- جداسازی کامل داده‌ی پذیرنده‌ها (هر کلید فقط تراکنش‌های خودش)
- نهایی‌سازی اتمیک وضعیت — callback تکراری اثر دوباره ندارد
- تطبیق اجباری مبلغ بانک با مبلغ تراکنش (+ Reverse خودکار در سامان)
- Rate limit پیش‌فرض ۶۰ درخواست در دقیقه
- مسدودسازی وبِ `config`، `data`، `logs`، `src`، `sdk`، `tools`

جزئیات: `docs/security.html`

## نکته‌ی درگاه بلوبانک

مستندات IPG بلوبانک عمومی منتشر نمی‌شود؛ درایور بلوبانک کاملاً کانفیگ‌محور است و مقادیر آن را از مستندات پنل پذیرندگی بلوبانک در `config.php` (بخش `drivers.bluebank`) وارد کنید. — `docs/gateways.html#bluebank`

---

نسخه ۱.۰ — ساخته‌شده برای وبیرو
