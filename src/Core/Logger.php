<?php

declare(strict_types=1);

namespace PayViro\Core;

/**
 * Minimal file logger:  logs/app-Y-m-d.log
 * Lines:  [Y-m-d H:i:s] LEVEL: message {"ctx":"json"}
 * Secret values are masked automatically.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            $min = (string) Config::get('log.level', 'info');
            if (!isset(self::LEVELS[$level]) || !isset(self::LEVELS[$min])) {
                $min = 'info';
            }
            if (self::LEVELS[$level] < self::LEVELS[$min]) {
                return;
            }
            $dir = (string) Config::get('log.dir', PV_ROOT . '/logs');
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . '/app-' . date('Y-m-d') . '.log';
            $line = sprintf(
                "[%s] %s: %s %s\n",
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $message,
                $context === [] ? '' : json_encode(self::mask($context), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            );
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
            self::rotate($dir);
        } catch (\Throwable $e) {
            // Logging must never break the payment flow.
        }
    }

    private static function rotate(string $dir): void
    {
        $max = (int) Config::get('log.max_files', 30);
        if ($max <= 0) {
            return;
        }
        static $lastRun = 0;
        if (time() - $lastRun < 3600) {
            return;
        }
        $lastRun = time();
        $files = glob($dir . '/app-*.log') ?: [];
        if (count($files) <= $max) {
            return;
        }
        sort($files);
        $remove = count($files) - $max;
        foreach ($files as $file) {
            if ($remove-- <= 0) {
                break;
            }
            @unlink($file);
        }
    }

    private static function mask(array $data): array
    {
        $secretKeys = ['password', 'secret', 'api_key', 'merchant_password', 'authorization'];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::mask($value);
                continue;
            }
            if (is_string($key) && in_array(strtolower($key), $secretKeys, true) && $value !== '') {
                $data[$key] = wg_mask_secret($value);
            }
        }
        return $data;
    }
}
