<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Page;
use App\Core\Validator;
use App\Repositories\ChapterRepository;
use App\Repositories\QuestionRepository;
use App\Repositories\TagRepository;
use App\Serializers\QuestionSerializer;
use App\Support\AppException;

/** F-06 題庫管理：題目、選項、標籤在同一個 transaction 寫入（docs/04 §4） */
final class QuestionService
{
    public function __construct(
        private readonly QuestionRepository $questions = new QuestionRepository(),
        private readonly ChapterRepository $chapters = new ChapterRepository(),
        private readonly TagRepository $tags = new TagRepository(),
    ) {
    }

    /** @return array{0:list<array>, 1:array} */
    public function list(array $user, array $query, Page $page): array
    {
        $f = Validator::validate($query, [
            'course_id'  => 'int|min:1',
            'chapter_id' => 'int|min:1',
            'type'       => 'in:single,multiple,truefalse,short',
            'difficulty' => 'int|min:1|max:3',
            'is_active'  => 'bool',
            'tag'        => 'trim|max:50',
            'q'          => 'trim|max:100',
        ]);
        // 老師只看得到自己課程的題目；管理員看全部
        $onlyTeacher = $user['role'] === 'teacher' ? (int) $user['user_id'] : null;

        [$rows, $total] = $this->questions->search($f, $onlyTeacher, $page->perPage, $page->offset());
        $tags = $this->questions->tagsFor(array_map(static fn (array $r): int => (int) $r['question_id'], $rows));

        return [
            array_map(static fn (array $r): array => QuestionSerializer::listItem($r, $tags[(int) $r['question_id']] ?? []), $rows),
            $page->meta($total),
        ];
    }

    public function get(array $user, int $id): array
    {
        $row = $this->questions->findWithCourse($id) ?? throw AppException::notFound('找不到這個題目');
        AccessPolicy::assertManageCourse($user, $row, hide: true);     // 題庫不公開：別人的題目回 404
        return $this->detail($row);
    }

    public function create(array $user, array $input): array
    {
        $n = QuestionInput::normalize($input);

        $chapter = $this->chapters->findWithCourse($n['chapter_id']) ?? throw AppException::notFound('找不到這個章節');
        AccessPolicy::assertManageCourse($user, $chapter, hide: true);

        $id = Database::transaction(function () use ($user, $n): int {
            $id = $this->questions->insert(
                $n['chapter_id'], (int) $user['user_id'], $n['type'], $n['content'], $n['difficulty'], $n['explanation']
            );
            $this->questions->insertOptions($id, $n['options']);
            $this->questions->setTags($id, $this->tagIds($n['tags']));
            return $id;
        });

        return $this->get($user, $id);
    }

    /**
     * 整題取代。題目「已被使用」時，題型、題幹、選項必須與現有的完全相同，
     * 只有解析、難度、章節、標籤可以改（docs/04 §4.3）。
     */
    public function replace(array $user, int $id, array $input): array
    {
        $row = $this->questions->findWithCourse($id) ?? throw AppException::notFound('找不到這個題目');
        AccessPolicy::assertManageCourse($user, $row, hide: true);

        $n = QuestionInput::normalize($input);
        $this->assertSameCourse($row, $n['chapter_id']);

        Database::transaction(function () use ($id, $n): void {
            $this->questions->lock($id);
            $current = $this->questions->findWithCourse($id) ?? throw AppException::notFound('找不到這個題目');

            if ($this->questions->inUse($id)) {
                if (!$this->sameCore($current, $n)) {
                    throw new AppException(409, 'QUESTION_IN_USE', '這題已被考卷或作答使用，不能修改題型、題幹與選項（可以修改解析、難度、章節與標籤，或把它停用）');
                }
            } else {
                $this->questions->updateCore($id, $n['type'], $n['content']);
                $this->questions->deleteOptions($id);
                $this->questions->insertOptions($id, $n['options']);
            }
            $this->questions->updateMeta($id, $n['chapter_id'], $n['difficulty'], $n['explanation']);
            $this->questions->setTags($id, $this->tagIds($n['tags']));
        });

        return $this->get($user, $id);
    }

    /** 部分更新：只能改解析、難度、章節、標籤、啟用狀態（不含題型、題幹、選項） */
    public function patch(array $user, int $id, array $input): array
    {
        $row = $this->questions->findWithCourse($id) ?? throw AppException::notFound('找不到這個題目');
        AccessPolicy::assertManageCourse($user, $row, hide: true);

        foreach (['type', 'content', 'options'] as $field) {
            if (array_key_exists($field, $input)) {
                throw AppException::validation([['field' => $field, 'reason' => '這個欄位不能用 PATCH 修改，請使用 PUT 整題取代']]);
            }
        }
        $v = Validator::validate($input, [
            'chapter_id'  => 'int|min:1',
            'difficulty'  => 'int|min:1|max:3',
            'explanation' => 'nullable|trim|max:5000',
            'is_active'   => 'bool',
            'tags'        => 'array',
        ]);
        if ($v === []) {
            throw AppException::validation([['field' => 'difficulty', 'reason' => '至少要提供 chapter_id、difficulty、explanation、is_active、tags 其中一項']]);
        }
        if (isset($v['chapter_id'])) {
            $this->assertSameCourse($row, $v['chapter_id']);
        }
        $tagNames = isset($v['tags']) ? QuestionInput::tags($v['tags']) : null;

        Database::transaction(function () use ($id, $v, $tagNames): void {
            $this->questions->lock($id);
            $this->questions->updatePartial($id, $v);
            if ($tagNames !== null) {
                $this->questions->setTags($id, $this->tagIds($tagNames));
            }
        });

        return $this->get($user, $id);
    }

    /** 軟刪除：is_active = 0。保留題目本身與歷史作答，只是不再能被新考卷挑選 */
    public function deactivate(array $user, int $id): void
    {
        $row = $this->questions->findWithCourse($id) ?? throw AppException::notFound('找不到這個題目');
        AccessPolicy::assertManageCourse($user, $row, hide: true);
        $this->questions->setActive($id, false);
    }

    // ------------------------------------------------------------------

    private function detail(array $row): array
    {
        $id = (int) $row['question_id'];
        return QuestionSerializer::detail(
            $row,
            $this->questions->options($id),
            $this->questions->tagsFor([$id])[$id] ?? [],
            $this->questions->inUse($id),
        );
    }

    /** 題目不能搬到別的課程（它可能已經在原課程的考卷裡） */
    private function assertSameCourse(array $question, int $chapterId): void
    {
        $chapter = $this->chapters->findWithCourse($chapterId);
        if ($chapter === null || (int) $chapter['course_id'] !== (int) $question['course_id']) {
            throw AppException::validation([['field' => 'chapter_id', 'reason' => '必須是同一門課程底下的章節']]);
        }
    }

    private function sameCore(array $current, array $new): bool
    {
        if ($current['type'] !== $new['type'] || $current['content'] !== $new['content']) {
            return false;
        }
        $old = array_map(
            static fn (array $o): array => [$o['content'], (bool) $o['is_correct']],
            $this->questions->options((int) $current['question_id'])
        );
        $next = array_map(static fn (array $o): array => [$o['content'], $o['is_correct']], $new['options']);
        return $old === $next;
    }

    /** @param list<string> $names @return list<int> */
    private function tagIds(array $names): array
    {
        return array_map(fn (string $name): int => $this->tags->idByName($name), $names);
    }
}
