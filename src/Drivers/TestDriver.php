<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\Core\Config;
use PayViro\Core\Http\Request;

/**
 * Built-in sandbox driver — a fake bank page for end-to-end testing
 * without real money. Only active when drivers.test.enabled = true.
 *
 * Sandbox page:  GET /go/{token}/sandbox
 * Actions:       POST /go/{token}/sandbox/pay | failed | cancel
 */
final class TestDriver implements DriverInterface
{
    public function createPayment(array $txn): array
    {
        return [
            'bank_url'   => Config::base() . '/go/' . $txn['token'] . '/sandbox',
            'bank_token' => 'test_' . $txn['token'],
            'redirect'   => 'location',
            'fields'     => [],
        ];
    }

    public function verifyPayment(array $txn, Request $request): array
    {
        $sim   = (string) $request->input('sim', 'success');
        $raw   = ['sandbox' => true, 'sim' => $sim];

        if ($sim === 'cancel') {
            return ['success' => false, 'status' => 'canceled', 'ref_no' => null, 'rrn' => null, 'card_no' => null, 'message' => 'پرداخت در سندباکس لغو شد.', 'raw' => $raw];
        }
        if ($sim === 'failed') {
            return ['success' => false, 'status' => 'failed', 'ref_no' => null, 'rrn' => null, 'card_no' => null, 'message' => 'پرداخت در سندباکس ناموفق بود.', 'raw' => $raw];
        }

        return [
            'success' => true,
            'status'  => 'paid',
            'ref_no'  => 'TEST-' . strtoupper(bin2hex(random_bytes(5))),
            'rrn'     => 'SBOX' . random_int(100000, 999999),
            'card_no' => '6104-******1234',
            'message' => 'پرداخت آزمایشی با موفقیت تایید شد.',
            'raw'     => $raw,
        ];
    }
}
