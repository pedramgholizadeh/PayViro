<?php

declare(strict_types=1);

namespace PayViro\Core;

/**
 * Tiny SOAP helper for gateways that still expose .asmx endpoints
 * (e.g. Saman Bank). Uses ext-soap when available.
 */
final class Soap
{
    /**
     * @param array<string,mixed> $params  ordered parameters (names must match the WSDL)
     * @param array<string,mixed> $options extra SoapClient options (location, connection_timeout, ...)
     *
     * @return mixed the raw response of the called method
     *
     * @throws \RuntimeException when ext-soap is missing or the call fails
     */
    public static function call(string $wsdl, string $method, array $params, array $options = [])
    {
        if (!class_exists('SoapClient')) {
            throw new \RuntimeException('اکستنشن ext-soap روی سرور فعال نیست. درگاه سامان برای تایید تراکنش به آن نیاز دارد.');
        }

        $options += [
            'trace'              => 1,
            'exceptions'         => true,
            'cache_wsdl'         => WSDL_CACHE_DISK,
            'connection_timeout' => 15,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
        ];

        try {
            $client = new \SoapClient($wsdl, $options);
            return $client->__soapCall($method, [$params]);
        } catch (\SoapFault $fault) {
            Logger::error('soap.fault', [
                'wsdl'   => $wsdl,
                'method' => $method,
                'fault'  => $fault->getMessage(),
            ]);
            throw new \RuntimeException('خطای ارتباط با وب‌سرویس بانک: ' . $fault->getMessage(), 0, $fault);
        } catch (\Throwable $e) {
            Logger::error('soap.error', ['wsdl' => $wsdl, 'method' => $method, 'error' => $e->getMessage()]);
            throw new \RuntimeException('خطای ارتباط با وب‌سرویس بانک: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Deeply extract the first scalar value of a SOAP result object.
     *
     * @return string
     */
    public static function extractScalar($result): string
    {
        if ($result === null) {
            return '';
        }
        if (is_scalar($result)) {
            return (string) $result;
        }
        foreach ((array) $result as $value) {
            $found = self::extractScalar($value);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }
}
