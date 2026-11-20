<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Page;
use App\Core\Validator;
use App\Domain\ExamRules;
use App\Repositories\ChapterRepository;
use App\Repositories\CourseRepository;
use App\Repositories\EnrollmentRepository;
use App\Repositories\ExamRepository;
use App\Serializers\ExamSerializer;
use App\Support\AppException;
use App\Support\Dates;

/** F-08 考卷與手動組卷、F-09 隨機抽題、F-10 發佈／撤回／關閉（狀態機見 docs/04 §6） */
final class ExamService
{
    private const MAX_QUESTIONS = 200;

    public function __construct(
        private readonly ExamRepository $exams = new ExamRepository(),
        private readonly CourseRepository $courses = new CourseRepository(),
        private readonly ChapterRepository $chapters = new ChapterRepository(),
        private readonly EnrollmentRepository $enrollments = new EnrollmentRepository(),
    ) {
    }

    // ================================================================ 查詢

    /** @return array{0:list<array>, 1:array} */
    public function list(array $user, array $query, Page $page): array
    {
        $f = Validator::validate($query, ['course_id' => 'int|min:1', 'status' => 'in:draft,published,closed']);
        [$rows, $total] = $this->exams->search($f, $user, $page->perPage, $page->offset());

        $now = Dates::now();
        $isStudent = $user['role'] === 'student';
        $items = array_map(
            static fn (array $r): array => $isStudent
                ? ExamSerializer::forStudent($r, ExamSerializer::mineFromListRow($r), $now)
                : ExamSerializer::item($r, $now),
            $rows
        );
        return [$items, $page->meta($total)];
    }

    public function get(array $user, int $id): array
    {
        $exam = $this->exams->findWithCourse($id) ?? throw AppException::notFound('找不到這份考卷');

        if ($user['role'] === 'student') {
            if ($exam['status'] === 'draft') {
                throw AppException::notFound('找不到這份考卷');                       // 草稿對學生不存在
            }
            if ($this->enrollments->find((int) $exam['course_id'], (int) $user['user_id']) === null) {
                throw new AppException(403, 'NOT_ENROLLED', '你尚未加入這門課程');
            }
            return ExamSerializer::forStudent($exam, $this->exams->studentSummary($id, (int) $user['user_id']), Dates::now());
        }

        AccessPolicy::assertManageCourse($user, $exam, hide: true);             // 別人的考卷回 404
        return $this->teacherDetail($exam);
    }

    // ================================================================ F-08 建立、修改、刪除

    public function create(array $user, array $input): array
    {
        $f = ExamInput::create($input);
        $course = $this->courses->findById($f['course_id']) ?? throw AppException::notFound('找不到這門課程');
        AccessPolicy::assertManageCourse($user, $course);                       // 課程大家都看得到，沒權限回 403

        $id = $this->exams->insert((int) $course['course_id'], (int) $user['user_id'], $f);
        return $this->teacherDetail($this->exams->findWithCourse($id));
    }

    /** 各狀態可修改的欄位：draft 全部；published 只有 title 與「延長」end_time；closed 只有 title（docs/04 §6） */
    public function update(array $user, int $id, array $input): array
    {
        $this->loadManageable($user, $id);
        $v = ExamInput::patch($input);

        Database::transaction(function () use ($id, $v): void {
            $this->exams->lock($id) ?? throw AppException::notFound('找不到這份考卷');
            $exam = $this->exams->findWithCourse($id);

            if ($exam['status'] === 'draft') {
                $start = array_key_exists('start_time', $v) ? $v['start_time'] : $exam['start_time'];
                $end = array_key_exists('end_time', $v) ? $v['end_time'] : $exam['end_time'];
                ExamInput::assertWindow($start, $end);
            } else {
                $editable = $exam['status'] === 'published' ? ['title', 'end_time'] : ['title'];
                $locked = array_values(array_diff(array_keys($v), $editable));
                if ($locked !== []) {
                    throw new AppException(
                        409, 'EXAM_NOT_EDITABLE',
                        $exam['status'] === 'published' ? '考卷已發佈，只能修改標題與延長結束時間' : '考卷已關閉，只能修改標題',
                        array_map(static fn (string $f): array => ['field' => $f], $locked)
                    );
                }
                if (isset($v['end_time'])) {                                      // 已發佈：只能延長，不能縮短
                    if ($exam['end_time'] === null || $v['end_time'] <= $exam['end_time']) {
                        throw new AppException(409, 'EXAM_NOT_EDITABLE', '考卷已發佈，結束時間只能延長，不能縮短', [['field' => 'end_time']]);
                    }
                    if ($v['end_time'] <= Dates::now()->format('Y-m-d H:i:s')) {
                        throw AppException::validation([['field' => 'end_time', 'reason' => '必須晚於現在']]);
                    }
                    ExamInput::assertWindow($exam['start_time'], $v['end_time']);
                }
            }
            $this->exams->update($id, $v);
        });

        return $this->teacherDetail($this->exams->findWithCourse($id));
    }

