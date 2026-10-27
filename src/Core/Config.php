<?php

declare(strict_types=1);

namespace PayViro\Core;

use PayViro\ApiException;

/**
 * Configuration loader — reads config/config.php once.
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static $data = null;

    /** @var array<string,array<string,mixed>> merchant lookup cache keyed by api_key */
    private static $merchantIndex = null;

    public static function load(): void
    {
        if (self::$data !== null) {
            return;
        }
        $file = PV_ROOT . '/config/config.php';
        if (!is_file($file)) {
            throw new ApiException(
                500,
                'config_missing',
                'فایل پیکربندی یافت نشد. فایل config/config.example.php را به config/config.php کپی و تکمیل کنید.'
            );
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new ApiException(500, 'config_invalid', 'فایل پیکربندی نامعتبر است.');
        }
        self::$data = $data;

        $tz = (string) self::get('app.timezone', 'Asia/Tehran');
        if (@date_default_timezone_set($tz) === false) {
            date_default_timezone_set('Asia/Tehran');
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::load();
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /**
     * Find a merchant config by its API key.
     *
     * @return array<string,mixed>|null
     */
    public static function merchantByApiKey(string $apiKey): ?array
    {
        self::load();
        if (self::$merchantIndex === null) {
            self::$merchantIndex = [];
            $merchants = (array) self::get('merchants', []);
            foreach ($merchants as $id => $merchant) {
                if (!is_array($merchant)) {
                    continue;
                }
                $merchant['_id'] = (string) $id;
                $key = (string) ($merchant['api_key'] ?? '');
                if ($key !== '') {
                    self::$merchantIndex[$key] = $merchant;
                }
            }
        }
        return self::$merchantIndex[$apiKey] ?? null;
    }

    public static function base(): string
    {
        return rtrim((string) self::get('app.base_url', ''), '/');
    }
}
