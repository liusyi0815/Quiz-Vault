<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** F-16 收藏與錯題本。「可檢視解析」＝考卷已關閉，或已發佈且超過結束時間（docs/04 §11）。 */
final class BookmarkRepository
{
    /** 已可檢視解析的 SQL 條件；$e 是 exams 的別名，$param 是現在時間的具名參數（不含冒號） */
    private static function revealed(string $e, string $param): string
    {
        return "({$e}.status = 'closed' OR ({$e}.status = 'published' AND {$e}.end_time IS NOT NULL AND {$e}.end_time < :{$param}))";
    }

    /** 這位學生是否「作答過這一題，而且那份考卷已可檢視解析」 */
    public function canView(int $userId, int $questionId, string $now): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM attempt_answers aa
             JOIN exam_attempts ea ON ea.attempt_id = aa.attempt_id AND ea.user_id = :u AND ea.status IN ('submitted', 'graded')
             JOIN exams e ON e.exam_id = ea.exam_id
             WHERE aa.question_id = :q AND " . self::revealed('e', 'now') . ' LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'q' => $questionId, 'now' => $now]);
        return $stmt->fetchColumn() !== false;
    }

    /** 重複收藏等同更新筆記（冪等） */
    public function upsert(int $userId, int $questionId, ?string $note): void
    {
        Database::connection()->prepare(
            'INSERT INTO bookmarks (user_id, question_id, note) VALUES (:u, :q, :n)
             ON DUPLICATE KEY UPDATE note = VALUES(note)'
        )->execute(['u' => $userId, 'q' => $questionId, 'n' => $note]);
    }

    public function find(int $userId, int $questionId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT question_id, note, created_at FROM bookmarks WHERE user_id = :u AND question_id = :q');
        $stmt->execute(['u' => $userId, 'q' => $questionId]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $userId, int $questionId): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM bookmarks WHERE user_id = :u AND question_id = :q');
        $stmt->execute(['u' => $userId, 'q' => $questionId]);
        return $stmt->rowCount();
    }

    /** @return array{0:list<array>, 1:int} */
    public function list(int $userId, ?int $courseId, string $now, int $limit, int $offset): array
    {
        $pdo = Database::connection();
        $where = 'b.user_id = :u';
        $params = ['u' => $userId];
        if ($courseId !== null) {
            $where .= ' AND c.course_id = :course';
            $params['course'] = $courseId;
        }
        $from = "FROM bookmarks b
                 JOIN questions q ON q.question_id = b.question_id
                 JOIN chapters c ON c.chapter_id = q.chapter_id
                 JOIN courses co ON co.course_id = c.course_id
                 WHERE {$where}";

        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);

        $stmt = $pdo->prepare(
            "SELECT q.question_id, q.type, q.content, q.explanation, q.chapter_id, c.name AS chapter_name,
                    co.course_id, co.name AS course_name, b.note, b.created_at,
                    EXISTS (SELECT 1 FROM attempt_answers aa
                            JOIN exam_attempts ea ON ea.attempt_id = aa.attempt_id AND ea.user_id = b.user_id AND ea.status IN ('submitted', 'graded')
                            JOIN exams e ON e.exam_id = ea.exam_id
                            WHERE aa.question_id = b.question_id AND " . self::revealed('e', 'now') . ") AS revealed
             {$from}
             ORDER BY b.created_at DESC, b.question_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params + ['now' => $now]);
        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    /**
     * 錯題本：已可檢視解析的考卷中，is_correct = 0 的題目；同一題多次答錯合併。
     * $f：course_id、chapter_id 選填。
     * @return array{0:list<array>, 1:int}
     */
    public function wrongQuestions(int $userId, array $f, string $now, int $limit, int $offset): array
    {
        $pdo = Database::connection();
        $where = ["ea.user_id = :u", "ea.status IN ('submitted', 'graded')", 'aa.is_correct = 0', self::revealed('e', 'now')];
        $params = ['u' => $userId, 'now' => $now];
        if (isset($f['course_id'])) {
            $where[] = 'co.course_id = :course';
            $params['course'] = $f['course_id'];
        }
        if (isset($f['chapter_id'])) {
            $where[] = 'q.chapter_id = :chapter';
            $params['chapter'] = $f['chapter_id'];
        }
        $from = 'FROM attempt_answers aa
                 JOIN exam_attempts ea ON ea.attempt_id = aa.attempt_id
                 JOIN exams e ON e.exam_id = ea.exam_id
                 JOIN questions q ON q.question_id = aa.question_id
                 JOIN chapters c ON c.chapter_id = q.chapter_id
                 JOIN courses co ON co.course_id = c.course_id
                 WHERE ' . implode(' AND ', $where);

        $count = $pdo->prepare("SELECT COUNT(DISTINCT aa.question_id) {$from}");
        $count->execute($params);

        $stmt = $pdo->prepare(
            "SELECT q.question_id, q.type, q.content, q.explanation, q.chapter_id, c.name AS chapter_name,
                    co.course_id, co.name AS course_name,
                    COUNT(*) AS wrong_times, MAX(ea.submitted_at) AS last_wrong_at,
                    EXISTS (SELECT 1 FROM bookmarks b WHERE b.user_id = :u2 AND b.question_id = q.question_id) AS bookmarked
             {$from}
             GROUP BY q.question_id, q.type, q.content, q.explanation, q.chapter_id, c.name, co.course_id, co.name
             ORDER BY last_wrong_at DESC, q.question_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params + ['u2' => $userId]);
        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    /**
     * 這些題目「最近一次答錯」時的答案（選了哪些選項／簡答內容）。
     * @param list<int> $questionIds
     * @return array<int,array{attempt_id:int, answer_text:?string, option_ids:list<int>}> question_id => 答案
     */
    public function lastWrongAnswers(int $userId, array $questionIds, string $now): array
    {
        if ($questionIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT aa.answer_id, aa.question_id, aa.answer_text, ea.attempt_id
             FROM attempt_answers aa
             JOIN exam_attempts ea ON ea.attempt_id = aa.attempt_id
             JOIN exams e ON e.exam_id = ea.exam_id
             WHERE ea.user_id = ? AND ea.status IN ('submitted', 'graded') AND aa.is_correct = 0
               AND aa.question_id IN ({$marks}) AND " . str_replace(':now', '?', self::revealed('e', 'now')) . '
             ORDER BY ea.submitted_at DESC, ea.attempt_id DESC'
        );
        $stmt->execute([$userId, ...array_values($questionIds), $now]);

        $latest = [];
        foreach ($stmt->fetchAll() as $r) {
            $latest[(int) $r['question_id']] ??= $r;                      // 排序後第一筆就是最近一次
        }
        if ($latest === []) {
            return [];
        }

        $answerIds = array_values(array_map(static fn (array $r): int => (int) $r['answer_id'], $latest));
        $m2 = implode(',', array_fill(0, count($answerIds), '?'));
        $opt = Database::connection()->prepare("SELECT answer_id, option_id FROM attempt_answer_options WHERE answer_id IN ({$m2}) ORDER BY option_id");
        $opt->execute($answerIds);
        $chosen = [];
        foreach ($opt->fetchAll() as $o) {
            $chosen[(int) $o['answer_id']][] = (int) $o['option_id'];
        }

        $out = [];
        foreach ($latest as $qid => $r) {
            $out[$qid] = [
                'attempt_id'  => (int) $r['attempt_id'],
                'answer_text' => $r['answer_text'],
                'option_ids'  => $chosen[(int) $r['answer_id']] ?? [],
            ];
        }
        return $out;
    }
}
