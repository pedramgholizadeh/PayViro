<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\Core\Http\Request;

/**
 * Contract for every payment driver.
 *
 * createPayment()  → the gateway initiates the payment with the bank and
 *                    returns how the user's browser must reach the bank page.
 *
 * verifyPayment()  → the bank has redirected the user back to
 *                    /callback/{token}; the driver validates the result
 *                    server-to-server and reports the outcome.
 */
interface DriverInterface
{
    /**
     * @param array<string,mixed> $txn transaction row
     *
     * @return array{
     *     bank_url:   string,
     *     bank_token: ?string,
     *     redirect:   string,            'location' | 'form_post'
     *     fields:     array<string,string>  form fields for 'form_post'
     * }
     */
    public function createPayment(array $txn): array;

    /**
     * @param array<string,mixed> $txn
     *
     * @return array{
     *     success: bool,
     *     status:  string,   paid | failed | canceled | pending
     *     ref_no:  ?string,
     *     rrn:     ?string,
     *     card_no: ?string,
     *     message: string,
     *     raw:     array<string,mixed>
     * }
     */
    public function verifyPayment(array $txn, Request $request): array;
}
