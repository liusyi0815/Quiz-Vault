<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Page;
use App\Core\Validator;
use App\Repositories\CourseRepository;
use App\Repositories\EnrollmentRepository;
use App\Repositories\UserRepository;
use App\Serializers\CourseSerializer;
use App\Support\AppException;

/** F-03 課程管理 */
final class CourseService
{
    public function __construct(
        private readonly CourseRepository $courses = new CourseRepository(),
        private readonly EnrollmentRepository $enrollments = new EnrollmentRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @return array{0:list<array>, 1:array} [資料, meta] */
    public function list(array $user, array $query, Page $page): array
    {
        $f = Validator::validate($query, ['q' => 'trim|max:100', 'mine' => 'bool']);
        [$rows, $total] = $this->courses->search($f, $user, $page->perPage, $page->offset());

        return [
            array_map(static fn (array $r): array => CourseSerializer::item($r, $user), $rows),
            $page->meta($total),
        ];
    }

    public function get(array $user, int $id): array
    {
        $course = $this->courses->findById($id) ?? throw AppException::notFound('找不到這門課程');
        $enrolled = $user['role'] === 'student' && $this->enrollments->find($id, (int) $user['user_id']) !== null;

        return CourseSerializer::detail($course, $user, $this->courses->counts($id), $enrolled);
    }

    public function create(array $user, array $input): array
    {
        $v = Validator::validate($input, [
            'name'        => 'required|trim|max:100',
            'description' => 'nullable|trim|max:2000',
            'teacher_id'  => 'int|min:1',
        ]);

        // 開課老師一律是目前登入者；只有管理員可以代指定
        $teacherId = (int) $user['user_id'];
        if ($user['role'] === 'admin' && isset($v['teacher_id'])) {
            $teacher = $this->users->findById($v['teacher_id']);
            if ($teacher === null || !in_array($teacher['role'], ['teacher', 'admin'], true) || !(bool) $teacher['is_active']) {
                throw AppException::validation([['field' => 'teacher_id', 'reason' => '必須是啟用中的老師帳號']]);
            }
            $teacherId = (int) $teacher['user_id'];
        }

        $id = $this->courses->create($teacherId, $v['name'], $v['description'] ?? null);
        return $this->get($user, $id);
    }

    public function update(array $user, int $id, array $input): array
    {
        $course = $this->courses->findById($id) ?? throw AppException::notFound('找不到這門課程');
        AccessPolicy::assertManageCourse($user, $course);      // 課程大家都看得到，沒權限修改回 403

        $v = Validator::validate($input, ['name' => 'trim|max:100', 'description' => 'nullable|trim|max:2000']);
        if ($v === []) {
            throw AppException::validation([['field' => 'name', 'reason' => '至少要提供 name 或 description 其中一項']]);
        }

        $this->courses->update($id, $v);
        return $this->get($user, $id);
    }

    /** 只有完全空白（沒有章節、沒有考卷）的課程可以刪除 */
    public function delete(array $user, int $id): void
    {
        Database::transaction(function () use ($user, $id): void {
            // 鎖住課程：刪除檢查與刪除之間，不會有人同時新增章節
            $locked = $this->courses->lockById($id) ?? throw AppException::notFound('找不到這門課程');
            AccessPolicy::assertManageCourse($user, $locked);

            $counts = $this->courses->counts($id);
            if ($counts['chapters'] > 0 || $counts['exams'] > 0) {
                throw new AppException(409, 'COURSE_NOT_EMPTY', '課程底下還有章節或考卷，不能刪除', [
                    ['chapters' => $counts['chapters'], 'exams' => $counts['exams']],
                ]);
            }
            $this->courses->delete($id);    // 修課紀錄由外鍵 ON DELETE CASCADE 一併刪除
        });
    }
}