    public function delete(array $user, int $id): void
    {
        $this->loadManageable($user, $id);

        Database::transaction(function () use ($id): void {
            $this->exams->lock($id) ?? throw AppException::notFound('找不到這份考卷');
            $exam = $this->exams->findWithCourse($id);
            if ($exam['status'] !== 'draft') {
                throw new AppException(409, 'EXAM_NOT_EDITABLE', '只有草稿考卷可以刪除');
            }
            if ($this->exams->attemptCount($id) > 0) {
                throw new AppException(409, 'HAS_ATTEMPTS', '這份考卷已經有作答紀錄，不能刪除');
            }
            $this->exams->delete($id);
        });
    }

    // ================================================================ F-08 手動組卷

    /** 整份取代題目與配分（docs/04 §5.1） */
    public function replaceQuestions(array $user, int $id, array $input): array
    {
        $exam = $this->loadManageable($user, $id);
        $this->assertDraft($exam);

        $items = ExamInput::questions($input);

        // 題目必須屬於這份考卷所在的課程，且是啟用中
        $known = $this->exams->questionsInCourse((int) $exam['course_id'], array_column($items, 'question_id'));
        $errors = [];
        $inactive = [];
        foreach ($items as $it) {
            if (!isset($known[$it['question_id']])) {
                $errors[] = ['field' => "questions[{$it['i']}].question_id", 'reason' => '找不到這個題目，或它不屬於這門課程'];
            } elseif ($known[$it['question_id']] === false) {
                $inactive[] = ['question_id' => $it['question_id']];
            }
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }
        if ($inactive !== []) {
            throw new AppException(409, 'QUESTION_INACTIVE', '有題目已停用，不能加入考卷', $inactive);
        }

        Database::transaction(function () use ($id, $items): void {
            $this->exams->lock($id) ?? throw AppException::notFound('找不到這份考卷');
            $this->assertDraft($this->exams->findWithCourse($id));            // 鎖到之後再確認一次狀態
            $this->exams->replaceQuestions($id, array_map(
                static fn (array $it): array => ['question_id' => $it['question_id'], 'score' => $it['score'], 'sort_order' => $it['sort_order']],
                $items
            ));
        });

        return $this->teacherDetail($this->exams->findWithCourse($id));
    }

    // ================================================================ F-09 隨機抽題

    /**
     * 依章節、標籤、難度題數從題庫抽題，加在現有題目之後（docs/04 §5.2）。
     * 任何一個條件的題數不足，就「不寫入任何東西」並回報缺口。
     *
     * @return array{0:list<array>, 1:array} [新加入的題目, meta]
     */
    public function drawRandom(array $user, int $id, array $input): array
    {
        $exam = $this->loadManageable($user, $id);
        $this->assertDraft($exam);

        $in = ExamInput::random($input);
        $courseId = (int) $exam['course_id'];

        $courseChapters = $this->chapters->idsByCourse($courseId);
        $foreign = array_values(array_diff($in['chapter_ids'], $courseChapters));
        if ($foreign !== []) {
            throw AppException::validation([['field' => 'chapter_ids', 'reason' => '章節必須屬於這份考卷所在的課程：' . implode('、', $foreign)]]);
        }

        $addedIds = Database::transaction(function () use ($id, $courseId, $in): array {
            $this->exams->lock($id) ?? throw AppException::notFound('找不到這份考卷');
            $exam = $this->exams->findWithCourse($id);
            $this->assertDraft($exam);

            $available = $this->exams->availableByDifficulty($id, $courseId, $in['chapter_ids'], $in['tags']);
            $wanted = $in['by_difficulty'];                                   // 難度 => 題數；或 null（改用 count）

            // ---- 檢查題數夠不夠，不夠就一題都不加 ----
            $shortages = [];
            if ($wanted !== null) {
                foreach ($wanted as $difficulty => $n) {
                    if ($available[$difficulty] < $n) {
                        $shortages[] = ['difficulty' => $difficulty, 'requested' => $n, 'available' => $available[$difficulty]];
                    }
                }
                $toAdd = array_sum($wanted);
            } else {
                $toAdd = $in['count'];
                if (array_sum($available) < $toAdd) {
                    $shortages[] = ['requested' => $toAdd, 'available' => array_sum($available)];
                }
            }
            if ($shortages !== []) {
                throw new AppException(409, 'INSUFFICIENT_QUESTIONS', '符合條件的題目不足', $shortages);
            }
            if ((int) $exam['question_count'] + $toAdd > self::MAX_QUESTIONS) {
                throw AppException::validation([['field' => 'count', 'reason' => '一份考卷最多 ' . self::MAX_QUESTIONS . ' 題']]);
            }

            // ---- 抽題 ----
            $ids = [];
            if ($wanted !== null) {
                foreach ($wanted as $difficulty => $n) {
                    array_push($ids, ...$this->exams->drawIds($id, $courseId, $in['chapter_ids'], $in['tags'], $difficulty, $n));
                }
            } else {
                $ids = $this->exams->drawIds($id, $courseId, $in['chapter_ids'], $in['tags'], null, $toAdd);
            }

            $order = $this->exams->nextQuestionOrder($id);
            $this->exams->insertQuestions($id, array_map(
                static function (int $qid) use (&$order, $in): array {
                    return ['question_id' => $qid, 'score' => $in['score_each'], 'sort_order' => $order++];
                },
                $ids
            ));
            return $ids;
        });

        $all = $this->exams->questions($id);
        $added = array_values(array_filter($all, static fn (array $q): bool => in_array((int) $q['question_id'], $addedIds, true)));
        $totals = $this->exams->findWithCourse($id);

        return [
            array_map([ExamSerializer::class, 'question'], $added),
            ['added' => count($added), 'question_count' => (int) $totals['question_count'], 'total_score' => (float) $totals['total_score']],
        ];
    }

