<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Page;
use App\Core\Validator;
use App\Domain\AttemptRules;
use App\Domain\ExamRules;
use App\Repositories\AttemptRepository;
use App\Repositories\EnrollmentRepository;
use App\Repositories\ExamRepository;
use App\Serializers\AttemptSerializer;
use App\Support\AppException;
use App\Support\Dates;

/**
 * F-11 作答（開始、續答、暫存、交卷）、F-12 自動批改、F-14 成績查詢、F-18 逾時處理。
 * 時間一律以「網站伺服器的時鐘」為準（Dates::now()），前端傳來的任何時間都不採信。
 */
final class AttemptService
{
    public function __construct(
        private readonly AttemptRepository $attempts = new AttemptRepository(),
        private readonly ExamRepository $exams = new ExamRepository(),
        private readonly EnrollmentRepository $enrollments = new EnrollmentRepository(),
    ) {
    }

    private static function grace(): int
    {
        return Config::int('ATTEMPT_GRACE_SECONDS', 5);
    }

    // ================================================================ 開始作答（docs/04 §7.2）

    public function start(array $user, int $examId): array
    {
        $exam = $this->exams->findWithCourse($examId) ?? throw AppException::notFound('找不到這份考卷');
        if ($exam['status'] === 'draft') {
            throw AppException::notFound('找不到這份考卷');                       // 草稿對學生不存在
        }
        $userId = (int) $user['user_id'];
        if ($this->enrollments->find((int) $exam['course_id'], $userId) === null) {
            throw new AppException(403, 'NOT_ENROLLED', '你尚未加入這門課程');
        }

        // 先結算這位學生在這份考卷「已經逾時」的舊作答，再判斷次數與是否有進行中的作答
        $this->finalizeOverdueFor($userId, $examId);

        $attemptId = Database::transaction(function () use ($examId, $userId, $exam): int {
            // 每位學生一把鎖：同時按兩次「開始」會一個接一個執行，第二個會看到第一個建立的作答
            if (!$this->attempts->lockEnrollment((int) $exam['course_id'], $userId)) {
                throw new AppException(403, 'NOT_ENROLLED', '你尚未加入這門課程');
            }
            $locked = $this->attempts->lockExamShared($examId) ?? throw AppException::notFound('找不到這份考卷');
            $now = Dates::now();
            if (!ExamRules::isOpen($locked, $now)) {
                throw self::notOpen($locked, $now);
            }

            $open = $this->attempts->inProgressId($examId, $userId);
            if ($open !== null) {
                throw new AppException(409, 'ATTEMPT_IN_PROGRESS', '你已經有一份進行中的作答，請繼續作答', [['attempt_id' => $open]]);
            }
            $used = $this->attempts->countByExamUser($examId, $userId);   // 所有狀態都算，包含作答中
            if ($used >= (int) $locked['max_attempts']) {
                throw new AppException(409, 'ATTEMPT_LIMIT_REACHED', '已達作答次數上限', [['used' => $used, 'max_attempts' => (int) $locked['max_attempts']]]);
            }

            $id = $this->attempts->insert($examId, $userId, $used + 1, $now->format('Y-m-d H:i:s'));
            $this->attempts->insertBlankAnswers($id, $examId);
            return $id;
        });

        return $this->payload($attemptId);
    }

    private static function notOpen(array $exam, \DateTimeImmutable $now): AppException
    {
        if ($exam['status'] !== 'published') {
            return new AppException(409, 'EXAM_NOT_OPEN', '考卷目前沒有開放作答', [['reason' => 'not_published']]);
        }
        $start = Dates::toObject($exam['start_time']);
        if ($start !== null && $start > $now) {
            return new AppException(409, 'EXAM_NOT_OPEN', '還沒到考試開始時間', [['reason' => 'not_started', 'start_time' => Dates::iso($exam['start_time'])]]);
        }
        return new AppException(409, 'EXAM_NOT_OPEN', '考試時間已經結束', [['reason' => 'ended']]);
    }

    // ================================================================ 續答

    public function resume(array $user, int $attemptId): array
    {
        $attempt = $this->loadOwned($user, $attemptId);
        if ($attempt['status'] === 'in_progress' && $this->finalizeIfOverdue($attemptId)) {
            $attempt = $this->attempts->find($attemptId);
        }
        if ($attempt['status'] !== 'in_progress') {
            throw new AppException(409, 'ATTEMPT_NOT_EDITABLE', '這次作答已經交卷，請改用 /result 查看成績', [['attempt_id' => $attemptId]]);
        }
        return $this->payload($attemptId);
    }

