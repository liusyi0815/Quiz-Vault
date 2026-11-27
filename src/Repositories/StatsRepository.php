<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * F-15 統計查詢。所有統計都只納入 status = 'graded' 的作答
 * （還有簡答題待評分的作答不納入，避免把「還沒評」當成「答錯」）。
 */
final class StatsRepository
{
    /** 這份考卷的配分總和 */
    public function examTotalPoints(int $examId): float
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(score), 0) FROM exam_questions WHERE exam_id = :e');
        $stmt->execute(['e' => $examId]);
        return (float) $stmt->fetchColumn();
    }

    /** @return list<float> 已批改完成的每一次作答的總分 */
    public function gradedScores(int $examId): array
    {
        $stmt = Database::connection()->prepare("SELECT total_score FROM exam_attempts WHERE exam_id = :e AND status = 'graded'");
        $stmt->execute(['e' => $examId]);
        return array_map('floatval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string,int> status => 筆數 */
    public function attemptCountsByStatus(int $examId): array
    {
        $stmt = Database::connection()->prepare('SELECT status, COUNT(*) AS n FROM exam_attempts WHERE exam_id = :e GROUP BY status');
        $stmt->execute(['e' => $examId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * 各題答對率（由低到高）。$f：exam_id、chapter_id 選填。
     * @return array{0:list<array>, 1:int}
     */
    public function questionRates(int $courseId, array $f, int $limit, int $offset): array
    {
        $pdo = Database::connection();
        $where = ["e.course_id = :course", "ea.status = 'graded'"];
        $params = ['course' => $courseId];
        if (isset($f['exam_id'])) {
            $where[] = 'ea.exam_id = :exam';
            $params['exam'] = $f['exam_id'];
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
                 WHERE ' . implode(' AND ', $where);

        $count = $pdo->prepare("SELECT COUNT(DISTINCT aa.question_id) {$from}");
        $count->execute($params);

        $stmt = $pdo->prepare(
            "SELECT q.question_id, q.type, q.content, q.chapter_id, c.name AS chapter_name,
                    COUNT(*) AS answered, SUM(aa.is_correct = 1) AS correct,
                    ROUND(100 * AVG(aa.is_correct = 1), 1) AS correct_rate
             {$from}
             GROUP BY q.question_id, q.type, q.content, q.chapter_id, c.name
             ORDER BY correct_rate ASC, answered DESC, q.question_id ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    /** 班級各章節的答對率（由低到高） */
    public function chapterRatesForCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.chapter_id, c.name, COUNT(*) AS total, SUM(aa.is_correct = 1) AS correct,
                    ROUND(100 * AVG(aa.is_correct = 1), 1) AS correct_rate
             FROM attempt_answers aa
             JOIN exam_attempts ea ON ea.attempt_id = aa.attempt_id AND ea.status = 'graded'
             JOIN questions q ON q.question_id = aa.question_id
             JOIN chapters c ON c.chapter_id = q.chapter_id
             WHERE c.course_id = :course
             GROUP BY c.chapter_id, c.name
             ORDER BY correct_rate ASC, c.chapter_id ASC"
        );
        $stmt->execute(['course' => $courseId]);
        return $stmt->fetchAll();
    }

    /**
     * 學生個人的章節答對率。只納入「已可檢視解析」（考卷已關閉或已超過結束時間）的考卷，
     * 否則考試期間可以靠重複查統計來推測哪幾題答對了。
     */
    public function chapterRatesForStudent(int $userId, ?int $courseId, string $now): array
    {
        $params = ['user' => $userId, 'now' => $now];
        $courseSql = '';
        if ($courseId !== null) {
            $courseSql = ' AND c.course_id = :course';
            $params['course'] = $courseId;
        }
        $stmt = Database::connection()->prepare(
            "SELECT c.course_id, co.name AS course_name, c.chapter_id, c.name,
                    COUNT(*) AS total, SUM(aa.is_correct = 1) AS correct,
                    ROUND(100 * AVG(aa.is_correct = 1), 1) AS correct_rate
             FROM attempt_answers aa
             JOIN exam_attempts ea ON ea.attempt_id = aa.attempt_id AND ea.status = 'graded'
             JOIN exams e ON e.exam_id = ea.exam_id
             JOIN questions q ON q.question_id = aa.question_id
             JOIN chapters c ON c.chapter_id = q.chapter_id
             JOIN courses co ON co.course_id = c.course_id
             WHERE ea.user_id = :user
               AND (e.status = 'closed' OR (e.status = 'published' AND e.end_time IS NOT NULL AND e.end_time < :now))
               {$courseSql}
             GROUP BY c.course_id, co.name, c.chapter_id, c.name
             ORDER BY correct_rate ASC, c.chapter_id ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
