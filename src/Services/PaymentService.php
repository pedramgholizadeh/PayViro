<?php

declare(strict_types=1);

namespace Webiro\Services;

use Webiro\ApiException;
use Webiro\Core\Config;
use Webiro\Core\Crypto;
use Webiro\Core\Http\Request;
use Webiro\Core\Http\Response;
use Webiro\Core\Logger;
use Webiro\Drivers\DriverFactory;
use Webiro\Models\Transaction;
/**
 * Orchestrates the full payment lifecycle:
 * merchant request → bank → callback → verify → webhook → merchant redirect.
 */
final class PaymentService
{
    /**
     * Create a payment on behalf of an authenticated merchant.
     *
     * @param array<string,mixed> $merchant
     *
     * @return array<string,mixed> API response data
     */
    public static function create(Request $request, array $merchant): array
    {
        $input = $request->all();

        // ---------- amount ----------
        $amount = $input['amount'] ?? null;
        if (!is_numeric($amount) || (int) $amount <= 0 || floor((float) $amount) !== (float) $amount) {
            throw new ApiException(400, 'invalid_request', 'مبلغ (amount) باید عدد صحیح مثبت باشد.', ['field' => 'amount']);
        }
        $currency = strtolower(trim((string) ($input['currency'] ?? 'rial')));
        if (!in_array($currency, ['rial', 'toman'], true)) {
            throw new ApiException(400, 'invalid_request', 'واحد پول فقط rial یا toman است.', ['field' => 'currency']);
        }
        $amountRial = $currency === 'toman' ? (int) round(((float) $amount) * 10) : (int) $amount;

        $min = (int) Config::get('payments.min_amount', 10000);
        $max = (int) Config::get('payments.max_amount', 500000000);
        if ($amountRial < $min || $amountRial > $max) {
            throw new ApiException(400, 'invalid_request', 'مبلغ باید بین ' . number_format($min) . ' و ' . number_format($max) . ' ریال باشد.', ['field' => 'amount']);
        }

        // ---------- callback_url ----------
        $callbackUrl = trim((string) ($input['callback_url'] ?? ''));
        if ($callbackUrl === '' || filter_var($callbackUrl, FILTER_VALIDATE_URL) === false) {
            throw new ApiException(400, 'invalid_request', 'آدرس بازگشت (callback_url) نامعتبر است.', ['field' => 'callback_url']);
        }
        if ((bool) Config::get('app.https_only', true) && strpos($callbackUrl, 'https://') !== 0) {
            throw new ApiException(400, 'invalid_request', 'آدرس بازگشت باید HTTPS باشد.', ['field' => 'callback_url']);
        }
        $allowedDomain = trim((string) ($merchant['callback_domain'] ?? ''));
        if ($allowedDomain !== '') {
            $allowedHost = strtolower((string) (parse_url($allowedDomain, PHP_URL_HOST) ?: ''));
            $callbackHost = strtolower((string) (parse_url($callbackUrl, PHP_URL_HOST) ?: ''));
            if ($allowedHost !== '' && $callbackHost !== $allowedHost) {
                throw new ApiException(403, 'forbidden_callback', 'دامنه آدرس بازگشت با دامنه مجاز پذیرنده همخوانی ندارد.', ['field' => 'callback_url']);
            }
        }

        // ---------- gateway ----------
        $allowedGateways = $merchant['gateways'] ?? null;
        $gateway = strtolower(trim((string) ($input['gateway'] ?? '')));
        if ($gateway === '' || $gateway === 'auto') {
            $gateway = self::pickAutoGateway(is_array($allowedGateways) ? $allowedGateways : null);
        } else {
            if (!in_array($gateway, DriverFactory::ALL, true)) {
                throw new ApiException(400, 'invalid_request', 'درگاه نامعتبر است. مقادیر مجاز: ' . implode(', ', DriverFactory::ALL), ['field' => 'gateway']);
            }
            if (is_array($allowedGateways) && !in_array($gateway, array_map('strval', $allowedGateways), true)) {
                throw new ApiException(403, 'gateway_forbidden', 'این درگاه برای پذیرنده شما مجاز نیست.', ['field' => 'gateway']);
            }
            DriverFactory::make($gateway); // throws gateway_unavailable when not configured
        }

        // ---------- optional fields ----------
        $description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 500);
        $mobile = self::normalizeMobile((string) ($input['mobile'] ?? ''));
        if ($mobile !== '' && preg_match('/^09\d{9}$/', $mobile) !== 1) {
            throw new ApiException(400, 'invalid_request', 'شماره موبایل نامعتبر است (الگوی 09xxxxxxxxx).', ['field' => 'mobile']);
        }
        $orderId = mb_substr(trim((string) ($input['order_id'] ?? '')), 0, 64);
        $metadata = $input['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            throw new ApiException(400, 'invalid_request', 'فیلد metadata باید یک آبجکت باشد.', ['field' => 'metadata']);
        }

