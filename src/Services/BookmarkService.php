<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Page;
use App\Core\Validator;
use App\Repositories\AttemptRepository;
use App\Repositories\BookmarkRepository;
use App\Support\AppException;
use App\Support\Dates;

/** F-16 題目收藏與錯題本（只有學生；docs/04 §13） */
final class BookmarkService
{
    public function __construct(
        private readonly BookmarkRepository $bookmarks = new BookmarkRepository(),
        private readonly AttemptRepository $attempts = new AttemptRepository(),
        private readonly AttemptService $attemptService = new AttemptService(),
    ) {
    }

    private static function now(): string
    {
        return Dates::now()->format('Y-m-d H:i:s');
    }

    /**
     * 收藏或更新筆記。只能收藏「自己作答過、而且那份考卷已可檢視解析」的題目，
     * 否則一律回 404，避免用收藏 API 去探測題目是否存在或藏在哪份考卷。
     */
    public function put(array $user, int $questionId, array $input): array
    {
        $v = Validator::validate($input, ['note' => 'nullable|trim|max:255']);
        $userId = (int) $user['user_id'];

        $this->attemptService->finalizeOverdue(200);                       // 逾時未交的作答先結算，才算「作答過」
        if (!$this->bookmarks->canView($userId, $questionId, self::now())) {
            throw AppException::notFound('找不到這一題，或這一題還不能收藏');
        }
        $this->bookmarks->upsert($userId, $questionId, $v['note'] ?? null);

        $row = $this->bookmarks->find($userId, $questionId);
        return ['question_id' => $questionId, 'note' => $row['note'], 'created_at' => Dates::iso($row['created_at'])];
    }

    public function delete(array $user, int $questionId): void
    {
        if ($this->bookmarks->delete((int) $user['user_id'], $questionId) === 0) {
            throw AppException::notFound('你沒有收藏這一題');
        }
    }

    /** 我的收藏。解析與正確答案只在「仍然可檢視」時才附上 */
    public function list(array $user, array $query, Page $page): array
    {
        $f = Validator::validate($query, ['course_id' => 'int|min:1']);
        $userId = (int) $user['user_id'];
        $this->attemptService->finalizeOverdue(200);

        [$rows, $total] = $this->bookmarks->list($userId, $f['course_id'] ?? null, self::now(), $page->perPage, $page->offset());
        $ids = array_map(static fn (array $r): int => (int) $r['question_id'], array_filter($rows, static fn (array $r): bool => (bool) $r['revealed']));
        $options = $this->attempts->optionsFor($ids, true);

        $items = array_map(static function (array $r) use ($options): array {
            $revealed = (bool) $r['revealed'];
            return [
                'question_id'     => (int) $r['question_id'],
                'type'            => $r['type'],
                'content'         => $r['content'],
                'chapter'         => ['chapter_id' => (int) $r['chapter_id'], 'name' => $r['chapter_name']],
                'course'          => ['course_id' => (int) $r['course_id'], 'name' => $r['course_name']],
                'note'            => $r['note'],
                'bookmarked_at'   => Dates::iso($r['created_at']),
                'answers_revealed' => $revealed,
                'options'         => $revealed ? self::options($options[(int) $r['question_id']] ?? []) : null,
                'explanation'     => $revealed ? $r['explanation'] : null,
            ];
        }, $rows);
        return [$items, $page->meta($total)];
    }

    /** 錯題本：答錯的題目（同一題多次答錯合併），含正確答案、解析與我最近一次答錯時的答案 */
    public function wrongQuestions(array $user, array $query, Page $page): array
    {
        $f = Validator::validate($query, ['course_id' => 'int|min:1', 'chapter_id' => 'int|min:1']);
        $userId = (int) $user['user_id'];
        $this->attemptService->finalizeOverdue(200);
        $now = self::now();

        [$rows, $total] = $this->bookmarks->wrongQuestions($userId, $f, $now, $page->perPage, $page->offset());
        $ids = array_map(static fn (array $r): int => (int) $r['question_id'], $rows);
        $options = $this->attempts->optionsFor($ids, true);
        $last = $this->bookmarks->lastWrongAnswers($userId, $ids, $now);

        $items = array_map(static fn (array $r): array => [
            'question_id'   => (int) $r['question_id'],
            'type'          => $r['type'],
            'content'       => $r['content'],
            'chapter'       => ['chapter_id' => (int) $r['chapter_id'], 'name' => $r['chapter_name']],
            'course'        => ['course_id' => (int) $r['course_id'], 'name' => $r['course_name']],
            'wrong_times'   => (int) $r['wrong_times'],
            'last_wrong_at' => Dates::iso($r['last_wrong_at']),
            'bookmarked'    => (bool) $r['bookmarked'],
            'options'       => self::options($options[(int) $r['question_id']] ?? []),
            'explanation'   => $r['explanation'],
            'last_answer'   => $last[(int) $r['question_id']] ?? null,
        ], $rows);
        return [$items, $page->meta($total)];
    }

    private static function options(array $rows): array
    {
        return array_map(static fn (array $o): array => [
            'option_id' => (int) $o['option_id'], 'content' => $o['content'], 'is_correct' => (bool) $o['is_correct'],
        ], $rows);
    }
}
