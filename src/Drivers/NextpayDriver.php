<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * NextPay driver — Iranian payment aggregator (nextpay.org).
 *
 * Amount unit is configurable: NextPay documents Rial (default here);
 * some merchant panels settle in Toman — switch via config if needed.
 */
final class NextpayDriver extends AbstractRestDriver
{
    protected const ID = 'nextpay';
    private const API = 'https://nextpay.org/nx/gateway';

    public function createPayment(array $txn): array
    {
        self::requireCredential('api_key', 'نکست‌پی');

        $response = HttpClient::postJson(self::API . '/token', [
            'api_key'      => (string) self::cfg()['api_key'],
            'order_id'     => (string) $txn['token'],
            'amount'       => self::amount($txn, (string) self::cfg()['amount_unit'] ?? 'rial'),
            'callback_uri' => Config::base() . '/callback/' . $txn['token'],
            'currency'     => (string) (self::cfg()['currency'] ?? 'IRR'),
            'customer_phone' => (string) ($txn['mobile'] ?? ''),
            'custom_json_fields' => (string) ($txn['description'] ?? ''),
        ]);

        $json = $response['json'] ?? [];
        $code = (int) ($json['code'] ?? 1);

        if ($code !== -1 || empty($json['trans_id'])) {
            Logger::warning('nextpay.request.failed', ['code' => $code, 'message' => $json['message'] ?? '']);
            throw \Webiro\ApiException::gatewayRejected('نکست‌پی', $code, (string) ($json['message'] ?? ''));
        }

        return [
            'bank_url'   => self::API . '/payment/' . $json['trans_id'],
            'bank_token' => (string) $json['trans_id'],
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $transId = (string) ($request->query['trans_id'] ?? $request->input('trans_id', ''));
        $raw = ['trans_id' => $transId];

        if ($transId === '') {
            return self::result(false, 'canceled', null, null, null, 'کاربر به درگاه نکست‌پی بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }

        $response = HttpClient::postJson(self::API . '/verify', [
            'api_key'  => (string) self::cfg()['api_key'],
            'trans_id' => $transId,
            'amount'   => self::amount($txn, (string) self::cfg()['amount_unit'] ?? 'rial'),
            'currency' => (string) (self::cfg()['currency'] ?? 'IRR'),
        ]);

        $json = $response['json'] ?? [];
        $code = (int) ($json['code'] ?? 1);
        $raw['verify_response'] = $json;

        if ($code !== 0) {
            $message = (string) ($json['message'] ?? '');
            $map = [
                0   => 'پرداخت با موفقیت تایید شد.',
                -51 => 'پرداخت ناموفق یا لغو شده است.',
                -52 => 'پرداخت قبلاً تایید شده است.',
                -53 => 'مبلغ پرداخت‌شده با مبلغ تراکنش مغایرت دارد.',
                -54 => 'مهلت تایید تراکنش گذشته است.',
            ];
            $status = $code === -51 ? 'canceled' : 'failed';
            return self::result(false, $status, $transId, null, null, $message !== '' ? $message : ($map[$code] ?? 'تایید تراکنش در نکست‌پی ناموفق بود. کد: ' . $code), $raw);
        }

        $verifiedAmount = (int) round((float) ($json['amount'] ?? 0));
        if ($verifiedAmount > 0 && $verifiedAmount !== self::amount($txn, (string) self::cfg()['amount_unit'] ?? 'rial')) {
            return self::result(false, 'failed', $transId, null, null, 'مبلغ تاییدشده با مبلغ تراکنش مغایرت دارد.', $raw);
        }

        $card = (string) ($json['card_number'] ?? '');
        return self::result(
            true,
            'paid',
            $transId,
            (string) ($json['Shaparak_Ref_Id'] ?? '') ?: null,
            $card !== '' ? self::maskCard($card) : null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }
}
