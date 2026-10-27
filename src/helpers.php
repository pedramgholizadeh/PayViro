<?php

declare(strict_types=1);

/* ------------------------------------------------------------------
 |  Small global helpers shared across the gateway.
 * ------------------------------------------------------------------ */

use Webiro\Core\Config;
use Webiro\Core\Http\Response;

/**
 * Current local datetime string, consistent format for DB storage.
 */
function wg_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Dotted config access.
 */
function wg_config(string $key, $default = null)
{
    return Config::get($key, $default);
}

/**
 * Mask a secret for logging: wgr_abcd...wxyz
 */
function wg_mask_secret(?string $secret): string
{
    if ($secret === null || $secret === '') {
        return '';
    }
    if (strlen($secret) <= 10) {
        return substr($secret, 0, 3) . '***';
    }
    return substr($secret, 0, 8) . '...' . substr($secret, -4);
}

/**
 * Render a branded Persian error page (browser-facing routes only).
 */
function wg_error_page(int $status, string $title, string $message, string $detail = ''): Response
{
    $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeDetail  = $detail !== '' ? htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') : '';

    $html = '<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<title>' . $safeTitle . ' | درگاه پرداخت وبیرو</title>
<style>
:root { color-scheme: dark; }
* { margin:0; padding:0; box-sizing:border-box; }
body {
  font-family: Vazirmatn, "Segoe UI", Tahoma, sans-serif;
  background:#020617; color:#F8FAFC; min-height:100vh;
  display:flex; align-items:center; justify-content:center; padding:24px;
}
.card {
  background:#0E1223; border:1px solid #1E293B; border-radius:20px;
  padding:48px 40px; max-width:460px; width:100%; text-align:center;
}
.badge {
  width:64px; height:64px; border-radius:18px; margin:0 auto 20px;
  background:linear-gradient(135deg,#14532d,#022c22); border:1px solid #166534;
  display:flex; align-items:center; justify-content:center;
}
.badge svg { width:30px; height:30px; stroke:#22C55E; }
h1 { font-size:20px; font-weight:700; margin-bottom:12px; }
p { font-size:14px; line-height:1.9; color:#94A3B8; }
.detail { margin-top:16px; font-size:12px; color:#64748B; direction:ltr; }
.brand { margin-top:28px; font-size:12px; color:#475569; }
</style>
</head>
<body>
<div class="card">
  <div class="badge">
    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg>
  </div>
  <h1>' . $safeTitle . '</h1>
  <p>' . $safeMessage . '</p>
  ' . ($safeDetail !== '' ? '<div class="detail">' . $safeDetail . '</div>' : '') . '
  <div class="brand">PayViro &mdash; webiro.ir</div>
</div>
</body>
</html>';

    return Response::html($html, $status);
}

/**
 * Format Rial amounts in Persian style for admin/error pages.
 */
function wg_format_amount(int $rial): string
{
    return number_format($rial) . ' ریال';
}
