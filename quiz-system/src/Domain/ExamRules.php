<?php
declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;

/**
 * 考卷的時間與狀態規則、發佈檢查（docs/04 §5.3、§6）。
 * 全部是純函式：時間以參數傳入（$now），不讀資料庫、不讀系統時鐘，方便單元測試。
 */
final class ExamRules
{
    /** 已發佈但已超過結束時間（讀取時判斷，不需要排程去改資料） */
    public static function isEnded(array $exam, \DateTimeImmutable $now): bool
    {
        $end = Dates::toObject($exam['end_time'] ?? null);
        return $exam['status'] === 'published' && $end !== null && $now > $end;
    }

    /** 可以開始作答：已發佈，且 (start_time 為空或 ≤ now)、(end_time 為空或 > now) */
    public static function isOpen(array $exam, \DateTimeImmutable $now): bool
    {
        if ($exam['status'] !== 'published') {
            return false;
        }
        $start = Dates::toObject($exam['start_time'] ?? null);
        $end = Dates::toObject($exam['end_time'] ?? null);
        return ($start === null || $start <= $now) && ($end === null || $end > $now);
    }

    /** draft / published / ended / closed */
    public static function effectiveStatus(array $exam, \DateTimeImmutable $now): string
    {
        return self::isEnded($exam, $now) ? 'ended' : (string) $exam['status'];
    }

    /**
     * 發佈檢查：回傳「所有」不通過的規則（空陣列代表可以發佈）。
     *
     * @param array $exam      pass_score、duration_min、start_time、end_time
     * @param list<array{question_id:int,is_active:mixed,type:string,score:mixed,options:list<array{content:string,is_correct:mixed}>}> $questions
     * @return list<array<string,mixed>>
     */
    public static function publishFailures(array $exam, array $questions, \DateTimeImmutable $now): array
    {
        $failures = [];

        if ((int) $exam['duration_min'] <= 0) {
            $failures[] = ['rule' => 'duration_invalid', 'duration_min' => (int) $exam['duration_min']];
        }

        $start = Dates::toObject($exam['start_time'] ?? null);
        $end = Dates::toObject($exam['end_time'] ?? null);
        if ($start !== null && $end !== null && $start >= $end) {
            $failures[] = ['rule' => 'invalid_window'];
        }
        if ($end !== null && $end <= $now) {
            $failures[] = ['rule' => 'end_in_past'];
        }

        if ($questions === []) {
            $failures[] = ['rule' => 'no_questions'];
            return $failures;       // 沒有題目時，總分相關的檢查沒有意義
        }

        $total = 0.0;
        foreach ($questions as $q) {
            $total += (float) $q['score'];
            if (!(bool) $q['is_active']) {
                $failures[] = ['rule' => 'question_inactive', 'question_id' => (int) $q['question_id']];
            }
            $problem = QuestionRules::problem($q['type'], array_map(
                static fn (array $o): array => ['content' => (string) $o['content'], 'is_correct' => (bool) $o['is_correct']],
                $q['options']
            ));
            if ($problem !== null) {
                $failures[] = ['rule' => 'question_shape_invalid', 'question_id' => (int) $q['question_id'], 'reason' => $problem];
            }
        }

        $total = round($total, 2);
        $pass = round((float) $exam['pass_score'], 2);
        if ($total <= 0) {
            $failures[] = ['rule' => 'total_score_zero'];
        } elseif ($pass > $total) {
            $failures[] = ['rule' => 'pass_score_exceeds_total', 'pass_score' => $pass, 'total_score' => $total];
        }

        return $failures;
    }
}