    // ================================================================ 暫存答案（docs/04 §7.4）

    public function saveAnswer(array $user, int $attemptId, int $questionId, array $input): array
    {
        $attempt = $this->loadOwned($user, $attemptId);
        $this->assertEditable($attempt, finalizeWhenExpired: true);

        $row = $this->attempts->answerRow($attemptId, $questionId) ?? throw AppException::notFound('這一題不屬於這次作答');
        $answer = AnswerInput::normalize($row['type'], $input, $row['type'] === 'short' ? [] : $this->attempts->validOptionIds($questionId));

        Database::transaction(function () use ($attemptId, $row, $answer): void {
            $this->attempts->lock($attemptId) ?? throw AppException::notFound('找不到這次作答');
            $this->assertEditable($this->attempts->find($attemptId), finalizeWhenExpired: false);   // 鎖到之後再確認一次
            $this->apply((int) $row['answer_id'], $row['type'], $answer);
        });

        return ['question_id' => $questionId, 'saved_at' => Dates::now()->format('c')];
    }

    /** 已交卷 → ATTEMPT_NOT_EDITABLE；已逾時 → ATTEMPT_EXPIRED（並順手把它結算掉） */
    private function assertEditable(array $attempt, bool $finalizeWhenExpired): void
    {
        if ($attempt['status'] !== 'in_progress') {
            throw new AppException(409, 'ATTEMPT_NOT_EDITABLE', '這次作答已經交卷，不能再修改答案');
        }
        if (AttemptRules::isExpired($this->deadlineOf($attempt), Dates::now(), self::grace())) {
            if ($finalizeWhenExpired) {
                $this->finalizeIfOverdue((int) $attempt['attempt_id']);
            }
            throw new AppException(409, 'ATTEMPT_EXPIRED', '作答時間已到，系統已依照已暫存的答案自動交卷');
        }
    }

    private function apply(int $answerId, string $type, array $answer): void
    {
        if ($type === 'short') {
            $this->attempts->setAnswerText($answerId, $answer['answer_text']);
        } else {
            $this->attempts->replaceChosen($answerId, $answer['option_ids']);
        }
    }

    // ================================================================ 交卷與批改（docs/04 §7.5、§9）

