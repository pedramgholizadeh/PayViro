<?php
/**
 * ------------------------------------------------------------------
 *  PayViro — Web Installer
 * ------------------------------------------------------------------
 *  Author: Pedram Gholizadeh (WEBIRO) — https://github.com/pedramgholizadeh
 *  License: MIT
 *
 *  ۱) این فایل را کنار index.php آپلود کنید و در مرورگر باز کنید:
 *        https://your-domain.ir/gateway/install.php
 *  ۲) پس از نصب موفق، یک فایل قفل در data/ نوشته می‌شود و نصب‌کننده
 *     دیگر اجرا نمی‌شود. برای نصب مجدد، data/installed.lock را حذف کنید.
 *
 *  این فایل هیچ وابستگی خارجی ندارد (فونت/آیکون همگی داخلی).
 */

declare(strict_types=1);

define('PV_ROOT', __DIR__);

session_name('payviro_install');
session_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

/* ------------------------------------------------------------------ */

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function install_lock_path(): string
{
    return PV_ROOT . '/data/installed.lock';
}

function is_installed(): bool
{
    return is_file(install_lock_path()) || is_file(PV_ROOT . '/config/config.php');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    if (!isset($_POST['csrf']) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) $_POST['csrf'])) {
        http_response_code(400);
        exit('توکن CSRF نامعتبر است.');
    }
}

/**
 * @return array<int,array{label:string,ok:bool,required:bool,hint:string}>
 */
function check_requirements(): array
{
    $dirWritable = static fn (string $p): bool => is_dir($p) ? is_writable($p) : is_writable(dirname($p)) && @mkdir($p, 0755, true);
    return [
        ['label' => 'PHP نسخه ۷.۴ یا بالاتر', 'ok' => version_compare(PHP_VERSION, '7.4.0', '>='), 'required' => true, 'hint' => 'نسخه فعلی: ' . PHP_VERSION],
        ['label' => 'اکستنشن curl', 'ok' => extension_loaded('curl'), 'required' => true, 'hint' => 'ارتباط با درگاه‌های بانکی'],
        ['label' => 'اکستنشن PDO', 'ok' => extension_loaded('pdo'), 'required' => true, 'hint' => ''],
        ['label' => 'درایور pdo_sqlite یا pdo_mysql', 'ok' => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'), 'required' => true, 'hint' => 'SQLite پیش‌فرض است و روی اکثر هاست‌ها فعال است'],
        ['label' => 'اکستنشن json', 'ok' => extension_loaded('json'), 'required' => true, 'hint' => ''],
        ['label' => 'اکستنشن mbstring', 'ok' => extension_loaded('mbstring'), 'required' => true, 'hint' => 'پشتیبانی فارسی'],
        ['label' => 'اکستنشن openssl', 'ok' => extension_loaded('openssl'), 'required' => true, 'hint' => 'تولید کلیدها'],
        ['label' => 'اکستنشن soap (فقط درگاه سامان)', 'ok' => extension_loaded('soap'), 'required' => false, 'hint' => 'اگر سامان نمی‌خواهید لازم نیست'],
        ['label' => 'قابل نوشتن: config/', 'ok' => $dirWritable(PV_ROOT . '/config'), 'required' => true, 'hint' => 'برای نوشتن config.php'],
        ['label' => 'قابل نوشتن: data/', 'ok' => $dirWritable(PV_ROOT . '/data'), 'required' => true, 'hint' => 'دیتابیس و فایل قفل'],
        ['label' => 'قابل نوشتن: logs/', 'ok' => $dirWritable(PV_ROOT . '/logs'), 'required' => true, 'hint' => 'لاگ‌ها'],
    ];
}

function guess_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . $dir;
}

