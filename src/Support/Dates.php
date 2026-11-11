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
}
