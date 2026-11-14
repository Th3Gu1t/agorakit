<?php

namespace App\Setup;

/**
 * What the setup page sends back: a status, headers and an HTML body.
 */
class SetupResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
        public bool $regenerateSession = false,
    ) {
    }

    public static function redirect(string $location): self
    {
        return new self(303, '', ['Location' => $location]);
    }

    /**
     * Sends the response from a web request.
     */
    public function send(): void
    {
        http_response_code($this->status);
        $headers = $this->headers + [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; "
                . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ];
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
