<?php

declare(strict_types=1);

namespace Webiro\Services;

use Webiro\Core\Config;
use Webiro\Core\Crypto;
use Webiro\Core\HttpClient;
use Webiro\Core\Logger;
use Webiro\Models\Transaction;

/**
 * Signed webhook delivery with retries.
 *
 * Payload:   {"event":"payment.succeeded","data":{...}}
 * Signature: X-Webiro-Signature: sha256=<HMAC-SHA256 of raw body with merchant secret>
 *
 * Failed deliveries are retried by the cron endpoint with backoff.
 */
final class WebhookService
{
    /**
     * Attempt one delivery. Updates retry bookkeeping on the transaction.
     *
     * @param array<string,mixed> $txn
     *
     * @return array{ok:bool, detail:string}
     */
    public static function notify(array $txn, string $event): array
    {
        $url = trim((string) ($txn['webhook_url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'detail' => 'آدرس وبهوکی برای این تراکنش ثبت نشده است.'];
        }

        $secret = (string) Config::get('merchants.' . $txn['merchant_id'] . '.secret', '');
        if ($secret === '') {
            return ['ok' => false, 'detail' => 'کلید امضای پذیرنده یافت نشد.'];
        }

        $payload = self::payload($txn, $event);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . Crypto::signBody($secret, $body);

        $response = HttpClient::postJson($url, $payload, [
            'X-Webiro-Signature' => $signature,
            'X-Webiro-Event'     => $event,
            'X-Webiro-Token'     => (string) $txn['token'],
        ], 10);

        $ok = $response['error'] === null && $response['status'] >= 200 && $response['status'] < 300;
        $attempt = (int) $txn['webhook_attempts'] + 1;
        $maxAttempts = (int) Config::get('payments.webhook_max_attempts', 5);
        $backoff = (array) Config::get('payments.webhook_backoff_minutes', [1, 5, 15, 60, 240]);

        if ($ok) {
            Transaction::update((string) $txn['token'], [
                'webhook_event'      => $event,
                'webhook_attempts'   => $attempt,
                'webhook_next_try'   => null,
                'webhook_last_status' => 'OK (HTTP ' . $response['status'] . ')',
            ]);
            Logger::info('webhook.delivered', ['token' => $txn['token'], 'event' => $event, 'attempt' => $attempt]);
            return ['ok' => true, 'detail' => 'تحویل موفق (HTTP ' . $response['status'] . ')'];
        }

        $detail = $response['error'] !== null
            ? 'خطای اتصال: ' . $response['error']
            : 'پاسخ HTTP ' . $response['status'] . ' از سرور پذیرنده';

        if ($attempt >= $maxAttempts) {
            Transaction::update((string) $txn['token'], [
                'webhook_event'      => $event,
                'webhook_attempts'   => $attempt,
                'webhook_next_try'   => null,
                'webhook_last_status' => mb_substr('تسلیم: ' . $detail, 0, 250),
            ]);
            Logger::warning('webhook.gave_up', ['token' => $txn['token'], 'detail' => $detail, 'attempts' => $attempt]);
            return ['ok' => false, 'detail' => 'ارسال ناموفق پس از ' . $attempt . ' تلاش: ' . $detail];
        }

        $minutes = (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 15);
        $nextTry = date('Y-m-d H:i:s', time() + $minutes * 60);

        Transaction::update((string) $txn['token'], [
            'webhook_event'      => $event,
            'webhook_attempts'   => $attempt,
            'webhook_next_try'   => $nextTry,
            'webhook_last_status' => mb_substr($detail, 0, 250),
        ]);
        Logger::warning('webhook.retry_scheduled', ['token' => $txn['token'], 'attempt' => $attempt, 'next' => $nextTry]);

        return ['ok' => false, 'detail' => $detail . ' — تلاش بعدی: ' . $nextTry];
    }

    /**
     * Cron worker: deliver every due webhook.
     *
     * @return array{processed:int, sent:int, failed:int}
     */
    public static function processDue(int $limit = 25): array
    {
        $rows = Transaction::dueWebhooks($limit);
        $sent = 0;
        $failed = 0;

        foreach ($rows as $txn) {
            $event = (string) ($txn['webhook_event'] ?? '');
            if ($event === '') {
                $event = $txn['status'] === 'paid' ? 'payment.succeeded' : 'payment.failed';
            }
            $result = self::notify($txn, $event);
            if ($result['ok']) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Build the webhook payload for a transaction.
     *
     * @param array<string,mixed> $txn
     *
     * @return array<string,mixed>
     */
    public static function payload(array $txn, string $event): array
    {
        return [
            'event' => $event,
            'sent_at' => wg_now(),
            'data' => [
                'token'       => (string) $txn['token'],
                'order_id'    => (string) ($txn['order_id'] ?? ''),
                'merchant'    => (string) $txn['merchant_id'],
                'gateway'     => (string) $txn['gateway'],
                'status'      => (string) $txn['status'],
                'amount'      => (int) $txn['amount_rial'],
                'currency'    => 'rial',
                'ref_no'      => $txn['ref_no'] ?? null,
                'rrn'         => $txn['rrn'] ?? null,
                'card_no'     => $txn['card_no'] ?? null,
                'message'     => (string) ($txn['fail_reason'] ?? ''),
                'created_at'  => (string) $txn['created_at'],
                'paid_at'     => $txn['paid_at'] ?? null,
            ],
        ];
    }
}
