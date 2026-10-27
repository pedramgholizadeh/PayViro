<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\ApiException;
use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * Generic config-driven BNPL (خرید قسطی) driver.
 *
 * Used for providers whose merchant API is only shared after onboarding
 * (Digipay, Tara, Lendo, Ghasta, …). The purchase → redirect → callback →
 * verify flow is fixed; every URL and field name comes from config:
 *
 *   drivers.digipay = [
 *     'enabled'          => true,
 *     'purchase_url'     => 'https://…/create',          // POST JSON
 *     'purchase_headers' => ['Authorization: Bearer …'],
 *     'payment_url'      => 'https://…/pay/{token}',     // or response field below
 *     'token_field'      => 'token',
 *     'callback_token_param'    => 'token',
 *     'callback_success_param'  => 'status',
 *     'callback_success_values'=> ['OK'],
 *     'verify_url'       => 'https://…/verify',
 *     'verify_result_field'     => 'result',
 *     'verify_success_values'   => ['100'],
 *     'verify_ref_field'        => 'ref_no',
 *     'verify_amount_field'     => null,                // e.g. 'amount' (rial)
 *   ]
 *
 * Fill the values from each provider's merchant panel docs — no code change.
 */
final class BnplDriver extends AbstractRestDriver
{
    protected const ID = '';

    public function createPayment(array $txn): array
    {
        $cfg = $this->cfg();
        $this->assertEnabled($cfg);

        $purchaseUrl = (string) ($cfg['purchase_url'] ?? '');
        if ($purchaseUrl === '') {
            throw ApiException::driverNotConfigured($this->label() . ' (purchase_url)');
        }

        $payload = array_merge([
            'merchant_id'  => (string) ($cfg['merchant_id'] ?? ''),
            'amount'       => self::amount($txn, (string) ($cfg['amount_unit'] ?? 'rial')),
            'callback_url' => Config::base() . '/callback/' . $txn['token'],
            'order_id'     => (string) ($txn['order_id'] ?? ''),
            'description'  => (string) ($txn['description'] ?? ''),
            'mobile'       => (string) ($txn['mobile'] ?? ''),
        ], (array) ($cfg['purchase_fields'] ?? []));

        $response = HttpClient::postJson($purchaseUrl, $payload, (array) ($cfg['purchase_headers'] ?? []));
        $json = $response['json'] ?? [];

        $token = (string) ($json[(string) ($cfg['token_field'] ?? 'token')] ?? '');
        $paymentUrl = (string) ($cfg['payment_url'] ?? '');
        if ($paymentUrl === '') {
            $paymentUrl = (string) ($json[(string) ($cfg['payment_url_field'] ?? 'payment_url')] ?? '');
        }
        if ($token === '' || $paymentUrl === '') {
            Logger::warning('bnpl.purchase.failed', ['gateway' => $this->gatewayId, 'status' => $response['status']]);
            throw ApiException::gatewayRejected($this->label(), $response['status'], 'دریافت توکن پرداخت قسطی ناموفق بود.');
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
        $cfg = $this->cfg();
        $this->assertEnabled($cfg);

        $tokenParam = (string) ($cfg['callback_token_param'] ?? 'token');
        $successParam = (string) ($cfg['callback_success_param'] ?? 'status');
        $successValues = array_map('strval', (array) ($cfg['callback_success_values'] ?? ['OK']));

        $bankToken = (string) $request->input($tokenParam, '');
        $successRaw = (string) $request->input($successParam, '');
        $raw = ['bank_callback' => [$tokenParam => $bankToken, $successParam => $successRaw]];

        if ($bankToken === '' && ($txn['bank_token'] ?? '') !== '') {
            $bankToken = (string) $txn['bank_token'];
        }
        if ($bankToken === '') {
            return self::result(false, 'canceled', null, null, null, 'کاربر به درگاه ' . $this->label() . ' بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }
        if (!in_array($successRaw, $successValues, true)) {
            return self::result(false, 'canceled', $bankToken, null, null, 'پرداخت قسطی در ' . $this->label() . ' تکمیل نشد.', $raw);
        }

        $verifyUrl = (string) ($cfg['verify_url'] ?? '');
        if ($verifyUrl === '') {
            Logger::warning('bnpl.verify.skipped', ['gateway' => $this->gatewayId, 'token' => $txn['token']]);
            return self::result(true, 'paid', $bankToken, null, null, 'پرداخت با موفقیت انجام شد (بدون تایید سمت سرور).', $raw);
        }

        $response = HttpClient::postJson($verifyUrl, [
            'merchant_id' => (string) ($cfg['merchant_id'] ?? ''),
            'token'       => $bankToken,
            'order_id'    => (string) ($txn['order_id'] ?? ''),
        ], (array) ($cfg['verify_headers'] ?? []));
        $json = $response['json'] ?? [];
        $raw['verify_response'] = $json;

        $resultField = (string) ($cfg['verify_result_field'] ?? 'result');
        $verifySuccess = array_map('strval', (array) ($cfg['verify_success_values'] ?? ['100']));
        $bankResult = (string) ($json[$resultField] ?? '');

        if (!in_array($bankResult, $verifySuccess, true)) {
            return self::result(false, 'failed', $bankToken, null, null, 'تایید خرید قسطی در ' . $this->label() . ' ناموفق بود. کد: ' . $bankResult, $raw);
        }

        $amountField = (string) ($cfg['verify_amount_field'] ?? '');
        if ($amountField !== '') {
            $verified = (int) round((float) ($json[$amountField] ?? 0));
            if ($verified > 0 && $verified !== self::amount($txn, (string) ($cfg['amount_unit'] ?? 'rial'))) {
                return self::result(false, 'failed', $bankToken, null, null, 'مبلغ تاییدشده با مبلغ تراکنش مغایرت دارد.', $raw);
            }
        }

        $cardField = (string) ($cfg['verify_card_field'] ?? 'card_no');
        $card = $cardField !== '' ? (string) ($json[$cardField] ?? '') : '';
        return self::result(
            true,
            'paid',
            (string) ($json[(string) ($cfg['verify_ref_field'] ?? 'ref_no')] ?? '') ?: $bankToken,
            null,
            $card !== '' ? self::maskCard($card) : null,
            'خرید قسطی با موفقیت تایید شد.',
            $raw
        );
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function assertEnabled(array $cfg): void
    {
        if ((bool) ($cfg['enabled'] ?? false) === false) {
            throw new ApiException(400, 'gateway_unavailable', 'درگاه «' . $this->label() . '» فعال یا پیکربندی نشده است.');
        }
    }

    private function label(): string
    {
        $names = [
            'digipay' => 'دیجی‌پی',
            'tara'    => 'تارا',
            'lendo'   => 'لندو',
            'ghasta'  => 'قسطا',
            'samand'  => 'سمند',
        ];
        return $names[(string) $this->gatewayId] ?? (string) $this->gatewayId;
    }
}
