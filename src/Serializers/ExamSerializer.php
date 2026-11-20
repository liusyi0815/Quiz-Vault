<?php
declare(strict_types=1);

namespace App\Serializers;

use App\Domain\ExamRules;
use App\Support\Dates;

/**
 * 考卷的回應結構。老師與學生是不同的結構：
 * 學生永遠拿不到題目內容，只拿到設定與自己的作答摘要（docs/03 §2.4）。
 */
final class ExamSerializer
{
    /** 列表與詳情共用的基本欄位 */
    public static function item(array $row, \DateTimeImmutable $now): array
    {
        return [
            'exam_id'          => (int) $row['exam_id'],
            'course'           => ['course_id' => (int) $row['course_id'], 'name' => $row['course_name']],
            'title'            => $row['title'],
            'status'           => $row['status'],
            'effective_status' => ExamRules::effectiveStatus($row, $now),   // 已發佈但超過結束時間 → ended
            'is_open'          => ExamRules::isOpen($row, $now),            // 現在可以開始作答嗎
            'duration_min'     => (int) $row['duration_min'],
            'start_time'       => Dates::iso($row['start_time']),
            'end_time'         => Dates::iso($row['end_time']),
            'pass_score'       => (float) $row['pass_score'],
            'max_attempts'     => (int) $row['max_attempts'],
            'question_count'   => (int) $row['question_count'],
            'total_score'      => (float) $row['total_score'],
            'created_at'       => Dates::iso($row['created_at']),
        ];
    }

    /** 學生：基本欄位 + 自己的作答摘要 */
    public static function forStudent(array $row, array $mine, \DateTimeImmutable $now): array
    {
        return self::item($row, $now) + ['my_attempts' => self::mine($row, $mine, $now)];
    }

    /** 老師、管理員：基本欄位 + 題目清單 + 作答人次 */
    public static function forTeacher(array $row, array $questions, int $attemptCount, \DateTimeImmutable $now): array
    {
        return self::item($row, $now) + [
            'creator_id'    => (int) $row['creator_id'],
            'attempt_count' => $attemptCount,
            'questions'     => array_map([self::class, 'question'], $questions),
        ];
    }

    public static function question(array $q): array
    {
        return [
            'question_id' => (int) $q['question_id'],
            'chapter'     => ['chapter_id' => (int) $q['chapter_id'], 'name' => $q['chapter_name']],
            'type'        => $q['type'],
            'content'     => $q['content'],
            'difficulty'  => (int) $q['difficulty'],
            'is_active'   => (bool) $q['is_active'],
            'score'       => (float) $q['score'],
            'sort_order'  => (int) $q['sort_order'],
        ];
    }

    /**
     * @param array{used:mixed,best:mixed,in_progress:mixed} $mine  資料庫查出來的摘要（used / best / in_progress）
     */
    private static function mine(array $row, array $mine, \DateTimeImmutable $now): array
    {
        $used = (int) $mine['used'];
        $inProgress = $mine['in_progress'] === null ? null : (int) $mine['in_progress'];

        return [
            'used'                   => $used,
            'max_attempts'           => (int) $row['max_attempts'],
            'best_score'             => $mine['best'] === null ? null : (float) $mine['best'],
            'in_progress_attempt_id' => $inProgress,
            // 開放中、次數還沒用完、沒有作答進行中
            'can_start'              => ExamRules::isOpen($row, $now) && $used < (int) $row['max_attempts'] && $inProgress === null,
        ];
    }

    /** 列表查詢的欄位（my_used / my_best / my_in_progress）轉成 mine() 用的格式 */
    public static function mineFromListRow(array $row): array
    {
        return ['used' => $row['my_used'], 'best' => $row['my_best'], 'in_progress' => $row['my_in_progress']];
    }
}
