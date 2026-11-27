<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Domain\AttemptRules;
use App\Repositories\AttemptRepository;
use App\Support\AppException;

/** F-13 簡答題人工評分（docs/04 §10） */
final class GradingService
{
    public function __construct(
        private readonly AttemptRepository $attempts = new AttemptRepository(),
        private readonly AttemptService $attemptService = new AttemptService(),
    ) {
    }

    public function grade(array $user, int $attemptId, int $questionId, array $input): array
    {
        $attempt = $this->attempts->find($attemptId) ?? throw AppException::notFound('找不到這次作答');
        AccessPolicy::assertManageCourse($user, $attempt, hide: true);            // 別人課程的作答一律 404

        $v = Validator::validate($input, ['score_awarded' => 'required|number|min:0']);
        $score = (float) $v['score_awarded'];
        if (abs($score * 100 - round($score * 100)) > 1e-6) {                     // 欄位是 DECIMAL(5,2)，最多兩位小數
            throw AppException::validation([['field' => 'score_awarded', 'reason' => '最多兩位小數']]);
        }
        $score = round($score, 2);

        // 已逾時但還沒被結算的作答，先結算再評分（結算後才會是 submitted / graded）
        if ($attempt['status'] === 'in_progress' && $this->attemptService->finalizeIfOverdue($attemptId)) {
            $attempt = $this->attempts->find($attemptId);
        }

        $result = Database::transaction(function () use ($attemptId, $questionId, $score): array {
            $locked = $this->attempts->lock($attemptId) ?? throw AppException::notFound('找不到這次作答');
            if ($locked['status'] === 'in_progress') {
                throw new AppException(409, 'ATTEMPT_NOT_SUBMITTED', '這次作答還沒有交卷，不能評分');
            }
            $row = $this->attempts->gradingRow($attemptId, $questionId) ?? throw AppException::notFound('這一題不屬於這次作答');
            if ($row['type'] !== 'short') {
                throw new AppException(409, 'NOT_GRADABLE', '只有簡答題需要人工評分');
            }
            $max = (float) $row['max_score'];
            if ($score > $max) {
                throw new AppException(400, 'SCORE_OUT_OF_RANGE', "給分必須介於 0 到 {$max} 之間", [['field' => 'score_awarded', 'min' => 0, 'max' => $max]]);
            }

            $this->attempts->setManualScore((int) $row['answer_id'], $score, abs($score - $max) < 0.005);
            $this->attempts->recalculate($attemptId);                              // 重新評分也一樣：總分隨之重算
            return ['max' => $max];
        });

        $fresh = $this->attempts->find($attemptId);
        $pending = $this->attempts->pendingCount($attemptId);
        $total = (float) $fresh['total_score'];

        return [
            'question_id'            => $questionId,
            'score_awarded'          => $score,
            'max_score'              => $result['max'],
            'is_correct'             => abs($score - $result['max']) < 0.005,
            'attempt_status'         => $fresh['status'],
            'total_score'            => $total,
            'passed'                 => AttemptRules::passed($total, (float) $fresh['pass_score'], $pending),
            'pending_manual_grading' => $pending,
        ];
    }
}
