<?php

declare(strict_types=1);

namespace Webiro;

/**
 * Structured API exception → converted to a JSON error response by the Router.
 */
class ApiException extends \RuntimeException
{
    /** @var int */
    private $httpStatus;

    /** @var string */
    private $errorCode;

    /** @var array<string,mixed> */
    private $extra;

    public function __construct(int $httpStatus, string $code, string $message, array $extra = [])
    {
        parent::__construct($message, 0, null);
        $this->httpStatus = $httpStatus;
        $this->errorCode  = $code;
        $this->extra      = $extra;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string,mixed>
     */
    public function extra(): array
    {
        return $this->extra;
    }

    /**
     * Convenience for "this gateway has no credentials in config yet".
     */
    public static function driverNotConfigured(string $label): self
    {
        return new self(502, 'driver_error', 'درگاه ' . $label . ' در پیکربندی گیت‌وی تنظیم نشده است.');
    }

    /**
     * Convenience for "the bank rejected the request" with its own code.
     */
    public static function gatewayRejected(string $label, int|string $code, string $detail = ''): self
    {
        $message = 'درگاه ' . $label . ' درخواست را نپذیرفت. کد: ' . $code;
        if ($detail !== '') {
            $message .= ' — ' . $detail;
        }
        return new self(502, 'gateway_rejected', $message);
    }
}
