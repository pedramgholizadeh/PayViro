<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\Core\Config;
use PayViro\Core\Http\Request;
use PayViro\Core\HttpClient;
use PayViro\Core\Logger;

/**
 * IDPay driver (v1.1) — popular Iranian payment aggregator.
 *
 * Docs: https://idpay.ir/web-service/v1.1/
 * Amounts in Rial. Sandbox via X-SANDBOX header.
 */
final class IdpayDriver extends AbstractRestDriver
{
    protected const ID = 'idpay';
    private const API = 'https://api.idpay.ir/v1.1';

    public function createPayment(array $txn): array
    {
        $cfg = self::cfg();
        $apiKey = self::requireCredential('api_key', 'آیدی‌پی');

        $response = HttpClient::postJson(self::API . '/payment', [
            'order_id'    => (string) $txn['token'],
            'amount'      => self::amount($txn, 'rial'),
            'callback'    => Config::base() . '/callback/' . $txn['token'],
            'desc'        => (string) ($txn['description'] ?? 'پرداخت'),
            'phone'       => (string) ($txn['mobile'] ?? ''),
        ], array_filter([
            'X-API-KEY' => $apiKey,
            'X-SANDBOX' => (bool) ($cfg['sandbox'] ?? false) ? '1' : null,
        ], static fn ($v) => $v !== null));

        $json = $response['json'] ?? [];

        if ($response['status'] !== 201 || empty($json['id']) || empty($json['link'])) {
            $errorMessage = is_array($json['error_message'] ?? null) ? '' : (string) ($json['error_message'] ?? '');
            Logger::warning('idpay.request.failed', ['status' => $response['status'], 'error' => $errorMessage ?: mb_substr($response['body'], 0, 200)]);
            throw \PayViro\ApiException::gatewayRejected('آیدی‌پی', (int) ($json['error_code'] ?? $response['status']), $errorMessage);
        }

        return [
            'bank_url'   => (string) $json['link'],
            'bank_token' => (string) $json['id'],
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = self::cfg();

        $id      = (string) ($request->query['id'] ?? '');
        $orderId = (string) ($request->query['order_id'] ?? '');
        $status  = (int) ($request->query['status'] ?? 0);
        $trackId = (string) ($request->query['track_id'] ?? '');

        $raw = ['id' => $id, 'order_id' => $orderId, 'bank_status' => $status, 'track_id' => $trackId];

        // Security binding: IDPay must echo OUR token as order_id.
        if ($orderId !== '' && $orderId !== (string) $txn['token']) {
            Logger::warning('idpay.order_mismatch', ['expected' => $txn['token'], 'got' => $orderId]);
            return self::result(false, 'failed', $trackId ?: null, null, null, 'تطبیق شناسه سفارش با درگاه ناموفق بود.', $raw);
        }

        if ($status === 6 || $status === 10) {
            return self::result(false, 'canceled', $trackId ?: null, null, null, 'پرداخت توسط کاربر لغو شد.', $raw);
        }
        if ($status === 7) {
            return self::result(false, 'failed', $trackId ?: null, null, null, 'مهلت پرداخت در آیدی‌پی به پایان رسید.', $raw);
        }
        if ($status !== 2 && $status !== 100 && $status !== 101) {
            return self::result(false, 'failed', $trackId ?: null, null, null, 'پرداخت تکمیل نشد. کد وضعیت آیدی‌پی: ' . $status, $raw);
        }

        $bankId = $id !== '' ? $id : (string) ($txn['bank_token'] ?? '');
        if ($bankId === '') {
            return self::result(false, 'failed', null, null, null, 'شناسه پرداخت آیدی‌پی دریافت نشد.', $raw);
        }

        $response = HttpClient::postJson(self::API . '/payment/verify', [
            'id'       => $bankId,
            'order_id' => (string) $txn['token'],
        ], array_filter([
            'X-API-KEY' => (string) ($cfg['api_key'] ?? ''),
            'X-SANDBOX' => (bool) ($cfg['sandbox'] ?? false) ? '1' : null,
        ], static fn ($v) => $v !== null));

        $json = $response['json'] ?? [];
        $verifyStatus = (int) ($json['status'] ?? 0);
        $raw['verify_response'] = $json;

        if ($response['status'] === 200 && ($verifyStatus === 100 || $verifyStatus === 101)) {
            $verifyAmount = (int) round((float) ($json['amount'] ?? 0));
            if ($verifyAmount > 0 && $verifyAmount !== (int) $txn['amount_rial']) {
                return self::result(false, 'failed', (string) ($json['track_id'] ?? ''), null, null, 'مبلغ تاییدشده با مبلغ تراکنش مغایرت دارد.', $raw);
            }
            $cardNo = (string) ($json['payment']['card_no'] ?? '');
            return self::result(
                true,
                'paid',
                (string) ($json['track_id'] ?? $trackId) ?: null,
                null,
                $cardNo !== '' ? self::maskCard($cardNo) : null,
                'پرداخت با موفقیت تایید شد.',
                $raw
            );
        }

        $errorMessage = is_array($json['error_message'] ?? null) ? '' : (string) ($json['error_message'] ?? '');
        return self::result(false, 'failed', $trackId ?: null, null, null, $errorMessage !== '' ? $errorMessage : 'تایید تراکنش در آیدی‌پی ناموفق بود. کد: ' . $verifyStatus, $raw);
    }
}
