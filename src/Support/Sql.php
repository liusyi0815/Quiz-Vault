<?php
declare(strict_types=1);

namespace App\Support;

final class Sql
{
    /** 讓使用者輸入的 % _ 當成一般字元，配合 LIKE ... ESCAPE '!' 使用 */
    public static function likeContains(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    }
}
