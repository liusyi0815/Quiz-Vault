<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    private function __construct(
        private readonly int $status,
        private readonly ?array $payload,
        private readonly array $headers = [],
    ) {
    }

    public static function ok(mixed $data, array $meta = [], int $status = 200): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return new self($status, $payload);
    }

    public static function created(mixed $data): self
    {
        return self::ok($data, [], 201);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public static function error(int $status, string $code, string $message, array $details = [], array $extra = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return new self($status, ['error' => $error + $extra]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');          // API 回應（含臨時密碼）不得被快取
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->payload !== null) {
            echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
