<?php
/**
 * ------------------------------------------------------------------
 *  PayViro — PHP SDK (سمت دامنه‌های پذیرنده)
 * ------------------------------------------------------------------
 *  این فایل را در پروژه‌ی هر دامنه (مثلاً lahze-app.ir) کپی کنید و
 *  بدون هیچ وابستگی خارجی استفاده کنید. PHP 7.4+ با cURL کافی است.
 *
 *  مستندات کامل: https://webiro.ir/gateway/docs  |  PayViro — درگاه پرداخت مرکزی
 */

declare(strict_types=1);

class PayViroException extends RuntimeException
{
    /** @var int HTTP status returned by the gateway */
    private $httpStatus;

    /** @var string|null */
    private $errorCode;

    public function __construct(string $message, int $httpStatus = 0, ?string $errorCode = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode  = $errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}

class PayViroSignatureException extends PayViroException
{
}

class PayViro
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $secret;

    /** @var int */
    private $timeout;

    public function __construct(string $baseUrl, string $apiKey, string $secret, int $timeout = 20)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->secret  = $secret;
        $this->timeout = $timeout;
    }

    /* ------------------------------------------------------------------
     |  ۱) ایجاد پرداخت
     |  $fields = [
     |      'amount'        => 2500000,            // ریال (یا with 'currency' => 'toman')
     |      'callback_url'  => 'https://lahze-app.ir/payment/result',
     |      'order_id'      => 'ORD-123',
     |      'description'   => 'خرید اشتراک یک‌ساله',
     |      'mobile'        => '09121234567',      // اختیاری
     |      'gateway'       => 'zarinpal',         // اختیاری: zarinpal|zibal|saman|bluebank|auto
     |      'webhook_url'   => '...',              // اختیاری
     |      'idempotency_key' => '...',            // اختیاری
     |  ]
     * ----------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed> شامل token و payment_url
     */
    public function requestPayment(array $fields): array
    {
        $response = $this->api('POST', '/api/v1/payments', $fields);
        return $response['data'] ?? [];
    }

    /**
     * کاربر را مستقیم به صفحه پرداخت می‌فرستد (هدر Location ارسال می‌شود و اسکریپت تمام می‌شود).
     *
     * @param array<string,mixed> $fields
     */
    public function redirect(array $fields): void
    {
        $payment = $this->requestPayment($fields);
        header('Location: ' . $payment['payment_url']);
        exit;
    }

    /* ------------------------------------------------------------------
     |  ۲) استعلام وضعیت
     * ----------------------------------------------------------------- */

    /**
     * @return array<string,mixed>
     */
    public function status(string $token): array
    {
        $response = $this->api('GET', '/api/v1/payments/' . rawurlencode($token), null);
        return $response['data'] ?? [];
    }

    /* ------------------------------------------------------------------
     |  ۳) تایید بازگشت کاربر از بانک (در callback_url شما)
     |  پارامتر sig را با کلید رمز پذیرنده بررسی می‌کند؛ در صورت دستکاری،
     |  WebiroSignatureException پرتاب می‌شود.
     * ----------------------------------------------------------------- */

    /**
     * @param array<string,mixed>|null $query پیش‌فرض $_GET
     *
     * @return array<string,mixed> ['ok'=>bool, 'status'=>'paid', 'token'=>..., 'order_id'=>..., ...]
     */
    public function verifyRedirect(?array $query = null): array
    {
        $query = $query ?? $_GET;

        $signature = (string) ($query['sig'] ?? '');
        if ($signature === '') {
            throw new WebiroSignatureException('امضای بازگشت پرداخت (sig) موجود نیست.', 0, 'missing_signature');
        }

        $parts = [
            (string) ($query['token'] ?? ''),
            (string) ($query['order_id'] ?? ''),
            (string) ($query['amount'] ?? ''),
            (string) ($query['status'] ?? ''),
            (string) ($query['ref_no'] ?? ''),
            (string) ($query['card_no'] ?? ''),
            (string) ($query['gateway'] ?? ''),
        ];
        $expected = hash_hmac('sha256', implode('|', $parts), $this->secret);

        if (!hash_equals($expected, $signature)) {
            throw new WebiroSignatureException('امضای بازگشت پرداخت نامعتبر است — احتمال دستکاری پارامترها.', 0, 'invalid_signature');
        }

        return [
            'ok'       => $query['status'] === 'paid',
            'status'   => (string) $query['status'],
            'token'    => (string) $query['token'],
            'order_id' => (string) $query['order_id'],
            'amount'   => (int) $query['amount'],
            'gateway'  => (string) $query['gateway'],
            'ref_no'   => (string) $query['ref_no'],
            'card_no'  => (string) $query['card_no'],
            'message'  => (string) ($query['message'] ?? ''),
        ];
    }

    /* ------------------------------------------------------------------
     |  ۴) دریافت و تایید وبهوک (در webhook_url شما)
     |  هدر X-Webiro-Signature روی بدنه‌ی خام بررسی می‌شود.
     * ----------------------------------------------------------------- */

    /**
     * @return array<string,mixed> ['event'=>..., 'data'=>[...]]
     */
    public function readWebhook(?string $rawBody = null, ?string $signatureHeader = null): array
    {
        $rawBody   = $rawBody ?? (string) file_get_contents('php://input');
        $signature = $signatureHeader ?? (string) ($_SERVER['HTTP_X_WEBIRO_SIGNATURE'] ?? '');

        if ($signature === '') {
            throw new WebiroSignatureException('هدر امضای وبهوک وجود ندارد.', 0, 'missing_signature');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->secret);
        if (!hash_equals($expected, $signature)) {
            throw new WebiroSignatureException('امضای وبهوک نامعتبر است.', 0, 'invalid_signature');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new WebiroGatewayException('بدنه وبهوک JSON معتبر نیست.', 0, 'invalid_body');
        }
        return $decoded;
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    private function api(string $method, string $path, ?array $body): array
    {
        $ch = curl_init();
        $headers = ['X-API-Key: ' . $this->apiKey, 'Accept: application/json'];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'PayViro-SDK/1.0',
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new WebiroGatewayException('اتصال به گیت‌وی برقرار نشد: ' . $error, 0, 'connection_failed');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new WebiroGatewayException('پاسخ نامعتبر از گیت‌وی (HTTP ' . $status . ')', $status, 'invalid_response');
        }

        if ($status >= 400 || ($decoded['status'] ?? '') === 'error') {
            $error = $decoded['error'] ?? [];
            throw new WebiroGatewayException(
                (string) ($error['message'] ?? 'خطای ناشناخته از گیت‌وی'),
                $status,
                (string) ($error['code'] ?? 'unknown')
            );
        }

        return $decoded;
    }
}
