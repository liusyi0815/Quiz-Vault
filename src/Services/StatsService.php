<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Page;
use App\Core\Validator;
use App\Repositories\CourseRepository;
use App\Repositories\ExamRepository;
use App\Repositories\StatsRepository;
use App\Support\AppException;
use App\Support\Dates;

/** F-15 統計報表（老師／管理員看班級，學生看自己） */
final class StatsService
{
    public function __construct(
        private readonly StatsRepository $stats = new StatsRepository(),
        private readonly ExamRepository $exams = new ExamRepository(),
        private readonly CourseRepository $courses = new CourseRepository(),
        private readonly AttemptService $attemptService = new AttemptService(),
    ) {
    }

    /** 單份考卷統計：平均、最高、最低、及格率、分數分佈（以占配分總和的百分比分三段） */
    public function exam(array $user, int $examId): array
    {
        $exam = $this->exams->findWithCourse($examId) ?? throw AppException::notFound('找不到這份考卷');
        AccessPolicy::assertManageCourse($user, $exam, hide: true);
        $this->attemptService->finalizeOverdueForExam($examId);               // 逾時未交的先結算，統計才完整

        $scores = $this->stats->gradedScores($examId);
        $total = $this->stats->examTotalPoints($examId);
        $passScore = (float) $exam['pass_score'];
        $n = count($scores);

        $bands = ['0-59' => 0, '60-79' => 0, '80-100' => 0];
        $passed = 0;
        foreach ($scores as $s) {
            $pct = $total > 0 ? $s / $total * 100 : 0;
            $bands[$pct < 60 ? '0-59' : ($pct < 80 ? '60-79' : '80-100')]++;
            if ($s >= $passScore) {
                $passed++;
            }
        }
        $byStatus = $this->stats->attemptCountsByStatus($examId);

        return [
            'exam_id'              => $examId,
            'title'                => $exam['title'],
            'total_points'         => $total,
            'pass_score'           => $passScore,
            'attempts'             => $n,
            'avg_score'            => $n > 0 ? round(array_sum($scores) / $n, 2) : null,
            'max_score'            => $n > 0 ? max($scores) : null,
            'min_score'            => $n > 0 ? min($scores) : null,
            'pass_rate'            => $n > 0 ? round($passed / $n * 100, 1) : null,
            'distribution'         => array_map(static fn (string $range, int $count): array => ['range' => $range, 'count' => $count], array_keys($bands), $bands),
            'distribution_basis'   => 'percent_of_total_points',
            'pending_attempts'     => $byStatus['submitted'] ?? 0,           // 有簡答題等待評分，尚未納入統計
            'in_progress_attempts' => $byStatus['in_progress'] ?? 0,
        ];
    }

    /** 各題答對率，由低到高 */
    public function questions(array $user, int $courseId, array $query, Page $page): array
    {
        $course = $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');
        AccessPolicy::assertManageCourse($user, $course, hide: true);

        $f = Validator::validate($query, ['exam_id' => 'int|min:1', 'chapter_id' => 'int|min:1']);
        if (isset($f['exam_id'])) {
            $exam = $this->exams->findWithCourse($f['exam_id']);
            if ($exam === null || (int) $exam['course_id'] !== $courseId) {
                throw AppException::notFound('這門課程沒有這份考卷');
            }
        }
        $this->attemptService->finalizeOverdue(200);

        [$rows, $total] = $this->stats->questionRates($courseId, $f, $page->perPage, $page->offset());
        $items = array_map(static fn (array $r): array => [
            'question_id'  => (int) $r['question_id'],
            'type'         => $r['type'],
            'content'      => $r['content'],
            'chapter'      => ['chapter_id' => (int) $r['chapter_id'], 'name' => $r['chapter_name']],
            'answered'     => (int) $r['answered'],
            'correct'      => (int) $r['correct'],
            'correct_rate' => (float) $r['correct_rate'],
        ], $rows);
        return [$items, $page->meta($total)];
    }

    /** 班級章節弱點 */
    public function courseChapters(array $user, int $courseId): array
    {
        $course = $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');
        AccessPolicy::assertManageCourse($user, $course, hide: true);
        $this->attemptService->finalizeOverdue(200);

        return ['course_id' => $courseId, 'chapters' => array_map([self::class, 'chapterItem'], $this->stats->chapterRatesForCourse($courseId))];
    }

    /** 學生個人章節弱點；`?course_id=` 選填（不給就是所有課程） */
    public function myChapters(array $user, array $query): array
    {
        $f = Validator::validate($query, ['course_id' => 'int|min:1']);
        $courseId = $f['course_id'] ?? null;
        if ($courseId !== null) {
            $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');
        }
        $this->attemptService->finalizeOverdue(200);

        $rows = $this->stats->chapterRatesForStudent((int) $user['user_id'], $courseId, Dates::now()->format('Y-m-d H:i:s'));
        return ['course_id' => $courseId, 'chapters' => array_map(static fn (array $r): array => self::chapterItem($r) + [
            'course' => ['course_id' => (int) $r['course_id'], 'name' => $r['course_name']],
        ], $rows)];
    }

    private static function chapterItem(array $r): array
    {
        return [
            'chapter_id'   => (int) $r['chapter_id'],
            'name'         => $r['name'],
            'total'        => (int) $r['total'],
            'correct'      => (int) $r['correct'],
            'correct_rate' => (float) $r['correct_rate'],
        ];
    }
}
