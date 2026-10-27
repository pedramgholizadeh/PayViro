<?php
/**
 * ------------------------------------------------------------------
 *  Webiro Gateway — پنل مدیریت
 *  آدرس: https://webiro.ir/gateway/admin
 * ------------------------------------------------------------------
 *  احراز هویت: username/password از config/config.php
 *  امکانات: داشبورد آماری، لیست تراکنش‌ها با فیلتر، جزئیات تراکنش،
 *  ارسال مجدد وبهوک، خروجی CSV.
 */

declare(strict_types=1);

define('WG_ROOT', dirname(__DIR__));

require WG_ROOT . '/src/bootstrap.php';

use Webiro\Core\Config;
use Webiro\Core\Database;
use Webiro\Core\Http\Response;
use Webiro\Models\Transaction;
use Webiro\Services\PaymentService;
use Webiro\Services\WebhookService;

session_name('wg_admin');
session_start();

/* --------------------------------------------------------------- */

Config::load();

$whiteList = (array) Config::get('admin.ip_whitelist', []);
if ($whiteList !== [] && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $whiteList, true)) {
    http_response_code(403);
    exit('دسترسی از این IP مجاز نیست.');
}

function admin_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function admin_check_csrf(): void
{
    if (!isset($_POST['csrf']) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) $_POST['csrf'])) {
        http_response_code(400);
        exit('توکن CSRF نامعتبر است.');
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function status_badge(string $status): string
{
    $map = [
        'paid'     => ['پرداخت شده', 's-paid'],
        'pending'  => ['در انتظار', 's-pending'],
        'failed'   => ['ناموفق', 's-failed'],
        'canceled' => ['لغو شده', 's-canceled'],
        'expired'  => ['منقضی', 's-expired'],
    ];
    [$label, $class] = $map[$status] ?? [$status, 's-failed'];
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}

/* ---------------- actions ---------------- */

$message = '';
$error   = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Resend webhook
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'resend_webhook') {
    if (empty($_SESSION['user'])) {
        header('Location: index.php');
        exit;
    }
    admin_check_csrf();
    $token = (string) ($_POST['token'] ?? '');
    $txn = Transaction::findByToken($token);
    if ($txn !== null && in_array($txn['status'], ['paid', 'failed', 'canceled'], true)) {
        Transaction::update($token, ['webhook_attempts' => 0, 'webhook_next_try' => null]);
        $event = $txn['status'] === 'paid' ? 'payment.succeeded' : ($txn['status'] === 'canceled' ? 'payment.canceled' : 'payment.failed');
        $result = WebhookService::notify(Transaction::findByToken($token) ?? $txn, $event);
        $message = $result['ok'] ? 'وبهوک با موفقیت ارسال شد. ' . e($result['detail']) : 'ارسال ناموفق: ' . e($result['detail']);
    } else {
        $error = 'تراکنش یا وضعیت آن برای ارسال وبهوک مناسب نیست.';
    }
}

/* ---------------- auth ---------------- */

$logged = !empty($_SESSION['user']);

