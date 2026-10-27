<?php
/**
 * ------------------------------------------------------------------
 *  PayViro — تنظیمات اصلی
 * ------------------------------------------------------------------
 *  ۱) این فایل را با نام «config.php» در همین پوشه کپی کنید:
 *        cp config.example.php config.php
 *  ۲) مقادیر مشخص‌شده با CHANGE_ME را پر کنید.
 *
 *  ⚠ هرگز این فایل را در گیت یا جایی عمومی به اشتراک نگذارید.
 *  این پوشه از طریق .htaccess از دسترسی وب مسدود شده است.
 */

declare(strict_types=1);

return [

    /* --------------------------------------------------------------
     *  تنظیمات کلی
     * -------------------------------------------------------------- */
    'app' => [
        // آدرس کامل محل نصب گیت‌وی (بدون اسلش انتهایی)
        'base_url'   => 'https://webiro.ir/gateway',

        'timezone'   => 'Asia/Tehran',

        // اجبار به HTTPS (برای تست روی لوکال آن را false کنید)
        'https_only' => true,

        // true فقط هنگام عیب‌یابی — پیام خطای واقعی به کلاینت برمی‌گردد
        'debug'      => false,

        // دامنه‌های مجاز برای CORS روی مسیرهای /api (معمولاً نیازی نیست؛
        // فراخوانی API سمت سرور انجام می‌شود). خالی = غیرفعال
        'cors_origins' => [],
    ],

    /* --------------------------------------------------------------
     *  بانک اطلاعاتی
     *  پیش‌فرض: SQLite (بدون نیاز به تنظیم؛ روی هاست سی‌پنل کار می‌کند)
     *  گزینه دوم: MySQL (از دیتابیس سی‌پنل خود استفاده کنید)
     * -------------------------------------------------------------- */
    'db' => [
        'driver'      => 'sqlite', // sqlite | mysql
        'sqlite_path' => __DIR__ . '/../data/gateway.sqlite',

        'mysql' => [
            'host'    => 'localhost',
            'port'    => 3306,
            'dbname'  => 'webiro_gateway',
            'user'    => 'CHANGE_ME',
            'pass'    => 'CHANGE_ME',
            'charset' => 'utf8mb4',
        ],
    ],

    /* --------------------------------------------------------------
     *  لاگ
     * -------------------------------------------------------------- */
    'log' => [
        // debug | info | warning | error
        'level'     => 'info',
        'dir'       => __DIR__ . '/../logs',
        'max_files' => 30,
    ],

    /* --------------------------------------------------------------
     *  رفتار پرداخت‌ها
     * -------------------------------------------------------------- */
    'payments' => [
        // مدت اعتبار تراکنش قبل از ورود به درگاه بانکی (دقیقه)
        'ttl_minutes'  => 20,

        // مبلغ مجاز (ریال)
        'min_amount'   => 10000,
        'max_amount'   => 500000000,

        // درگاه پیش‌فرض وقتی درخواست gateway نفرستد
        'default_gateway' => 'saman',

        // ترتیب انتخاب خودکار وقتی gateway = "auto" باشد
        // ⭐ بانک سامان و پاسارگاد در اولویت هستند
        'auto_order'   => ['saman', 'pasargad', 'zarinpal', 'zibal', 'idpay', 'nextpay', 'payping', 'sepordeh', 'payir', 'aqayepardakht', 'vandar', 'bluebank', 'snappay', 'digipay', 'tara', 'lendo', 'ghasta', 'oxapay'],

        // محدودیت فراخوانی API (درخواست در دقیقه، به‌ازای هر IP)
        'rate_limit'   => 60,

        // تعداد تلاش ارسال وبهوک + فواصل (دقیقه) بین تلاش‌ها
        'webhook_max_attempts' => 5,
        'webhook_backoff_minutes' => [1, 5, 15, 60, 240],
    ],

    /* --------------------------------------------------------------
     *  پذیرندگان (دامنه‌هایی که اجازه دارند از این گیت‌وی استفاده کنند)
     * --------------------------------------------------------------
     *  برای ساخت کلید جدید این دستور را روی سرور اجرا کنید:
     *        php tools/make-api-key.php "lahze-app"
     *
     *  callback_domain : فقط این دامنه اجازه دارد callback_url بدهد
     *  webhook_url     : آدرس پیش‌فرض وبهوک این پذیرنده
     *  gateways        : null = همه درگاه‌ها؛ یا لیست مجاز مثل ['zarinpal','zibal']
     */
    'merchants' => [
        'lahze-app' => [
            'enabled'         => true,
            'api_key'         => 'wgr_CHANGE_ME',
            'secret'          => 'wgs_CHANGE_ME',
            'callback_domain' => 'https://lahze-app.ir',
            'webhook_url'     => 'https://lahze-app.ir/api/payment/webhook',
            'gateways'        => null,
        ],
    ],

    /* --------------------------------------------------------------
     *  پنل مدیریت  ( https://webiro.ir/gateway/admin )
     * -------------------------------------------------------------- */
    'admin' => [
        'username' => 'admin',
        'password' => 'CHANGE_ME_STRONG_PASSWORD',
        // اگر خالی باشد همه IPها مجازند؛ مثال: ['5.10.10.5']
        'ip_whitelist' => [],
    ],

    /* --------------------------------------------------------------
     *  کلید فراخوانی کرون (ارسال مجدد وبهوک‌های ناموفق)
     *  کرون سی‌پنل — هر ۵ دقیقه:
     *  wget -q -O /dev/null "https://webiro.ir/gateway/api/cron/webhooks?key=CHANGE_ME_CRON"
     * -------------------------------------------------------------- */
    'cron' => [
        'key' => 'CHANGE_ME_CRON',
    ],

    /* --------------------------------------------------------------
     *  اتصال HTTP به بانک‌ها (تست CA روی هاست‌های اشتراکی)
     * -------------------------------------------------------------- */
    'http' => [
        'verify_ssl'  => true,
        'timeout'     => 20,
    ],

    /* ==============================================================
     *  درگاه‌ها — اعتبارنامه‌های هر درگاه خود را وارد کنید
     * ============================================================== */
    'drivers' => [

        /* ---------- زرین‌پال ---------- */
        'zarinpal' => [
            'merchant_id' => 'CHANGE_ME_ZARINPAL_MERCHANT_ID', // UUID پنل زرین‌پال
            'sandbox'     => false,
            'currency'    => 'IRR', // زرین‌پال به ریال کار می‌کند
        ],

        /* ---------- زیبال ---------- */
        'zibal' => [
            // مرچنت کد زیبال؛ برای تست بدون پول روی «zibal» بگذارید (سندباکس)
            'merchant' => 'CHANGE_ME_ZIBAL_MERCHANT',
        ],

        /* ---------- بانک سامان ---------- */
        'saman' => [
            'merchant_id' => 'CHANGE_ME_SAMAN_MID',
            'terminal_id' => 'CHANGE_ME_SAMAN_TID',
            'password'    => 'CHANGE_ME_SAMAN_PASSWORD',

            // WSDL دریافت توکن و صفحه پرداخت
            'wsdl_purchase' => 'https://sep.shaparak.ir/Payments/InitPayment.asmx?WSDL',
            'payment_url'   => 'https://sep.shaparak.ir/payment.aspx',

            // WSDL تایید تراکنش
            'wsdl_verify' => 'https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL',

            // حالت تایید:
            //  classic = verifyTransaction(StringRefNum, TerminalId, MerchantId, Password, Amount)
            //  simple  = verifyTransaction(RefNum, MerchantId)   ← پکیج‌های رایج PHP از این حالت استفاده می‌کنند
            'verify_mode' => 'simple',
        ],

        /* ---------- ⭐ بانک پاسارگاد (نسل جدید — pep.shaparak.ir) ---------- */
        'pasargad' => [
            'base_url'        => 'https://pep.shaparak.ir/dorsa1',
            'username'        => 'CHANGE_ME_PASARGAD_USERNAME',
            'password'        => 'CHANGE_ME_PASARGAD_PASSWORD',
            'terminal_number' => 'CHANGE_ME_PASARGAD_TERMINAL',
        ],

        /* ---------- بلوبانک ----------
         * مستندات IPG بلوبانک برای عموم منتشر نمی‌شود و از پنل پذیرندگی
         * بلوبانک دریافت می‌شود. مقادیر زیر را طبق مستندات خودتان پر کنید.
         * ساختار فرض‌شده: توکن‌محور (Purchase → Redirect → Callback → Verify)
         */
        'bluebank' => [
            'enabled' => false, // تا وقتی مقادیر زیر پر نشده false بماند

            'purchase_url'    => null,          // مثال: https://ipg.bluebank.example/api/purchase
            'purchase_headers'=> [],            // مثال: ['Authorization: Bearer TOKEN']
            'payment_url'     => null,          // مثال: https://ipg.bluebank.example/pay/{token}

            // نام فیلد توکن در پاسخ purchase
            'token_field'     => 'token',

            'callback_token_param'   => 'token',
            'callback_success_param' => 'status',
            'callback_success_values'=> ['OK'],

            'verify_url'      => null,
            'verify_result_field'    => 'result',
            'verify_success_values'  => ['100'],
            'verify_ref_field'       => 'ref_no',
            'verify_card_field'      => 'card_no',
        ],

        /* ---------- آیدی‌پی (IDPay) ---------- */
        'idpay' => [
            'api_key' => 'CHANGE_ME_IDPAY_API_KEY',
            'sandbox' => false, // true = محیط تست آیدی‌پی
        ],

        /* ---------- نکست‌پی (NextPay) ---------- */
        'nextpay' => [
            'api_key' => 'CHANGE_ME_NEXTPAY_API_KEY',
        ],

        /* ---------- پی‌پینگ (PayPing) ---------- */
        'payping' => [
            'api_key' => 'CHANGE_ME_PAYPING_TOKEN', // توکن API از پنل پی‌پینگ
        ],

        /* ---------- سپرده (Sepordeh) ---------- */
        'sepordeh' => [
            'merchant' => 'CHANGE_ME_SEPORDEH_PAYMENT_KEY',
            // پیش‌فرض‌ها معمولاً درست‌اند:
            'purchase_url'        => 'https://sepordeh.com/merchant/invoices/add',
            'payment_url'         => 'https://sepordeh.com/merchant/invoices/pay/id:',
            'payment_url_direct'  => 'https://sepordeh.com/merchant/invoices/pay/automatic:true/id:',
            'verify_url'          => 'https://sepordeh.com/merchant/invoices/verify',
            'direct_payment'      => false,
        ],

        /* ---------- پی.آی‌آر (Pay.ir) ---------- */
        'payir' => [
            'api' => 'CHANGE_ME_PAYIR_API', // برای تست: 'test'
        ],

        /* ---------- آقای پرداخت (Aqayepardakht) ---------- */
        'aqayepardakht' => [
            'pin'     => 'CHANGE_ME_AQAYE_PIN',
            'sandbox' => false,
        ],

        /* ---------- وندار (Vandar) ---------- */
        'vandar' => [
            'api_key' => 'CHANGE_ME_VANDAR_API_KEY',
        ],

        /* ---------- ⭐ اسنپ‌پی (خرید قسطی) ----------
         * مقادیر را از پنل پذیرندگان اسنپ‌پی دریافت کنید.
         * base_url نمونهٔ استیجینگ: https://fms-gateway-staging.apps.public.teh-1.snappcloud.io
         */
        'snappay' => [
            'base_url'      => 'CHANGE_ME_SNAPPPAY_BASE_URL',
            'client_id'     => 'CHANGE_ME_CLIENT_ID',
            'client_secret' => 'CHANGE_ME_CLIENT_SECRET',
            'username'      => 'CHANGE_ME_USERNAME',
            'password'      => 'CHANGE_ME_PASSWORD',
        ],

        /* ---------- خرید قسطی (BNPL) — کانفیگ‌محور ----------
         * مستندات API این سرویس‌ها فقط بعد از عقد قرارداد به پذیرنده داده می‌شود.
         * ساختار فرض‌شده: purchase → redirect → callback → verify
         * هر سرویس را با مقادیر مستندات خودتان پر و enabled کنید:
         */
        'digipay' => [
            'enabled'         => false,
            'purchase_url'    => null,
            'purchase_headers'=> [],
            'payment_url'     => null,           // با {token}
            'token_field'     => 'token',
            'callback_token_param'   => 'token',
            'callback_success_param' => 'status',
            'callback_success_values'=> ['OK'],
            'verify_url'      => null,
            'verify_result_field'    => 'result',
            'verify_success_values'  => ['100'],
            'verify_ref_field'       => 'ref_no',
            'verify_amount_field'    => null,
        ],

        'tara' => [
            'enabled' => false,
            // همان ساختار digipay — مقادیر را از مستندات تارا پر کنید
        ],

        'lendo' => [
            'enabled' => false,
            // همان ساختار digipay — مقادیر را از مستندات لندو پر کنید
        ],

        'ghasta' => [
            'enabled' => false,
            // همان ساختار digipay — مقادیر را از مستندات قسطا پر کنید
        ],

        /* ---------- کریپتو (OxaPay) ----------
         * پرداخت با تتر/بیت‌کوین/… روی شبکه‌های TRON، ERC20، BEP20 و…
         * مستندات: docs.oxapay.com — کلید Merchant از پنل اوکساپی
         */
        'oxapay' => [
            'merchant_key' => 'CHANGE_ME_OXAPAY_MERCHANT_KEY',
            'currency'     => 'IRT',       // IRT=تومان | IRR | USD | USDT | ...
            'amount_unit'  => 'toman',     // واحد currency نسبت به ریال: toman یا rial
            'network'      => null,        // مثال: 'TRON' — null = انتخاب پرداخت‌کننده
            'fee_paid_by_payer' => 1,      // 1 = کارمزد با پرداخت‌کننده
            'sandbox'      => false,
        ],

        /* ---------- درگاه تست (سندباکس داخلی) ----------
         * صفحه شبیه‌ساز بانک برای تست کامل جریان پرداخت بدون پول واقعی.
         * روی پروداکشن می‌توانید enabled=false کنید.
         */
        'test' => [
            'enabled' => true,
        ],
    ],
];
