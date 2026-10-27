<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * Sepordeh driver (sepordeh.com) — Iranian payment aggregator.
 *
 * Amounts in TOMAN. Purchase/verify are form-encoded POSTs returning JSON
 * with status 200 on success.
 */
final class SepordehDriver extends AbstractRestDriver
{
    protected const ID = 'sepordeh';

    public function createPayment(array $txn): array
    {
        $cfg = self::cfg();
        $merchant = self::requireCredential('merchant', 'سپرده');
        $purchaseUrl = (string) ($cfg['purchase_url'] ?? 'https://sepordeh.com/merchant/invoices/add');
        $payBase = (string) ($cfg['payment_url'] ?? 'https://sepordeh.com/merchant/invoices/pay/id:');
        $payBaseDirect = (string) ($cfg['payment_url_direct'] ?? 'https://sepordeh.com/merchant/invoices/pay/automatic:true/id:');
        $direct = (bool) ($cfg['direct_payment'] ?? false);

        $response = HttpClient::postForm($purchaseUrl, [
            'merchant'    => $merchant,
            'amount'      => self::amount($txn, 'toman'),
            'orderId'     => (string) $txn['token'],
            'phone'       => (string) ($txn['mobile'] ?? ''),
            'callback'    => Config::base() . '/callback/' . $txn['token'],
            'description' => (string) ($txn['description'] ?? 'پرداخت'),
        ]);

        $json = $response['json'] ?? [];

        if ((int) ($json['status'] ?? 0) !== 200 || empty($json['information']['invoice_id'])) {
            Logger::warning('sepordeh.request.failed', ['status' => $response['status'], 'message' => $json['message'] ?? '']);
            throw \Webiro\ApiException::gatewayRejected('سپرده', (int) ($json['status'] ?? $response['status']), (string) ($json['message'] ?? ''));
        }

        $invoiceId = (string) $json['information']['invoice_id'];

        return [
            'bank_url'   => ($direct ? $payBaseDirect : $payBase) . $invoiceId,
            'bank_token' => $invoiceId,
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = self::cfg();
        $verifyUrl = (string) ($cfg['verify_url'] ?? 'https://sepordeh.com/merchant/invoices/verify');

        $authority = (string) ($request->query['authority'] ?? $request->input('authority', ''));
        $orderId   = (string) ($request->query['orderId'] ?? $request->input('orderId', ''));
        $raw = ['authority' => $authority, 'order_id' => $orderId];

        // Security binding: Sepordeh echoes our token as orderId.
        if ($orderId !== '' && $orderId !== (string) $txn['token']) {
            Logger::warning('sepordeh.order_mismatch', ['expected' => $txn['token'], 'got' => $orderId]);
            return self::result(false, 'failed', $authority ?: null, null, null, 'تطبیق شناسه سفارش با درگاه ناموفق بود.', $raw);
        }

        $invoiceId = $authority !== '' ? $authority : (string) ($txn['bank_token'] ?? '');
        if ($invoiceId === '') {
            return self::result(false, 'canceled', null, null, null, 'کاربر به درگاه سپرده بازنگشت یا پرداخت تکمیل نشد.', $raw);
        }

        $response = HttpClient::postForm($verifyUrl, [
            'merchant'  => (string) ($cfg['merchant'] ?? ''),
            'authority' => $invoiceId,
        ]);

        $json = $response['json'] ?? [];
        $raw['verify_response'] = $json;

        if ((int) ($json['status'] ?? 0) !== 200) {
            $message = (string) ($json['message'] ?? '');
            return self::result(false, 'failed', $invoiceId, null, null, $message !== '' ? $message : 'تایید تراکنش در سپرده ناموفق بود.', $raw);
        }

        $card = (string) ($json['information']['card'] ?? '');
        return self::result(
            true,
            'paid',
            (string) ($json['information']['invoice_id'] ?? $invoiceId),
            null,
            $card !== '' ? self::maskCard($card) : null,
            'پرداخت با موفقیت تایید شد.',
            $raw
        );
    }
}
