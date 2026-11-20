<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static ?Request $request = null;

    public static function init(Request $request): void
    {
        self::$request = $request;
    }

    private static function name(): string
    {
        return Config::get('SESSION_NAME', 'QUIZSESSID');
    }

    private static function hasCookie(): bool
    {
        return isset($_COOKIE[self::name()]);
    }

    private static function ensure(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::name());
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) Config::int('SESSION_LIFETIME', 7200));
        session_set_cookie_params([
            'lifetime' => 0,                                  // 關閉瀏覽器就失效
            'path'     => '/',
            'secure'   => self::$request?->isHttps() ?? false, // 正式環境（HTTPS）自動加上 Secure
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // 沒有 session cookie 的請求（例如 /health、未登入）不必建立 session
        if (session_status() !== PHP_SESSION_ACTIVE && !self::hasCookie()) {
            return $default;
        }
        self::ensure();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::ensure();
        $_SESSION[$key] = $value;
    }

    /** 登入、改密碼後重新產生 session id，防止 session fixation */
    public static function regenerate(): void
    {
        self::ensure();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !self::hasCookie()) {
            return;
        }
        self::ensure();
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(self::name(), '', [
            'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}