function write_config(array $in): string
{
    $cronKey = 'cron_' . bin2hex(random_bytes(12));
    $tz = $in['timezone'] ?: 'Asia/Tehran';

    if ($in['db_driver'] === 'mysql') {
        $dbBlock = <<<MYSQL
    'db' => [
        'driver'      => 'mysql',
        'sqlite_path' => __DIR__ . '/../data/payviro.sqlite',
        'mysql' => [
            'host'    => '{$in['db_host']}',
            'port'    => {$in['db_port']},
            'dbname'  => '{$in['db_name']}',
            'user'    => '{$in['db_user']}',
            'pass'    => '{$in['db_pass']}',
            'charset' => 'utf8mb4',
        ],
    ],
MYSQL;
    } else {
        $dbBlock = <<<SQLITE
    'db' => [
        'driver'      => 'sqlite',
        'sqlite_path' => __DIR__ . '/../data/payviro.sqlite',
        'mysql' => [
            'host'    => 'localhost',
            'port'    => 3306,
            'dbname'  => 'payviro',
            'user'    => '',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ],
    ],
SQLITE;
    }

    $config = <<<PHP
<?php
/**
 * PayViro — فایل پیکربندی (تولیدشده توسط نصب‌کننده)
 * Author: Pedram Gholizadeh (WEBIRO) — https://github.com/pedramgholizadeh
 *
 * اعتبارنامه‌ی درگاه‌های بانکی را بعداً در بخش drivers وارد کنید؛
 * هر درگاهی که تنظیم نشده باشد خودکار غیرفعال است.
 * مستندات: docs/ یا apps.webiro.ir/payviro/docs
 */

declare(strict_types=1);

return [

    'app' => [
        // آدرس کامل محل نصب PayViro (بدون اسلش انتهایی)
        'base_url'   => '{$in['base_url']}',
        'timezone'   => '{$tz}',
        'https_only' => {$in['https_only']},
        'debug'      => false,
        'cors_origins' => [],
    ],

{$dbBlock}

    'log' => [
        'level'     => 'info',
        'dir'       => __DIR__ . '/../logs',
        'max_files' => 30,
    ],

    'payments' => [
        'ttl_minutes'  => 20,
        'min_amount'   => 10000,
        'max_amount'   => 500000000,
        // ⭐ بانک سامان و پاسارگاد در اولویت انتخاب خودکار هستند
        'default_gateway' => 'saman',
        'auto_order'   => ['saman', 'pasargad', 'zarinpal', 'zibal', 'idpay', 'nextpay', 'payping', 'sepordeh', 'payir', 'aqayepardakht', 'vandar', 'bluebank', 'snappay', 'digipay', 'tara', 'lendo', 'ghasta', 'oxapay'],
        'rate_limit'   => 60,
        'webhook_max_attempts' => 5,
        'webhook_backoff_minutes' => [1, 5, 15, 60, 240],
    ],

    'merchants' => [
        'demo' => [
            'enabled'         => true,
            'api_key'         => '{$in['demo_key']}',
            'secret'          => '{$in['demo_secret']}',
            'callback_domain' => '', // مثال: https://your-shop.ir — خالی = هر دامنه‌ای (برای شروع)
            'webhook_url'     => '',
            'gateways'        => null,
        ],
    ],

    'admin' => [
        'username' => '{$in['admin_user']}',
        'password' => '{$in['admin_pass']}',
        'ip_whitelist' => [],
    ],

    'cron' => [
        'key' => '{$cronKey}',
    ],

    'http' => [
        'verify_ssl'  => true,
        'timeout'     => 20,
    ],

    'drivers' => [
        'zarinpal' => [
            'merchant_id' => 'CHANGE_ME',
            'sandbox'     => false,
            'currency'    => 'IRR',
        ],
        'zibal' => [
            'merchant' => 'CHANGE_ME',
        ],
        'saman' => [
            'merchant_id'   => 'CHANGE_ME',
            'terminal_id'   => 'CHANGE_ME',
            'password'      => 'CHANGE_ME',
            'wsdl_purchase' => 'https://sep.shaparak.ir/Payments/InitPayment.asmx?WSDL',
            'payment_url'   => 'https://sep.shaparak.ir/payment.aspx',
            'wsdl_verify'   => 'https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL',
            'verify_mode'   => 'simple',
        ],
        'pasargad' => [
            'base_url'        => 'https://pep.shaparak.ir/dorsa1',
            'username'        => 'CHANGE_ME',
            'password'        => 'CHANGE_ME',
            'terminal_number' => 'CHANGE_ME',
        ],
        'idpay' => [
            'api_key' => 'CHANGE_ME',
            'sandbox' => false,
        ],
        'nextpay' => [
            'api_key'     => 'CHANGE_ME',
            'currency'    => 'IRR',
            'amount_unit' => 'rial',
        ],
        'payping' => [
            'api_key' => 'CHANGE_ME',
        ],
        'sepordeh' => [
            'merchant' => 'CHANGE_ME',
        ],
        'payir' => [
            'api' => 'CHANGE_ME',
        ],
        'aqayepardakht' => [
            'pin'     => 'CHANGE_ME',
            'sandbox' => false,
        ],
        'vandar' => [
            'api_key' => 'CHANGE_ME',
        ],
        'bluebank' => [
            'enabled' => false,
        ],
        'snappay' => [
            'base_url'      => '',
            'client_id'     => '',
            'client_secret' => '',
            'username'      => '',
            'password'      => '',
        ],
        'digipay' => ['enabled' => false],
        'tara'    => ['enabled' => false],
        'lendo'   => ['enabled' => false],
        'ghasta'  => ['enabled' => false],
        'oxapay' => [
            'merchant_key'      => 'CHANGE_ME',
            'currency'          => 'IRT',
            'amount_unit'       => 'toman',
            'network'           => null,
            'fee_paid_by_payer' => 1,
            'sandbox'           => false,
        ],
        'test' => [
            'enabled' => true, // برای تست اولیه فعال است — روی پروداکشن false کنید
        ],
    ],
];
PHP;

    $target = PV_ROOT . '/config/config.php';
    if (@file_put_contents($target, $config, LOCK_EX) === false) {
        throw new RuntimeException('نوشتن config/config.php ناموفق بود — دسترسی پوشه را بررسی کنید.');
    }
    return $cronKey;
}

