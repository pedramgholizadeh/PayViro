<?php

declare(strict_types=1);

namespace Webiro\Controllers;

use Webiro\Core\Config;
use Webiro\Core\Http\Request;
use Webiro\Core\Http\Response;
use Webiro\Models\Transaction;
use Webiro\Services\WebhookService;

/**
 * Cron endpoint for shared hosting:
 *   GET /api/cron/webhooks?key=<config cron.key>
 *
 * cPanel cron (every 5 minutes):
 *   wget -q -O /dev/null "https://webiro.ir/gateway/api/cron/webhooks?key=..."
 */
final class CronController
{
    public function webhooks(Request $request, array $params): Response
    {
        $expected = (string) Config::get('cron.key', '');
        $given = (string) ($request->query['key'] ?? $request->header('x-cron-key'));

        if ($expected === '' || strpos($expected, 'CHANGE_ME') === 0) {
            return Response::json(['status' => 'error', 'error' => [
                'code' => 'cron_disabled', 'message' => 'کلید کرون در پیکربندی تنظیم نشده است.',
            ]], 503);
        }
        if (!hash_equals($expected, $given)) {
            return Response::json(['status' => 'error', 'error' => [
                'code' => 'unauthorized', 'message' => 'کلید کرون نامعتبر است.',
            ]], 401);
        }

        $expired = Transaction::expirePending();
        $result = WebhookService::processDue(25);

        return Response::json([
            'status' => 'success',
            'data'   => [
                'time'     => wg_now(),
                'expired_transactions' => $expired,
                'webhooks' => $result,
            ],
        ]);
    }
}
