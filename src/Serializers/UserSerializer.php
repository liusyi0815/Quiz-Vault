<?php
declare(strict_types=1);

namespace App\Serializers;

/** 決定「可以回給前端的使用者欄位」。password_hash 永遠不在這裡。 */
final class UserSerializer
{
    public static function toArray(array $row): array
    {
        return [
            'user_id'              => (int) $row['user_id'],
            'username'             => $row['username'],
            'email'                => $row['email'],
            'role'                 => $row['role'],
            'must_change_password' => (bool) $row['must_change_password'],
        ];
    }

    /** 管理員看的使用者資料：多了啟用狀態與建立時間 */
    public static function forAdmin(array $row): array
    {
        return self::toArray($row) + [
            'is_active'  => (bool) $row['is_active'],
            'created_at' => \App\Support\Dates::iso($row['created_at']),
        ];
    }
}