/* ------------------------------------------------------------------ */
/*  Locked?                                                            */
/* ------------------------------------------------------------------ */

if (is_installed()) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>PayViro — قبلاً نصب شده</title><style>body{font-family:Vazirmatn,Tahoma,sans-serif;background:#040806;color:#e7fbf2;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}c{display:block}.box{background:#0a1611;border:1px solid #1c3630;border-radius:16px;padding:40px;max-width:460px;text-align:center}h1{font-size:18px;color:#00c389}p{color:#8fb8a8;font-size:13.5px;line-height:2}</style></head><body><div class="box"><h1>✓ PayViro قبلاً نصب شده است</h1><p>برای امنیت، نصب‌کننده قفل شده است.<br>اگر می‌خواهید دوباره نصب کنید، فایل <b style="direction:ltr">data/installed.lock</b> و در صورت نیاز <b style="direction:ltr">config/config.php</b> را حذف کنید.</p></div></body></html>';
    exit;
}

/* ------------------------------------------------------------------ */
/*  Handle POST (final install)                                        */
/* ------------------------------------------------------------------ */

$done = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['step'] ?? '') === 'install') {
    csrf_check();
    try {
        $in = [
            'base_url'   => rtrim(trim((string) ($_POST['base_url'] ?? '')), '/'),
            'timezone'   => trim((string) ($_POST['timezone'] ?? 'Asia/Tehran')),
            'https_only' => (strpos($_POST['base_url'] ?? '', 'https://') === 0) ? 'true' : 'false',
            'db_driver'  => ($_POST['db_driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite',
            'db_host'    => trim((string) ($_POST['db_host'] ?? 'localhost')),
            'db_port'    => (int) ($_POST['db_port'] ?? 3306),
            'db_name'    => trim((string) ($_POST['db_name'] ?? 'payviro')),
            'db_user'    => trim((string) ($_POST['db_user'] ?? '')),
            'db_pass'    => (string) ($_POST['db_pass'] ?? ''),
            'admin_user' => trim((string) ($_POST['admin_user'] ?? '')),
            'admin_pass' => (string) ($_POST['admin_pass'] ?? ''),
            'demo_key'   => 'wgr_' . bin2hex(random_bytes(20)),
            'demo_secret'=> 'wgs_' . bin2hex(random_bytes(24)),
        ];

        // values are embedded into a PHP source file — harden them
        $clean = static function (string $v): string {
            return addslashes(str_replace(["\r", "\n", "\0"], '', trim($v)));
        };
        foreach (['base_url', 'db_host', 'db_name', 'db_user', 'db_pass', 'admin_user', 'admin_pass'] as $k) {
            $in[$k] = $clean($in[$k]);
        }
        $in['timezone'] = in_array($in['timezone'], ['Asia/Tehran', 'Asia/Dubai', 'Europe/Berlin', 'UTC'], true)
            ? $in['timezone'] : 'Asia/Tehran';

        if ($in['base_url'] === '' || filter_var($in['base_url'], FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('آدرس پایه نامعتبر است.');
        }
        if ($in['admin_user'] === '' || strlen($in['admin_pass']) < 8) {
            throw new InvalidArgumentException('رمز پنل مدیریت باید حداقل ۸ کاراکتر باشد.');
        }
        if (is_file(PV_ROOT . '/config/config.php')) {
            throw new RuntimeException('config/config.php از قبل موجود است — برای نصب مجدد آن را حذف کنید.');
        }

        // MySQL early connectivity check
        if ($in['db_driver'] === 'mysql') {
            try {
                $pdo = new PDO(
                    "mysql:host={$in['db_host']};port={$in['db_port']};dbname={$in['db_name']};charset=utf8mb4",
                    $in['db_user'],
                    $in['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                unset($pdo);
            } catch (PDOException $e) {
                throw new RuntimeException('اتصال به MySQL ناموفق بود: ' . $e->getMessage());
            }
        }

        $cronKey = write_config($in);

        // ---- create schema through the app itself ----
        require PV_ROOT . '/src/bootstrap.php';
        PayViro\Core\Database::pdo(); // triggers schema creation

        // ---- lock ----
        @mkdir(PV_ROOT . '/data', 0755, true);
        file_put_contents(install_lock_path(), json_encode([
            'installed_at' => date('c'),
            'version' => '1.0',
            'by' => 'Pedram Gholizadeh (WEBIRO) — PayViro installer',
        ], JSON_PRETTY_PRINT));

        $done = [
            'admin_url'   => $in['base_url'] . '/admin',
            'cron_url'    => $in['base_url'] . '/api/cron/webhooks?key=' . $cronKey,
            'demo_key'    => $in['demo_key'],
            'demo_secret' => $in['demo_secret'],
            'base_url'    => $in['base_url'],
        ];
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$reqs = check_requirements();
$requiredFailed = count(array_filter($reqs, static fn ($r) => $r['required'] && !$r['ok']));
$csrf = e(csrf_token());
$baseUrl = e(guess_base_url());
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>نصب PayViro</title>
<style>
@font-face{font-family:Vazirmatn;src:url(assets/fonts/Vazirmatn-Regular.woff2) format('woff2');font-weight:400;font-display:swap}
@font-face{font-family:Vazirmatn;src:url(assets/fonts/Vazirmatn-Bold.woff2) format('woff2');font-weight:700;font-display:swap}
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#040806;--surface:#0a1611;--surface2:#0f221b;--border:#1c3630;--fg:#ecfdf5;--muted:#8fb8a8;--faint:#5f8272;--jade:#00c389;--teal:#2dd4bf;--soft:rgba(0,195,137,.1);--red:#f87171}
body{font-family:Vazirmatn,Tahoma,sans-serif;background:var(--bg);color:var(--fg);font-size:14.5px;line-height:1.9;min-height:100vh}
.shell{max-width:760px;margin:0 auto;padding:36px 20px 60px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}
.brand .mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#065f46,#022c22);border:1px solid rgba(0,195,137,.4);display:flex;align-items:center;justify-content:center}
.brand .mark svg{width:22px;height:22px;stroke:var(--jade)}
.brand b{font-size:17px;font-weight:800}
.brand span{display:block;font-size:11px;color:var(--faint)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:26px;margin-bottom:18px}
h2{font-size:17px;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:10px}
h2 .stepn{width:26px;height:26px;border-radius:50%;background:var(--soft);border:1px solid rgba(0,195,137,.4);color:var(--jade);font-size:12.5px;display:inline-flex;align-items:center;justify-content:center}
.req{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px dashed var(--border);font-size:13.5px}
.req:last-child{border-bottom:0}
.req .st{font-size:12px;font-weight:700;padding:2px 12px;border-radius:20px}
.ok{color:#2ee6b0;background:rgba(52,224,172,.1);border:1px solid rgba(52,224,172,.3)}
.bad{color:var(--red);background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3)}
.opt{color:var(--faint);background:var(--surface2);border:1px solid var(--border)}
.hint{font-size:11px;color:var(--faint);display:block}
label{display:block;font-size:12.5px;color:var(--muted);margin:15px 0 6px}
input,select{width:100%;background:#020605;border:1px solid var(--border);border-radius:10px;padding:10px 14px;color:var(--fg);font-family:inherit;font-size:14px;outline:none;transition:border-color .2s}
input:focus,select:focus{border-color:var(--jade)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
.note{border:1px solid var(--border);border-radius:12px;background:var(--surface2);padding:12px 16px;font-size:12.5px;color:var(--muted);margin-top:14px;line-height:2}
button{width:100%;margin-top:24px;background:linear-gradient(120deg,#00c389,#2dd4bf);color:#022c22;font-family:inherit;font-weight:800;font-size:15px;border:0;border-radius:12px;padding:14px;cursor:pointer;transition:filter .2s,transform .15s}
button:hover{filter:brightness(1.08);transform:translateY(-1px)}
button:disabled{filter:grayscale(1) brightness(.6);cursor:not-allowed;transform:none}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.35);color:#fca5a5;border-radius:12px;padding:12px 16px;font-size:13px;margin-bottom:16px}
.ok-box{background:rgba(52,224,172,.06);border:1px solid rgba(52,224,172,.3);border-radius:16px;padding:26px}
.ok-box h2{color:#2ee6b0}
.kv{display:grid;grid-template-columns:170px 1fr;gap:7px 12px;font-size:13px;margin:16px 0}
.kv .k{color:var(--faint)}
.kv .v{direction:ltr;text-align:left;font-family:ui-monospace,monospace;font-size:12px;word-break:break-all;color:var(--fg)}
code{background:#020605;border:1px solid var(--border);border-radius:6px;padding:2px 8px;font-size:12px;direction:ltr;display:inline-block}
@media(max-width:600px){.grid{grid-template-columns:1fr}.kv{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">
  <div class="brand">
    <div class="mark"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/><path d="M6 15h4"/></svg></div>
    <div><b>PayViro — نصب‌کننده</b><span>سوئیچ پرداخت مرکزی اوپن‌سورس · نسخه ۱.۰</span></div>
  </div>

<?php if ($done !== null): ?>

  <div class="ok-box">
    <h2>✓ نصب با موفقیت انجام شد</h2>
    <p style="color:var(--muted);font-size:13.5px;margin-top:8px">دیتابیس ساخته شد، پیکربندی نوشته شد و پنل مدیریت آماده است.</p>
    <div class="kv">
      <div class="k">پنل مدیریت</div><div class="v"><a href="<?= e($done['admin_url']) ?>" style="color:#2ee6b0"><?= e($done['admin_url']) ?></a></div>
      <div class="k">کلید API دمو</div><div class="v"><?= e($done['demo_key']) ?></div>
      <div class="k">Secret دمو</div><div class="v"><?= e($done['demo_secret']) ?></div>
    </div>
    <div class="note">
      <b style="color:var(--fg)">قدم‌های بعدی:</b><br>
      ۱) وارد پنل مدیریت شوید (<?= e($done['admin_url']) ?>)<br>
      ۲) کلیدهای پذیرنده‌ی جدید بسازید: <code>php tools/make-api-key.php "shop" "https://your-shop.ir"</code> — کلید دمو بالا هم همین الان کار می‌کند<br>
      ۳) اعتبارنامه‌ی درگاه‌های بانکی را در <code>config/config.php</code> بخش <code>drivers</code> وارد کنید<br>
      ۴) کرون هر ۵ دقیقه: <code style="word-break:break-all">wget -q -O /dev/null "<?= e($done['cron_url']) ?>"</code><br>
      ۵) بعد از تست، درگاه <code>test</code> را در کانفیگ غیرفعال کنید
    </div>
    <div class="note" style="border-color:rgba(248,113,113,.35);background:rgba(248,113,113,.05)">
      🔒 <b style="color:var(--fg)">امنیت:</b> نصب‌کننده با فایل <code>data/installed.lock</code> قفل شد و دیگر اجرا نمی‌شود.
      مستندات کامل: <code>docs/</code> در همین نصب یا <a href="https://apps.webiro.ir/payviro/docs" target="_blank" rel="noopener" style="color:#2ee6b0">apps.webiro.ir/payviro/docs</a>
    </div>
  </div>

<?php else: ?>

  <?php if ($error !== null): ?><div class="err">⚠ <?= e($error) ?></div><?php endif; ?>

  <div class="card">
    <h2><span class="stepn">۱</span> بررسی پیش‌نیازها</h2>
    <?php foreach ($reqs as $r): ?>
    <div class="req">
      <div><?= e($r['label']) ?><?= $r['hint'] !== '' ? '<span class="hint">' . e($r['hint']) . '</span>' : '' ?></div>
      <span class="st <?= $r['ok'] ? 'ok' : ($r['required'] ? 'bad' : 'opt') ?>"><?= $r['ok'] ? '✓ موجود' : ($r['required'] ? '✗ نصب نشده' : '— اختیاری') ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="step" value="install">

    <div class="card">
      <h2><span class="stepn">۲</span> تنظیمات کلی</h2>
      <label for="base_url">آدرس پایه‌ی نصب (بدون اسلش انتهایی)</label>
      <input id="base_url" name="base_url" value="<?= $baseUrl ?>" dir="ltr" required>
      <label for="timezone">منطقه‌ی زمانی</label>
      <select id="timezone" name="timezone">
        <option value="Asia/Tehran" selected>Asia/Tehran (تهران)</option>
        <option value="Asia/Dubai">Asia/Dubai</option>
        <option value="Europe/Berlin">Europe/Berlin</option>
        <option value="UTC">UTC</option>
      </select>
    </div>

    <div class="card">
      <h2><span class="stepn">۳</span> دیتابیس</h2>
      <label style="display:flex;align-items:center;gap:10px;margin-top:4px">
        <input type="radio" name="db_driver" value="sqlite" checked style="width:auto" onchange="document.getElementById('mysqlbox').style.display='none'">
        <span style="color:var(--fg);font-size:13.5px">SQLite — پیشنهادی، بدون تنظیم</span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;margin-top:10px">
        <input type="radio" name="db_driver" value="mysql" style="width:auto" onchange="document.getElementById('mysqlbox').style.display='block'">
        <span style="color:var(--fg);font-size:13.5px">MySQL</span>
      </label>
      <div id="mysqlbox" style="display:none">
        <div class="grid">
          <div><label for="db_host">هاست</label><input id="db_host" name="db_host" value="localhost" dir="ltr"></div>
          <div><label for="db_port">پورت</label><input id="db_port" name="db_port" value="3306" dir="ltr"></div>
          <div><label for="db_name">نام دیتابیس</label><input id="db_name" name="db_name" value="payviro" dir="ltr"></div>
          <div><label for="db_user">کاربر</label><input id="db_user" name="db_user" dir="ltr"></div>
        </div>
        <label for="db_pass">رمز</label><input id="db_pass" type="password" name="db_pass" dir="ltr">
      </div>
    </div>

    <div class="card">
      <h2><span class="stepn">۴</span> پنل مدیریت</h2>
      <label for="admin_user">نام کاربری مدیر</label>
      <input id="admin_user" name="admin_user" value="admin" dir="ltr" required>
      <label for="admin_pass">رمز عبور (حداقل ۸ کاراکتر)</label>
      <input id="admin_pass" type="password" name="admin_pass" dir="ltr" required minlength="8">
      <div class="note">کلید API یک پذیرنده‌ی دمو هم به‌صورت خودکار ساخته می‌شود تا بلافاصله تست کنید. کلیدهای پذیرنده‌های بعدی را با <code>php tools/make-api-key.php</code> بسازید.</div>
    </div>

    <button type="submit" <?= $requiredFailed > 0 ? 'disabled' : '' ?>>
      <?= $requiredFailed > 0 ? 'ابتدا پیش‌نیازهای اجباری را رفع کنید' : 'نصب PayViro' ?>
    </button>
  </form>

<?php endif; ?>
</div>
</body>
</html>
