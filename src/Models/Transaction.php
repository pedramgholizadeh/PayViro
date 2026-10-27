<?php

declare(strict_types=1);

namespace PayViro\Models;

use PayViro\Core\Database;

/**
 * Transaction repository & state machine.
 *
 * Status lifecycle:  pending → paid | failed | canceled | expired
 * Transitions OUT of "pending" are atomic (guard: WHERE status='pending')
 * so a double bank callback can never produce two successes.
 */
final class Transaction
{
    public const STATUSES = ['pending', 'paid', 'failed', 'canceled', 'expired'];

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function create(array $data): array
    {
        $now = wg_now();
        $row = [
            'token'            => (string) $data['token'],
            'merchant_id'      => (string) $data['merchant_id'],
            'gateway'          => (string) $data['gateway'],
            'amount_rial'      => (int) $data['amount_rial'],
            'currency'         => (string) ($data['currency'] ?? 'rial'),
            'description'      => $data['description'] ?? null,
            'mobile'           => $data['mobile'] ?? null,
            'order_id'         => $data['order_id'] ?? null,
            'idempotency_key'  => $data['idempotency_key'] ?? null,
            'metadata'         => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
            'callback_url'     => (string) $data['callback_url'],
            'webhook_url'      => $data['webhook_url'] ?? null,
            'status'           => 'pending',
            'created_at'       => $now,
            'updated_at'       => $now,
            'expires_at'       => (string) $data['expires_at'],
        ];

        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        Database::run(
            'INSERT INTO transactions (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $row
        );

        return self::findByToken($row['token']) ?? $row;
    }

    public static function findByToken(string $token): ?array
    {
        return Database::one('SELECT * FROM transactions WHERE token = :token', ['token' => $token]);
    }

    public static function findByIdempotencyKey(string $merchantId, string $key): ?array
    {
        return Database::one(
            'SELECT * FROM transactions WHERE merchant_id = :m AND idempotency_key = :k ORDER BY id DESC LIMIT 1',
            ['m' => $merchantId, 'k' => $key]
        );
    }

    public static function update(string $token, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $params = ['token' => $token];
        foreach ($fields as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }
        $sets[] = 'updated_at = :updated_at';
        $params['updated_at'] = wg_now();

        Database::run('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE token = :token', $params);
    }

    /**
     * Atomically move a pending transaction to a final state.
     * Returns false when the transaction was already finalized (idempotency).
     *
     * @param array<string,mixed> $fields ref_no, rrn, card_no, fail_reason, verify_raw
     */
    public static function finalize(string $token, string $status, array $fields = []): bool
    {
        $allowed = ['ref_no', 'rrn', 'card_no', 'fail_reason', 'verify_raw'];
        $sets = ['status = :status'];
        $params = ['token' => $token, 'status' => $status];

        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = $fields[$column];
            }
        }
        if ($status === 'paid') {
            $sets[] = 'paid_at = :paid_at';
            $params['paid_at'] = wg_now();
        }
        $sets[] = 'updated_at = :updated_at';
        $params['updated_at'] = wg_now();

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE transactions SET ' . implode(', ', $sets)
            . ' WHERE token = :token AND status = :pending'
        );
        $params['pending'] = 'pending';
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $filters
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($filters);
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM transactions ' . $where
             . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        return Database::all($sql, $params);
    }

    /**
     * @param array<string,mixed> $filters
     */
    public static function countAll(array $filters = []): int
    {
        [$where, $params] = self::buildWhere($filters);
        $row = Database::one('SELECT COUNT(*) AS c FROM transactions ' . $where, $params);
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Aggregate stats for the admin dashboard.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function stats(): array
    {
        $todayStart = date('Y-m-d 00:00:00');
        $monthStart = date('Y-m-01 00:00:00');

        $row = Database::one(
            "SELECT
                COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= :t THEN amount_rial END), 0) AS today_amount,
                COUNT(CASE WHEN status='paid' AND paid_at >= :t2 THEN 1 END) AS today_count,
                COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= :m THEN amount_rial END), 0) AS month_amount,
                COUNT(CASE WHEN status='paid' AND paid_at >= :m2 THEN 1 END) AS month_count,
                COUNT(CASE WHEN status='pending' THEN 1 END) AS pending_count
             FROM transactions",
            ['t' => $todayStart, 't2' => $todayStart, 'm' => $monthStart, 'm2' => $monthStart]
        );

        $byGateway = Database::all(
            "SELECT gateway, COUNT(*) AS count, COALESCE(SUM(amount_rial),0) AS amount
             FROM transactions WHERE status = 'paid' AND paid_at >= :m
             GROUP BY gateway ORDER BY amount DESC",
            ['m' => $monthStart]
        );

        return [
            'today'      => ['amount' => (int) ($row['today_amount'] ?? 0), 'count' => (int) ($row['today_count'] ?? 0)],
            'month'      => ['amount' => (int) ($row['month_amount'] ?? 0), 'count' => (int) ($row['month_count'] ?? 0)],
            'pending'    => (int) ($row['pending_count'] ?? 0),
            'by_gateway' => $byGateway,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function dueWebhooks(int $limit = 25): array
    {
        return Database::all(
            "SELECT * FROM transactions
             WHERE webhook_next_try IS NOT NULL
               AND webhook_next_try <= :now
               AND status IN ('paid','failed','canceled')
             ORDER BY updated_at ASC
             LIMIT " . max(1, min(100, $limit)),
            ['now' => wg_now()]
        );
    }

    public static function expirePending(): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "UPDATE transactions SET status = 'expired', updated_at = :now
             WHERE status = 'pending' AND expires_at < :now2"
        );
        $stmt->execute(['now' => wg_now(), 'now2' => wg_now()]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $filters
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $where  = [];
        $params = [];
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['gateway'])) {
            $where[] = 'gateway = :gateway';
            $params['gateway'] = (string) $filters['gateway'];
        }
        if (!empty($filters['merchant_id'])) {
            $where[] = 'merchant_id = :merchant_id';
            $params['merchant_id'] = (string) $filters['merchant_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(token LIKE :search OR order_id LIKE :search OR mobile LIKE :search OR ref_no LIKE :search)';
            $params['search'] = '%' . (string) $filters['search'] . '%';
        }
        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }
}
