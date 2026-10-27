<?php

declare(strict_types=1);

namespace PayViro\Core\Http;

/**
 * Outgoing HTTP response.
 */
final class Response
{
    /** @var int */
    public $status = 200;

    /** @var string */
    public $body = '';

    /** @var array<string,string> */
    public $headers = [];

    public static function json($data, int $status = 200): self
    {
        $response = new self();
        $response->status = $status;
        $response->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $response;
    }

    public static function html(string $html, int $status = 200): self
    {
        $response = new self();
        $response->status = $status;
        $response->body = $html;
        $response->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $response;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self();
        $response->status = $status;
        $response->body = '';
        $response->headers['Location'] = $url;
        return $response;
    }

    public static function noContent(): self
    {
        $response = new self();
        $response->status = 204;
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
