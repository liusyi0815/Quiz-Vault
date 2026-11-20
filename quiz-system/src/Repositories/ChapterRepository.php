<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ChapterRepository
{
    /** 章節 + 所屬課程的 teacher_id（權限判斷用） */
    public function findWithCourse(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ch.chapter_id, ch.course_id, ch.name, ch.sort_order, c.teacher_id
             FROM chapters ch JOIN courses c ON c.course_id = ch.course_id
             WHERE ch.chapter_id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function listByCourse(int $courseId, bool $withCounts): array
    {
        $count = $withCounts
            ? ', (SELECT COUNT(*) FROM questions q WHERE q.chapter_id = ch.chapter_id) AS question_count'
            : '';
        $stmt = Database::connection()->prepare(
            'SELECT ch.chapter_id, ch.course_id, ch.name, ch.sort_order' . $count . '
             FROM chapters ch WHERE ch.course_id = :c ORDER BY ch.sort_order, ch.chapter_id'
        );
        $stmt->execute(['c' => $courseId]);
        return $stmt->fetchAll();
    }

    public function idsByCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare('SELECT chapter_id FROM chapters WHERE course_id = :c');
        $stmt->execute(['c' => $courseId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function nextSortOrder(int $courseId): int
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM chapters WHERE course_id = :c');
        $stmt->execute(['c' => $courseId]);
        return (int) $stmt->fetchColumn();
    }

    public function create(int $courseId, string $name, int $sortOrder): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO chapters (course_id, name, sort_order) VALUES (:c, :n, :s)')
            ->execute(['c' => $courseId, 'n' => $name, 's' => $sortOrder]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['name', 'sort_order']));
        if ($allowed === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($allowed) as $column) {
            $sets[] = "{$column} = :{$column}";
        }
        Database::connection()
            ->prepare('UPDATE chapters SET ' . implode(', ', $sets) . ' WHERE chapter_id = :id')
            ->execute($allowed + ['id' => $id]);
    }

    public function setSortOrder(int $id, int $sortOrder): void
    {
        Database::connection()
            ->prepare('UPDATE chapters SET sort_order = :s WHERE chapter_id = :id')
            ->execute(['s' => $sortOrder, 'id' => $id]);
    }

    public function countQuestions(int $id): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM questions WHERE chapter_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM chapters WHERE chapter_id = :id')->execute(['id' => $id]);
    }
}
