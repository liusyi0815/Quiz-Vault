<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Page;
use App\Repositories\CourseRepository;
use App\Repositories\EnrollmentRepository;
use App\Support\AppException;
use App\Support\Dates;

/** F-04 修課與退選 */
final class EnrollmentService
{
    public function __construct(
        private readonly CourseRepository $courses = new CourseRepository(),
        private readonly EnrollmentRepository $enrollments = new EnrollmentRepository(),
    ) {
    }

    public function enroll(array $user, int $courseId): array
    {
        $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');

        try {
            $this->enrollments->enroll($courseId, (int) $user['user_id']);
        } catch (\PDOException $e) {
            // 1062 = 主鍵 (course_id, user_id) 重複；連按兩次也只會成功一次
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new AppException(409, 'ALREADY_ENROLLED', '你已經加入這門課程');
            }
            throw $e;
        }

        $row = $this->enrollments->find($courseId, (int) $user['user_id']);
        return ['course_id' => $courseId, 'enrolled_at' => Dates::iso($row['enrolled_at'])];
    }

    /** 退選只刪除修課紀錄，不會刪除過往的作答與成績 */
    public function unenroll(array $user, int $courseId): void
    {
        $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');

        if ($this->enrollments->remove($courseId, (int) $user['user_id']) === 0) {
            throw new AppException(403, 'NOT_ENROLLED', '你尚未加入這門課程');
        }
    }

    /** @return array{0:list<array>, 1:array} */
    public function students(array $user, int $courseId, ?string $q, Page $page): array
    {
        $course = $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');
        AccessPolicy::assertManageCourse($user, $course, hide: true);     // 修課名單不公開：別人的課回 404

        [$rows, $total] = $this->enrollments->students($courseId, $q, $page->perPage, $page->offset());
        $items = array_map(static fn (array $r): array => [
            'user_id'     => (int) $r['user_id'],
            'username'    => $r['username'],
            'email'       => $r['email'],
            'enrolled_at' => Dates::iso($r['enrolled_at']),
        ], $rows);

        return [$items, $page->meta($total)];
    }

    public function removeStudent(array $user, int $courseId, int $studentId): void
    {
        $course = $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');
        AccessPolicy::assertManageCourse($user, $course, hide: true);

        if ($this->enrollments->remove($courseId, $studentId) === 0) {
            throw AppException::notFound('這位學生沒有修這門課');
        }
    }
}
