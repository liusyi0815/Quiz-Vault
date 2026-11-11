<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Sql;

final class CourseRepository
{
    private const SELECT = 'SELECT c.course_id, c.teacher_id, c.name, c.description, c.created_at, u.username AS teacher_name';

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            self::SELECT . ' FROM courses c JOIN users u ON u.user_id = c.teacher_id WHERE c.course_id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 鎖住課程那一列（在 transaction 內使用），避免刪除課程的同時有人新增章節 */
    public function lockById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT course_id, teacher_id FROM courses WHERE course_id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{q?:?string, mine?:bool} $filters
     * @return array{0:list<array>, 1:int} [本頁資料, 符合條件的總筆數]
     */
    public function search(array $filters, array $viewer, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = "c.name LIKE :q ESCAPE '!'";
            $params['q'] = Sql::likeContains($filters['q']);
        }
        if (!empty($filters['mine'])) {
            if ($viewer['role'] === 'teacher') {
                $where[] = 'c.teacher_id = :mine_teacher';
                $params['mine_teacher'] = $viewer['user_id'];
            } elseif ($viewer['role'] === 'student') {
                $where[] = 'EXISTS (SELECT 1 FROM course_enrollments m WHERE m.course_id = c.course_id AND m.user_id = :mine_student)';
                $params['mine_student'] = $viewer['user_id'];
            }   // 管理員的「我的」= 全部
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*) FROM courses c' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        // $limit / $offset 由 Page 保證是整數，直接放進 SQL 是安全的
        $stmt = $pdo->prepare(
            self::SELECT . ',
                    (SELECT COUNT(*) FROM course_enrollments e WHERE e.course_id = c.course_id AND e.user_id = :viewer) AS enrolled
             FROM courses c JOIN users u ON u.user_id = c.teacher_id' . $whereSql . '
             ORDER BY c.course_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params + ['viewer' => $viewer['user_id']]);

        return [$stmt->fetchAll(), $total];
    }

    public function create(int $teacherId, string $name, ?string $description): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO courses (teacher_id, name, description) VALUES (:t, :n, :d)')
            ->execute(['t' => $teacherId, 'n' => $name, 'd' => $description]);
        return (int) $pdo->lastInsertId();
    }

    /** 只允許更新白名單內的欄位 */
    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['name', 'description']));
        if ($allowed === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($allowed) as $column) {
            $sets[] = "{$column} = :{$column}";
        }
        Database::connection()
            ->prepare('UPDATE courses SET ' . implode(', ', $sets) . ' WHERE course_id = :id')
            ->execute($allowed + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM courses WHERE course_id = :id')->execute(['id' => $id]);
    }

    /** 課程底下的各種數量（刪除前檢查、詳情頁顯示用） */
    public function counts(int $id): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT
               (SELECT COUNT(*) FROM chapters WHERE course_id = :c1) AS chapters,
               (SELECT COUNT(*) FROM exams WHERE course_id = :c2) AS exams,
               (SELECT COUNT(*) FROM course_enrollments WHERE course_id = :c3) AS students,
               (SELECT COUNT(*) FROM questions q JOIN chapters ch ON ch.chapter_id = q.chapter_id
                 WHERE ch.course_id = :c4) AS questions'
        );
        $stmt->execute(['c1' => $id, 'c2' => $id, 'c3' => $id, 'c4' => $id]);
        return array_map('intval', $stmt->fetch());
    }
}
