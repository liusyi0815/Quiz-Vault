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
}
