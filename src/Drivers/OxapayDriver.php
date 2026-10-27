<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Config;
use Webiro\Core\Crypto;
use Webiro\Core\Http\Request;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;

/**
 * OxaPay driver — crypto payment gateway (USDT/BTC/… over Tron, ERC20, …).
 *
 * Docs: https://docs.oxapay.com
 *
 * Flow:
 *   1. POST /v1/payment/invoice  →  payment_url on OxaPay's hosted page
 *   2. Payer pays in crypto; OxaPay calls our callback twice
 *      (status "Paying" → "Paid") with an HMAC-SHA512 signature header,
 *      and also redirects the payer back to our callback.
 *   3. We confirm via webhook body or the Payment Information API.
 *
 * Amount unit is driven by `currency` (default IRT = Toman, Rial-based
 * system amount divided by 10). Set any other fiat/crypto symbol and the
 * matching unit in `amount_unit` (rial|toman) — for USD-style currencies
 * the raw Rial amount must be converted by an exchange rate, which is out
 * of scope, so fiat IRT/IRR or crypto-stable setups are recommended.
 */
final class OxapayDriver extends AbstractRestDriver
{
    protected const ID = 'oxapay';
    private const API = 'https://api.oxapay.com/v1';

    public function createPayment(array $txn): array
    {
        $cfg = self::cfg();
        $merchantKey = self::requireCredential('merchant_key', 'کریپتو OxaPay');
        $currency = (string) ($cfg['currency'] ?? 'IRT');
        $unit = (string) ($cfg['amount_unit'] ?? 'toman');
        $ttl = (int) Config::get('payments.ttl_minutes', 20);
        $lifetime = max(15, min(2880, $ttl));

        $body = [
            'amount'           => self::cryptoAmount($txn, $unit),
            'currency'         => $currency,
            'lifetime'         => $lifetime,
            'callback_url'     => Config::base() . '/callback/' . $txn['token'],
            'return_url'       => Config::base() . '/callback/' . $txn['token'],
            'order_id'         => (string) $txn['token'],
            'description'      => (string) ($txn['description'] ?? 'پرداخت'),
            'fee_paid_by_payer' => (int) ($cfg['fee_paid_by_payer'] ?? 1),
            'sandbox'          => (bool) ($cfg['sandbox'] ?? false),
        ];
        if (!empty($cfg['network'])) {
            $body['network'] = (string) $cfg['network'];
        }

        $response = HttpClient::postJson(self::API . '/payment/invoice', $body, [
            'merchant_api_key' => $merchantKey,
        ]);

        $json = $response['json'] ?? [];

        if ((int) ($json['status'] ?? 0) !== 200 || empty($json['data']['payment_url'])) {
            $error = (array) ($json['error'] ?? []);
            $detail = (string) ($error['message'] ?? $json['message'] ?? '');
            Logger::warning('oxapay.request.failed', ['status' => $response['status'], 'detail' => $detail]);
            throw \Webiro\ApiException::gatewayRejected('OxaPay', (int) ($json['status'] ?? $response['status']), $detail);
        }

        return [
            'bank_url'   => (string) $json['data']['payment_url'],
            'bank_token' => (string) $json['data']['track_id'],
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = self::cfg();
        $merchantKey = (string) ($cfg['merchant_key'] ?? '');
        $raw = [];

        // ---- Case A: OxaPay webhook (JSON body + HMAC-SHA512 header) ----
        if (is_array($request->json) && isset($request->json['track_id'])) {
            $raw = $request->json;
            $signature = (string) $request->header('hmac');
            $expected = hash_hmac('sha512', $request->rawBody, $merchantKey);
            if ($signature === '' || !hash_equals($expected, $signature)) {
                Logger::warning('oxapay.webhook.bad_signature', ['token' => $txn['token']]);
                // 'rejected' = ignore this call entirely; the transaction must
                // stay pending so a forged callback cannot kill a real payment.
                return self::result(false, 'rejected', null, null, null, 'امضای وبهوک OxaPay نامعتبر است.', $raw);
            }
            $trackId = (string) $request->json['track_id'];
            return self::judge($txn, $trackId, (float) ($request->json['amount'] ?? 0), $raw);
        }

        // ---- Case B: payer redirected back (no body) → ask the API ----
        $trackId = (string) ($request->query['track_id'] ?? ($txn['bank_token'] ?? ''));
        if ($trackId === '') {
            return self::result(false, 'canceled', null, null, null, 'شناسه تراکنش کریپتو دریافت نشد.', $raw);
        }

        $response = HttpClient::request('GET', self::API . '/payment/' . rawurlencode($trackId), null, [
            'merchant_api_key' => $merchantKey,
        ]);
        $json = $response['json'] ?? [];
        $raw['info_response'] = $json;

        if ((int) ($json['status'] ?? 0) !== 200 || !is_array($json['data'] ?? null)) {
            return self::result(false, 'pending', $trackId, null, null, 'استعلام وضعیت پرداخت کریپتو هنوز ممکن نشد؛ دوباره بررسی می‌شود.', $raw);
        }

        return self::judge($txn, $trackId, (float) ($json['data']['amount'] ?? 0), $raw);
    }

    /**
     * Map an OxaPay status + amount to our transaction verdict.
     *
     * @param array<string,mixed> $txn
     * @param array<string,mixed> $raw
     *
     * @return array<string,mixed>
     */
    private function judge(array $txn, string $trackId, float $paidAmount, array $raw): array
    {
        $status = strtolower((string) ($raw['status'] ?? ''));

        if (in_array($status, ['paid', 'completed'], true)) {
            if ($paidAmount > 0) {
                $expected = self::cryptoAmount($txn, (string) ($this->cfg()['amount_unit'] ?? 'toman'));
                if (abs($paidAmount - $expected) > 0.5) {
                    return self::result(false, 'failed', $trackId, null, null, 'مبلغ پرداخت‌شده کریپتو با مبلغ تراکنش مغایرت دارد.', $raw);
                }
            }
            $txHash = '';
            if (isset($raw['txs'][0]['tx_hash'])) {
                $txHash = (string) $raw['txs'][0]['tx_hash'];
            }
            return self::result(
                true,
                'paid',
                $trackId,
                $txHash !== '' ? mb_substr($txHash, 0, 120) : null,
                null,
                'پرداخت کریپتو با موفقیت تایید شد.',
                $raw
            );
        }

        if (in_array($status, ['expired', 'canceled', 'cancelled'], true)) {
            return self::result(false, 'canceled', $trackId, null, null, 'پرداخت کریپتو لغو یا منقضی شد.', $raw);
        }
        if (in_array($status, ['failed', 'invalid'], true)) {
            return self::result(false, 'failed', $trackId, null, null, 'پرداخت کریپتو ناموفق بود.', $raw);
        }

        // paying / pending / confirming → wait for the next webhook
        return self::result(false, 'pending', $trackId, null, null, 'پرداخت کریپتو در حال انجام است و منتظر تایید شبکه است.', $raw);
    }

    /**
     * Amount in the configured currency's unit (Toman for IRT by default).
     */
    private static function cryptoAmount(array $txn, string $unit): float
    {
        $rial = (int) $txn['amount_rial'];
        return $unit === 'toman' ? round($rial / 10, 2) : (float) $rial;
    }

    /**
     * OxaPay webhooks must be answered with plain 200 "ok".
     */
    public function wantsPlainAck(Request $request): bool
    {
        if ($request->hasHeader('hmac')) {
            return true;
        }
        return is_array($request->json) && isset($request->json['track_id']);
    }
}
