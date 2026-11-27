<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 作答的時間與成績規則（docs/04 §7.3、§11）。純函式，時間一律以參數傳入。
 */
final class AttemptRules
{
    /** 截止時間 = min(開始時間 + 限時, 考卷結束時間)；考卷沒有結束時間就只看限時 */
    public static function deadline(\DateTimeImmutable $startedAt, int $durationMin, ?\DateTimeImmutable $examEnd): \DateTimeImmutable
    {
        $byDuration = $startedAt->modify('+' . $durationMin . ' minutes');
        return ($examEnd !== null && $examEnd < $byDuration) ? $examEnd : $byDuration;
    }

    /** 超過截止時間 + 寬限秒數（涵蓋網路延遲）才算逾時；剛好等於寬限的最後一刻仍算準時 */
    public static function isExpired(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $graceSeconds): bool
    {
        return $now > $deadline->modify('+' . $graceSeconds . ' seconds');
    }

    public static function remainingSeconds(\DateTimeImmutable $deadline, \DateTimeImmutable $now): int
    {
        return max(0, $deadline->getTimestamp() - $now->getTimestamp());
    }

    /**
     * 是否通過。簡答題待評分時只能下「確定通過」的結論：
     * 人工評分只會增加分數，所以目前總分已達及格 → 一定通過；還沒達到且有待評分 → 未定（null）。
     */
    public static function passed(float $total, float $passScore, int $pendingManualGrading): ?bool
    {
        if (round($total, 2) >= round($passScore, 2)) {
            return true;
        }
        return $pendingManualGrading > 0 ? null : false;
    }

    /** 逐題對錯與正確答案何時開放給學生：考卷已關閉，或已發佈但超過結束時間（docs/04 §11） */
    public static function answersRevealed(array $exam, \DateTimeImmutable $now): bool
    {
        return $exam['status'] === 'closed' || ExamRules::isEnded($exam, $now);
    }
}
