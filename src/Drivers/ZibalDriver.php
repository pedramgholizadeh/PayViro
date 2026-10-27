<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\ApiException;
use PayViro\Core\Config;
use PayViro\Core\Http\Request;
use PayViro\Core\HttpClient;
use PayViro\Core\Logger;

/**
 * Zibal v1 driver.
 *
 * Docs: https://docs.zibal.ir
 * Amounts are in Rial. Sandbox merchant: "zibal"
 */
final class ZibalDriver implements DriverInterface
{
    private const API = 'https://gateway.zibal.ir/v1';

    public function createPayment(array $txn): array
    {
        $cfg = (array) Config::get('drivers.zibal', []);
        $merchant = (string) ($cfg['merchant'] ?? '');
        if ($merchant === '' || strpos($merchant, 'CHANGE_ME') === 0) {
            throw new ApiException(502, 'driver_error', 'درگاه زیبال در پیکربندی گیت‌وی تنظیم نشده است.');
        }

        $response = HttpClient::postJson(self::API . '/request', [
            'merchant'     => $merchant,
            'amount'       => (int) $txn['amount_rial'],
            'callbackUrl'  => Config::base() . '/callback/' . $txn['token'],
            'description'  => (string) ($txn['description'] ?? 'پرداخت'),
            'orderId'      => (string) ($txn['order_id'] ?? ''),
            'mobile'       => (string) ($txn['mobile'] ?? ''),
        ]);

        $json = $response['json'] ?? [];
        $result = (int) ($json['result'] ?? 0);
        $trackId = (string) ($json['trackId'] ?? '');

        if ($result !== 100 || $trackId === '') {
            Logger::warning('zibal.request.failed', ['result' => $result, 'message' => $json['message'] ?? '']);
            throw new ApiException(502, 'gateway_rejected', self::messageFor($result, (string) ($json['message'] ?? '')));
        }

        return [
            'bank_url'   => 'https://gateway.zibal.ir/' . $trackId,
            'bank_token' => $trackId,
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = (array) Config::get('drivers.zibal', []);

        $trackId = (string) ($request->query['trackId'] ?? $request->input('trackId', ''));
        $success = (string) ($request->query['success'] ?? $request->input('success', ''));
        $status  = (int) ($request->query['status'] ?? $request->input('status', 0));

        $raw = ['trackId' => $trackId, 'success' => $success, 'bank_status' => $status];

        if ($trackId === '') {
            return self::result(false, 'failed', null, null, null, 'پارامتر trackId از بانک دریافت نشد.', $raw);
        }
        if ($success !== '1') {
            $statusText = self::statusText($status);
            $isCanceled = in_array($status, [2, 3], true);
            return self::result(
                false,
                $isCanceled ? 'canceled' : 'failed',
                null,
                null,
                null,
                'پرداخت تکمیل نشد: ' . $statusText,
                $raw
            );
        }

        $response = HttpClient::postJson(self::API . '/verify', [
            'merchant' => (string) ($cfg['merchant'] ?? ''),
            'trackId'  => $trackId,
        ]);

        $json   = $response['json'] ?? [];
        $result = (int) ($json['result'] ?? 0);
        $raw['verify_response'] = $json;

        if ($result !== 100 && $result !== 201) {
            return self::result(false, 'failed', null, null, null, self::messageFor($result, (string) ($json['message'] ?? '')), $raw);
        }

        // 201 = previously verified — still a success for us.
        $paidAmount = (int) ($json['paidAmount'] ?? 0);
        if ($paidAmount < (int) $txn['amount_rial']) {
            return self::result(false, 'failed', null, null, null, 'مبلغ پرداخت‌شده کمتر از مبلغ تراکنش است.', $raw);
        }

        $cardNumber = (string) ($json['cardNumber'] ?? '');
        return self::result(
            true,
            'paid',
            $trackId,
            null,
            $cardNumber !== '' ? self::maskCard($cardNumber) : null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }

    private static function statusText(int $status): string
    {
        $map = [
            -1  => 'در انتظار پرداخت',
            -2  => 'خطای داخلی زیبال',
            1   => 'پرداخت شده',
            2   => 'پرداخت لغو شده',
            3   => 'خطا در پرداخت (موجودی یا رمز نادرست)',
            4   => 'کارت بلوکه شده',
            5   => 'حساب پذیرنده نامعتبر',
        ];
        return $map[$status] ?? ('کد وضعیت ' . $status);
    }

    private static function messageFor(int $result, string $message): string
    {
        $map = [
            102   => 'مرچنت یافت نشد.',
            103   => 'مرچنت غیرفعال است.',
            104   => 'مرچنت نامعتبر است.',
            105   => 'مبلغ تراکنش باید عددی و بزرگ‌تر از ۱۰۰ ریال باشد.',
            106   => 'آدرس بازگشت (callbackUrl) نامعتبر است.',
            113   => 'مبلغ تراکنش از سقف مجاز کمتر است.',
            201   => 'تراکنش قبلاً تایید شده است.',
            202   => 'تراکنش پرداخت نشده یا ناموفق است.',
            203   => 'تراکنش ناموفق است.',
        ];
        return $map[$result] ?? ('پرداخت تایید نشد. کد: ' . $result . ($message !== '' ? ' (' . $message . ')' : ''));
    }

    private static function maskCard(string $card): string
    {
        $digits = preg_replace('/\D/', '', $card) ?? '';
        if (strlen($digits) < 10) {
            return $card;
        }
        return substr($digits, 0, 6) . '******' . substr($digits, -4);
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
