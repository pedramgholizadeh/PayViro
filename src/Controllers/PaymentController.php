<?php

declare(strict_types=1);

namespace PayViro\Controllers;

use PayViro\Core\Config;
use PayViro\Core\Http\Request;
use PayViro\Core\Http\Response;
use PayViro\Core\Logger;
use PayViro\Drivers\DriverFactory;
use PayViro\Models\Transaction;
use PayViro\Services\PaymentService;

/**
 * Browser-facing flow: /go/{token}, /callback/{token} and the sandbox page.
 */
final class PaymentController
{
    public function go(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $txn = Transaction::findByToken($token);

        if ($txn === null) {
            return wg_error_page(404, 'تراکنش یافت نشد', 'لینک پرداخت معتبر نیست یا منقضی شده است. از صفحه فروشگاه دوباره تلاش کنید.');
        }

        // Already finalized (user came back late) → straight to merchant.
        if ($txn['status'] !== 'pending') {
            return PaymentService::redirectResponse($txn);
        }

        // Expired while pending → mark and bounce.
        if ((string) $txn['expires_at'] < wg_now()) {
            Transaction::finalize($token, 'expired');
            $txn = Transaction::findByToken($token) ?? $txn;
            return PaymentService::redirectResponse($txn);
        }

        if (empty($txn['bank_url'])) {
            Logger::error('go.missing_bank_url', ['token' => $token]);
            return wg_error_page(502, 'خطای درگاه', 'لینک بانک برای این تراکنش ساخته نشده است.');
        }

        $response = new Response();

        if ((string) $txn['bank_redirect'] === 'form_post') {
            return self::autoPostForm($txn);
        }

        $response->status = 302;
        $response->headers['Location'] = (string) $txn['bank_url'];
        $response->headers['Referrer-Policy'] = 'no-referrer';
        return $response;
    }

    public function callback(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        return PaymentService::handleCallback($request, $token);
    }

    /* --------------------------------------------------------------
     |  Sandbox bank simulator (drivers.test)
     * ------------------------------------------------------------- */