if (!$logged && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'login') {
    admin_check_csrf();
    $user = (string) Config::get('admin.username', 'admin');
    $pass = (string) Config::get('admin.password', '');
    if (hash_equals($user, (string) ($_POST['username'] ?? '')) && $pass !== '' && strpos($pass, 'CHANGE_ME') !== 0 && hash_equals($pass, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $logged = true;
    } else {
        $error = 'نام کاربری یا رمز عبور اشتباه است.';
        usleep(400000);
    }
}

if (!$logged) {
    $csrf = e(admin_csrf());
    $errHtml = $error !== '' ? '<div class="alert alert-error">' . e($error) . '</div>' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>ورود به پنل مدیریت | PayViro</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Vazirmatn,Tahoma,sans-serif;background:#020617;color:#F8FAFC;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
.login{width:100%;max-width:380px;background:#0E1223;border:1px solid #1E293B;border-radius:20px;padding:40px 32px}
.logo{text-align:center;margin-bottom:28px}
.logo .mark{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#166534,#052e16);border:1px solid #22c55e44;margin:0 auto 14px;display:flex;align-items:center;justify-content:center}
.logo h1{font-size:17px;font-weight:700}
.logo p{font-size:12px;color:#64748B;margin-top:4px}
label{display:block;font-size:12px;color:#94A3B8;margin:14px 0 6px}
input{width:100%;background:#020617;border:1px solid #1E293B;border-radius:10px;padding:11px 14px;color:#F8FAFC;font-family:inherit;font-size:14px;outline:none;transition:border-color .2s}
input:focus{border-color:#22C55E}
button{width:100%;margin-top:22px;background:#22C55E;color:#052e16;font-family:inherit;font-weight:700;font-size:14px;border:0;border-radius:10px;padding:12px;cursor:pointer;transition:filter .2s}
button:hover{filter:brightness(1.1)}
.alert{border-radius:10px;padding:10px 14px;font-size:12.5px;margin-top:16px}
.alert-error{background:#450a0a;color:#fca5a5;border:1px solid #7f1d1d}
</style>
</head>
<body>
<form class="login" method="post">
  <input type="hidden" name="csrf" value="{$csrf}">
  <div class="logo">
    <div class="mark"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg></div>
    <h1>پنل مدیریت PayViro</h1>
    <p>درگاه پرداخت مرکزی — نسخه ۱.۰</p>
  </div>
  {$errHtml}
  <label for="u">نام کاربری</label>
  <input id="u" name="username" autocomplete="username" required>
  <label for="p">رمز عبور</label>
  <input id="p" type="password" name="password" autocomplete="current-password" required>
  <button type="submit">ورود</button>
</form>
</body>
</html>
HTML;
    exit;
}

/* ---------------- dashboard data ---------------- */

try {
    Database::pdo();
} catch (\Throwable $ex) {
    http_response_code(500);
    exit('اتصال به دیتابیس برقرار نشد: ' . e($ex->getMessage()));
}

$filters = [
    'status'  => (string) ($_GET['status'] ?? ''),
    'gateway' => (string) ($_GET['gateway'] ?? ''),
    'search'  => trim((string) ($_GET['search'] ?? '')),
];
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$total   = Transaction::countAll($filters);
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min($page, $pages);
$rows    = Transaction::list($filters, $perPage, ($page - 1) * $perPage);
$stats   = Transaction::stats();

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transactions-' . date('Ymd-His') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['token', 'merchant', 'gateway', 'status', 'amount_rial', 'order_id', 'ref_no', 'card_no', 'mobile', 'created_at', 'paid_at']);
    foreach (Transaction::list($filters, 500, 0) as $r) {
        fputcsv($out, [$r['token'], $r['merchant_id'], $r['gateway'], $r['status'], $r['amount_rial'], $r['order_id'], $r['ref_no'], $r['card_no'], $r['mobile'], $r['created_at'], $r['paid_at']]);
    }
    fclose($out);
    exit;
}

$detailToken = trim((string) ($_GET['detail'] ?? ''));
$detail = $detailToken !== '' ? Transaction::findByToken($detailToken) : null;

function fmt_rial($n): string
{
    return number_format((int) $n);
}

$csrf = e(admin_csrf());
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>پنل مدیریت | PayViro</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Vazirmatn,Tahoma,sans-serif;background:#020617;color:#F8FAFC;font-size:14px}
a{color:#4ADE80;text-decoration:none}
.topbar{background:#0E1223;border-bottom:1px solid #1E293B;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
.topbar h1{font-size:15px;font-weight:700;display:flex;align-items:center;gap:10px}
.topbar h1 .dot{width:9px;height:9px;border-radius:50%;background:#22C55E;box-shadow:0 0 12px #22C55E88}
.topbar a{font-size:12.5px;color:#94A3B8}
.container{max-width:1100px;margin:0 auto;padding:24px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px}
.stat{background:#0E1223;border:1px solid #1E293B;border-radius:14px;padding:18px}
.stat .label{font-size:12px;color:#94A3B8;margin-bottom:8px}
.stat .value{font-size:22px;font-weight:800;color:#F8FAFC}
.stat .value small{font-size:11px;color:#64748B;font-weight:400}
.stat.green .value{color:#22C55E}
.card{background:#0E1223;border:1px solid #1E293B;border-radius:14px;padding:20px;margin-bottom:20px}
.card h2{font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.filters label{display:block;font-size:11px;color:#94A3B8;margin-bottom:4px}
.filters select,.filters input{background:#020617;border:1px solid #1E293B;border-radius:8px;color:#F8FAFC;padding:8px 10px;font-family:inherit;font-size:13px;min-width:130px}
.filters button,.btn{background:#166534;border:0;color:#DCFCE7;border-radius:8px;padding:8px 16px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:filter .2s}
.filters button:hover,.btn:hover{filter:brightness(1.15)}
table{width:100%;border-collapse:collapse}
th{font-size:11.5px;color:#64748B;text-align:right;padding:10px 8px;border-bottom:1px solid #1E293B;font-weight:600}
td{padding:11px 8px;border-bottom:1px solid #141a2e;font-size:12.5px}
tr:hover td{background:#0a0e1d}
.ltr{direction:ltr;text-align:left;font-family:ui-monospace,monospace;font-size:11.5px}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.s-paid{background:#052e16;color:#4ADE80;border:1px solid #166534}
.s-pending{background:#422006;color:#FCD34D;border:1px solid #92400e}
.s-failed{background:#450a0a;color:#FCA5A5;border:1px solid #7f1d1d}
.s-canceled{background:#1e1b4b;color:#A5B4FC;border:1px solid #3730a3}
.s-expired{background:#1c1917;color:#D6D3D1;border:1px solid #44403c}
.alert{border-radius:10px;padding:11px 16px;font-size:13px;margin-bottom:16px}
.alert-ok{background:#052e16;color:#86EFAC;border:1px solid #166534}
.alert-error{background:#450a0a;color:#FCA5A5;border:1px solid #7f1d1d}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:16px}
.pagination a{padding:6px 12px;border:1px solid #1E293B;border-radius:8px;font-size:12px;color:#94A3B8}
.pagination a.active{background:#166534;color:#fff;border-color:#166534}
.kv{display:grid;grid-template-columns:170px 1fr;gap:8px 14px;font-size:13px}
.kv .k{color:#94A3B8}
.kv .v{word-break:break-all}
pre.raw{background:#020617;border:1px solid #1E293B;border-radius:10px;padding:14px;font-size:11.5px;overflow-x:auto;direction:ltr;text-align:left;line-height:1.7}
@media (max-width:640px){.kv{grid-template-columns:1fr}.container{padding:14px}table{font-size:11px}}
</style>
</head>
<body>
<div class="topbar">
  <h1><span class="dot"></span> PayViro — درگاه پرداخت مرکزی</h1>
  <div><a href="?logout=1">خروج</a></div>
</div>
<div class="container">
  <?php if ($message !== ''): ?><div class="alert alert-ok"><?= $message ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat green"><div class="label">پرداختی امروز</div><div class="value"><?= fmt_rial($stats['today']['amount']) ?> <small>ریال</small></div></div>
    <div class="stat green"><div class="label">تعداد امروز</div><div class="value"><?= fmt_rial($stats['today']['count']) ?></div></div>
    <div class="stat"><div class="label">پرداختی این ماه</div><div class="value"><?= fmt_rial($stats['month']['amount']) ?> <small>ریال</small></div></div>
    <div class="stat"><div class="label">تراکنش‌های در انتظار</div><div class="value"><?= fmt_rial($stats['pending']) ?></div></div>
  </div>

  <?php if ($stats['by_gateway'] !== []): ?>
  <div class="card">
    <h2>سهم درگاه‌ها (این ماه)</h2>
    <table>
      <tr><th>درگاه</th><th>تعداد</th><th>مبلغ (ریال)</th></tr>
      <?php foreach ($stats['by_gateway'] as $g): ?>
      <tr><td><?= e($g['gateway']) ?></td><td><?= fmt_rial($g['count']) ?></td><td><?= fmt_rial($g['amount']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($detail !== null): ?>
  <div class="card">
    <h2>جزئیات تراکنش <span class="ltr"><?= e($detail['token']) ?></span></h2>
    <div class="kv">
      <div class="k">وضعیت</div><div class="v"><?= status_badge((string) $detail['status']) ?></div>
      <div class="k">پذیرنده</div><div class="v"><?= e($detail['merchant_id']) ?></div>
      <div class="k">درگاه</div><div class="v"><?= e($detail['gateway']) ?></div>
      <div class="k">مبلغ</div><div class="v"><?= fmt_rial($detail['amount_rial']) ?> ریال</div>
      <div class="k">شماره سفارش</div><div class="v ltr"><?= e($detail['order_id']) ?></div>
      <div class="k">شناسه مرجع</div><div class="v ltr"><?= e($detail['ref_no']) ?></div>
      <div class="k">RRN</div><div class="v ltr"><?= e($detail['rrn']) ?></div>
      <div class="k">کارت</div><div class="v ltr"><?= e($detail['card_no']) ?></div>
      <div class="k">موبایل</div><div class="v ltr"><?= e($detail['mobile']) ?></div>
      <div class="k">شرح</div><div class="v"><?= e($detail['description']) ?></div>
      <div class="k">Callback</div><div class="v ltr"><?= e($detail['callback_url']) ?></div>
      <div class="k">Webhook URL</div><div class="v ltr"><?= e($detail['webhook_url']) ?></div>
      <div class="k">وبهوک</div><div class="v">تلاش <?= (int) $detail['webhook_attempts'] ?> — <?= e($detail['webhook_last_status']) ?></div>
      <div class="k">ایجاد</div><div class="v ltr"><?= e($detail['created_at']) ?></div>
      <div class="k">پرداخت</div><div class="v ltr"><?= e($detail['paid_at']) ?></div>
      <div class="k">انقضا</div><div class="v ltr"><?= e($detail['expires_at']) ?></div>
      <div class="k">دلیل خطا</div><div class="v"><?= e($detail['fail_reason']) ?></div>
    </div>
    <?php if (in_array($detail['status'], ['paid', 'failed', 'canceled'], true) && !empty($detail['webhook_url'])): ?>
    <form method="post" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="resend_webhook">
      <input type="hidden" name="token" value="<?= e($detail['token']) ?>">
      <button class="btn" type="submit">ارسال مجدد وبهوک</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($detail['verify_raw'])): ?>
      <h2 style="margin-top:20px">پاسخ خام تایید بانک</h2>
      <pre class="raw"><?php
        $decoded = json_decode((string) $detail['verify_raw'], true);
        echo e(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      ?></pre>
    <?php endif; ?>
    <p style="margin-top:16px"><a href="index.php">&larr; بازگشت به لیست</a></p>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>تراکنش‌ها <span style="font-weight:400;font-size:12px;color:#64748B">(<?= fmt_rial($total) ?> مورد)</span></h2>
    <form method="get" class="filters" style="margin-bottom:16px">
      <div><label>وضعیت</label>
        <select name="status">
          <option value="">همه</option>
          <?php foreach (Transaction::STATUSES as $s): ?>
          <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>درگاه</label>
        <select name="gateway">
          <option value="">همه</option>
          <?php foreach (['zarinpal', 'zibal', 'saman', 'bluebank', 'test'] as $g): ?>
          <option value="<?= $g ?>" <?= $filters['gateway'] === $g ? 'selected' : '' ?>><?= $g ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>جست‌وجو</label>
        <input name="search" value="<?= e($filters['search']) ?>" placeholder="token / order_id / موبایل">
      </div>
      <button type="submit">اعمال</button>
      <a class="btn" href="?export=csv&status=<?= e($filters['status']) ?>&gateway=<?= e($filters['gateway']) ?>&search=<?= urlencode($filters['search']) ?>" style="text-decoration:none">خروجی CSV</a>
    </form>
    <div style="overflow-x:auto">
    <table>
      <tr><th>زمان</th><th>توکن</th><th>پذیرنده</th><th>درگاه</th><th>مبلغ (ریال)</th><th>سفارش</th><th>وضعیت</th><th></th></tr>
      <?php if ($rows === []): ?>
      <tr><td colspan="8" style="text-align:center;color:#64748B;padding:24px">تراکنشی یافت نشد.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="ltr"><?= e($r['created_at']) ?></td>
        <td class="ltr"><a href="?detail=<?= e($r['token']) ?>&status=<?= e($filters['status']) ?>&gateway=<?= e($filters['gateway']) ?>&search=<?= urlencode($filters['search']) ?>"><?= e(substr((string) $r['token'], 0, 18)) ?>…</a></td>
        <td><?= e($r['merchant_id']) ?></td>
        <td><?= e($r['gateway']) ?></td>
        <td><?= fmt_rial($r['amount_rial']) ?></td>
        <td class="ltr"><?= e($r['order_id']) ?></td>
        <td><?= status_badge((string) $r['status']) ?></td>
        <td><a href="?detail=<?= e($r['token']) ?>">جزئیات</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php for ($p = 1; $p <= min($pages, 10); $p++): ?>
      <a class="<?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p ?>&status=<?= e($filters['status']) ?>&gateway=<?= e($filters['gateway']) ?>&search=<?= urlencode($filters['search']) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>

  <p style="color:#475569;font-size:11.5px;text-align:center;padding:10px 0 20px">
    PayViro v<?= e(WG_VERSION) ?> — مستندات: <a href="../docs/">/gateway/docs</a>
  </p>
</div>
</body>
</html>
