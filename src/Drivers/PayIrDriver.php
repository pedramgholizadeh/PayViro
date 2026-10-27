<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\Core\Config;
use PayViro\Core\Http\Request;
use PayViro\Core\HttpClient;
use PayViro\Core\Logger;

/**
 * Pay.ir driver — popular Iranian payment aggregator.
 *
 * Amounts in TOMAN. Sandbox api key: "test".
 */
final class PayIrDriver extends AbstractRestDriver
{
    protected const ID = 'payir';
    private const API = 'https://pay.ir/pg';

    public function createPayment(array $txn): array
    {
        $cfg = self::cfg();
        $apiKey = self::requireCredential('api', 'پی.آی‌آر');

        $response = HttpClient::postJson(self::API . '/send', [
            'api'          => $apiKey,
            'amount'       => self::amount($txn, 'toman'),
            'redirect'     => Config::base() . '/callback/' . $txn['token'],
            'mobile'       => (string) ($txn['mobile'] ?? ''),
            'factorNumber' => (string) ($txn['order_id'] ?? ''),
            'description'  => (string) ($txn['description'] ?? 'پرداخت'),
        ]);

        $json = $response['json'] ?? [];

        if ((int) ($json['status'] ?? 0) !== 1 || empty($json['token'])) {
            Logger::warning('payir.request.failed', ['status' => $response['status'], 'message' => $json['message'] ?? '']);
            throw \PayViro\ApiException::gatewayRejected('پی.آی‌آر', (int) ($json['status'] ?? $response['status']), (string) ($json['message'] ?? ''));
        }

        return [
            'bank_url'   => self::API . '/' . $json['token'],
            'bank_token' => (string) $json['token'],
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg    = self::cfg();
        $token  = (string) ($request->query['token'] ?? $request->input('token', ''));
        $status = (int) ($request->query['status'] ?? $request->input('status', 0));
        $transId = (string) ($request->query['transId'] ?? '');
        $message = (string) ($request->query['message'] ?? '');
        $raw = ['token' => $token, 'bank_status' => $status, 'transId' => $transId];

        if ($token === '') {
            return self::result(false, 'canceled', null, null, null, 'کاربر به درگاه پی.آی‌آر بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }
        if ($status !== 1) {
            return self::result(false, 'canceled', $transId ?: null, null, null, $message !== '' ? 'پرداخت تکمیل نشد: ' . $message : 'پرداخت در پی.آی‌آر تکمیل نشد.', $raw);
        }

        $response = HttpClient::postJson(self::API . '/verify', [
            'api'    => (string) ($cfg['api'] ?? ''),
            'token'  => $token,
            'status' => $status,
        ]);

        $json = $response['json'] ?? [];
        $raw['verify_response'] = $json;

        if ((int) ($json['status'] ?? 0) !== 1) {
            return self::result(false, 'failed', $transId ?: null, null, null, (string) ($json['message'] ?? 'تایید تراکنش در پی.آی‌آر ناموفق بود.'), $raw);
        }

        $card = (string) ($json['cardNumber'] ?? '');
        return self::result(
            true,
            'paid',
            (string) ($json['transId'] ?? $transId) ?: $token,
            null,
            $card !== '' ? self::maskCard($card) : null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }
}
