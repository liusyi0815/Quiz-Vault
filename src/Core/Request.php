<?php
declare(strict_types=1);

namespace App\Core;

use App\Support\AppException;

final class Request
{
    /** 路由參數，例如 /admin/users/{user_id} → ['user_id' => '24'] */
    public array $params = [];
    private ?array $body = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly string $rawBody,
        private readonly array $server,
    ) {
    }

    public static function capture(): self
    {
        $uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        // 不論專案放在哪個子目錄（例如 /quiz-system/public/api/v1/health），只取 /api/v1 之後的部分
        $pos = strpos($uri, '/api/v1');
        $path = $pos === false ? $uri : substr($uri, $pos + strlen('/api/v1'));
        $path = '/' . trim($path, '/');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            file_get_contents('php://input') ?: '',
            $_SERVER,
        );
    }

    /** 解析 JSON body；空 body 回傳 []；格式錯誤丟 INVALID_JSON */
    public function body(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }
        if (trim($this->rawBody) === '') {
            return $this->body = [];
        }
        $data = json_decode($this->rawBody, true);
        if (!is_array($data)) {
            throw new AppException(400, 'INVALID_JSON', '請求內容不是合法的 JSON');
        }
        return $this->body = $data;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function isHttps(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function isUnsafeMethod(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
