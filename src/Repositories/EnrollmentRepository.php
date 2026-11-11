<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Sql;

final class EnrollmentRepository
{
    /** 重複加入會因主鍵 (course_id, user_id) 丟出 1062，由 Service 轉成 ALREADY_ENROLLED */
    public function enroll(int $courseId, int $userId): void
    {
        Database::connection()
            ->prepare('INSERT INTO course_enrollments (course_id, user_id) VALUES (:c, :u)')
            ->execute(['c' => $courseId, 'u' => $userId]);
    }

    /** @return int 實際刪除的列數（0 = 本來就沒修這門課） */
    public function remove(int $courseId, int $userId): int
    {
        $stmt = Database::connection()
            ->prepare('DELETE FROM course_enrollments WHERE course_id = :c AND user_id = :u');
        $stmt->execute(['c' => $courseId, 'u' => $userId]);
        return $stmt->rowCount();
    }

    public function find(int $courseId, int $userId): ?array
    {
        $stmt = Database::connection()
            ->prepare('SELECT course_id, user_id, enrolled_at FROM course_enrollments WHERE course_id = :c AND user_id = :u');
        $stmt->execute(['c' => $courseId, 'u' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array{0:list<array>, 1:int} */
    public function students(int $courseId, ?string $q, int $limit, int $offset): array
    {
        $where = 'e.course_id = :c';
        $params = ['c' => $courseId];
        if ($q !== null && $q !== '') {
            $where .= " AND (u.username LIKE :q1 ESCAPE '!' OR u.email LIKE :q2 ESCAPE '!')";
            $params['q1'] = $params['q2'] = Sql::likeContains($q);
        }
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*) FROM course_enrollments e JOIN users u ON u.user_id = e.user_id WHERE ' . $where);
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.username, u.email, e.enrolled_at
             FROM course_enrollments e JOIN users u ON u.user_id = e.user_id
             WHERE ' . $where . ' ORDER BY e.enrolled_at, u.user_id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);

        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }
}
