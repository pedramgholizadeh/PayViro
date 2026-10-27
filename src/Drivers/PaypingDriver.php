<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\Core\Config;
use PayViro\Core\Http\Request;
use PayViro\Core\HttpClient;
use PayViro\Core\Logger;

/**
 * PayPing driver (v2) — Iranian payment aggregator (payping.ir).
 *
 * Amounts in TOMAN. Bearer-token auth. Verify = HTTP 200 on /pay/verify.
 */
final class PaypingDriver extends AbstractRestDriver
{
    protected const ID = 'payping';
    private const API = 'https://api.payping.ir/v2';

    public function createPayment(array $txn): array
    {
        $apiKey = self::requireCredential('api_key', 'پی‌پینگ');

        $response = HttpClient::postJson(self::API . '/pay', [
            'amount'        => self::amount($txn, 'toman'),
            'returnUrl'     => Config::base() . '/callback/' . $txn['token'],
            'payerIdentity' => (string) ($txn['mobile'] ?? ''),
            'payerName'     => (string) ($txn['metadata_name'] ?? ''),
            'description'   => (string) ($txn['description'] ?? 'پرداخت'),
            'clientRefId'   => (string) $txn['token'],
        ], [
            'Authorization' => 'bearer ' . $apiKey,
        ]);

        $json = $response['json'] ?? [];
        $code = (string) ($json['paymentCode'] ?? $json['code'] ?? '');

        if ($response['status'] !== 200 || $code === '') {
            $detail = is_array($json) && $json !== [] ? (string) (array_values($json)[0] ?? '') : '';
            Logger::warning('payping.request.failed', ['status' => $response['status'], 'detail' => $detail]);
            throw \PayViro\ApiException::gatewayRejected('پی‌پینگ', $response['status'], $detail);
        }

        return [
            'bank_url'   => self::API . '/pay/gotoipg/' . $code,
            'bank_token' => $code,
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $refId      = (string) ($request->query['refid'] ?? $request->input('refid', ''));
        $clientRef  = (string) ($request->query['clientrefid'] ?? $request->input('clientrefid', ''));
        $raw = ['refid' => $refId, 'clientrefid' => $clientRef];

        if ($refId === '') {
            return self::result(false, 'canceled', null, null, null, 'کاربر به درگاه پی‌پینگ بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }
        // Security binding: clientrefid is the token we issued.
        if ($clientRef !== '' && $clientRef !== (string) $txn['token']) {
            Logger::warning('payping.ref_mismatch', ['expected' => $txn['token'], 'got' => $clientRef]);
            return self::result(false, 'failed', $refId, null, null, 'تطبیق شناسه سفارش با درگاه ناموفق بود.', $raw);
        }

        $response = HttpClient::postJson(self::API . '/pay/verify', [
            'paymentRefId' => $refId,
        ], [
            'Authorization' => 'bearer ' . (string) self::cfg()['api_key'],
        ]);

        $json = $response['json'] ?? [];
        $raw['verify_response'] = $json;

        if ($response['status'] !== 200) {
            $detail = is_array($json) && $json !== [] ? (string) (array_values($json)[0] ?? '') : '';
            return self::result(false, 'failed', $refId, null, null, 'تایید تراکنش در پی‌پینگ ناموفق بود. کد: ' . $response['status'] . ($detail !== '' ? ' — ' . $detail : ''), $raw);
        }

        $card = (string) ($json['cardNumber'] ?? '');
        return self::result(
            true,
            'paid',
            $refId,
            null,
            $card !== '' ? self::maskCard($card) : null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }
}
