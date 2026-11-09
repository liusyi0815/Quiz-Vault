<?php
declare(strict_types=1);

namespace App\Core;

/** 讀取專案根目錄的 .env；系統環境變數優先，其次 .env，最後才是預設值 */
final class Config
{
    private static array $file = [];
    private static string $root = '';

    public static function load(string $root): void
    {
        self::$root = rtrim($root, '/\\');
        self::$file = [];
        $path = self::$root . '/.env';
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
            }
            self::$file[trim($key)] = $value;
        }
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function get(string $key, string $default = ''): string
    {
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return self::$file[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(self::get($key, $default ? 'true' : 'false'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, '');
        return $v === '' ? $default : (int) $v;
    }
}