    public function submit(array $user, int $attemptId, array $input): array
    {
        $this->loadOwned($user, $attemptId);
        $v = Validator::validate($input, ['answers' => 'array|max:200']);
        $items = array_values($v['answers'] ?? []);
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                throw AppException::validation([['field' => "answers[{$i}]", 'reason' => '必須是物件']]);
            }
        }

        Database::transaction(function () use ($attemptId, $items): void {
            $this->attempts->lock($attemptId) ?? throw AppException::notFound('找不到這次作答');
            $fresh = $this->attempts->find($attemptId);
            if ($fresh['status'] !== 'in_progress') {
                return;                                                         // 已經交過了：冪等，直接回傳現有結果
            }

            $now = Dates::now();
            $expired = AttemptRules::isExpired($this->deadlineOf($fresh), $now, self::grace());

            // 逾時：忽略這次送來的答案，只採計「已暫存」的；準時：先套用最後一批答案（全部合法才寫入）
            if (!$expired) {
                foreach ($items as $i => $item) {
                    $this->applyItem($attemptId, $i, $item);
                }
            }
            $this->attempts->grade($attemptId, $now->format('Y-m-d H:i:s'));
        });

        return $this->submitPayload($attemptId);
    }

    private function applyItem(int $attemptId, int $i, array $item): void
    {
        $questionId = $item['question_id'] ?? null;
        $row = is_int($questionId) && $questionId > 0 ? $this->attempts->answerRow($attemptId, $questionId) : null;
        if ($row === null) {
            throw AppException::validation([['field' => "answers[{$i}].question_id", 'reason' => '這一題不屬於這次作答']]);
        }
        try {
            $answer = AnswerInput::normalize($row['type'], $item, $row['type'] === 'short' ? [] : $this->attempts->validOptionIds((int) $questionId));
        } catch (AppException $e) {                                              // 讓錯誤訊息指出是第幾筆答案
            throw new AppException($e->status, $e->errorCode, $e->getMessage(), array_map(
                static fn (array $d): array => isset($d['field']) ? ['field' => "answers[{$i}]." . $d['field']] + $d : $d + ['field' => "answers[{$i}]"],
                $e->details === [] ? [[]] : $e->details
            ));
        }
        $this->apply((int) $row['answer_id'], $row['type'], $answer);
    }

    private function submitPayload(int $attemptId): array
    {
        $attempt = $this->attempts->find($attemptId);
        $pending = $this->attempts->pendingCount($attemptId);
        $total = (float) $attempt['total_score'];

        return [
            'attempt_id'             => $attemptId,
            'status'                 => $attempt['status'],
            'total_score'            => $total,
            'pass_score'             => (float) $attempt['pass_score'],
            'passed'                 => AttemptRules::passed($total, (float) $attempt['pass_score'], $pending),
            'late'                   => $this->isLate($attempt),
            'pending_manual_grading' => $pending,
        ];
    }

    /** 交卷時間超過「截止 + 寬限」就算逾時（逾時由系統自動交卷） */
    private function isLate(array $attempt): bool
    {
        $submitted = Dates::toObject($attempt['submitted_at']);
        return $submitted !== null && AttemptRules::isExpired($this->deadlineOf($attempt), $submitted, self::grace());
    }

    // ================================================================ 成績與解析（docs/04 §11）

    public function result(array $user, int $attemptId): array
    {
        $attempt = $this->attempts->find($attemptId) ?? throw AppException::notFound('找不到這次作答');
        $isStudent = $user['role'] === 'student';
        if ($isStudent) {
            if ((int) $attempt['user_id'] !== (int) $user['user_id']) {
                throw AppException::notFound('找不到這次作答');                  // 看不到別人的作答
            }
        } else {
            AccessPolicy::assertManageCourse($user, $attempt, hide: true);
        }

        if ($attempt['status'] === 'in_progress' && $this->finalizeIfOverdue($attemptId)) {
            $attempt = $this->attempts->find($attemptId);
        }
        if ($attempt['status'] === 'in_progress') {
            throw new AppException(409, 'ATTEMPT_NOT_SUBMITTED', '這次作答還沒有交卷');
        }

        $now = Dates::now();
        $pending = $this->attempts->pendingCount($attemptId);
        $passed = AttemptRules::passed((float) $attempt['total_score'], (float) $attempt['pass_score'], $pending);

        // 老師、管理員隨時看得到完整答案；學生要等考卷結束（關閉，或超過結束時間）
        $revealed = !$isStudent || AttemptRules::answersRevealed(
            ['status' => $attempt['exam_status'], 'start_time' => $attempt['start_time'], 'end_time' => $attempt['end_time']], $now
        );
        $detail = null;
        if ($revealed) {
            $answers = $this->attempts->answers($attemptId);
            $detail = AttemptSerializer::detail(
                $answers,
                $this->attempts->optionsFor(array_map(static fn (array $a): int => (int) $a['question_id'], $answers), true),
                $this->attempts->chosenOptionIds($attemptId)
            );
        }
        return AttemptSerializer::result($attempt, $passed, $pending, $this->isLate($attempt), $detail);
    }

    /** 我的作答紀錄 */
    public function listMine(array $user, array $query, Page $page): array
    {
        $f = Validator::validate($query, ['exam_id' => 'int|min:1', 'course_id' => 'int|min:1']);
        $userId = (int) $user['user_id'];
        $this->finalizeOverdueFor($userId, null);

        [$rows, $total] = $this->attempts->listByUser($userId, $f, $page->perPage, $page->offset());
        $items = array_map(function (array $r): array {
            $inProgress = $r['status'] === 'in_progress';
            $passed = $inProgress ? null : AttemptRules::passed((float) $r['total_score'], (float) $r['pass_score'], (int) $r['pending']);
            $deadline = $inProgress
                ? AttemptRules::deadline(Dates::toObject($r['started_at']), (int) $r['duration_min'], Dates::toObject($r['end_time']))->format('c')
                : null;
            return AttemptSerializer::listItem($r, $passed, $deadline);
        }, $rows);

        return [$items, $page->meta($total)];
    }

    /** 全班作答列表（課程擁有者、管理員）；`?pending=1` 只列出還有簡答題待評分的 */
    public function listForExam(array $user, int $examId, array $query, Page $page): array
    {
        $exam = $this->exams->findWithCourse($examId) ?? throw AppException::notFound('找不到這份考卷');
        AccessPolicy::assertManageCourse($user, $exam, hide: true);

        $f = Validator::validate($query, ['status' => 'in:in_progress,submitted,graded', 'pending' => 'bool']);
        $this->finalizeOverdueFor(null, $examId);

        [$rows, $total] = $this->attempts->listByExam($examId, $f, $page->perPage, $page->offset());
        $passScore = (float) $exam['pass_score'];
        $items = array_map(static function (array $r) use ($passScore): array {
            $inProgress = $r['status'] === 'in_progress';
            return [
                'attempt_id'             => (int) $r['attempt_id'],
                'student'                => ['user_id' => (int) $r['user_id'], 'username' => $r['username'], 'email' => $r['email']],
                'attempt_no'             => (int) $r['attempt_no'],
                'status'                 => $r['status'],
                'started_at'             => Dates::iso($r['started_at']),
                'submitted_at'           => Dates::iso($r['submitted_at']),
                'total_score'            => $inProgress ? null : (float) $r['total_score'],
                'passed'                 => $inProgress ? null : AttemptRules::passed((float) $r['total_score'], $passScore, (int) $r['pending']),
                'pending_manual_grading' => (int) $r['pending'],
            ];
        }, $rows);

        return [$items, $page->meta($total)];
    }

    // ================================================================ 逾時處理（docs/04 §12）

    /**
     * 如果這份作答已逾時（超過截止 + 寬限）還在作答中，就依「已暫存的答案」交卷並批改。
     * 鎖住作答單後會重新檢查狀態，所以與學生交卷、與排程同時發生都不會重複批改。
     *
     * @return bool 這次是否真的結算了
     */
    public function finalizeIfOverdue(int $attemptId): bool
    {
        $row = $this->attempts->find($attemptId);
        if ($row === null || $row['status'] !== 'in_progress'
            || !AttemptRules::isExpired($this->deadlineOf($row), Dates::now(), self::grace())) {
            return false;
        }

        return (bool) Database::transaction(function () use ($attemptId): bool {
            $this->attempts->lock($attemptId);
            $fresh = $this->attempts->find($attemptId);
            if ($fresh === null || $fresh['status'] !== 'in_progress'
                || !AttemptRules::isExpired($this->deadlineOf($fresh), Dates::now(), self::grace())) {
                return false;
            }
            $this->attempts->grade($attemptId, Dates::now()->format('Y-m-d H:i:s'));
            return true;
        });
    }

    /** 排程用：結算所有已逾時的作答，回傳處理筆數 */
    public function finalizeOverdue(int $limit = 100): int
    {
        $count = 0;
        foreach ($this->attempts->overdueIds($this->cutoff(), null, null, $limit) as $id) {
            if ($this->finalizeIfOverdue($id)) {
                $count++;
            }
        }
        return $count;
    }

    /** 讀取時順手結算：某位學生（可限定考卷）或某份考卷的逾時作答 */
    private function finalizeOverdueFor(?int $userId, ?int $examId): void
    {
        foreach ($this->attempts->overdueIds($this->cutoff(), $userId, $examId, 200) as $id) {
            $this->finalizeIfOverdue($id);
        }
    }

    private function cutoff(): string
    {
        return Dates::now()->modify('-' . self::grace() . ' seconds')->format('Y-m-d H:i:s');
    }

    // ================================================================

    private function loadOwned(array $user, int $attemptId): array
    {
        $attempt = $this->attempts->find($attemptId) ?? throw AppException::notFound('找不到這次作答');
        if ((int) $attempt['user_id'] !== (int) $user['user_id']) {
            throw AppException::notFound('找不到這次作答');                      // 別人的作答一律 404
        }
        return $attempt;
    }

    private function deadlineOf(array $attempt): \DateTimeImmutable
    {
        return AttemptRules::deadline(
            Dates::toObject($attempt['started_at']), (int) $attempt['duration_min'], Dates::toObject($attempt['end_time'])
        );
    }

    /** 作答中的題目與已暫存的答案（開始作答與續答共用；絕不含 is_correct 與解析） */
    private function payload(int $attemptId): array
    {
        $attempt = $this->attempts->find($attemptId) ?? throw AppException::notFound('找不到這次作答');
        $answers = $this->attempts->answers($attemptId);
        $options = $this->attempts->optionsFor(array_map(static fn (array $a): int => (int) $a['question_id'], $answers), false);

        return AttemptSerializer::inProgress(
            $attempt, $this->deadlineOf($attempt), Dates::now(), $answers, $options, $this->attempts->chosenOptionIds($attemptId)
        );
    }
}
