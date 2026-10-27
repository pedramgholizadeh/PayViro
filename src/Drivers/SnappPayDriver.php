<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * SnappPay driver (اسنپ‌پی) — Buy-Now-Pay-Later.
 *
 * Official flow (per SnappPay merchant docs):
 *   1. OAuth:  POST {base}/api/online/v1/oauth/token   (Basic client_id:client_secret)
 *              body {grant_type:'password', scope:'online-merchant', username, password}
 *   2. Create: POST {base}/api/online/payment/v1/token {amount(rial),
 *              paymentMethodTypeDto:'INSTALLMENT', returnURL, transactionId,
 *              mobile?, cartList[]}
 *              → {successful, response:{paymentToken, paymentPageUrl}}
 *   3. Payer returns; verify + settle:  POST /verify and /settle {paymentToken}
 *
 * base_url / credentials come from the SnappPay merchant panel.
 * Amounts in Rial.
 */
final class SnappPayDriver extends AbstractRestDriver
{
    protected const ID = 'snappay';

    /** @var string|null cached OAuth access token */
    private static $accessToken = null;

    public function createPayment(array $txn): array
    {
        $cfg = self::cfg();
        $base = $this->requireBase($cfg);

        $cartList = [[
            'productId' => (string) ($txn['order_id'] ?? $txn['token']),
            'title'     => mb_substr((string) ($txn['description'] ?? 'خرید'), 0, 120) ?: 'خرید',
            'quantity'  => 1,
            'price'     => self::amount($txn, 'rial'),
            'category'  => 'general',
        ]];

        $data = [
            'amount'               => self::amount($txn, 'rial'),
            'paymentMethodTypeDto' => 'INSTALLMENT',
            'returnURL'            => \Webiro\Core\Config::base() . '/callback/' . $txn['token'],
            'transactionId'        => (string) $txn['token'],
            'externalSourceAmount' => 0,
            'discountAmount'       => 0,
            'cartList'             => $cartList,
        ];
        if (!empty($txn['mobile'])) {
            $data['mobile'] = (string) $txn['mobile'];
        }

        $response = $this->api($base, '/api/online/payment/v1/token', $data);
        $successful = (bool) ($response['successful'] ?? false);
        $paymentUrl = (string) ($response['response']['paymentPageUrl'] ?? '');
        $paymentToken = (string) ($response['response']['paymentToken'] ?? '');

        if (!$successful || $paymentUrl === '' || $paymentToken === '') {
            Logger::warning('snappay.request.failed', ['msg' => $response['message'] ?? mb_substr((string) ($response['error'] ?? ''), 0, 200)]);
            throw \Webiro\ApiException::gatewayRejected('اسنپ‌پی', 0, (string) ($response['message'] ?? ''));
        }

        return [
            'bank_url'   => $paymentUrl,
            'bank_token' => $paymentToken,
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = self::cfg();
        $base = $this->requireBase($cfg);

        $paymentToken = (string) ($request->query['paymentToken'] ?? $request->input('paymentToken', ''));
        if ($paymentToken === '') {
            $paymentToken = (string) ($txn['bank_token'] ?? '');
        }
        $raw = ['paymentToken' => $paymentToken];

        if ($paymentToken === '') {
            return self::result(false, 'canceled', null, null, null, 'کاربر به درگاه اسنپ‌پی بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }

        // ---- verify ----
        $verify = $this->api($base, '/api/online/payment/v1/verify', ['paymentToken' => $paymentToken]);
        $raw['verify_response'] = $verify;

        if (!(bool) ($verify['successful'] ?? false)) {
            $message = (string) ($verify['message'] ?? '');
            return self::result(false, 'failed', $paymentToken, null, null, $message !== '' ? $message : 'تایید خرید قسطی در اسنپ‌پی ناموفق بود.', $raw);
        }

        // Amount sanity when the provider reports it (units may differ — compare softly).
        $verifiedAmount = (float) ($verify['response']['amount'] ?? 0);
        if ($verifiedAmount > 0) {
            $expected = (float) self::amount($txn, 'rial');
            if (abs($verifiedAmount - $expected) > max(1.0, $expected * 0.001)) {
                return self::result(false, 'failed', $paymentToken, null, null, 'مبلغ تاییدشده با مبلغ تراکنش مغایرت دارد.', $raw);
            }
        }

        // ---- settle (required by SnappPay to complete the sale) ----
        $settle = $this->api($base, '/api/online/payment/v1/settle', ['paymentToken' => $paymentToken]);
        $raw['settle_response'] = $settle;

        if (!(bool) ($settle['successful'] ?? false)) {
            // Verified but not settled: money is committed — treat as paid but log loudly.
            Logger::error('snappay.settle.failed', ['token' => $txn['token'], 'msg' => $settle['message'] ?? '']);
        }

        return self::result(
            true,
            'paid',
            $paymentToken,
            (string) ($verify['response']['referenceNumber'] ?? '') ?: null,
            isset($verify['response']['cardNumber']) ? self::maskCard((string) $verify['response']['cardNumber']) : null,
            'خرید قسطی با موفقیت تایید شد.',
            $raw
        );
    }

    /**
     * Authorized API call with automatic OAuth token fetch.
     *
     * @return array<string,mixed>
     */
    private function api(string $base, string $path, array $body): array
    {
        if (self::$accessToken === null) {
            $cfg = self::cfg();
            $basic = base64_encode((string) ($cfg['client_id'] ?? '') . ':' . (string) ($cfg['client_secret'] ?? ''));
            $response = HttpClient::postJson($base . '/api/online/v1/oauth/token', [
                'grant_type' => 'password',
                'scope'      => 'online-merchant',
                'username'   => (string) ($cfg['username'] ?? ''),
                'password'   => (string) ($cfg['password'] ?? ''),
            ], ['Authorization' => 'Basic ' . $basic]);

            $json = $response['json'] ?? [];
            if (empty($json['access_token'])) {
                Logger::warning('snappay.oauth.failed', ['msg' => mb_substr((string) ($response['body'] ?? ''), 0, 200)]);
                throw new \RuntimeException('احراز هویت با اسنپ‌پی ناموفق بود.');
            }
            self::$accessToken = (string) $json['access_token'];
        }

        $response = HttpClient::request('POST', $base . $path, $body, [
            'Authorization' => 'Bearer ' . self::$accessToken,
        ]);
        return $response['json'] ?? [];
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function requireBase(array $cfg): string
    {
        $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        if ($base === '' || strpos($base, 'CHANGE_ME') === 0) {
            throw \Webiro\ApiException::driverNotConfigured('اسنپ‌پی (آدرس base_url از پنل پذیرندگی)');
        }
        foreach (['client_id', 'client_secret', 'username', 'password'] as $key) {
            if (trim((string) ($cfg[$key] ?? '')) === '') {
                throw \Webiro\ApiException::driverNotConfigured('اسنپ‌پی (' . $key . ')');
            }
        }
        return $base;
    }
}
