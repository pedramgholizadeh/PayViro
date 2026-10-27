<?php

declare(strict_types=1);

namespace PayViro\Core\Http;

use PayViro\Core\Config;

/**
 * Incoming HTTP request abstraction.
 */
final class Request
{
    /** @var string */
    public $method;

    /** @var string path relative to the gateway mount point, always starts with "/" */
    public $path;

    /** @var array<string,mixed> */
    public $query;

    /** @var array<string,mixed> */
    public $post;

    /** @var string */
    public $rawBody;

    /** @var array<string,mixed>|null decoded JSON body */
    public $json;

    /** @var array<string,string> lowercase header name => value */
    private $headers;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $headers
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        string $rawBody = '',
        array $headers = []
    ) {
        $this->method  = strtoupper($method);
        $this->path    = '/' . ltrim($path, '/');
        $this->query   = $query;
        $this->post    = $post;
        $this->rawBody = $rawBody;
        $this->headers = $headers;

        $decoded = null;
        if ($rawBody !== '') {
            $contentType = $headers['content-type'] ?? '';
            $looksJson   = $contentType === '' || strpos($contentType, 'application/json') !== false;
            if ($looksJson) {
                $maybe = json_decode($rawBody, true);
                if (is_array($maybe)) {
                    $decoded = $maybe;
                }
            }
        }
        $this->json = $decoded;
    }

    public static function capture(): self
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // Strip the mount prefix, e.g. "/gateway" from "https://your-domain.ir/gateway/go/pay_x"
        // Config may be missing on a fresh install — treat mount as empty then.
        $mount = '';
        try {
            $mount = trim((string) (parse_url((string) Config::get('app.base_url', ''), PHP_URL_PATH) ?: ''), '/');
        } catch (\Throwable $e) {
            $mount = '';
        }
        if ($mount !== '') {
            $prefix = '/' . $mount;
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                $path = substr($path, strlen($prefix));
            } elseif ($path === '/' . $mount . '.php') {
                $path = '/';
            }
        }
        // Front controller invoked directly as index.php
        if (preg_match('#/index\.php$#', $path) === 1) {
            $path = '/';
        }

        $headers = [];
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = (string) $value;
                }
            }
        }

        $rawBody = (string) file_get_contents('php://input');

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $rawBody,
            $headers
        );
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Merged input: JSON body > form POST > query string.
     *
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        if (is_array($this->json) && array_key_exists($key, $this->json)) {
            return $this->json[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post, is_array($this->json) ? $this->json : []);
    }

    public function ip(): string
    {
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            $candidate = trim($parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return $this->header('user-agent');
    }

    public function isApi(): bool
    {
        return strpos($this->path, '/api/') === 0;
    }
}
