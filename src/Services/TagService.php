<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Page;
use App\Core\Validator;
use App\Repositories\TagRepository;
use App\Support\AppException;

/** F-07 標籤管理。標籤是全站共用的（不屬於任何課程），老師與管理員都能看與新增，只有管理員能刪。 */
final class TagService
{
    public function __construct(private readonly TagRepository $tags = new TagRepository())
    {
    }

    /** @return array{0:list<array>, 1:array} */
    public function list(array $query, Page $page): array
    {
        $f = Validator::validate($query, ['q' => 'trim|max:50']);
        [$rows, $total] = $this->tags->list($f['q'] ?? null, $page->perPage, $page->offset());
        return [array_map([self::class, 'item'], $rows), $page->meta($total)];
    }

    public function create(array $input): array
    {
        $v = Validator::validate($input, ['name' => 'required|trim|max:50']);

        // 名稱唯一且忽略大小寫：SQL 與 sql 是同一個標籤
        if (($existing = $this->tags->findByName($v['name'])) !== null) {
            throw self::exists($existing);
        }
        try {
            $id = $this->tags->create($v['name']);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {                     // 兩人同時新增：唯一鍵擋下後者
                throw self::exists($this->tags->findByName($v['name']) ?? ['tag_id' => 0, 'name' => $v['name']]);
            }
            throw $e;
        }
        return self::item(['tag_id' => $id, 'name' => $v['name'], 'question_count' => 0]);
    }

    /** 只移除標籤與題目的關聯，不影響題目本身 */
    public function delete(int $tagId): void
    {
        if ($this->tags->delete($tagId) === 0) {
            throw AppException::notFound('找不到這個標籤');
        }
    }

    private static function exists(array $tag): AppException
    {
        return new AppException(409, 'TAG_EXISTS', '已經有這個標籤了', [['tag_id' => (int) $tag['tag_id'], 'name' => $tag['name']]]);
    }

    private static function item(array $r): array
    {
        return ['tag_id' => (int) $r['tag_id'], 'name' => $r['name'], 'question_count' => (int) ($r['question_count'] ?? 0)];
    }
}
