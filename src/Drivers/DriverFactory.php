<?php

declare(strict_types=1);

namespace PayViro\Drivers;

use PayViro\ApiException;
use PayViro\Core\Config;

/**
 * Resolves gateway names to driver instances.
 *
 * Priority bank gateways:  saman | pasargad
 * Other bank gateways:     zarinpal | zibal | idpay | nextpay | payping
 *                          sepordeh | payir | aqayepardakht | vandar | bluebank
 * BNPL (خرید قسطی):        snappay | digipay | tara | lendo | ghasta
 * Crypto:                  oxapay
 * Sandbox:                 test
 */
final class DriverFactory
{
    public const ALL = [
        'saman', 'pasargad',
        'zarinpal', 'zibal', 'idpay', 'nextpay', 'payping', 'sepordeh', 'payir', 'aqayepardakht', 'vandar', 'bluebank',
        'snappay', 'digipay', 'tara', 'lendo', 'ghasta',
        'oxapay', 'test',
    ];

    public const NAMES = [
        'saman'          => 'بانک سامان',
        'pasargad'       => 'بانک پاسارگاد',
        'zarinpal'       => 'زرین‌پال',
        'zibal'          => 'زیبال',
        'idpay'          => 'آیدی‌پی',
        'nextpay'        => 'نکست‌پی',
        'payping'        => 'پی‌پینگ',
        'sepordeh'       => 'سپرده',
        'payir'          => 'پی.آی‌آر',
        'aqayepardakht'  => 'آقای پرداخت',
        'vandar'         => 'وندار',
        'bluebank'       => 'بلوبانک',
        'snappay'        => 'اسنپ‌پی (خرید قسطی)',
        'digipay'        => 'دیجی‌پی (خرید قسطی)',
        'tara'           => 'تارا (خرید قسطی)',
        'lendo'          => 'لندو (خرید قسطی)',
        'ghasta'         => 'قسطا (خرید قسطی)',
        'oxapay'         => 'کریپتو (OxaPay)',
        'test'           => 'درگاه تست (سندباکس)',
    ];

    /** @var array<string,string> provider category for the API listing */
    public const TYPES = [
        'saman' => 'bank', 'pasargad' => 'bank', 'zarinpal' => 'bank', 'zibal' => 'bank',
        'idpay' => 'bank', 'nextpay' => 'bank', 'payping' => 'bank', 'sepordeh' => 'bank',
        'payir' => 'bank', 'aqayepardakht' => 'bank', 'vandar' => 'bank', 'bluebank' => 'bank',
        'snappay' => 'bnpl', 'digipay' => 'bnpl', 'tara' => 'bnpl', 'lendo' => 'bnpl', 'ghasta' => 'bnpl',
        'oxapay' => 'crypto', 'test' => 'test',
    ];

    /** @var array<string,array<int,string>> credential config paths per gateway */
    private const CREDENTIALS = [
        'saman'          => ['drivers.saman.merchant_id'],
        'pasargad'       => ['drivers.pasargad.username', 'drivers.pasargad.terminal_number'],
        'zarinpal'       => ['drivers.zarinpal.merchant_id'],
        'zibal'          => ['drivers.zibal.merchant'],
        'idpay'          => ['drivers.idpay.api_key'],
        'nextpay'        => ['drivers.nextpay.api_key'],
        'payping'        => ['drivers.payping.api_key'],
        'sepordeh'       => ['drivers.sepordeh.merchant'],
        'payir'          => ['drivers.payir.api'],
        'aqayepardakht'  => ['drivers.aqayepardakht.pin'],
        'vandar'         => ['drivers.vandar.api_key'],
        'bluebank'       => [],
        'snappay'        => ['drivers.snappay.base_url', 'drivers.snappay.client_id', 'drivers.snappay.client_secret', 'drivers.snappay.username', 'drivers.snappay.password'],
        'digipay'        => [],
        'tara'           => [],
        'lendo'          => [],
        'ghasta'         => [],
        'oxapay'         => ['drivers.oxapay.merchant_key'],
        'test'           => [],
    ];

    public static function make(string $gateway): DriverInterface
    {
        switch ($gateway) {
            case 'saman':
                return new SamanDriver();
            case 'pasargad':
                return new PasargadDriver();
            case 'zarinpal':
                return new ZarinPalDriver();
            case 'zibal':
                return new ZibalDriver();
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
            case 'snappay':
                return new SnappPayDriver();
            case 'digipay':
            case 'tara':
            case 'lendo':
            case 'ghasta':
                return new BnplDriver($gateway);
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
        if ($gateway === 'bluebank' || in_array($gateway, ['digipay', 'tara', 'lendo', 'ghasta'], true)) {
            // flag-based gateways (generic / panel-documented)
            $path = $gateway === 'bluebank' ? 'drivers.bluebank.enabled' : 'drivers.' . $gateway . '.enabled';
            if ((bool) Config::get($path, false) === false) {
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

        foreach (self::CREDENTIALS[$gateway] ?? [] as $path) {
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
