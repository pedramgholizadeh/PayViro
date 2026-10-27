<?php

declare(strict_types=1);

namespace Webiro\Core;

use PDO;
use RuntimeException;

/**
 * PDO wrapper (SQLite default / MySQL optional) with automatic schema
 * creation. Zero external dependencies — works on any cPanel host.
 */
final class Database
{
    /** @var PDO|null */
    private static $pdo = null;

    /** @var bool */
    private static $schemaReady = false;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            if (self::$schemaReady === false) {
                self::ensureSchema();
            }
            return self::$pdo;
        }

        $driver = (string) Config::get('db.driver', 'sqlite');

        if ($driver === 'mysql') {
            $cfg   = (array) Config::get('db.mysql', []);
            $dsn   = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($cfg['host'] ?? 'localhost'),
                (int) ($cfg['port'] ?? 3306),
                (string) ($cfg['dbname'] ?? 'webiro_gateway'),
                (string) ($cfg['charset'] ?? 'utf8mb4')
            );
            self::$pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } else {
            $path = (string) Config::get('db.sqlite_path', WG_ROOT . '/data/gateway.sqlite');
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA busy_timeout=5000');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
        }

        self::ensureSchema();
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): void
    {
        self::pdo()->prepare($sql)->execute($params);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    private static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $driver = (string) Config::get('db.driver', 'sqlite');
        $isMysql = $driver === 'mysql';

        $idPk   = $isMysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $transactions = "
            CREATE TABLE IF NOT EXISTS transactions (
                id {$idPk},
                token VARCHAR(64) NOT NULL UNIQUE,
                merchant_id VARCHAR(64) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                amount_rial BIGINT NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'rial',
                description VARCHAR(512) NULL,
                mobile VARCHAR(20) NULL,
                order_id VARCHAR(64) NULL,
                idempotency_key VARCHAR(64) NULL,
                metadata TEXT NULL,
                bank_url VARCHAR(512) NULL,
                bank_token VARCHAR(256) NULL,
                bank_redirect VARCHAR(16) NULL,
                bank_fields TEXT NULL,
                ref_no VARCHAR(128) NULL,
                rrn VARCHAR(64) NULL,
                card_no VARCHAR(32) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                fail_reason VARCHAR(512) NULL,
                verify_raw TEXT NULL,
                callback_url VARCHAR(512) NOT NULL,
                webhook_url VARCHAR(512) NULL,
                webhook_event VARCHAR(32) NULL,
                webhook_attempts INT NOT NULL DEFAULT 0,
                webhook_next_try DATETIME NULL,
                webhook_last_status VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                paid_at DATETIME NULL,
                expires_at DATETIME NOT NULL
            ){$suffix}";

        $rateLimits = "
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket VARCHAR(64) NOT NULL PRIMARY KEY,
                window_start VARCHAR(20) NOT NULL,
                count INT NOT NULL DEFAULT 0
            ){$suffix}";

        self::$pdo->exec($transactions);
        self::$pdo->exec($rateLimits);

        if ($isMysql) {
            $idx = self::all("SHOW INDEX FROM transactions WHERE Key_name = 'idx_status_created'");
            if ($idx === []) {
                self::$pdo->exec('CREATE INDEX idx_status_created ON transactions (status, created_at)');
            }
        } else {
            self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_status_created ON transactions (status, created_at)');
            self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhook_next ON transactions (webhook_next_try)');
        }

        self::$schemaReady = true;
    }
}