        $webhookUrl = trim((string) ($input['webhook_url'] ?? ''));
        if ($webhookUrl === '') {
            $webhookUrl = trim((string) ($merchant['webhook_url'] ?? ''));
        }
        if ($webhookUrl !== '' && filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
            throw new ApiException(400, 'invalid_request', 'آدرس وبهوک نامعتبر است.', ['field' => 'webhook_url']);
        }

        // ---------- idempotency ----------
        $idempotencyKey = mb_substr(trim((string) ($input['idempotency_key'] ?? '')), 0, 64);
        if ($idempotencyKey !== '') {
            $existing = Transaction::findByIdempotencyKey((string) $merchant['_id'], $idempotencyKey);
            if ($existing !== null) {
                Logger::info('payment.idempotent_replay', ['merchant' => $merchant['_id'], 'key' => $idempotencyKey]);
                return self::createResponse($existing);
            }
        }

        // ---------- persist ----------
        $ttl = (int) Config::get('payments.ttl_minutes', 20);
        $txn = Transaction::create([
            'token'           => Crypto::generateToken(),
            'merchant_id'     => (string) $merchant['_id'],
            'gateway'         => $gateway,
            'amount_rial'     => $amountRial,
            'currency'        => $currency,
            'description'     => $description !== '' ? $description : null,
            'mobile'          => $mobile !== '' ? $mobile : null,
            'order_id'        => $orderId !== '' ? $orderId : null,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'metadata'        => $metadata,
            'callback_url'    => $callbackUrl,
            'webhook_url'     => $webhookUrl !== '' ? $webhookUrl : null,
            'expires_at'      => date('Y-m-d H:i:s', time() + $ttl * 60),
        ]);

