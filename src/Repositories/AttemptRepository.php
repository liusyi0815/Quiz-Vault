<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** exam_attempts / attempt_answers / attempt_answer_options 的所有 SQL（含批改） */
final class AttemptRepository
{
    private const PENDING = "(SELECT COUNT(*) FROM attempt_answers pa JOIN questions pq ON pq.question_id = pa.question_id
                              WHERE pa.attempt_id = ea.attempt_id AND pq.type = 'short' AND pa.is_correct IS NULL)";

    // ---------------------------------------------------------------- 鎖

    /** 每位學生在每門課一把鎖：同時按兩次「開始作答」會一個接一個執行 */
    public function lockEnrollment(int $courseId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM course_enrollments WHERE course_id = :c AND user_id = :u FOR UPDATE');
        $stmt->execute(['c' => $courseId, 'u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** 對考卷取共享鎖：開始作答期間，考卷不能同時被撤回或改設定（MySQL 與 MariaDB 都支援這個語法） */
    public function lockExamShared(int $examId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT exam_id, course_id, title, status, duration_min, start_time, end_time, pass_score, max_attempts
             FROM exams WHERE exam_id = :id LOCK IN SHARE MODE'
        );
        $stmt->execute(['id' => $examId]);
        return $stmt->fetch() ?: null;
    }

    public function lock(int $attemptId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT attempt_id, status FROM exam_attempts WHERE attempt_id = :id FOR UPDATE');
        $stmt->execute(['id' => $attemptId]);
        return $stmt->fetch() ?: null;
    }

    // ---------------------------------------------------------------- 查詢

    /** 作答單 + 考卷 + 課程（權限、時間、及格分都從這裡取） */
    public function find(int $attemptId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ea.attempt_id, ea.exam_id, ea.user_id, ea.attempt_no, ea.started_at, ea.submitted_at, ea.total_score, ea.status,
                    e.title, e.duration_min, e.start_time, e.end_time, e.status AS exam_status, e.pass_score, e.max_attempts,
                    e.course_id, c.teacher_id, c.name AS course_name
             FROM exam_attempts ea JOIN exams e ON e.exam_id = ea.exam_id JOIN courses c ON c.course_id = e.course_id
             WHERE ea.attempt_id = :id'
        );
        $stmt->execute(['id' => $attemptId]);
        return $stmt->fetch() ?: null;
    }

    public function countByExamUser(int $examId, int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM exam_attempts WHERE exam_id = :e AND user_id = :u');
        $stmt->execute(['e' => $examId, 'u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function inProgressId(int $examId, int $userId): ?int
    {
        $stmt = Database::connection()->prepare("SELECT attempt_id FROM exam_attempts WHERE exam_id = :e AND user_id = :u AND status = 'in_progress' LIMIT 1");
        $stmt->execute(['e' => $examId, 'u' => $userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** 每題一列：作答內容 + 題目 + 這份考卷裡的配分與順序 */
    public function answers(int $attemptId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT aa.answer_id, aa.question_id, aa.answer_text, aa.is_correct, aa.score_awarded,
                    q.type, q.content, q.explanation, eq.score, eq.sort_order
             FROM exam_attempts ea
             JOIN attempt_answers aa ON aa.attempt_id = ea.attempt_id
             JOIN questions q ON q.question_id = aa.question_id
             JOIN exam_questions eq ON eq.exam_id = ea.exam_id AND eq.question_id = aa.question_id
             WHERE ea.attempt_id = :a ORDER BY eq.sort_order, aa.question_id'
        );
        $stmt->execute(['a' => $attemptId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, list<int>> answer_id => 勾選的 option_id */
    public function chosenOptionIds(int $attemptId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT x.answer_id, x.option_id FROM attempt_answer_options x
             JOIN attempt_answers aa ON aa.answer_id = x.answer_id WHERE aa.attempt_id = :a ORDER BY x.option_id'
        );
        $stmt->execute(['a' => $attemptId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['answer_id']][] = (int) $r['option_id'];
        }
        return $out;
    }

    /**
     * @param list<int> $questionIds
     * @return array<int, list<array>> question_id => 選項（$withCorrect 為 false 時完全不查 is_correct）
     */
    public function optionsFor(array $questionIds, bool $withCorrect): array
    {
        if ($questionIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($questionIds), '?'));
        $cols = $withCorrect ? 'question_id, option_id, content, is_correct' : 'question_id, option_id, content';
        $stmt = Database::connection()->prepare("SELECT {$cols} FROM question_options WHERE question_id IN ($marks) ORDER BY question_id, sort_order, option_id");
        $stmt->execute(array_values($questionIds));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['question_id']][] = $r;
        }
        return $out;
    }

    /** 這次作答中的某一題（沒有就代表這題不屬於這次作答） */
    public function answerRow(int $attemptId, int $questionId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT aa.answer_id, q.type FROM attempt_answers aa JOIN questions q ON q.question_id = aa.question_id
             WHERE aa.attempt_id = :a AND aa.question_id = :q'
        );
        $stmt->execute(['a' => $attemptId, 'q' => $questionId]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<int> */
    public function validOptionIds(int $questionId): array
    {
        $stmt = Database::connection()->prepare('SELECT option_id FROM question_options WHERE question_id = :q');
        $stmt->execute(['q' => $questionId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function pendingCount(int $attemptId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM attempt_answers aa JOIN questions q ON q.question_id = aa.question_id
             WHERE aa.attempt_id = :a AND q.type = 'short' AND aa.is_correct IS NULL"
        );
        $stmt->execute(['a' => $attemptId]);
        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------- 開始作答

    public function insert(int $examId, int $userId, int $attemptNo, string $startedAt): int
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO exam_attempts (exam_id, user_id, attempt_no, started_at, status) VALUES (:e, :u, :n, :s, 'in_progress')")
            ->execute(['e' => $examId, 'u' => $userId, 'n' => $attemptNo, 's' => $startedAt]);
        return (int) $pdo->lastInsertId();
    }

    /** 為考卷的每一題預先建立一列（沒作答的題目也看得到，統計才不會漏） */
    public function insertBlankAnswers(int $attemptId, int $examId): void
    {
        Database::connection()->prepare(
            'INSERT INTO attempt_answers (attempt_id, question_id)
             SELECT :a, eq.question_id FROM exam_questions eq WHERE eq.exam_id = :e ORDER BY eq.sort_order'
        )->execute(['a' => $attemptId, 'e' => $examId]);
    }

    // ---------------------------------------------------------------- 暫存答案

    /** @param list<int> $optionIds 整組取代這一題勾選的選項 */
    public function replaceChosen(int $answerId, array $optionIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM attempt_answer_options WHERE answer_id = :a')->execute(['a' => $answerId]);
        $stmt = $pdo->prepare('INSERT INTO attempt_answer_options (answer_id, option_id) VALUES (:a, :o)');
        foreach ($optionIds as $o) {
            $stmt->execute(['a' => $answerId, 'o' => $o]);
        }
    }

    public function setAnswerText(int $answerId, ?string $text): void
    {
        Database::connection()->prepare('UPDATE attempt_answers SET answer_text = :t WHERE answer_id = :a')->execute(['t' => $text, 'a' => $answerId]);
    }

    // ---------------------------------------------------------------- 批改（docs/04 §9.2）

    /**
     * 自動批改並結算，必須在 transaction 內、且已鎖住這張作答單時呼叫。
     * MySQL 的多表 UPDATE 不保證 SET 的賦值順序，所以「判定對錯」與「給分」分成兩個 UPDATE。
     */
    public function grade(int $attemptId, string $submittedAt): void
    {
        $pdo = Database::connection();

        // 步驟一：單選、多選、是非——勾選的集合必須「完全等於」正確選項的集合（沒勾 = 答錯）
        $pdo->prepare(
            "UPDATE attempt_answers aa
             JOIN questions q ON q.question_id = aa.question_id AND q.type <> 'short'
             SET aa.is_correct = (
                   EXISTS (SELECT 1 FROM attempt_answer_options x WHERE x.answer_id = aa.answer_id)
               AND NOT EXISTS (SELECT 1 FROM attempt_answer_options x JOIN question_options o ON o.option_id = x.option_id
                               WHERE x.answer_id = aa.answer_id AND o.is_correct = 0)
               AND NOT EXISTS (SELECT 1 FROM question_options o
                               WHERE o.question_id = aa.question_id AND o.is_correct = 1
                                 AND NOT EXISTS (SELECT 1 FROM attempt_answer_options x
                                                 WHERE x.answer_id = aa.answer_id AND x.option_id = o.option_id))
             )
             WHERE aa.attempt_id = :a"
        )->execute(['a' => $attemptId]);

        // 步驟二：答對得配分，否則 0
        $pdo->prepare(
            "UPDATE attempt_answers aa
             JOIN exam_attempts ea ON ea.attempt_id = aa.attempt_id
             JOIN exam_questions eq ON eq.exam_id = ea.exam_id AND eq.question_id = aa.question_id
             JOIN questions q ON q.question_id = aa.question_id AND q.type <> 'short'
             SET aa.score_awarded = IF(aa.is_correct = 1, eq.score, 0)
             WHERE aa.attempt_id = :a"
        )->execute(['a' => $attemptId]);

        // 簡答題：沒有作答（空白）就直接 0 分，不必麻煩老師；有作答的維持 NULL，等人工評分
        $pdo->prepare(
            "UPDATE attempt_answers aa JOIN questions q ON q.question_id = aa.question_id AND q.type = 'short'
             SET aa.is_correct = 0, aa.score_awarded = 0
             WHERE aa.attempt_id = :a AND aa.is_correct IS NULL AND (aa.answer_text IS NULL OR TRIM(aa.answer_text) = '')"
        )->execute(['a' => $attemptId]);

        // 步驟三：加總；還有待評分的簡答題就停在 submitted，否則 graded
        $pdo->prepare(
            "UPDATE exam_attempts ea
             SET ea.total_score = (SELECT COALESCE(SUM(score_awarded), 0) FROM attempt_answers WHERE attempt_id = ea.attempt_id),
                 ea.submitted_at = :now,
                 ea.status = IF(EXISTS (SELECT 1 FROM attempt_answers aa JOIN questions q ON q.question_id = aa.question_id
                                        WHERE aa.attempt_id = ea.attempt_id AND q.type = 'short' AND aa.is_correct IS NULL),
                                'submitted', 'graded')
             WHERE ea.attempt_id = :a"
        )->execute(['now' => $submittedAt, 'a' => $attemptId]);
    }

    // ---------------------------------------------------------------- 逾時

    /** 已逾時（超過 deadline + 寬限，$cutoff = 現在 - 寬限）但還在作答中的作答單 */
    public function overdueIds(string $cutoff, ?int $userId, ?int $examId, int $limit): array
    {
        $where = ["ea.status = 'in_progress'",
                  "LEAST(DATE_ADD(ea.started_at, INTERVAL e.duration_min MINUTE), COALESCE(e.end_time, '9999-12-31 23:59:59')) < :cutoff"];
        $params = ['cutoff' => $cutoff];
        if ($userId !== null) {
            $where[] = 'ea.user_id = :u';
            $params['u'] = $userId;
        }
        if ($examId !== null) {
            $where[] = 'ea.exam_id = :e';
            $params['e'] = $examId;
        }
        $stmt = Database::connection()->prepare(
            'SELECT ea.attempt_id FROM exam_attempts ea JOIN exams e ON e.exam_id = ea.exam_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY ea.attempt_id LIMIT ' . (int) $limit
        );
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ---------------------------------------------------------------- 列表

    /** @return array{0:list<array>, 1:int} */
    public function listByUser(int $userId, array $f, int $limit, int $offset): array
    {
        $where = ['ea.user_id = :u'];
        $params = ['u' => $userId];
        foreach (['exam_id' => 'ea.exam_id', 'course_id' => 'e.course_id'] as $key => $column) {
            if (isset($f[$key])) {
                $where[] = "{$column} = :{$key}";
                $params[$key] = $f[$key];
            }
        }
        $from = ' FROM exam_attempts ea JOIN exams e ON e.exam_id = ea.exam_id JOIN courses c ON c.course_id = e.course_id WHERE ' . implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT ea.attempt_id, ea.exam_id, ea.attempt_no, ea.started_at, ea.submitted_at, ea.total_score, ea.status,
                    e.title, e.course_id, c.name AS course_name, e.pass_score, e.duration_min, e.end_time, ' . self::PENDING . ' AS pending'
            . $from . ' ORDER BY ea.attempt_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    /** @param array{status?:string, pending?:bool} $f @return array{0:list<array>, 1:int} */
    public function listByExam(int $examId, array $f, int $limit, int $offset): array
    {
        $where = ['ea.exam_id = :e'];
        $params = ['e' => $examId];
        if (isset($f['status'])) {
            $where[] = 'ea.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['pending'])) {
            $where[] = self::PENDING . ' > 0';
        }
        $from = ' FROM exam_attempts ea JOIN users u ON u.user_id = ea.user_id WHERE ' . implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT ea.attempt_id, ea.user_id, u.username, u.email, ea.attempt_no, ea.started_at, ea.submitted_at, ea.total_score, ea.status, '
            . self::PENDING . ' AS pending' . $from . ' ORDER BY ea.attempt_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }
}
