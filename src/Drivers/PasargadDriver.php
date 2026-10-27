<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * Pasargad Bank (PEP) driver — new-generation REST API.
 *
 * Base: https://pep.shaparak.ir/dorsa1
 *   1. POST /token/getToken            {username, password}          → Bearer token
 *   2. POST /api/payment/purchase      {invoice, invoiceDate, amount,
 *                                       serviceCode:8, serviceType:'PURCHASE',
 *                                       terminalNumber, callbackApi} → data.url
 *   3. Bank redirects back with ?invoiceId=…
 *   4. POST /api/payment/payment-inquiry       → authoritative status + amount
 *   5. POST /api/payment/confirm-transactions  → card number, ref numbers
 *
 * Amounts in Rial. Invoice numbers must be unique and numeric.
 */
final class PasargadDriver extends AbstractRestDriver
{
    protected const ID = 'pasargad';

    /** @var string|null cached bearer token for the lifetime of the request */
    private static $bearer = null;

    public function createPayment(array $txn): array
    {
        $cfg = self::cfg();
        $base = rtrim((string) ($cfg['base_url'] ?? 'https://pep.shaparak.ir/dorsa1'), '/');
        $username = self::requireCredential('username', 'پاسارگاد');
        $password = (string) ($cfg['password'] ?? '');
        $terminal = (string) ($cfg['terminal_number'] ?? '');
        if ($terminal === '' || strpos($terminal, 'CHANGE_ME') === 0) {
            throw \Webiro\ApiException::driverNotConfigured('پاسارگاد (شماره ترمینال)');
        }

        // Unique numeric invoice number — our token is hex, so hash it.
        $invoice = (string) abs(crc32((string) $txn['token'])) . substr((string) time(), -6);
        $invoiceDate = date('Y/m/d H:i:s'); // Tehran timezone is set in config

        $purchase = self::api($base, '/api/payment/purchase', [
            'invoice'       => $invoice,
            'invoiceDate'   => $invoiceDate,
            'amount'        => self::amount($txn, 'rial'),
            'serviceCode'   => 8, // 8 = PURCHASE
            'serviceType'   => 'PURCHASE',
            'terminalNumber' => $terminal,
            'callbackApi'   => Config::base() . '/callback/' . $txn['token'],
        ], $username, $password);

        $paymentUrl = (string) ($purchase['data']['url'] ?? '');
        if ($paymentUrl === '') {
            throw new \RuntimeException('بانک پاسارگاد آدرس صفحه پرداخت را برنگرداند.');
        }

        return [
            'bank_url'   => $paymentUrl,
            'bank_token' => $invoice,
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = self::cfg();
        $base = rtrim((string) ($cfg['base_url'] ?? 'https://pep.shaparak.ir/dorsa1'), '/');

        $invoiceId = (string) ($request->query['invoiceId'] ?? $request->input('invoiceId', ''));
        $raw = ['invoiceId' => $invoiceId];

        // Security binding: the invoiceId echoed back must be ours.
        if ($invoiceId === '' || (string) ($txn['bank_token'] ?? '') !== $invoiceId) {
            Logger::warning('pasargad.invoice_mismatch', ['expected' => $txn['bank_token'] ?? '', 'got' => $invoiceId]);
            return self::result(false, 'rejected', null, null, null, 'شناسه فاکتور بازگشتی از بانک معتبر نیست.', $raw);
        }

        // ---- 1) inquiry: authoritative status + amount ----
        $inquiry = self::api($base, '/api/payment/payment-inquiry', ['invoiceId' => $invoiceId], (string) ($cfg['username'] ?? ''), $password);
        $raw['inquiry'] = $inquiry;
        $data = (array) ($inquiry['data'] ?? []);
        $inquiryStatus = $data['status'] ?? null;

        if ((int) ($inquiry['resultCode'] ?? -1) !== 0 || (int) $inquiryStatus !== 0) {
            $message = self::translateStatus($inquiryStatus ?? $inquiry['resultCode'] ?? null, (string) ($inquiry['resultMsg'] ?? ''));
            $isCanceled = in_array((int) $inquiryStatus, [13021, 13045, 13033], true);
            return self::result(false, $isCanceled ? 'canceled' : 'failed', $invoiceId, (string) ($data['referenceNumber'] ?? '') ?: null, null, $message, $raw);
        }

        $verifiedAmount = (int) round((float) ($data['amount'] ?? 0));
        if ($verifiedAmount > 0 && $verifiedAmount !== self::amount($txn, 'rial')) {
            return self::result(false, 'failed', $invoiceId, (string) ($data['referenceNumber'] ?? '') ?: null, null, 'مبلغ تاییدشده بانک با مبلغ تراکنش مغایرت دارد.', $raw);
        }

        // ---- 2) confirm: finalize the transaction on Pasargad's side ----
        $confirm = self::api($base, '/api/payment/confirm-transactions', [
            'invoice' => $invoiceId,
            'urlId'   => (string) ($data['url'] ?? ''),
        ], $username, $password);
        $raw['confirm'] = $confirm;

        $card = (string) ($confirm['data']['maskedCardNumber'] ?? ($data['maskedCardNumber'] ?? ''));
        return self::result(
            true,
            'paid',
            (string) ($data['transactionId'] ?? $invoiceId),
            (string) ($data['referenceNumber'] ?? '') ?: null,
            $card !== '' ? self::maskCard($card) : null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }

    /**
     * Authorized API call: fetch (and cache) a Bearer token, then POST JSON.
     *
     * @return array<string,mixed>
     */
    private static function api(string $base, string $path, array $body, string $username, string $password): array
    {
        if (self::$bearer === null) {
            $response = HttpClient::postJson($base . '/token/getToken', [
                'username' => $username,
                'password' => $password,
            ]);
            $json = $response['json'] ?? [];
            if ((int) ($json['resultCode'] ?? -1) !== 0 || empty($json['token'])) {
                Logger::warning('pasargad.token.failed', ['msg' => $json['resultMsg'] ?? '']);
                throw new \Webiro\ApiException(
                    502,
                    'gateway_rejected',
                    'درگاه پاسارگاد: احراز هویت ناموفق بود («' . (string) ($json['resultMsg'] ?? 'نامشخص') . '») — username/password پنل پذیرندگی را بررسی کنید.'
                );
            }
            self::$bearer = (string) $json['token'];
        }

        $response = HttpClient::request('POST', $base . $path, $body, [
            'Authorization' => 'Bearer ' . self::$bearer,
        ]);
        return $response['json'] ?? [];
    }

    /**
     * Human message for Pasargad result/inquiry status codes (subset).
     */
    private static function translateStatus($status, string $fallback = ''): string
    {
        $map = [
            0      => 'تراکنش موفق است',
            13016  => 'تراکنش یافت نشد',
            13021  => 'پرداخت از پیش لغو شده است',
            13022  => 'تراکنش پرداخت نشده است',
            13025  => 'تراکنش ناموفق است',
            13028  => 'آدرس بازگشت نامعتبر است',
            13029  => 'تراکنش موفق و تایید شده است',
            13031  => 'تراکنش منتظر تایید است',
            13032  => 'کارت منقضی شده است',
            13045  => 'دارنده کارت تراکنش را لغو کرد',
            13049  => 'تراکنش کامل نشده است',
            13062  => 'تراکنش تکراری است',
            13063  => 'موجودی کافی نیست',
            13071  => 'مبلغ تراکنش با مبلغ نهایی مطابقت ندارد',
            13084  => 'سوئیچ یا شاپرک در دسترس نیست',
            13085  => 'میزبان بانک در دسترس نیست',
            13301  => 'فروشگاه در سیستم تعریف نشده است',
            13303  => 'رمز ترمینال نامعتبر است',
            13399  => 'ترمینال یافت نشد',
            13407  => 'خطای احراز هویت',
            13412  => 'خطا در بازیابی اطلاعات',
        ];
        if ($status !== null && is_numeric((string) $status) && isset($map[(int) $status])) {
            return $map[(int) $status];
        }
        return $fallback !== '' ? $fallback : 'پرداخت در بانک پاسارگاد تایید نشد. کد: ' . (string) $status;
    }
}