    // ================================================================ F-10 發佈、撤回、關閉

    /** draft → published：通過全部發佈檢查才改狀態，未通過時一次列出所有失敗的規則 */
    public function publish(array $user, int $id): array
    {
        $this->loadManageable($user, $id);

        return Database::transaction(function () use ($id): array {
            $this->exams->lock($id) ?? throw AppException::notFound('找不到這份考卷');
            $exam = $this->exams->findWithCourse($id);
            if ($exam['status'] !== 'draft') {
                throw new AppException(409, 'EXAM_INVALID_STATE', '只有草稿考卷可以發佈（目前狀態：' . $exam['status'] . '）');
            }

            $failures = ExamRules::publishFailures($exam, $this->exams->questionsWithOptions($id), Dates::now());
            if ($failures !== []) {
                throw new AppException(409, 'EXAM_PUBLISH_CHECK_FAILED', '考卷未通過發佈檢查', $failures);
            }

            $this->exams->setStatus($id, 'published');
            return $this->statusPayload($id);
        });
    }

    /** published → draft：已經有任何作答就不能撤回 */
    public function unpublish(array $user, int $id): array
    {
        $this->loadManageable($user, $id);

        return Database::transaction(function () use ($id): array {
            $this->exams->lock($id) ?? throw AppException::notFound('找不到這份考卷');
            $exam = $this->exams->findWithCourse($id);
            if ($exam['status'] !== 'published') {
                throw new AppException(409, 'EXAM_INVALID_STATE', '只有已發佈的考卷可以撤回（目前狀態：' . $exam['status'] . '）');
            }
            if ($this->exams->attemptCount($id) > 0) {
                throw new AppException(409, 'HAS_ATTEMPTS', '已經有人作答，不能撤回為草稿');
            }
            $this->exams->setStatus($id, 'draft');
            return $this->statusPayload($id);
        });
    }

    /** published → closed：進行中的作答仍可在各自的截止時間前完成 */
    public function close(array $user, int $id): array
    {
        $this->loadManageable($user, $id);

        return Database::transaction(function () use ($id): array {
            $this->exams->lock($id) ?? throw AppException::notFound('找不到這份考卷');
            $exam = $this->exams->findWithCourse($id);
            if ($exam['status'] !== 'published') {
                throw new AppException(409, 'EXAM_INVALID_STATE', '只有已發佈的考卷可以關閉（目前狀態：' . $exam['status'] . '）');
            }
            $this->exams->setStatus($id, 'closed');
            return $this->statusPayload($id);
        });
    }

    // ================================================================

    /** 考卷不是公開資源：不是自己課程的老師一律看到 404 */
    private function loadManageable(array $user, int $id): array
    {
        $exam = $this->exams->findWithCourse($id) ?? throw AppException::notFound('找不到這份考卷');
        AccessPolicy::assertManageCourse($user, $exam, hide: true);
        return $exam;
    }

    private function assertDraft(array $exam): void
    {
        if ($exam['status'] !== 'draft') {
            throw new AppException(409, 'EXAM_NOT_EDITABLE', '只有草稿考卷可以修改題目與配分（目前狀態：' . $exam['status'] . '）');
        }
    }

    private function teacherDetail(array $exam): array
    {
        $id = (int) $exam['exam_id'];
        return ExamSerializer::forTeacher($exam, $this->exams->questions($id), $this->exams->attemptCount($id), Dates::now());
    }

    private function statusPayload(int $id): array
    {
        $row = $this->exams->findWithCourse($id);
        return ['exam_id' => $id, 'status' => $row['status'], 'total_score' => (float) $row['total_score']];
    }
}
