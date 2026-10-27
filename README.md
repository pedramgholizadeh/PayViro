<div dir="rtl">

# PayViro

<p align="center">
  <b>سوئیچ پرداخت مرکزی — اوپن‌سورس و سلف‌هاست</b><br>
  یک بار نصب کنید؛ همه‌ی دامنه‌ها و اپلیکیشن‌هایتان پرداخت داشته باشند.
</p>

- 🌐 **وب‌سایت و لندینگ:** [apps.webiro.ir/payviro](https://apps.webiro.ir/payviro)
- 📚 **مستندات کامل فارسی:** [apps.webiro.ir/payviro/docs](https://apps.webiro.ir/payviro/docs)
- 🧑‍💻 **نویسنده:** [Pedram Gholizadeh](https://github.com/pedramgholizadeh) — برند **WEBIRO**
- 📄 مجوز: MIT

---

## English (TL;DR)

**PayViro** is a self-hosted, open-source payment gateway hub. Deploy it once on your own domain and every other domain/app of yours accepts payments through a single REST API — no separate merchant account, gateway or trust seal per domain. 18 drivers: Iranian bank & aggregator gateways, BNPL (installment) providers and crypto. Zero dependencies, plain PHP 7.4+, cPanel-friendly. *Developed by Pedram Gholizadeh (WEBIRO).*

</div>

---

## ایده در یک جمله

درگاه پرداخت، ای‌نماد و تایید شاپرک فقط روی **یک دامنه** لازم است. PayViro را روی همان دامنه نصب می‌کنید و بقیه‌ی سایت‌ها و اپ‌هایتان با یک API Key به آن درخواست می‌زنند؛ PayViro پرداخت را با بانک انجام می‌دهد، نتیجه را **سمت سرور** تایید می‌کند و نتیجه‌ی **امضاشده (HMAC-SHA256)** را به دامنه‌ی مبدأ برمی‌گرداند — هم با ریدایرکت و هم با وبهوک.

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

درگاهی که در کانفیگ تنظیم نشده باشد خودکار غیرفعال است. حالت `"gateway": "auto"` بر اساس `auto_order` انتخاب می‌کند — پیش‌فرض با اولویت سامان و پاسارگاد.

## ویژگی‌ها

- **یک API واحد** برای همه‌ی درگاه‌ها + انتخاب خودکار (`auto`)
- **کلید API مستقل برای هر دامنه** با قفل دامنه‌ی callback و وبهوک اختصاصی
- **تایید سمت سرور** با استعلام مستقیم از بانک + تطبیق اجباری مبلغ (+ برگشت وجه خودکار در سامان)
- **امضای HMAC-SHA256** روی ریدایرکت بازگشت و وبهوک؛ وب‌هوک کریپتو با HMAC-SHA512
- **نهایی‌سازی اتمیک** — callback تکراری بانک هرگز دو بار «پرداخت موفق» نمی‌سازد
- **وبهوک با Retry خودکار** + endpoint کرون برای هاست اشتراکی
- **پنل مدیریت فارسی**: آمار، جست‌وجوی تراکنش، پاسخ خام بانک، ارسال مجدد وبهوک، CSV
- **نصب‌کننده‌ی وب** (`install.php`) — بدون نیاز به خط فرمان
- **بدون هیچ وابستگی** — PHP خالص 7.4+، فونت‌ها و آیکون‌ها هم لوکال

## نصب

### روش ۱ — نصب‌کننده‌ی وب (پیشنهادی)

1. محتویات پروژه را در `public_html/gateway/` آپلود کنید.
2. در مرورگر `https://your-domain.ir/gateway/install.php` را باز کنید.
3. نصب‌کننده پیش‌نیازها را چک می‌کند، دیتابیس را می‌سازد، `config/config.php` را می‌نویسد، پنل مدیریت و یک کلید API دمو تحویل می‌دهد و خودش را قفل می‌کند.
4. اعتبارنامه‌ی درگاه‌های بانکی را بعداً در `config/config.php` بخش `drivers` وارد کنید.

### روش ۲ — دستی

```bash
# ۱) آپلود در public_html/gateway/
# ۲) ساخت کانفیگ:
cp config/config.example.php config/config.php   # و پر کردن CHANGE_MEها
# ۳) ساخت کلید پذیرنده برای هر دامنه:
php tools/make-api-key.php "my-shop" "https://my-shop.ir"
# ۴) کرون هر ۵ دقیقه:
wget -q -O /dev/null "https://your-domain.ir/gateway/api/cron/webhooks?key=YOUR_CRON_KEY"
```

پیش از پروداکشن: رمز پنل مدیریت را قوی کنید، `drivers.test.enabled` را `false` کنید و یک پرداخت واقعی کوچک را چرخه بدهید.

## استفاده از دامنه‌ی پذیرنده

فایل `sdk/PayViro.php` را در پروژه‌ی دامنه‌ی دیگر کپی کنید (بدون وابستگی، PHP 7.4+):

```php
require 'PayViro.php';

$gateway = new PayViro(
    'https://your-domain.ir/gateway',   // آدرس نصب PayViro شما
    'wgr_...',                          // API Key این دامنه
    'wgs_...'                           // Secret برای تایید امضاها
);

// ۱) ایجاد پرداخت و هدایت کاربر
$payment = $gateway->requestPayment([
    'amount'       => 250000,          // تومان (یا 'currency' => 'rial')
    'currency'     => 'toman',
    'gateway'      => 'auto',          // یا saman / pasargad / oxapay / …
    'order_id'     => 'ORD-101',
    'callback_url' => 'https://your-shop.ir/payment/result',
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

## مرجع API

| متد | مسیر | توضیح |
|---|---|---|
| `POST` | `/api/v1/payments` | ایجاد پرداخت (احراز: هدر `X-API-Key`) |
| `GET` | `/api/v1/payments/{token}` | استعلام وضعیت — منبع حقیقت |
| `GET` | `/api/v1/gateways` | درگاه‌های فعال + نوع هرکدام |
| `GET` | `/api/v1/health` | سلامت سرویس |
| `GET` | `/go/{token}` | هدایت کاربر به بانک |
| `GET\|POST` | `/callback/{token}` | بازگشت بانک / وب‌هوک تامین‌کننده |
| `GET` | `/api/cron/webhooks?key=…` | کرون وبهوک + انقضا |
| — | `/admin` | پنل مدیریت |

مرجع کامل: [apps.webiro.ir/payviro/docs/api-reference.html](https://apps.webiro.ir/payviro/docs/api-reference.html)

## امنیت

- تایید پرداخت همیشه سمت سرور و با استعلام مستقیم از بانک — هرگز با پارامترهای مرورگر
- امضای HMAC روی ریدایرکت (`sig`) و بدنه‌ی وبهوک (`X-PayViro-Signature`)؛ مقایسه با `hash_equals`
- تطبیق اجباری مبلغ بانک با مبلغ تراکنش؛ مغایرت = شکست (+ برگشت وجه خودکار در سامان)
- اتصال امن شناسه‌های بانکی (ResNum/RefNum/invoiceId باید متعلق به همان تراکنش باشند)
- جداسازی کامل پذیرنده‌ها؛ rate limit؛ شماره کارت فقط ماسک‌شده
- مسدودسازی وبِ `config`، `data`، `logs`، `src`، `sdk`، `tools` با `.htaccess`

## افزودن درگاه جدید

کلاسی بسازید که `PayViro\Drivers\DriverInterface` را پیاده کند (دو متد: `createPayment` و `verifyPayment`)، در `DriverFactory` و `config.php` معرفی کنید و مستندات را یک بخش اضافه کنید. الگوها: `ZibalDriver.php` (REST ساده) و `BnplDriver.php` (کانفیگ‌محور). برای ایجنت‌های هوش مصنوعی، [AGENTS.md](AGENTS.md) راهنمای کامل است.

## مجوز و نویسنده

- مجوز: [MIT](LICENSE)
- **نویسنده و توسعه‌دهنده: Pedram Gholizadeh** — برند **WEBIRO**
- مشارکت، فورک و درایور جدید خوش‌آمدگویی.

<div dir="ltr" align="left">

*PayViro — a multi-domain payment switch for Iranian gateways & crypto.*
*Authored by [Pedram Gholizadeh](https://github.com/pedramgholizadeh) / WEBIRO. MIT licensed.*

</div>
