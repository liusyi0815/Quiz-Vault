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

    // ---------------------------------------------------------------- F-17 使用者管理

    /** @return array{0:list<array>, 1:int} 不含 password_hash */
    public function search(array $f, int $limit, int $offset): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];
        if (isset($f['role'])) {
            $where[] = 'role = :role';
            $params['role'] = $f['role'];
        }
        if (isset($f['is_active'])) {
            $where[] = 'is_active = :active';
            $params['active'] = $f['is_active'] ? 1 : 0;
        }
        if (isset($f['q'])) {
            $where[] = "(username LIKE :q1 ESCAPE '!' OR email LIKE :q2 ESCAPE '!')";
            $params['q1'] = $params['q2'] = \App\Support\Sql::likeContains($f['q']);
        }
        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $pdo->prepare('SELECT COUNT(*) FROM users' . $sql);
        $count->execute($params);
        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, role, is_active, must_change_password, created_at FROM users' . $sql
            . ' ORDER BY user_id LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    public function findForAdmin(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, username, email, role, is_active, must_change_password, created_at FROM users WHERE user_id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 鎖住這位使用者那一列（transaction 內） */
    public function lockById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT user_id, role, is_active FROM users WHERE user_id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * 鎖住「所有啟用中的管理員」並回傳他們的 user_id。
     * 兩位管理員同時互相停用對方時，會一個接一個執行，所以不會把管理員停光。
     * @return list<int>
     */
    public function lockActiveAdminIds(): array
    {
        $rows = Database::connection()->query("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY user_id FOR UPDATE")->fetchAll();
        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    public function updateRoleAndActive(int $id, string $role, bool $active): void
    {
        Database::connection()->prepare('UPDATE users SET role = :role, is_active = :active WHERE user_id = :id')
            ->execute(['role' => $role, 'active' => $active ? 1 : 0, 'id' => $id]);
    }

    /** 這個帳號在其他資料表留下的歷史（改角色前檢查，避免出現「學生開課」或「老師修課」） */
    public function historyCounts(int $id): array
    {
        $pdo = Database::connection();
        $one = static function (string $sql) use ($pdo, $id): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return (int) $stmt->fetchColumn();
        };
        return [
            'courses'     => $one('SELECT COUNT(*) FROM courses WHERE teacher_id = :id'),
            'questions'   => $one('SELECT COUNT(*) FROM questions WHERE creator_id = :id'),
            'exams'       => $one('SELECT COUNT(*) FROM exams WHERE creator_id = :id'),
            'enrollments' => $one('SELECT COUNT(*) FROM course_enrollments WHERE user_id = :id'),
            'attempts'    => $one('SELECT COUNT(*) FROM exam_attempts WHERE user_id = :id'),
        ];
    }
}
