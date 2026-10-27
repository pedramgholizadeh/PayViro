<?php

declare(strict_types=1);

namespace PayViro\Core;

/**
 * cURL-based HTTP client for talking to the payment providers.
 * Returns a normalized array; never throws on HTTP errors.
 */
final class HttpClient
{
    public const UA = 'PayViro/' . PV_VERSION;

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $options  timeout, connect_timeout, verify_ssl, form (bool)
     *
     * @return array{status:int, body:string, json:?array, error:?string, duration_ms:float}
     */
    public static function request(string $method, string $url, ?array $body = null, array $headers = [], array $options = []): array
    {
        $ch = curl_init();
        $started = microtime(true);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $headerLines[] = (string) $value;
            } else {
                $headerLines[] = $name . ': ' . $value;
            }
        }

        $payload = null;
        if ($body !== null) {
            if (!empty($options['form'])) {
                $payload = http_build_query($body);
                $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headerLines[] = 'Content-Type: application/json';
            }
        }

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? Config::get('http.timeout', 20)),
            CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? 10),
            CURLOPT_SSL_VERIFYPEER => (bool) Config::get('http.verify_ssl', true),
            CURLOPT_SSL_VERIFYHOST => ((bool) Config::get('http.verify_ssl', true)) ? 2 : 0,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => $headerLines,
        ];

        $method = strtoupper($method);
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = $payload ?? '';
        } elseif ($method !== 'GET') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
            if ($payload !== null) {
                $curlOptions[CURLOPT_POSTFIELDS] = $payload;
            }
        }

        curl_setopt_array($ch, $curlOptions);

        $responseBody = curl_exec($ch);
        $duration = round((microtime(true) - $started) * 1000, 1);

        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            Logger::warning('http.error', ['url' => $url, 'error' => $error, 'errno' => $errno]);
            return ['status' => 0, 'body' => '', 'json' => null, 'error' => $error ?: 'connection_failed', 'duration_ms' => $duration];
        }

        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = null;
        $decoded = json_decode((string) $responseBody, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }

        return ['status' => $status, 'body' => (string) $responseBody, 'json' => $json, 'error' => null, 'duration_ms' => $duration];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public static function postJson(string $url, array $body, array $headers = [], ?int $timeout = null): array
    {
        $options = $timeout !== null ? ['timeout' => $timeout] : [];
        return self::request('POST', $url, $body, $headers, $options);
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function postForm(string $url, array $fields, array $headers = [], ?int $timeout = null): array
    {
        $options = ['form' => true];
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }
        return self::request('POST', $url, $fields, $headers, $options);
    }
}
