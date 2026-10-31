<?php

declare(strict_types=1);

final class Request
{
    /** @param array<string,string> $headers keys in lower case */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public string $body,
    ) {}
}

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public static function json(int $status, array|object $data): Response
    {
        return new Response(
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
