<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\Core\Config;
use Webiro\Core\Http\Request;

/**
 * Shared helpers for the REST-based drivers.
 *
 * Every gateway stores its amount either in Rial or Toman; the gateway's
 * unit is applied here so each driver stays tiny and obvious.
 */
abstract class AbstractRestDriver implements DriverInterface
{
    /** @var string gateway id used in config (drivers.<id>) */
    protected const ID = '';

    /**
     * Config block of this driver (drivers.<id>).
     *
     * @return array<string,mixed>
     */
    protected static function cfg(): array
    {
        return (array) Config::get('drivers.' . static::ID, []);
    }

    /**
     * Convert the stored Rial amount to the unit the gateway expects.
     */
    protected static function amount(array $txn, string $unit): int
    {
        return $unit === 'toman' ? (int) round(((int) $txn['amount_rial']) / 10) : (int) $txn['amount_rial'];
    }

    /**
     * Standard "gateway not configured" guard.
     */
    protected static function requireCredential(string $key, string $label): string
    {
        $value = trim((string) (self::cfg()[$key] ?? ''));
        if ($value === '' || strpos($value, 'CHANGE_ME') === 0) {
            throw \Webiro\ApiException::driverNotConfigured($label);
        }
        return $value;
    }

    protected static function maskCard(string $card): string
    {
        $digits = preg_replace('/\D/', '', $card) ?? '';
        if (strlen($digits) < 10) {
            return $card;
        }
        return substr($digits, 0, 6) . '******' . substr($digits, -4);
    }

    /**
     * @return array{success:bool,status:string,ref_no:?string,rrn:?string,card_no:?string,message:string,raw:array<string,mixed>}
     */
    protected static function result(bool $success, string $status, ?string $refNo, ?string $rrn, ?string $cardNo, string $message, array $raw): array
    {
        return [
            'success' => $success,
            'status'  => $status,
            'ref_no'  => $refNo,
            'rrn'     => $rrn,
            'card_no' => $cardNo,
            'message' => $message,
            'raw'     => $raw,
        ];
    }
}
