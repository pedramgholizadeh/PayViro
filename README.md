<div dir="rtl">

# PayViro

**سوئیچ پرداخت مرکزی اوپن‌سورس** — یک نقطه‌ی اتصال روی `webiro.ir/gateway` که همه‌ی درگاه‌های پرداخت شما (بانکی، خرید قسطی و کریپتو) را برای تمام دامنه‌ها و اپلیکیشن‌هایتان فراهم می‌کند.

> **نام PayViro** از ترکیب **Pay + Viro** (از Webiro) ساخته شده است.

- 🌐 **لندینگ پروژه:** [apps.webiro.ir/payviro](https://apps.webiro.ir/payviro)
- 📚 **مستندات کامل:** [webiro.ir/gateway/docs](https://webiro.ir/gateway/docs/)
- 🧑‍💻 **نویسنده:** [Pedram Gholizadeh](https://github.com/pedramgholizadeh) — برند **WEBIRO**

---

## English (TL;DR)

**PayViro** is a self-hosted, open-source payment gateway hub. Deploy it once on your main domain (e.g. `webiro.ir/gateway`) and every other domain/app of yours accepts payments through a single REST API — no separate merchant account, gateway or e-namad trust seal needed per domain. 18 drivers: Iranian bank & aggregator gateways, BNPL (installment) providers and crypto. Zero dependencies, plain PHP 7.4+, cPanel-friendly. *Developed by Pedram Gholizadeh (WEBIRO).*

</div>

---

## چرا PayViro؟

درگاه پرداخت، ای‌نماد و تایید شاپرک فقط روی **یک دامنه** لازم است. دامنه‌های دیگر (مثل `lahze-app.ir`، `webirox.ir` یا اپلیکیشن موبایل) با یک API Key به این سرویس درخواست می‌زنند؛ گیت‌وی پرداخت را با بانک انجام می‌دهد، نتیجه را **سمت سرور** تایید می‌کند و نتیجه‌ی **امضاشده (HMAC-SHA256)** را به دامنه‌ی مبدأ برمی‌گرداند — هم با ریدایرکت و هم با وبهوک.

## درگاه‌های پشتیبانی‌شده (۱۸)

| اولویت | درگاه | نوع | واحد مبلغ |
|---|---|---|---|
| ⭐ | بانک سامان (SEP) | بانک مستقیم (SOAP) | ریال |
| ⭐ | بانک پاسارگاد (نسل جدید `pep.shaparak.ir`) | بانک مستقیم (REST) | ریال |
| | زرین‌پال | واسط | ریال |
| | زیبال | واسط | ریال |
| | آیدی‌پی (IDPay) | واسط | ریال |
| | نکست‌پی (NextPay) | واسط | ریال/تومان (قابل تنظیم) |
| | پی‌پینگ (PayPing) | واسط | تومان |
| | سپرده (Sepordeh) | واسط | تومان |
| | پی.آی‌آر (Pay.ir) | واسط | تومان |
| | آقای پرداخت | واسط | تومان |
| | وندار (Vandar) | واسط | ریال |
| | بلوبانک | بانک (کانفیگ‌محور) | ریال |
| | اسنپ‌پی (SnappPay) | خرید قسطی (BNPL) | ریال |
| | دیجی‌پی، تارا، لندو، قسطا | خرید قسطی (BNPL — کانفیگ‌محور) | قابل تنظیم |
| | OxaPay | کریپتو (تتر/بیت‌کوین و…) | IRT/USD/… |
| | درگاه تست داخلی | شبیه‌ساز کامل بانک | ریال |

درگاهی که در کانفیگ تنظیم نشده باشد خودکار از چرخه حذف می‌شود. حالت `"gateway": "auto"` بر اساس ترتیب اولویت (`auto_order`) انتخاب می‌کند — پیش‌فرض با اولویت سامان و پاسارگاد.

## ویژگی‌ها

- **یک API واحد** برای همه‌ی درگاه‌ها + انتخاب خودکار (`auto`)
- **کلید API مستقل برای هر دامنه** با قفل دامنه‌ی callback و وبهوک اختصاصی
- **تایید سمت سرور** با استعلام مستقیم از بانک + تطبیق اجباری مبلغ (+ برگشت وجه خودکار در سامان)
- **امضای HMAC-SHA256** روی ریدایرکت بازگشت و وبهوک؛ وب‌هوک کریپتو با HMAC-SHA512
- **نهایی‌سازی اتمیک** — callback تکراری بانک هرگز دو بار «پرداخت موفق» نمی‌سازد
- **وبهوک با Retry خودکار** (backoff ۱ تا ۲۴۰ دقیقه) + endpoint کرون برای هاست اشتراکی
- **پنل مدیریت فارسی**: آمار، جست‌وجوی تراکنش، پاسخ خام بانک، ارسال مجدد وبهوک، CSV
- **درگاه تست داخلی** — شبیه‌ساز بانک برای تست کل چرخه بدون پول واقعی
- **بدون هیچ وابستگی** — PHP خالص 7.4+ با cURL و PDO؛ SQLite پیش‌فرض، MySQL اختیاری

## پیش‌نیازها

| نیاز | مقدار |
|---|---|
| PHP | 7.4+ (تست‌شده روی 8.x) |
| اکستنشن اجباری | `curl` · `PDO` + (`pdo_sqlite` یا `pdo_mysql`) |
| اکستنشن اختیاری | `soap` (فقط سامان) |
| وب‌سرور | Apache / LiteSpeed با `mod_rewrite` |
| گواهی SSL | روی دامنه‌ی میزبان فعال باشد |

## نصب و راه‌اندازی (خلاصه)

راهنمای تصویری و کامل: [مستندات — نصب و راه‌اندازی](https://webiro.ir/gateway/docs/getting-started.html)

```bash
# ۱) محتویات پروژه را در public_html/gateway/ آپلود کنید
#    (config/ ، data/ ، logs/ ، src/ ، sdk/ ، tools/ از وب مسدود هستند)

# ۲) فایل کانفیگ را بسازید و همه‌ی CHANGE_MEها را پر کنید
cp config/config.example.php config/config.php

# ۳) برای هر دامنه‌ی پذیرنده یک کلید بسازید
php tools/make-api-key.php "lahze-app" "https://lahze-app.ir"
#    تکه‌ی خروجی را در بخش merchants کانفیگ اضافه کنید

# ۴) اعتبارنامه‌ی درگاه‌های موردنظر را در بخش drivers وارد کنید
#    (زرین‌پال/زیبال/سامان/پاسارگاد/… — هر کدام را تنظیم کنید فعال می‌شود)

# ۵) با درگاه تست، چرخه را بدون پول تست کنید:
curl -X POST https://webiro.ir/gateway/api/v1/payments \
  -H "X-API-Key: wgr_..." -H "Content-Type: application/json" \
  -d '{"amount":1500000,"gateway":"test","callback_url":"https://lahze-app.ir/payment/result"}'
#    payment_url را در مرورگر باز کنید → شبیه‌ساز بانک

# ۶) کرون سی‌پنل (هر ۵ دقیقه) برای وبهوک‌های معوق و انقضای تراکنش‌ها:
wget -q -O /dev/null "https://webiro.ir/gateway/api/cron/webhooks?key=YOUR_CRON_KEY"
```

پیش از پروداکشن: رمز پنل مدیریت را عوض کنید، `drivers.test.enabled` را `false` کنید و یک پرداخت واقعی کوچک را چرخه بدهید.

## استفاده از دامنه‌ی پذیرنده

فایل `sdk/PayViro.php` را در پروژه‌ی دامنه‌ی دیگر کپی کنید (بدون وابستگی، PHP 7.4+):

```php
require 'PayViro.php';

$gateway = new PayViro(
    'https://webiro.ir/gateway',
    'wgr_...',   // API Key این دامنه
    'wgs_...'    // Secret برای تایید امضاها
);

// ۱) ایجاد پرداخت و هدایت کاربر
$payment = $gateway->requestPayment([
    'amount'       => 250000,      // تومان (یا 'currency' => 'rial')
    'currency'     => 'toman',
    'gateway'      => 'auto',      // یا saman / pasargad / oxapay / …
    'order_id'     => 'ORD-101',
    'callback_url' => 'https://lahze-app.ir/payment/result',
]);
header('Location: ' . $payment['payment_url']);

// ۲) در صفحه‌ی بازگشت — امضا تایید می‌شود، دستکاری رد می‌شود
$result = $gateway->verifyRedirect();
if ($result['ok']) {
    // سفارش را پرداخت‌شده کنید (idempotent)
}

// ۳) وبهوک سمت سرور
$hook = $gateway->readWebhook();   // event: payment.succeeded / payment.failed / payment.canceled
```

نمونه‌های کامل در `sdk/examples/` و راهنمای لاراول در [مستندات SDK](https://webiro.ir/gateway/docs/sdk.html).

## مرجع API

همه‌ی مسیرها زیر `https://webiro.ir/gateway` سرو می‌شوند:

| متد | مسیر | توضیح |
|---|---|---|
| `POST` | `/api/v1/payments` | ایجاد پرداخت (احراز: هدر `X-API-Key`) |
| `GET` | `/api/v1/payments/{token}` | استعلام وضعیت — منبع حقیقت |
| `GET` | `/api/v1/gateways` | درگاه‌های فعال + نوع هرکدام |
| `GET` | `/api/v1/health` | سلامت سرویس و دیتابیس |
| `GET` | `/go/{token}` | هدایت کاربر به بانک |
| `GET\|POST` | `/callback/{token}` | بازگشت بانک / وب‌هوک تامین‌کننده |
| `GET` | `/api/cron/webhooks?key=…` | کرون وبهوک + انقضا |
| — | `/admin` | پنل مدیریت |
| — | `/docs/` | مستندات |

مرجع کامل با پارامترها، پاسخ‌ها و کدهای خطا: [API Reference](https://webiro.ir/gateway/docs/api-reference.html)

## امنیت

- تایید پرداخت همیشه سمت سرور و با استعلام مستقیم از بانک — هرگز با پارامترهای مرورگر
- امضای HMAC روی ریدایرکت (`sig`) و بدنه‌ی وبهوک (`X-Webiro-Signature`)؛ همیشه با `hash_equals`
- تطبیق اجباری مبلغ بانک با مبلغ تراکنش؛ مغایرت = شکست
- اتصال امن شناسه‌های بانکی (ResNum/RefNum/invoiceId باید متعلق به همان تراکنش باشند)
- جداسازی کامل پذیرنده‌ها؛ rate limit (۶۰ درخواست در دقیقه)؛ شماره کارت فقط ماسک‌شده
- مسدودسازی وبِ `config`، `data`، `logs`، `src`، `sdk`، `tools` با `.htaccess`

جزئیات: [امنیت و امضا](https://webiro.ir/gateway/docs/security.html)

## افزودن درگاه جدید

معماری درایورمحور است: کلاسی بسازید که `Webiro\Drivers\DriverInterface` را پیاده کند (دو متد: `createPayment` و `verifyPayment`)، در `DriverFactory` و `config.php` معرفی کنید و مستندات را یک بخش اضافه کنید. بقیه‌ی چرخه (توکن، callback، وبهوک، امضا، پنل) خودکار کار می‌کند. الگوهای آماده: `ZibalDriver.php` (REST ساده) و `BnplDriver.php` (کانفیگ‌محور).

## ساختار پروژه

```
gateway/
├── index.php            ← front controller
├── .htaccess            ← روتینگ + مسدودسازی مسیرهای داخلی
├── admin/               ← پنل مدیریت (عمومی، با رمز)
├── docs/                ← مستندات استاتیک فارسی (عمومی)
├── landing/             ← لندینگ اوپن‌سورس (apps.webiro.ir/payviro)
├── config/              ← کانفیگ (مسدود)
├── data/                ← دیتابیس SQLite (مسدود)
├── logs/                ← لاگ‌ها (مسدود)
├── src/                 ← کد منبع (مسدود)
│   ├── Core/            ← Router, Database, HttpClient, Crypto, Soap, …
│   ├── Controllers/     ← Api, Payment, Cron
│   ├── Drivers/         ← ۱۸ درایور (بانکی، قسطی، کریپتو، تست)
│   ├── Models/          ← Transaction
│   └── Services/        ← PaymentService, WebhookService
├── sdk/                 ← SDK پذیرنده + نمونه‌ها (مسدود)
└── tools/               ← make-api-key.php (مسدود)
```

## مجوز و نویسنده

- مجوز: [MIT](LICENSE) — استفاده تجاری آزاد است با حفظ اعلان مجوز.
- **نویسنده و توسعه‌دهنده: Pedram Gholizadeh** — برند **WEBIRO**
- این پروژه به‌صورت اوپن‌سورس منتشر شده؛ مشارکت، فورک و درایور جدید خوش‌آمدگویی.

<div dir="ltr" align="left">

*PayViro — a multi-domain payment switch for Iranian gateways & crypto.*
*Authored by [Pedram Gholizadeh](https://github.com/pedramgholizadeh) / WEBIRO. MIT licensed.*

</div>
