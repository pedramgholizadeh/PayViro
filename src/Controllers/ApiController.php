<?php

declare(strict_types=1);

namespace PayViro\Controllers;

use PayViro\ApiException;
use PayViro\Core\Config;
use PayViro\Core\Crypto;
use PayViro\Core\Database;
use PayViro\Core\Http\Request;
use PayViro\Core\Http\Response;
use PayViro\Core\RateLimiter;
use PayViro\Drivers\DriverFactory;
use PayViro\Models\Transaction;
use PayViro\Services\PaymentService;

/**
 * Server-to-server JSON API for merchant domains.
 */
final class ApiController
{
    public function health(Request $request, array $params): Response
    {
        $dbOk = false;
        try {
            Database::pdo()->query('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            // reported below
        }

        return Response::json([
            'status'  => 'success',
            'data'    => [
                'service'  => 'PayViro',
                'version'  => PV_VERSION,
                'time'     => wg_now(),
                'database' => $dbOk ? 'ok' : 'error',
                'gateways' => array_keys(array_filter(DriverFactory::enabled())),
            ],
        ]);
    }

    public function gateways(Request $request, array $params): Response
    {
        $enabled = DriverFactory::enabled();
        $list = [];
        foreach ($enabled as $gateway => $isEnabled) {
            if (!$isEnabled) {
                continue;
            }
            $list[] = [
                'id'       => $gateway,
                'name'     => DriverFactory::NAMES[$gateway] ?? $gateway,
                'type'     => DriverFactory::TYPES[$gateway] ?? 'bank',
            ];
        }

        return Response::json(['status' => 'success', 'data' => ['gateways' => $list, 'default' => (string) Config::get('payments.default_gateway', 'saman')]]);
    }

    public function create(Request $request, array $params): Response
    {
        $merchant = self::authenticate($request);

        $limit = (int) Config::get('payments.rate_limit', 60);
        if (!RateLimiter::allow('api:' . $request->ip(), $limit)) {
            throw new ApiException(429, 'rate_limited', 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.');
        }

        $data = PaymentService::create($request, $merchant);

        return Response::json(['status' => 'success', 'data' => $data], 201);
    }

    public function status(Request $request, array $params): Response
    {
        $merchant = self::authenticate($request);
        $token = (string) ($params['token'] ?? '');

        $txn = Transaction::findByToken($token);
        if ($txn === null || (string) $txn['merchant_id'] !== (string) $merchant['_id']) {
            throw new ApiException(404, 'not_found', 'تراکنش یافت نشد.');
        }

        return Response::json(['status' => 'success', 'data' => PaymentService::statusPayload($txn)]);
    }

    /**
     * Resolve the merchant from X-API-Key or "Authorization: Bearer <key>".
     *
     * @return array<string,mixed>
     */
    private static function authenticate(Request $request): array
    {
        $apiKey = $request->header('x-api-key');
        if ($apiKey === '' && strpos($request->header('authorization'), 'Bearer ') === 0) {
            $apiKey = trim(substr($request->header('authorization'), 7));
        }
        if ($apiKey === '') {
            throw new ApiException(401, 'unauthorized', 'کلید API ارسال نشده است. هدر X-API-Key را بفرستید.');
        }

        $merchant = Config::merchantByApiKey($apiKey);
        if ($merchant === null) {
            throw new ApiException(401, 'unauthorized', 'کلید API نامعتبر است.');
        }
        if ((bool) ($merchant['enabled'] ?? false) === false) {
            throw new ApiException(403, 'merchant_disabled', 'حساب پذیرنده غیرفعال است.');
        }

        return $merchant;
    }
}
