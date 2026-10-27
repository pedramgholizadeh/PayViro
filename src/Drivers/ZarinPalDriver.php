<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\ApiException;
use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * ZarinPal v4 driver.
 *
 * Docs: https://docs.zarinpal.com
 * Amounts are in Rial.
 */
final class ZarinPalDriver implements DriverInterface
{
    public function createPayment(array $txn): array
    {
        $cfg  = (array) Config::get('drivers.zarinpal', []);
        $merchantId = (string) ($cfg['merchant_id'] ?? '');
        if ($merchantId === '' || strpos($merchantId, 'CHANGE_ME') === 0) {
            throw new ApiException(502, 'driver_error', 'درگاه زرین‌پال در پیکربندی گیت‌وی تنظیم نشده است.');
        }

        $sandbox = (bool) ($cfg['sandbox'] ?? false);
        $base    = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';

        $response = HttpClient::postJson($base . '/pg/v4/payment/request.json', [
            'merchant_id'  => $merchantId,
            'amount'       => (int) $txn['amount_rial'],
            'currency'     => (string) ($cfg['currency'] ?? 'IRR'),
            'description'  => (string) ($txn['description'] ?? 'پرداخت'),
            'callback_url' => Config::base() . '/callback/' . $txn['token'],
            'metadata'     => array_filter([
                'order_id' => (string) ($txn['order_id'] ?? ''),
                'mobile'   => (string) ($txn['mobile'] ?? ''),
                'email'    => '',
            ], static fn ($v) => $v !== ''),
        ]);

        if ($response['json'] === null) {
            Logger::error('zarinpal.request.bad_json', ['body' => mb_substr($response['body'], 0, 500)]);
            throw new ApiException(502, 'driver_error', 'پاسخ نامعتبر از زرین‌پال دریافت شد.');
        }

        $data = (array) ($response['json']['data'] ?? []);
        $code = (int) ($data['code'] ?? 0);

        if ($code !== 100 || empty($data['authority'])) {
            $message = self::messageFor((string) ($response['json']['errors']['code'] ?? ''), $code);
            Logger::warning('zarinpal.request.failed', ['code' => $code, 'data' => $data]);
            throw new ApiException(502, 'gateway_rejected', $message);
        }

        return [
            'bank_url'   => $base . '/pg/StartPay/' . $data['authority'],
            'bank_token' => (string) $data['authority'],
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg     = (array) Config::get('drivers.zarinpal', []);
        $sandbox = (bool) ($cfg['sandbox'] ?? false);
        $base    = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';

        $authority = (string) ($request->query['Authority'] ?? $request->input('authority', ''));
        $status    = (string) ($request->query['Status'] ?? $request->input('status', ''));

        $raw = ['authority' => $authority, 'bank_status' => $status];

        if ($authority === '') {
            return self::result(false, 'failed', null, null, null, 'پارامتر Authority از بانک دریافت نشد.', $raw);
        }
        if (strtoupper($status) !== 'OK') {
            return self::result(false, 'canceled', null, null, null, 'کاربر پرداخت را در زرین‌پال تکمیل نکرد.', $raw);
        }

        $response = HttpClient::postJson($base . '/pg/v4/payment/verify.json', [
            'merchant_id' => (string) ($cfg['merchant_id'] ?? ''),
            'amount'      => (int) $txn['amount_rial'],
            'authority'   => $authority,
        ]);

        $json = $response['json'] ?? [];
        $data = (array) ($json['data'] ?? []);
        $code = (int) ($data['code'] ?? 0);
        $raw['verify_response'] = $json;

        if ($code === 100 || $code === 101) {
            $cardPan = (string) ($data['card_pan'] ?? '');
            return self::result(
                true,
                'paid',
                isset($data['ref_id']) ? (string) $data['ref_id'] : null,
                null,
                $cardPan !== '' ? $cardPan : null,
                'پرداخت با موفقیت تایید شد.',
                $raw
            );
        }

        $errorCode = (string) ($json['errors']['code'] ?? '');
        return self::result(false, 'failed', null, null, null, self::messageFor($errorCode, $code), $raw);
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

    private static function messageFor(string $errorCode, int $dataCode): string
    {
        $map = [
            '-9'    => 'خطای اعتبارسنجی پارامترهای ارسالی به زرین‌پال.',
            '-10'   => 'مرچنت‌کد زرین‌پال نامعتبر است.',
            '-11'   => 'مرچنت‌کد زرین‌پال فعال نیست.',
            '-12'   => 'تلاش بیش از حد در بازه کوتاه؛ کمی بعد تلاش کنید.',
            '-15'   => 'ترمینال شما به حالت تعلیق درآمده است.',
            '-50'   => 'مبلغ پرداخت‌شده با مبلغ تراکنش همخوانی ندارد.',
            '-51'   => 'تراکنش پرداخت نشده یا ناموفق بوده است.',
            '-53'   => 'پرداخت به پذیرنده تعلق ندارد.',
            '-54'   => 'تراکنش قبلاً تایید/اهدا شده است.',
        ];
        $key = $errorCode !== '' ? $errorCode : (string) $dataCode;
        return $map[$key] ?? ('پرداخت تایید نشد. کد خطای زرین‌پال: ' . $key);
    }
}
