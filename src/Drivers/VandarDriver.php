<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * Vandar driver (v3) — Iranian payment aggregator (vandar.io).
 *
 * Amounts in RIAL. Callback GET: token + payment_status (OK|FAILED).
 */
final class VandarDriver extends AbstractRestDriver
{
    protected const ID = 'vandar';
    private const API = 'https://ipg.vandar.io/v3';

    public function createPayment(array $txn): array
    {
        self::requireCredential('api_key', 'وندار');

        $response = HttpClient::postJson(self::API . '/send', [
            'api_key'      => (string) self::cfg()['api_key'],
            'amount'       => self::amount($txn, 'rial'),
            'callback_url' => Config::base() . '/callback/' . $txn['token'],
            'description'  => (string) ($txn['description'] ?? 'پرداخت'),
            'mobile_number' => (string) ($txn['mobile'] ?? ''),
            'factorNumber' => (string) ($txn['order_id'] ?? ''),
        ]);

        $json = $response['json'] ?? [];

        if ((int) ($json['status'] ?? 0) !== 1 || empty($json['token'])) {
            $errors = $json['errors'] ?? [];
            $detail = is_array($errors) && $errors !== [] ? (string) (array_values($errors)[0] ?? '') : '';
            Logger::warning('vandar.request.failed', ['status' => $response['status'], 'detail' => $detail]);
            throw \Webiro\ApiException::gatewayRejected('وندار', (int) ($json['status'] ?? $response['status']), $detail);
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
        $token = (string) ($request->query['token'] ?? $request->input('token', ''));
        $paymentStatus = strtoupper((string) ($request->query['payment_status'] ?? $request->input('payment_status', '')));
        $raw = ['token' => $token, 'payment_status' => $paymentStatus];

        if ($token === '') {
            return self::result(false, 'canceled', null, null, null, 'کاربر به درگاه وندار بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }
        if ($paymentStatus === 'FAILED') {
            return self::result(false, 'failed', $token, null, null, 'پرداخت در وندار با شکست مواجه شد.', $raw);
        }
        if ($paymentStatus !== 'OK' && $paymentStatus !== '') {
            return self::result(false, 'canceled', $token, null, null, 'پرداخت در وندار تکمیل نشد.', $raw);
        }

        $response = HttpClient::postJson(self::API . '/verify', [
            'api_key' => (string) self::cfg()['api_key'],
            'token'   => $token,
        ]);

        $json = $response['json'] ?? [];
        $raw['verify_response'] = $json;

        if ((int) ($json['status'] ?? 0) !== 1) {
            $errors = $json['errors'] ?? ($json['error'] ?? []);
            $detail = is_array($errors) && $errors !== [] ? (string) (array_values($errors)[0] ?? '') : '';
            return self::result(false, 'failed', $token, null, null, $detail !== '' ? $detail : 'تایید تراکنش در وندار ناموفق بود.', $raw);
        }

        $card = (string) ($json['cardNumber'] ?? '');
        return self::result(
            true,
            'paid',
            $token,
            null,
            $card !== '' ? self::maskCard($card) : null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }
}
