<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Sql;

final class QuestionRepository
{
    private const BASE = 'SELECT q.question_id, q.chapter_id, ch.name AS chapter_name, ch.course_id, c.teacher_id,
                                 q.creator_id, q.type, q.content, q.difficulty, q.explanation, q.is_active, q.created_at
                          FROM questions q
                          JOIN chapters ch ON ch.chapter_id = q.chapter_id
                          JOIN courses c ON c.course_id = ch.course_id';

    public function findWithCourse(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::BASE . ' WHERE q.question_id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 鎖住題目那一列（transaction 內），修改期間不會有別人同時改 */
    public function lock(int $id): void
    {
        Database::connection()->prepare('SELECT question_id FROM questions WHERE question_id = :id FOR UPDATE')->execute(['id' => $id]);
    }

    /**
     * @param array{course_id?:int, chapter_id?:int, type?:string, difficulty?:int, is_active?:bool, tag?:string, q?:string} $f
     * @param int|null $onlyTeacherId 非管理員時只看自己課程的題目
     * @return array{0:list<array>, 1:int}
     */
    public function search(array $f, ?int $onlyTeacherId, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($onlyTeacherId !== null) {
            $where[] = 'c.teacher_id = :teacher';
            $params['teacher'] = $onlyTeacherId;
        }
        foreach (['course_id' => 'ch.course_id', 'chapter_id' => 'q.chapter_id', 'type' => 'q.type', 'difficulty' => 'q.difficulty'] as $key => $column) {
            if (isset($f[$key])) {
                $where[] = "{$column} = :{$key}";
                $params[$key] = $f[$key];
            }
        }
        if (isset($f['is_active'])) {
            $where[] = 'q.is_active = :is_active';
            $params['is_active'] = $f['is_active'] ? 1 : 0;
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = "q.content LIKE :q ESCAPE '!'";
            $params['q'] = Sql::likeContains($f['q']);
        }
        if (($f['tag'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM question_tags qt JOIN tags t ON t.tag_id = qt.tag_id
                                WHERE qt.question_id = q.question_id AND t.name = :tag)';
            $params['tag'] = $f['tag'];
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*) FROM questions q JOIN chapters ch ON ch.chapter_id = q.chapter_id
                                JOIN courses c ON c.course_id = ch.course_id' . $whereSql);
        $count->execute($params);

        $stmt = $pdo->prepare(self::BASE . $whereSql . ' ORDER BY q.question_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
        $stmt->execute($params);

        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    public function insert(int $chapterId, int $creatorId, string $type, string $content, int $difficulty, ?string $explanation): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO questions (chapter_id, creator_id, type, content, difficulty, explanation)
             VALUES (:ch, :cr, :t, :c, :d, :e)'
        )->execute(['ch' => $chapterId, 'cr' => $creatorId, 't' => $type, 'c' => $content, 'd' => $difficulty, 'e' => $explanation]);
        return (int) $pdo->lastInsertId();
    }

    /** 不含 type / content：這兩個欄位只有「尚未被使用」的題目才能改，由 updateCore 處理 */
    public function updateMeta(int $id, int $chapterId, int $difficulty, ?string $explanation): void
    {
        Database::connection()
            ->prepare('UPDATE questions SET chapter_id = :ch, difficulty = :d, explanation = :e WHERE question_id = :id')
            ->execute(['ch' => $chapterId, 'd' => $difficulty, 'e' => $explanation, 'id' => $id]);
    }

    public function updateCore(int $id, string $type, string $content): void
    {
        Database::connection()
            ->prepare('UPDATE questions SET type = :t, content = :c WHERE question_id = :id')
            ->execute(['t' => $type, 'c' => $content, 'id' => $id]);
    }

    public function updatePartial(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['chapter_id', 'difficulty', 'explanation', 'is_active']));
        if ($allowed === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($allowed) as $column) {
            $sets[] = "{$column} = :{$column}";
        }
        if (isset($allowed['is_active'])) {
            $allowed['is_active'] = $allowed['is_active'] ? 1 : 0;
        }
        Database::connection()
            ->prepare('UPDATE questions SET ' . implode(', ', $sets) . ' WHERE question_id = :id')
            ->execute($allowed + ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        Database::connection()
            ->prepare('UPDATE questions SET is_active = :a WHERE question_id = :id')
            ->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }

    /**
     * 「已被使用」：出現在已發佈／已關閉考卷的題目清單，或已有任何作答紀錄。
     * 已被使用的題目不可改題型、題幹與選項（docs/04 §4.3）。
     */
    public function inUse(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT (EXISTS (SELECT 1 FROM exam_questions eq JOIN exams e ON e.exam_id = eq.exam_id
                             WHERE eq.question_id = :q1 AND e.status IN ('published', 'closed'))
                  OR EXISTS (SELECT 1 FROM attempt_answers aa WHERE aa.question_id = :q2)) AS in_use"
        );
        $stmt->execute(['q1' => $id, 'q2' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function options(int $id): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT option_id, content, is_correct, sort_order FROM question_options
             WHERE question_id = :q ORDER BY sort_order, option_id'
        );
        $stmt->execute(['q' => $id]);
        return $stmt->fetchAll();
    }

    public function deleteOptions(int $id): void
    {
        Database::connection()->prepare('DELETE FROM question_options WHERE question_id = :q')->execute(['q' => $id]);
    }

    /** @param list<array{content:string,is_correct:bool,sort_order:int}> $options */
    public function insertOptions(int $id, array $options): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO question_options (question_id, content, is_correct, sort_order) VALUES (:q, :c, :ok, :s)'
        );
        foreach ($options as $o) {
            $stmt->execute(['q' => $id, 'c' => $o['content'], 'ok' => $o['is_correct'] ? 1 : 0, 's' => $o['sort_order']]);
        }
    }

    /** @param list<int> $tagIds  整組取代 */
    public function setTags(int $id, array $tagIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM question_tags WHERE question_id = :q')->execute(['q' => $id]);
        $stmt = $pdo->prepare('INSERT INTO question_tags (question_id, tag_id) VALUES (:q, :t)');
        foreach (array_unique($tagIds) as $tagId) {
            $stmt->execute(['q' => $id, 't' => $tagId]);
        }
    }

    /**
     * @param list<int> $questionIds
     * @return array<int, list<array{tag_id:int,name:string}>> question_id => 標籤清單
     */
    public function tagsFor(array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT qt.question_id, t.tag_id, t.name FROM question_tags qt JOIN tags t ON t.tag_id = qt.tag_id
             WHERE qt.question_id IN ($marks) ORDER BY t.name"
        );
        $stmt->execute(array_values($questionIds));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['question_id']][] = ['tag_id' => (int) $row['tag_id'], 'name' => $row['name']];
        }
        return $out;
    }
}
