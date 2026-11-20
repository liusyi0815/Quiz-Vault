<?php
declare(strict_types=1);

namespace App\Core;

use App\Support\AppException;

/** 保存「目前登入者」，由 AuthMiddleware 在每個受保護請求載入 */
final class Auth
{
    private static ?array $user = null;

    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    public static function user(): array
    {
        return self::$user ?? throw AppException::unauthenticated();
    }

    public static function id(): int
    {
        return (int) self::user()['user_id'];
    }
}
