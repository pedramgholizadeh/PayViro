<?php

declare(strict_types=1);

namespace PayViro\Core;

/**
 * HMAC signing / token generation helpers.
 *
 * - Redirect params are signed with:  HMAC-SHA256 over "a|b|c" canonical string
 * - Webhook bodies are signed with:   HMAC-SHA256 over the raw JSON body
 * - Header format:  sha256=<hex>
 */
final class Crypto
{
    public const API_KEY_PREFIX  = 'wgr_';
    public const SECRET_PREFIX   = 'wgs_';
    public const TOKEN_PREFIX    = 'pay_';

    public static function generateApiKey(): string
    {
        return self::API_KEY_PREFIX . bin2hex(random_bytes(20));
    }

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX . bin2hex(random_bytes(24));
    }

    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(16));
    }

    /**
     * @param array<int,string> $parts
     */
    public static function signParts(string $secret, array $parts): string
    {
        return hash_hmac('sha256', implode('|', $parts), $secret);
    }

    /**
     * @param array<int,string> $parts
     */
    public static function verifyParts(string $secret, array $parts, string $signature): bool
    {
        return hash_equals(self::signParts($secret, $parts), $signature);
    }

    public static function signBody(string $secret, string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    public static function verifyBody(string $secret, string $rawBody, string $signature): bool
    {
        return hash_equals(self::signBody($secret, $rawBody), $signature);
    }
}
