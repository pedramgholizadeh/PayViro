<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * Aqayepardakht driver (v2) — Iranian payment service (aqayepardakht.ir).
 *
 * Amounts in TOMAN. Callback POSTs tracking_number + transid.
 */
final class AqayepardakhtDriver extends AbstractRestDriver
{
    protected const ID = 'aqayepardakht';
    private const API = 'https://panel.aqayepardakht.ir/api/v2';

    public function createPayment(array $txn): array
    {
        $cfg = self::cfg();
        $pin = self::requireCredential('pin', 'آقای پرداخت');
        $sandbox = (bool) ($cfg['sandbox'] ?? false);

        $response = HttpClient::postJson(self::API . '/create', [
            'pin'        => $sandbox ? 'sandbox' : $pin,
            'amount'     => self::amount($txn, 'toman'),
            'callback'   => Config::base() . '/callback/' . $txn['token'],
            'invoice_id' => (string) $txn['token'],
            'mobile'     => (string) ($txn['mobile'] ?? ''),
            'description' => (string) ($txn['description'] ?? 'پرداخت'),
        ]);

        $json = $response['json'] ?? [];

        if ((string) ($json['status'] ?? '') !== 'success' || empty($json['transid'])) {
            Logger::warning('aqayepardakht.request.failed', ['status' => $response['status'], 'code' => $json['code'] ?? '']);
            throw \Webiro\ApiException::gatewayRejected('آقای پرداخت', (string) ($json['code'] ?? $response['status']), self::messageFor((string) ($json['code'] ?? '')));
        }

        $startBase = $sandbox
            ? 'https://panel.aqayepardakht.ir/startpay/sandbox/'
            : 'https://panel.aqayepardakht.ir/startpay/';

        return [
            'bank_url'   => $startBase . $json['transid'],
            'bank_token' => (string) $json['transid'],
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $trackingNumber = (string) $request->input('tracking_number', '');
        $transId = (string) $request->input('transid', '');
        $raw = ['transid' => $transId, 'tracking_number' => $trackingNumber];

        if ($transId === '' || $trackingNumber === '') {
            return self::result(false, 'canceled', $transId ?: null, null, null, 'کاربر به درگاه آقای پرداخت بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }

        $response = HttpClient::postJson(self::API . '/verify', [
            'pin'    => (string) self::cfg()['pin'],
            'amount' => self::amount($txn, 'toman'),
            'transid' => $transId,
        ]);

        $json = $response['json'] ?? [];
        $status = (string) ($json['status'] ?? '');
        $code = (string) ($json['code'] ?? '');
        $raw['verify_response'] = $json;

        if ($status === 'canceled') {
            return self::result(false, 'canceled', $trackingNumber, null, null, 'پرداخت توسط کاربر لغو شد.', $raw);
        }
        if ($status !== 'success') {
            return self::result(false, 'failed', $trackingNumber, null, null, self::messageFor($code), $raw);
        }

        return self::result(
            true,
            'paid',
            $trackingNumber,
            null,
            null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }

    private static function messageFor(string $code): string
    {
        $map = [
            '-1'  => 'مبلغ نباید خالی باشد.',
            '-2'  => 'کد پین درگاه نمی‌تواند خالی باشد.',
            '-3'  => 'آدرس بازگشت نمی‌تواند خالی باشد.',
            '-4'  => 'مبلغ وارد شده اشتباه است.',
            '-5'  => 'مبلغ باید بین ۱۰۰ تا ۵۰ میلیون تومان باشد.',
            '-6'  => 'کد پین وارد شده اشتباه است.',
            '-8'  => 'تراکنش مورد نظر وجود ندارد.',
            '-9'  => 'کد پین با درگاه تراکنش مطابقت ندارد.',
            '-10' => 'مبلغ با مبلغ تراکنش مطابقت ندارد.',
            '-11' => 'درگاه در انتظار تایید یا غیرفعال است.',
            '0'   => 'پرداخت انجام نشد.',
            '2'   => 'تراکنش قبلاً تایید شده است.',
        ];
        return $map[$code] ?? 'تایید تراکنش در آقای پرداخت ناموفق بود. کد: ' . $code;
    }
}
