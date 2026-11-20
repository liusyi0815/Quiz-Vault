<?php
declare(strict_types=1);

namespace App\Support;

final class PasswordGenerator
{
    // 排除 0 O 1 l I 等容易看錯的字元
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';
    private const DIGIT = '23456789';

    /** 密碼學安全的隨機臨時密碼，至少含大寫、小寫與數字各一 */
    public static function temporary(int $length = 12): string
    {
        $all = self::UPPER . self::LOWER . self::DIGIT;
        do {
            $password = '';
            for ($i = 0; $i < $length; $i++) {
                $password .= $all[random_int(0, strlen($all) - 1)];
            }
        } while (
            !preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password)
        );
        return $password;
    }
}
