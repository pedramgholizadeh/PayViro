<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\ApiException;
use PayViro\Core\Config;
use PayViro\Core\Http\Request;
use PayViro\Core\HttpClient;
use PayViro\Core\Logger;

/**
 * BlueBank (بلوبانک) driver — configurable token-based IPG.
 *
 * BlueBank does not publish its merchant API publicly; the exact URLs and
 * field names come from your BlueBank merchant onboarding docs. Everything
 * below is therefore driven by config (config.php → drivers.bluebank):
 *
 *   purchase_url     POST target that issues a payment token
 *   payment_url      bank page, "{token}" placeholder is replaced
 *   token_field      response field holding the token
 *   callback params  how the bank reports success back to /callback/{token}
 *   verify_url       server-to-server verification endpoint
 *
 * Expected (default) REST shape — adjust the field names in config:
 *   POST purchase_url {merchant_id, amount, callback_url, order_id, description, mobile}
 *     → {token: "..."}
 *   POST verify_url   {merchant_id, token}
 *     → {result: 100, ref_no: "...", card_no: "6104-****-1234"}
 */
final class BlueBankDriver implements DriverInterface
{
    public function createPayment(array $txn): array
    {
        $cfg = (array) Config::get('drivers.bluebank', []);

        if ((bool) ($cfg['enabled'] ?? false) === false) {
            throw new ApiException(502, 'gateway_unavailable', 'درگاه بلوبانک هنوز در پیکربندی گیت‌وی فعال نشده است.');
        }

        $purchaseUrl = (string) ($cfg['purchase_url'] ?? '');
        if ($purchaseUrl === '') {
            throw new ApiException(502, 'driver_error', 'آدرس purchase درگاه بلوبانک تنظیم نشده است (drivers.bluebank.purchase_url).');
        }

        $response = HttpClient::postJson($purchaseUrl, [
            'merchant_id'  => (string) ($cfg['merchant_id'] ?? ''),
            'amount'       => (int) $txn['amount_rial'],
            'callback_url' => Config::base() . '/callback/' . $txn['token'],
            'order_id'     => (string) ($txn['order_id'] ?? ''),
            'description'  => (string) ($txn['description'] ?? ''),
            'mobile'       => (string) ($txn['mobile'] ?? ''),
        ], (array) ($cfg['purchase_headers'] ?? []));

        $json = $response['json'] ?? [];
        $token = (string) ($json[(string) ($cfg['token_field'] ?? 'token')] ?? '');

        if ($token === '') {
            Logger::warning('bluebank.purchase.failed', ['status' => $response['status']]);
            throw new ApiException(502, 'gateway_rejected', 'دریافت توکن از بلوبانک ناموفق بود.');
        }

        $paymentUrl = (string) ($cfg['payment_url'] ?? '');
        if ($paymentUrl === '') {
            $paymentUrl = (string) ($json['payment_url'] ?? '');
        }
        if ($paymentUrl === '') {
            throw new ApiException(502, 'driver_error', 'آدرس صفحه پرداخت بلوبانک تنظیم نشده است (drivers.bluebank.payment_url).');
        }

        return [
            'bank_url'   => str_replace('{token}', urlencode($token), $paymentUrl),
            'bank_token' => $token,
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = (array) Config::get('drivers.bluebank', []);

        $tokenParam  = (string) ($cfg['callback_token_param'] ?? 'token');
        $successParam = (string) ($cfg['callback_success_param'] ?? 'status');
        $successValues = array_map('strval', (array) ($cfg['callback_success_values'] ?? ['OK']));

        $bankToken = (string) $request->input($tokenParam, '');
        $successRaw = (string) $request->input($successParam, '');

        $raw = ['bank_callback' => [$tokenParam => $bankToken, $successParam => $successRaw]];

        if ($bankToken === '') {
            return self::result(false, 'failed', null, null, null, 'پارامتر توکن از بلوبانک دریافت نشد.', $raw);
        }
        if (!in_array($successRaw, $successValues, true)) {
            return self::result(false, 'canceled', null, null, null, 'پرداخت در بلوبانک تکمیل نشد.', $raw);
        }

        $verifyUrl = (string) ($cfg['verify_url'] ?? '');
        if ($verifyUrl === '') {
            // No verify endpoint configured: trust only the explicit success param
            // (not recommended for production).
            Logger::warning('bluebank.verify.skipped', ['token' => $txn['token']]);
            return self::result(true, 'paid', $bankToken, null, null, 'پرداخت با موفقیت انجام شد (بدون تایید سمت سرور).', $raw);
        }

        $response = HttpClient::postJson($verifyUrl, [
            'merchant_id' => (string) ($cfg['merchant_id'] ?? ''),
            'token'       => $bankToken,
        ]);
        $json = $response['json'] ?? [];
        $raw['verify_response'] = $json;

        $resultField  = (string) ($cfg['verify_result_field'] ?? 'result');
        $successList  = array_map('strval', (array) ($cfg['verify_success_values'] ?? ['100']));
        $bankResult   = (string) ($json[$resultField] ?? '');

        if (!in_array($bankResult, $successList, true)) {
            return self::result(false, 'failed', null, null, null, 'تایید تراکنش در بلوبانک ناموفق بود. کد: ' . $bankResult, $raw);
        }

        return self::result(
            true,
            'paid',
            (string) ($json[(string) ($cfg['verify_ref_field'] ?? 'ref_no')] ?? '') ?: $bankToken,
            null,
            (string) ($json[(string) ($cfg['verify_card_field'] ?? 'card_no')] ?? '') ?: null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }

    private static function result(bool $success, string $status, ?string $refNo, ?string $rrn, ?string $cardNo, string $message, array $raw): array
    {
        return [
            'success' => $success,
            'status'  => $status,
            'ref_no'  => $refNo,
            'rrn'     => $rrn,
            'card_no' => $cardNo,
            'message' => $message,
            'raw'     => $raw,
        ];
    }
}
