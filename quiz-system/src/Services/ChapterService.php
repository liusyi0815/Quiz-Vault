<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Repositories\ChapterRepository;
use App\Repositories\CourseRepository;
use App\Serializers\ChapterSerializer;
use App\Support\AppException;

/** F-05 章節管理 */
final class ChapterService
{
    public function __construct(
        private readonly ChapterRepository $chapters = new ChapterRepository(),
        private readonly CourseRepository $courses = new CourseRepository(),
    ) {
    }

    /** @return list<array> 章節本身大家都看得到；只有能管理該課程的人會多看到每章的題數 */
    public function list(array $user, int $courseId): array
    {
        $course = $this->courses->findById($courseId) ?? throw AppException::notFound('找不到這門課程');
        $rows = $this->chapters->listByCourse($courseId, AccessPolicy::canManageCourse($user, $course));
        return array_map([ChapterSerializer::class, 'item'], $rows);
    }

    public function create(array $user, int $courseId, array $input): array
    {
        $v = Validator::validate($input, ['name' => 'required|trim|max:100', 'sort_order' => 'int|min:0|max:100000']);

        $id = Database::transaction(function () use ($user, $courseId, $v): int {
            $course = $this->courses->lockById($courseId) ?? throw AppException::notFound('找不到這門課程');
            AccessPolicy::assertManageCourse($user, $course);
            return $this->chapters->create($courseId, $v['name'], $v['sort_order'] ?? $this->chapters->nextSortOrder($courseId));
        });

        return ChapterSerializer::item($this->chapters->findWithCourse($id));
    }

    public function update(array $user, int $chapterId, array $input): array
    {
        $chapter = $this->chapters->findWithCourse($chapterId) ?? throw AppException::notFound('找不到這個章節');
        AccessPolicy::assertManageCourse($user, $chapter);

        $v = Validator::validate($input, ['name' => 'trim|max:100', 'sort_order' => 'int|min:0|max:100000']);
        if ($v === []) {
            throw AppException::validation([['field' => 'name', 'reason' => '至少要提供 name 或 sort_order 其中一項']]);
        }

        $this->chapters->update($chapterId, $v);
        return ChapterSerializer::item($this->chapters->findWithCourse($chapterId));
    }

    /** 章節底下還有題目（含已停用的）就不能刪除，避免出現孤兒題目 */
    public function delete(array $user, int $chapterId): void
    {
        $chapter = $this->chapters->findWithCourse($chapterId) ?? throw AppException::notFound('找不到這個章節');
        AccessPolicy::assertManageCourse($user, $chapter);

        Database::transaction(function () use ($chapter, $chapterId): void {
            $this->courses->lockById((int) $chapter['course_id']);
            $n = $this->chapters->countQuestions($chapterId);
            if ($n > 0) {
                throw new AppException(409, 'CHAPTER_NOT_EMPTY', '章節底下還有題目，不能刪除', [['question_count' => $n]]);
            }
            $this->chapters->delete($chapterId);
        });
    }

    /** 一次調整整門課的章節順序：chapter_ids 必須剛好是這門課所有的章節 */
    public function reorder(array $user, int $courseId, array $input): array
    {
        $v = Validator::validate($input, ['chapter_ids' => 'required|array|min:1']);
        $ids = $v['chapter_ids'];
        foreach ($ids as $i => $id) {
            if (!is_int($id) || $id < 1) {
                throw AppException::validation([['field' => "chapter_ids[{$i}]", 'reason' => '必須是正整數']]);
            }
        }

        Database::transaction(function () use ($user, $courseId, $ids): void {
            $course = $this->courses->lockById($courseId) ?? throw AppException::notFound('找不到這門課程');
            AccessPolicy::assertManageCourse($user, $course);

            $existing = $this->chapters->idsByCourse($courseId);
            $given = $ids;
            sort($existing);
            sort($given);
            if ($existing !== $given) {
                throw AppException::validation([['field' => 'chapter_ids', 'reason' => '必須剛好包含這門課程的所有章節，且不可重複']]);
            }
            foreach ($ids as $position => $id) {
                $this->chapters->setSortOrder($id, $position + 1);
            }
        });

        return $this->list($user, $courseId);
    }
}