    public function sandboxPage(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $txn = Transaction::findByToken($token);

        if ($txn === null || (string) $txn['gateway'] !== 'test') {
            return wg_error_page(404, 'تراکنش یافت نشد', 'این صفحه فقط برای درگاه تست معتبر است.');
        }
        if ($txn['status'] !== 'pending') {
            return PaymentService::redirectResponse($txn);
        }

        $amount  = number_format((int) $txn['amount_rial']);
        $desc    = htmlspecialchars((string) ($txn['description'] ?? 'پرداخت آزمایشی'), ENT_QUOTES, 'UTF-8');
        $orderId = htmlspecialchars((string) ($txn['order_id'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $base    = Config::base();

        $html = '<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<title>درگاه پرداخت آزمایشی | PayViro Sandbox</title>
<style>
:root { color-scheme: dark; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Vazirmatn, "Segoe UI", Tahoma, sans-serif; background:#030806; color:#eefdf6; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; }
.terminal { width:100%; max-width:420px; background:#0a1410; border:1px solid #1a332a; border-radius:20px; overflow:hidden; }
.header { background:linear-gradient(135deg,#065f46,#032018); padding:18px 24px; display:flex; align-items:center; gap:12px; border-bottom:1px solid #065f46; }
.logo { width:44px; height:44px; border-radius:12px; background:#032018; border:1px solid #00c38955; display:flex; align-items:center; justify-content:center; font-size:11px; color:#00c389; font-weight:700; }
.header h2 { font-size:14px; font-weight:600; color:#c9f7e8; }
.header span { font-size:11px; color:#6ee7c7; display:block; }
.body { padding:24px; }
.row { display:flex; justify-content:space-between; padding:10px 0; font-size:13px; border-bottom:1px dashed #1a332a; }
.row:last-of-type { border-bottom:0; }
.row .label { color:#8fb8a8; }
.row .value { font-weight:600; }
.amount { text-align:center; padding:18px 0; }
.amount b { font-size:26px; color:#00c389; letter-spacing:.5px; }
.amount span { display:block; font-size:12px; color:#8fb8a8; margin-top:4px; }
.actions { display:grid; gap:10px; margin-top:20px; }
button { font-family:inherit; font-size:14px; font-weight:600; padding:13px; border-radius:12px; border:1px solid transparent; cursor:pointer; transition:transform .15s ease, filter .15s ease; }
button:hover { filter:brightness(1.1); }
button:active { transform:scale(.98); }
.pay { background:#00c389; color:#032018; }
.fail { background:#7f1d1d; color:#fecaca; }
.cancel { background:transparent; color:#8fb8a8; border-color:#334155; }
.sandbox-note { margin-top:16px; font-size:11px; color:#5e8577; text-align:center; line-height:1.8; }
</style>
</head>
<body>
<div class="terminal">
  <div class="header">
    <div class="logo">TEST<br>BANK</div>
    <div><h2>درگاه پرداخت آزمایشی</h2><span>PayViro Sandbox</span></div>
  </div>
  <div class="body">
    <div class="amount"><b>' . $amount . '</b><span>ریال</span></div>
    <div class="row"><span class="label">شرح تراکنش</span><span class="value">' . $desc . '</span></div>
    <div class="row"><span class="label">شماره سفارش</span><span class="value" style="direction:ltr">' . $orderId . '</span></div>
    <div class="actions">
      <form method="post" action="' . $base . '/go/' . $token . '/sandbox/pay"><button class="pay" type="submit">پرداخت موفق (شبیه‌سازی)</button></form>
      <form method="post" action="' . $base . '/go/' . $token . '/sandbox/failed"><button class="fail" type="submit">پرداخت ناموفق</button></form>
      <form method="post" action="' . $base . '/go/' . $token . '/sandbox/cancel"><button class="cancel" type="submit">انصراف از پرداخت</button></form>
    </div>
    <div class="sandbox-note">این یک صفحه شبیه‌ساز است و هیچ پول واقعی جابه‌جا نمی‌شود.<br>جریان کامل (Callback، Verify، Webhook و Redirect) دقیقاً مثل بانک واقعی اجرا می‌شود.</div>
  </div>
</div>
</body>
</html>';

        return Response::html($html);
    }

    public function sandboxAction(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $txn = Transaction::findByToken($token);

        if ($txn === null || (string) $txn['gateway'] !== 'test' || $txn['status'] !== 'pending') {
            return wg_error_page(404, 'تراکنش یافت نشد', 'این درخواست فقط برای تراکنش‌های در حال انتظار درگاه تست معتبر است.');
        }

        // Simulate what the real bank does: call our own callback with a result.
        $sim = 'success';
        if (strpos($request->path, '/failed') !== false) {
            $sim = 'failed';
        } elseif (strpos($request->path, '/cancel') !== false) {
            $sim = 'cancel';
        }

        $simulatedBankRequest = new Request('GET', '/callback/' . $token, ['sim' => $sim, 'token' => $token]);

        return PaymentService::handleCallback($simulatedBankRequest, $token);
    }

    /* --------------------------------------------------------------
     |  Auto-submitting form for POST-based banks (e.g. Saman)
     * ------------------------------------------------------------- */

    private static function autoPostForm(array $txn): Response
    {
        $fields = [];
        $decoded = json_decode((string) ($txn['bank_fields'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $name => $value) {
                $fields[(string) $name] = (string) $value;
            }
        }

        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                     . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        $url = htmlspecialchars((string) $txn['bank_url'], ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<title>در حال انتقال به درگاه بانکی...</title>
</head>
<body onload="document.forms[0].submit()">
<form method="post" action="' . $url . '">
' . $inputs . '
<noscript>
  <p style="font-family:Tahoma,sans-serif;text-align:center;direction:rtl;padding:40px">
    در حال انتقال به درگاه بانکی هستید. اگر صفحه به‌صورت خودکار باز نشد،
    <button type="submit">اینجا کلیک کنید</button>.
  </p>
</noscript>
</form>
</body>
</html>';

        return Response::html($html);
    }
}
