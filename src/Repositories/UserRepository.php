<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** 所有與 users 資料表有關的 SQL 都在這裡（一律使用具名參數，不拼接字串） */
final class UserRepository
{
    private const COLUMNS = 'user_id, username, email, password_hash, role, is_active, must_change_password';

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $username, string $email, string $passwordHash, string $role, bool $mustChange = false): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, must_change_password)
             VALUES (:username, :email, :hash, :role, :must)'
        );
        $stmt->execute([
            'username' => $username, 'email' => $email, 'hash' => $passwordHash,
            'role' => $role, 'must' => $mustChange ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** 只允許更新白名單內的欄位 */
    public function updateProfile(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['username', 'email']));
        if ($allowed === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($allowed) as $column) {
            $sets[] = "{$column} = :{$column}";   // $column 只可能是上面白名單的值
        }
        $stmt = Database::connection()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = :id');
        $stmt->execute($allowed + ['id' => $id]);
    }

    public function updatePassword(int $id, string $hash, bool $mustChange): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = :must WHERE user_id = :id'
        );
        $stmt->execute(['hash' => $hash, 'must' => $mustChange ? 1 : 0, 'id' => $id]);
    }
}
