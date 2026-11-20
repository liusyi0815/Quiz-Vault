<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

final class Dates
{
    /** 資料庫的 DATETIME（'2026-11-03 10:30:00'）→ ISO 8601（'2026-11-03T10:30:00+08:00'） */
    public static function iso(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $tz = new \DateTimeZone(Config::get('APP_TIMEZONE', 'Asia/Taipei'));
        return (new \DateTimeImmutable($datetime, $tz))->format('c');
    }

    /**
     * 使用者輸入的日期時間 → 資料庫用的 'Y-m-d H:i:s'（轉成 APP_TIMEZONE）。
     * 接受 2026-11-03T10:00:00+08:00、2026-11-03T02:00:00Z、2026-11-03 10:00:00（沒有時區就當成本地時間）。
     * 格式不對、或日期不存在（例如 2 月 31 日）回傳 null。
     */
    public static function parse(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $text) !== 1) {
            return null;
        }
        $tz = new \DateTimeZone(Config::get('APP_TIMEZONE', 'Asia/Taipei'));
        try {
            $dt = new \DateTimeImmutable($text, $tz);
        } catch (\Exception) {
            return null;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }
        return $dt->setTimezone($tz)->format('Y-m-d H:i:s');
    }

    /** 資料庫的 DATETIME → DateTimeImmutable（APP_TIMEZONE）；null 原樣回傳 */
    public static function toObject(?string $datetime): ?\DateTimeImmutable
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        return new \DateTimeImmutable($datetime, new \DateTimeZone(Config::get('APP_TIMEZONE', 'Asia/Taipei')));
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(Config::get('APP_TIMEZONE', 'Asia/Taipei')));
    }
}
