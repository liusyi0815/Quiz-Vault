<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ExamRepository
{
    private const COLUMNS = 'e.exam_id, e.course_id, e.creator_id, e.title, e.duration_min, e.start_time, e.end_time,
                             e.pass_score, e.max_attempts, e.status, e.created_at, c.teacher_id, c.name AS course_name';

    private const TOTALS = '(SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.exam_id) AS question_count,
                            (SELECT COALESCE(SUM(eq.score), 0) FROM exam_questions eq WHERE eq.exam_id = e.exam_id) AS total_score';

    // ---------------------------------------------------------------- 查詢

    public function findWithCourse(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ', ' . self::TOTALS . '
             FROM exams e JOIN courses c ON c.course_id = e.course_id WHERE e.exam_id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 鎖住考卷那一列（transaction 內），狀態轉換與改題目期間不會被別人同時改 */
    public function lock(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT exam_id, status FROM exams WHERE exam_id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * 學生只看得到「自己已修課程」且已發佈／已關閉的考卷；老師看自己課程的；管理員看全部。
     *
     * @param array{course_id?:int, status?:string} $f
     * @return array{0:list<array>, 1:int}
     */
    public function search(array $f, array $viewer, int $limit, int $offset): array
    {
        $join = '';
        $where = [];
        $params = [];
        $studentCols = '';
        $studentParams = [];

        if ($viewer['role'] === 'student') {
            $join = ' JOIN course_enrollments en ON en.course_id = e.course_id AND en.user_id = :me';
            $params['me'] = $viewer['user_id'];
            $where[] = "e.status IN ('published', 'closed')";
            $studentCols = ",
                (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = e.exam_id AND a.user_id = :me_used) AS my_used,
                (SELECT MAX(a.total_score) FROM exam_attempts a WHERE a.exam_id = e.exam_id AND a.user_id = :me_best
                   AND a.status IN ('submitted', 'graded')) AS my_best,
                (SELECT a.attempt_id FROM exam_attempts a WHERE a.exam_id = e.exam_id AND a.user_id = :me_prog
                   AND a.status = 'in_progress' LIMIT 1) AS my_in_progress";
            $studentParams = ['me_used' => $viewer['user_id'], 'me_best' => $viewer['user_id'], 'me_prog' => $viewer['user_id']];
        } elseif ($viewer['role'] === 'teacher') {
            $where[] = 'c.teacher_id = :teacher';
            $params['teacher'] = $viewer['user_id'];
        }
        if (isset($f['course_id'])) {
            $where[] = 'e.course_id = :course_id';
            $params['course_id'] = $f['course_id'];
        }
        if (isset($f['status'])) {
            $where[] = 'e.status = :status';
            $params['status'] = $f['status'];
        }
        $from = ' FROM exams e JOIN courses c ON c.course_id = e.course_id' . $join
              . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ', ' . self::TOTALS . $studentCols . $from
            . ' ORDER BY e.exam_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params + $studentParams);

        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    /** 學生對這份考卷的作答摘要 */
    public function studentSummary(int $examId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS used,
                    MAX(CASE WHEN status IN ('submitted', 'graded') THEN total_score END) AS best,
                    MAX(CASE WHEN status = 'in_progress' THEN attempt_id END) AS in_progress
             FROM exam_attempts WHERE exam_id = :e AND user_id = :u"
        );
        $stmt->execute(['e' => $examId, 'u' => $userId]);
        return $stmt->fetch();
    }

    public function attemptCount(int $examId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM exam_attempts WHERE exam_id = :e');
        $stmt->execute(['e' => $examId]);
        return (int) $stmt->fetchColumn();
    }

    /** 考卷的題目清單（含題目基本資料，不含選項）*/
    public function questions(int $examId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT eq.question_id, eq.score, eq.sort_order, q.type, q.content, q.difficulty, q.is_active,
                    ch.chapter_id, ch.name AS chapter_name, ch.course_id
             FROM exam_questions eq
             JOIN questions q ON q.question_id = eq.question_id
             JOIN chapters ch ON ch.chapter_id = q.chapter_id
             WHERE eq.exam_id = :e ORDER BY eq.sort_order, eq.question_id'
        );
        $stmt->execute(['e' => $examId]);
        return $stmt->fetchAll();
    }

    /** 題目清單 + 每題的選項（發佈檢查用） */
    public function questionsWithOptions(int $examId): array
    {
        $rows = $this->questions($examId);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['question_id'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT question_id, content, is_correct FROM question_options WHERE question_id IN ($marks) ORDER BY question_id, sort_order, option_id"
        );
        $stmt->execute($ids);
        $byQuestion = [];
        foreach ($stmt->fetchAll() as $o) {
            $byQuestion[(int) $o['question_id']][] = ['content' => $o['content'], 'is_correct' => (bool) $o['is_correct']];
        }
        foreach ($rows as &$r) {
            $r['options'] = $byQuestion[(int) $r['question_id']] ?? [];
        }
        return $rows;
    }

    /**
     * 這門課程底下，指定的題目 ID 中實際存在的有哪些（question_id => is_active）。
     * 不屬於這門課程或不存在的題目不會出現在結果裡。
     */
    public function questionsInCourse(int $courseId, array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT q.question_id, q.is_active FROM questions q JOIN chapters ch ON ch.chapter_id = q.chapter_id
             WHERE ch.course_id = ? AND q.question_id IN ($marks)"
        );
        $stmt->execute([$courseId, ...array_values($questionIds)]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['question_id']] = (bool) $r['is_active'];
        }
        return $out;
    }

    // ---------------------------------------------------------------- 寫入

    public function insert(int $courseId, int $creatorId, array $f): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO exams (course_id, creator_id, title, duration_min, start_time, end_time, pass_score, max_attempts, status)
             VALUES (:c, :u, :t, :d, :s, :e, :p, :m, 'draft')"
        )->execute([
            'c' => $courseId, 'u' => $creatorId, 't' => $f['title'], 'd' => $f['duration_min'],
            's' => $f['start_time'], 'e' => $f['end_time'], 'p' => $f['pass_score'], 'm' => $f['max_attempts'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** 只允許更新白名單內的欄位 */
    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['title', 'duration_min', 'start_time', 'end_time', 'pass_score', 'max_attempts']));
        if ($allowed === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($allowed) as $column) {
            $sets[] = "{$column} = :{$column}";
        }
        Database::connection()
            ->prepare('UPDATE exams SET ' . implode(', ', $sets) . ' WHERE exam_id = :id')
            ->execute($allowed + ['id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE exams SET status = :s WHERE exam_id = :id')->execute(['s' => $status, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM exams WHERE exam_id = :id')->execute(['id' => $id]);   // exam_questions 連動刪除
    }

    /** @param list<array{question_id:int,score:float,sort_order:int}> $items  整份取代 */
    public function replaceQuestions(int $examId, array $items): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM exam_questions WHERE exam_id = :e')->execute(['e' => $examId]);
        $this->insertQuestions($examId, $items);
    }

    /** @param list<array{question_id:int,score:float,sort_order:int}> $items */
    public function insertQuestions(int $examId, array $items): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO exam_questions (exam_id, question_id, score, sort_order) VALUES (:e, :q, :s, :o)'
        );
        foreach ($items as $it) {
            $stmt->execute(['e' => $examId, 'q' => $it['question_id'], 's' => $it['score'], 'o' => $it['sort_order']]);
        }
    }

    public function nextQuestionOrder(int $examId): int
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM exam_questions WHERE exam_id = :e');
        $stmt->execute(['e' => $examId]);
        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------- 隨機抽題

    /** @return array{0:string,1:array} [WHERE 條件, 參數]：啟用中、在指定章節（與標籤）內、尚未在這份考卷裡 */
    private function candidateWhere(int $examId, int $courseId, array $chapterIds, array $tags): array
    {
        $where = ['ch.course_id = :course', 'q.is_active = 1',
                  'q.question_id NOT IN (SELECT x.question_id FROM exam_questions x WHERE x.exam_id = :exam)'];
        $params = ['course' => $courseId, 'exam' => $examId];

        if ($chapterIds !== []) {
            $names = [];
            foreach (array_values($chapterIds) as $i => $id) {
                $names[] = ":chapter{$i}";
                $params["chapter{$i}"] = $id;
            }
            $where[] = 'q.chapter_id IN (' . implode(',', $names) . ')';
        }
        if ($tags !== []) {
            $names = [];
            foreach (array_values($tags) as $i => $tag) {
                $names[] = ":tag{$i}";
                $params["tag{$i}"] = $tag;
            }
            // 帶有「任何一個」指定標籤的題目
            $where[] = 'EXISTS (SELECT 1 FROM question_tags qt JOIN tags t ON t.tag_id = qt.tag_id
                                WHERE qt.question_id = q.question_id AND t.name IN (' . implode(',', $names) . '))';
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return array<int,int> 難度 => 可抽的題數 */
    public function availableByDifficulty(int $examId, int $courseId, array $chapterIds, array $tags): array
    {
        [$where, $params] = $this->candidateWhere($examId, $courseId, $chapterIds, $tags);
        $stmt = Database::connection()->prepare(
            "SELECT q.difficulty, COUNT(*) AS n FROM questions q JOIN chapters ch ON ch.chapter_id = q.chapter_id
             WHERE {$where} GROUP BY q.difficulty"
        );
        $stmt->execute($params);
        $out = [1 => 0, 2 => 0, 3 => 0];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['difficulty']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<int> 隨機抽出的 question_id（$difficulty 為 null 代表不限難度） */
    public function drawIds(int $examId, int $courseId, array $chapterIds, array $tags, ?int $difficulty, int $n): array
    {
        [$where, $params] = $this->candidateWhere($examId, $courseId, $chapterIds, $tags);
        if ($difficulty !== null) {
            $where .= ' AND q.difficulty = :difficulty';
            $params['difficulty'] = $difficulty;
        }
        $stmt = Database::connection()->prepare(
            "SELECT q.question_id FROM questions q JOIN chapters ch ON ch.chapter_id = q.chapter_id
             WHERE {$where} ORDER BY RAND() LIMIT " . (int) $n   // $n 由 Service 保證是整數
        );
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
