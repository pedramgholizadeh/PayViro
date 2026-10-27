<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\ApiException;
use PayViro\Core\Config;
use PayViro\Core\Http\Request;
use PayViro\Core\Logger;
use PayViro\Core\Soap;

/**
 * Saman Bank (SEP) driver — classic token-based flow.
 *
 *   1. RequestToken(MID, ResNum, Amount, CellNumber)  via InitPayment.asmx
 *   2. Auto-POST form → payment.aspx  (Token + RedirectUrl)
 *   3. Saman POSTs back: State, StateCode, RefNum, ResNum, TraceNo, RRN, SecurePan
 *   4. verifyTransaction(RefNum, ...) via ReferencePayment.asmx
 *
 * Amounts are in Rial.
 */
final class SamanDriver implements DriverInterface
{
    public function createPayment(array $txn): array
    {
        $cfg = (array) Config::get('drivers.saman', []);
        $merchantId = (string) ($cfg['merchant_id'] ?? '');
        $wsdl = (string) ($cfg['wsdl_purchase'] ?? '');

        if ($merchantId === '' || strpos($merchantId, 'CHANGE_ME') === 0 || $wsdl === '') {
            throw new ApiException(502, 'driver_error', 'درگاه سامان در پیکربندی گیت‌وی تنظیم نشده است.');
        }

        $result = Soap::call($wsdl, 'RequestToken', [
            'MID'        => $merchantId,
            'ResNum'     => (string) $txn['token'],
            'Amount'     => (string) $txn['amount_rial'], // Rial
            'CellNumber' => (string) ($txn['mobile'] ?? ''),
        ]);

        $token = Soap::extractScalar($result);
        if ($token === '') {
            throw new ApiException(502, 'gateway_rejected', 'دریافت توکن از بانک سامان ناموفق بود.');
        }

        return [
            'bank_url'   => (string) ($cfg['payment_url'] ?? 'https://sep.shaparak.ir/payment.aspx'),
            'bank_token' => $token,
            'redirect'   => 'form_post',
            'fields'     => [
                'Token'       => $token,
                'RedirectUrl' => Config::base() . '/callback/' . $txn['token'],
            ],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $cfg = (array) Config::get('drivers.saman', []);

        $state     = (string) $request->input('State', '');
        $stateCode = (string) $request->input('StateCode', '');
        $refNum    = (string) $request->input('RefNum', '');
        $resNum    = (string) $request->input('ResNum', '');
        $traceNo   = (string) $request->input('TraceNo', '');
        $rrn       = (string) $request->input('RRN', '');
        $securePan = (string) $request->input('SecurePan', '');
        $cardNo    = (string) $request->input('PAN', '');

        $raw = [
            'State' => $state, 'StateCode' => $stateCode, 'RefNum' => $refNum,
            'ResNum' => $resNum, 'TraceNo' => $traceNo, 'RRN' => $rrn, 'SecurePan' => $securePan,
        ];

        // Security binding: ResNum must be OUR token — blocks parameter injection.
        if ($resNum !== (string) $txn['token']) {
            Logger::warning('saman.resnum_mismatch', ['expected' => $txn['token'], 'got' => $resNum]);
            return self::result(false, 'failed', null, null, null, 'تطبیق شناسه تراکنش با بانک ناموفق بود.', $raw);
        }

        if ($state === 'Canceled' || $state === 'Cancel' || strtoupper($state) === 'CANCELED') {
            return self::result(false, 'canceled', null, null, null, 'پرداخت توسط کاربر لغو شد.', $raw);
        }
        if ($state !== 'OK' || (int) $stateCode !== 0) {
            return self::result(false, 'failed', null, null, null, 'پرداخت ناموفق بود. وضعیت بانک: ' . ($state ?: 'نامشخص') . ' (' . $stateCode . ')', $raw);
        }
        if ($refNum === '') {
            return self::result(false, 'failed', null, null, null, 'شناسه مرجع (RefNum) از بانک دریافت نشد.', $raw);
        }

        // ---- Server-to-server verification ----
        try {
            $verifyMode = (string) ($cfg['verify_mode'] ?? 'simple');
            $args = $verifyMode === 'classic'
                ? [
                    'StringRefNum' => $refNum,
                    'TerminalId'   => (string) ($cfg['terminal_id'] ?? ''),
                    'MerchantId'   => (string) ($cfg['merchant_id'] ?? ''),
                    'Password'     => (string) ($cfg['password'] ?? ''),
                    'Amount'       => (string) $txn['amount_rial'],
                ]
                : [
                    'RefNum'     => $refNum,
                    'MerchantId' => (string) ($cfg['merchant_id'] ?? ''),
                ];

            $verified = Soap::call((string) ($cfg['wsdl_verify'] ?? ''), 'verifyTransaction', $args);
            $verifiedAmount = (int) round((float) Soap::extractScalar($verified));
        } catch (\Throwable $e) {
            // Bank unreachable right now → keep pending so the callback can be replayed.
            Logger::error('saman.verify.unreachable', ['token' => $txn['token'], 'error' => $e->getMessage()]);
            return self::result(false, 'pending', $refNum, $rrn, $securePan, 'ارتباط با وب‌سرویس تایید بانک سامان برقرار نشد؛ تایید بعداً تلاش می‌شود.', $raw);
        }

        $raw['verified_amount'] = $verifiedAmount;

        if ($verifiedAmount <= 0) {
            return self::result(false, 'failed', $refNum, $rrn, $securePan, 'بانک سامان تراکنش را تایید نکرد. کد: ' . $verifiedAmount, $raw);
        }
        if ($verifiedAmount !== (int) $txn['amount_rial']) {
            // Amount mismatch → try to reverse the payment (money must not stay taken).
            try {
                Soap::call((string) ($cfg['wsdl_verify'] ?? ''), 'reverseTransaction', [
                    'RefNum'         => $refNum,
                    'MerchantId'     => (string) ($cfg['merchant_id'] ?? ''),
                    'Password'       => (string) ($cfg['password'] ?? ''),
                    'verifiedAmount' => (string) $verifiedAmount,
                ]);
                $raw['reversed'] = true;
            } catch (\Throwable $e) {
                $raw['reversed'] = false;
                Logger::error('saman.reverse.failed', ['token' => $txn['token'], 'error' => $e->getMessage()]);
            }
            return self::result(false, 'failed', $refNum, $rrn, $securePan, 'مبلغ تاییدشده بانک با مبلغ تراکنش مغایرت دارد.', $raw);
        }

        return self::result(true, 'paid', $refNum, $rrn, $securePan !== '' ? $securePan : ($cardNo !== '' ? self::maskCard($cardNo) : null), 'پرداخت با موفقیت تایید شد.', $raw);
    }

    private static function maskCard(string $card): string
    {
        $digits = preg_replace('/\D/', '', $card) ?? '';
        if (strlen($digits) < 10) {
            return $card;
        }
        return substr($digits, 0, 6) . '******' . substr($digits, -4);
    }

    private static function result(bool $success, string $status, ?string $refNo, ?string $rrn, ?string $cardNo, string $message, array $raw): array
    {
        return [
            'success' => $success,
            'status'  => $status,
            'ref_no'  => $refNo,
            'rrn'     => $rrn,
            'card_no' => $cardNo,
            'message' => $message,
            'raw'     => $raw,
        ];
    }
}
