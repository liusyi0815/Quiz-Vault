<?php
declare(strict_types=1);

namespace App\Support;

/** 可預期的業務錯誤：會被轉成 { "error": { code, message, details } } */
final class AppException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(array $details, string $message = '欄位驗證失敗'): self
    {
        return new self(400, 'VALIDATION_FAILED', $message, $details);
    }

    public static function notFound(string $message = '找不到資源'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function unauthenticated(string $message = '請先登入'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $message = '沒有權限執行此操作'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }
}
