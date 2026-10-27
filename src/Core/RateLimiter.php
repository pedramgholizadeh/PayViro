<?php

declare(strict_types=1);

namespace PayViro\Core;

/**
 * Simple per-minute rate limiter backed by the database.
 */
final class RateLimiter
{
    /**
     * Count one hit for a bucket; returns true when the caller is allowed.
     */
    public static function allow(string $bucket, int $limit): bool
    {
        if ($limit <= 0) {
            return true;
        }
        $key    = md5($bucket);
        $window = date('YmdHi');
        $isMysql = (string) Config::get('db.driver', 'sqlite') === 'mysql';

        if ($isMysql) {
            Database::run(
                'INSERT INTO rate_limits (bucket, window_start, count) VALUES (:bucket, :window, 1)
                 ON DUPLICATE KEY UPDATE
                    count = IF(window_start = :window2, count + 1, 1),
                    window_start = :window3',
                [
                    'bucket'  => $key,
                    'window'  => $window,
                    'window2' => $window,
                    'window3' => $window,
                ]
            );
        } else {
            Database::run(
                'INSERT INTO rate_limits (bucket, window_start, count) VALUES (:bucket, :window, 1)
                 ON CONFLICT(bucket) DO UPDATE SET
                    count = CASE WHEN window_start = :window2 THEN count + 1 ELSE 1 END,
                    window_start = :window3',
                [
                    'bucket'  => $key,
                    'window'  => $window,
                    'window2' => $window,
                    'window3' => $window,
                ]
            );
        }

        $row = Database::one('SELECT window_start, count FROM rate_limits WHERE bucket = :bucket', ['bucket' => $key]);
        if ($row === null) {
            return true;
        }
        if ((string) $row['window_start'] !== $window) {
            return true;
        }
        return (int) $row['count'] <= $limit;
    }
}