        // ---------- ask the bank for a payment URL ----------
        try {
            $driver  = DriverFactory::make($gateway);
            $gatewayResult = $driver->createPayment($txn);
        } catch (ApiException $e) {
            Transaction::finalize((string) $txn['token'], 'failed', ['fail_reason' => 'ایجاد پرداخت ناموفق: ' . $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            Transaction::finalize((string) $txn['token'], 'failed', ['fail_reason' => 'ایجاد پرداخت ناموفق: ' . $e->getMessage()]);
            Logger::error('payment.create.driver_error', ['token' => $txn['token'], 'error' => $e->getMessage()]);
            throw new ApiException(502, 'driver_error', 'ارتباط با درگاه پرداخت برقرار نشد. لطفاً دوباره تلاش کنید.');
        }

        Transaction::update((string) $txn['token'], [
            'bank_url'     => mb_substr($gatewayResult['bank_url'], 0, 500),
            'bank_token'   => mb_substr((string) ($gatewayResult['bank_token'] ?? ''), 0, 250),
            'bank_redirect' => $gatewayResult['redirect'],
            'bank_fields'  => json_encode($gatewayResult['fields'], JSON_UNESCAPED_UNICODE),
        ]);

        Logger::info('payment.created', [
            'token'    => $txn['token'],
            'merchant' => $merchant['_id'],
            'gateway'  => $gateway,
            'amount'   => $amountRial,
        ]);

        return self::createResponse(Transaction::findByToken((string) $txn['token']) ?? $txn);
    }

    /**
     * Bank redirected the user back to /callback/{token}.
     * Verify server-to-server, finalize, fire webhook, redirect to merchant.
     */
    public static function handleCallback(Request $request, string $token): Response
    {
        $txn = Transaction::findByToken($token);
        if ($txn === null) {
            return wg_error_page(404, 'تراکنش یافت نشد', 'شناسه پرداخت معتبر نیست یا منقضی شده است.');
        }

        $alreadyFinal = $txn['status'] !== 'pending';

        if (!$alreadyFinal) {
            try {
                $driver = DriverFactory::make((string) $txn['gateway']);
                $result = $driver->verifyPayment($txn, $request);
            } catch (\Throwable $e) {
                Logger::error('callback.verify_error', ['token' => $token, 'error' => $e->getMessage()]);
                return wg_error_page(502, 'خطا در تایید پرداخت', 'بررسی نتیجه پرداخت با بانک ناموفق بود. در صورت کسر مبلغ، تا ۷۲ ساعت آینده به حساب شما بازمی‌گردد.');
            }

            if ($result['success'] === true && $result['status'] === 'paid') {
                $finalized = Transaction::finalize($token, 'paid', [
                    'ref_no'     => $result['ref_no'],
                    'rrn'        => $result['rrn'],
                    'card_no'    => $result['card_no'] !== null ? mb_substr($result['card_no'], 0, 30) : null,
                    'verify_raw' => json_encode($result['raw'], JSON_UNESCAPED_UNICODE),
                ]);
                if ($finalized) {
                    $fresh = Transaction::findByToken($token);
                    if ($fresh !== null) {
                        WebhookService::notify($fresh, 'payment.succeeded');
                    }
                }
            } elseif ($result['status'] === 'rejected') {
                // Untrusted callback (e.g. bad crypto webhook signature) —
                // ignore it completely and keep the transaction pending.
                Logger::warning('callback.rejected', ['token' => $token, 'message' => $result['message']]);
            } elseif ($result['status'] === 'pending') {
                // Verification is temporarily impossible (e.g. bank WSDL unreachable).
                // Stay pending; cron or merchant status-poll will resolve later.
                Transaction::update($token, [
                    'fail_reason' => $result['message'],
                    'verify_raw'  => json_encode($result['raw'], JSON_UNESCAPED_UNICODE),
                ]);
                Logger::warning('callback.still_pending', ['token' => $token, 'message' => $result['message']]);
            } else {
                $finalStatus = $result['status'] === 'canceled' ? 'canceled' : 'failed';
                $finalized = Transaction::finalize($token, $finalStatus, [
                    'fail_reason' => mb_substr($result['message'], 0, 500),
                    'verify_raw'  => json_encode($result['raw'], JSON_UNESCAPED_UNICODE),
                ]);
                if ($finalized) {
                    $fresh = Transaction::findByToken($token);
                    if ($fresh !== null) {
                        WebhookService::notify($fresh, $finalStatus === 'canceled' ? 'payment.canceled' : 'payment.failed');
                    }
                }
            }
        }

        $final = Transaction::findByToken($token) ?? $txn;

        // Crypto webhooks (e.g. OxaPay) must be answered with a plain 200 "ok"
        // instead of a browser redirect, or the provider keeps retrying.
        try {
            $driver = DriverFactory::make((string) $final['gateway']);
            if (method_exists($driver, 'wantsPlainAck') && $driver->wantsPlainAck($request)) {
                return Response::html('ok', 200);
            }
        } catch (\Throwable $e) {
            // gateway no longer configured — fall through to the redirect
        }

        return self::redirectResponse($final);
    }

    /**
     * Signed redirect back to the merchant's callback_url.
     *
     * @param array<string,mixed> $txn
     */
    public static function redirectResponse(array $txn): Response
    {
        $secret = (string) Config::get('merchants.' . $txn['merchant_id'] . '.secret', '');

        $params = [
            'token'    => (string) $txn['token'],
            'status'   => (string) $txn['status'],
            'order_id' => (string) ($txn['order_id'] ?? ''),
            'amount'   => (string) $txn['amount_rial'],
            'gateway'  => (string) $txn['gateway'],
            'ref_no'   => (string) ($txn['ref_no'] ?? ''),
            'card_no'  => (string) ($txn['card_no'] ?? ''),
            'message'  => $txn['status'] === 'paid'
                ? 'پرداخت با موفقیت انجام شد.'
                : (string) ($txn['fail_reason'] ?? ($txn['status'] === 'expired' ? 'مهلت پرداخت به پایان رسید.' : 'پرداخت تکمیل نشد.')),
        ];

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $separator = strpos($txn['callback_url'], '?') === false ? '?' : '&';

        if ($secret !== '') {
            $signature = Crypto::signParts($secret, [
                $params['token'], $params['order_id'], $params['amount'],
                $params['status'], $params['ref_no'], $params['card_no'], $params['gateway'],
            ]);
            $query .= '&sig=' . $signature;
        }

        return Response::redirect($txn['callback_url'] . $separator . $query, 302);
    }

    /**
     * Normalized status payload shared by API and admin.
     *
     * @param array<string,mixed> $txn
     *
     * @return array<string,mixed>
     */
    public static function statusPayload(array $txn): array
    {
        return [
            'token'       => (string) $txn['token'],
            'status'      => (string) $txn['status'],
            'gateway'     => (string) $txn['gateway'],
            'order_id'    => (string) ($txn['order_id'] ?? ''),
            'amount'      => (int) $txn['amount_rial'],
            'currency'    => 'rial',
            'ref_no'      => $txn['ref_no'] ?? null,
            'rrn'         => $txn['rrn'] ?? null,
            'card_no'     => $txn['card_no'] ?? null,
            'message'     => $txn['fail_reason'] ?? null,
            'webhook_attempts' => (int) $txn['webhook_attempts'],
            'webhook_last_status' => $txn['webhook_last_status'] ?? null,
            'created_at'  => (string) $txn['created_at'],
            'paid_at'     => $txn['paid_at'] ?? null,
            'expires_at'  => (string) $txn['expires_at'],
        ];
    }

    /**
     * @param array<string,mixed> $txn
     *
     * @return array<string,mixed>
     */
    private static function createResponse(array $txn): array
    {
        return [
            'token'       => (string) $txn['token'],
            'payment_url' => Config::base() . '/go/' . $txn['token'],
            'gateway'     => (string) $txn['gateway'],
            'status'      => (string) $txn['status'],
            'amount'      => (int) $txn['amount_rial'],
            'currency'    => 'rial',
            'order_id'    => (string) ($txn['order_id'] ?? ''),
            'description' => $txn['description'] ?? null,
            'created_at'  => (string) $txn['created_at'],
            'expires_at'  => (string) $txn['expires_at'],
        ];
    }

    /**
     * Pick the first configured gateway for "auto" mode.
     *
     * @param array<int,string>|null $merchantWhitelist
     */
    private static function pickAutoGateway(?array $merchantWhitelist): string
    {
        $order = (array) Config::get('payments.auto_order', ['zarinpal', 'zibal', 'saman', 'bluebank']);
        $enabled = DriverFactory::enabled();
        foreach ($order as $gateway) {
            $gateway = (string) $gateway;
            if (!($enabled[$gateway] ?? false)) {
                continue;
            }
            if (is_array($merchantWhitelist) && !in_array($gateway, array_map('strval', $merchantWhitelist), true)) {
                continue;
            }
            return $gateway;
        }
        throw new ApiException(502, 'gateway_unavailable', 'هیچ درگاه پرداختی فعال نیست. تنظیمات درگاه‌ها را بررسی کنید.');
    }

    private static function normalizeMobile(string $mobile): string
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            return '';
        }
        $mobile = str_replace([' ', '-', '(', ')'], '', $mobile);
        if (strpos($mobile, '+98') === 0) {
            $mobile = '0' . substr($mobile, 3);
        } elseif (strpos($mobile, '0098') === 0) {
            $mobile = '0' . substr($mobile, 4);
        } elseif (strpos($mobile, '98') === 0 && strlen($mobile) === 12) {
            $mobile = '0' . substr($mobile, 2);
        }
        return preg_replace('/\D/', '', $mobile) ?? '';
    }
}
