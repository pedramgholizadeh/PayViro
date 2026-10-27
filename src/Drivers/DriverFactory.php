<?php

declare(strict_types=1);

namespace Webiro\Drivers;

use Webiro\ApiException;
use Webiro\Core\Config;

/**
 * Resolves gateway names to driver instances.
 *
 * Bank gateways:  zarinpal | zibal | saman | idpay | nextpay | payping
 *                 sepordeh | payir | aqayepardakht | vandar | bluebank
 * Crypto:         oxapay
 * Sandbox:        test
 */
final class DriverFactory
{
    public const ALL = [
        'zarinpal', 'zibal', 'saman',
        'idpay', 'nextpay', 'payping', 'sepordeh', 'payir', 'aqayepardakht', 'vandar',
        'bluebank', 'oxapay', 'test',
    ];

    public const NAMES = [
        'zarinpal'       => 'زرین‌پال',
        'zibal'          => 'زیبال',
        'saman'          => 'بانک سامان',
        'idpay'          => 'آیدی‌پی',
        'nextpay'        => 'نکست‌پی',
        'payping'        => 'پی‌پینگ',
        'sepordeh'       => 'سپرده',
        'payir'          => 'پی.آی‌آر',
        'aqayepardakht'  => 'آقای پرداخت',
        'vandar'         => 'وندار',
        'bluebank'       => 'بلوبانک',
        'oxapay'         => 'کریپتو (OxaPay)',
        'test'           => 'درگاه تست (سندباکس)',
    ];

    public static function make(string $gateway): DriverInterface
    {
        switch ($gateway) {
            case 'zarinpal':
                return new ZarinPalDriver();
            case 'zibal':
                return new ZibalDriver();
            case 'saman':
                return new SamanDriver();
            case 'idpay':
                return new IdpayDriver();
            case 'nextpay':
                return new NextpayDriver();
            case 'payping':
                return new PaypingDriver();
            case 'sepordeh':
                return new SepordehDriver();
            case 'payir':
                return new PayIrDriver();
            case 'aqayepardakht':
                return new AqayepardakhtDriver();
            case 'vandar':
                return new VandarDriver();
            case 'bluebank':
                return new BlueBankDriver();
            case 'oxapay':
                return new OxapayDriver();
            case 'test':
                if ((bool) Config::get('drivers.test.enabled', true) === false) {
                    throw self::unavailable($gateway);
                }
                return new TestDriver();
            default:
                throw new ApiException(400, 'invalid_request', 'درگاه پرداخت ناشناخته: ' . $gateway);
        }
    }

    /**
     * Gateways that are enabled in config (for /api/v1/gateways).
     *
     * @return array<string,bool>
     */
    public static function enabled(): array
    {
        $list = [];
        foreach (self::ALL as $gateway) {
            try {
                self::checkConfigured($gateway);
                $list[$gateway] = true;
            } catch (ApiException $e) {
                $list[$gateway] = false;
            }
        }
        return $list;
    }

    /**
     * Cheap config check without constructing the driver.
     */
    private static function checkConfigured(string $gateway): void
    {
        $credentialKeys = [
            'zarinpal'      => ['drivers.zarinpal.merchant_id'],
            'zibal'         => ['drivers.zibal.merchant'],
            'saman'         => ['drivers.saman.merchant_id'],
            'idpay'         => ['drivers.idpay.api_key'],
            'nextpay'       => ['drivers.nextpay.api_key'],
            'payping'       => ['drivers.payping.api_key'],
            'sepordeh'      => ['drivers.sepordeh.merchant'],
            'payir'         => ['drivers.payir.api'],
            'aqayepardakht' => ['drivers.aqayepardakht.pin'],
            'vandar'        => ['drivers.vandar.api_key'],
            'bluebank'      => [], // enabled flag only
            'oxapay'        => ['drivers.oxapay.merchant_key'],
            'test'          => [],
        ];

        if ($gateway === 'bluebank') {
            if ((bool) Config::get('drivers.bluebank.enabled', false) === false) {
                throw self::unavailable($gateway);
            }
            return;
        }
        if ($gateway === 'test') {
            if ((bool) Config::get('drivers.test.enabled', true) === false) {
                throw self::unavailable($gateway);
            }
            return;
        }

        foreach ($credentialKeys[$gateway] ?? [] as $path) {
            $value = (string) Config::get($path, '');
            if ($value === '' || strpos($value, 'CHANGE_ME') === 0) {
                throw self::unavailable($gateway);
            }
        }
    }

    private static function unavailable(string $gateway): ApiException
    {
        return new ApiException(400, 'gateway_unavailable', 'درگاه «' . $gateway . '» فعال یا پیکربندی نشده است.');
    }
}
