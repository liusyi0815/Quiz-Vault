<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use App\Domain\QuestionRules;
use App\Support\AppException;

/**
 * 題目輸入的驗證與整理（新增、整題取代共用）。
 * 題型規則見 docs/04-behavior.md §4.1：
 *   single    選項 2~6 個，恰 1 個正確
 *   multiple  選項 3~8 個，至少 1 個正確
 *   truefalse 恰 2 個選項「對」「錯」，恰 1 個正確
 *   short     不可有選項
 */
final class QuestionInput
{
    /** @return array{chapter_id:int, type:string, content:string, difficulty:int, explanation:?string, options:list<array>, tags:list<string>} */
    public static function normalize(array $input): array
    {
        $v = Validator::validate($input, [
            'chapter_id'  => 'required|int|min:1',
            'type'        => 'required|in:single,multiple,truefalse,short',
            'content'     => 'required|trim|max:5000',
            'difficulty'  => 'int|min:1|max:3',
            'explanation' => 'nullable|trim|max:5000',
            'options'     => 'array',
            'tags'        => 'array',
        ]);

        return [
            'chapter_id'  => $v['chapter_id'],
            'type'        => $v['type'],
            'content'     => $v['content'],
            'difficulty'  => $v['difficulty'] ?? 1,
            'explanation' => $v['explanation'] ?? null,
            'options'     => self::options($v['type'], $v['options'] ?? []),
            'tags'        => self::tags($v['tags'] ?? []),
        ];
    }

    /** @return list<string> 去除重複（不分大小寫）後的標籤名稱 */
    public static function tags(array $raw): array
    {
        $errors = [];
        $seen = [];
        $out = [];
        foreach (array_values($raw) as $i => $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                $errors[] = ['field' => "tags[{$i}]", 'reason' => '必須是非空白的文字'];
                continue;
            }
            $name = trim($tag);
            if (mb_strlen($name) > 50) {
                $errors[] = ['field' => "tags[{$i}]", 'reason' => '長度不可超過 50 個字元'];
                continue;
            }
            $key = mb_strtolower($name);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $name;
            }
        }
        if (count($out) > 10) {
            $errors[] = ['field' => 'tags', 'reason' => '最多只能有 10 個標籤'];
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }
        return $out;
    }

    /** @return list<array{content:string,is_correct:bool,sort_order:int}> */
    private static function options(string $type, array $raw): array
    {
        if ($type === 'short') {
            if ($raw !== []) {
                throw self::shape('options', '簡答題不可有選項');
            }
            return [];
        }

        // ---- 逐筆檢查欄位格式 ----
        $errors = [];
        $items = [];
        foreach (array_values($raw) as $i => $o) {
            if (!is_array($o)) {
                $errors[] = ['field' => "options[{$i}]", 'reason' => '必須是物件'];
                continue;
            }
            $content = is_string($o['content'] ?? null) ? trim($o['content']) : '';
            if ($content === '') {
                $errors[] = ['field' => "options[{$i}].content", 'reason' => '必填'];
            } elseif (mb_strlen($content) > 500) {
                $errors[] = ['field' => "options[{$i}].content", 'reason' => '長度不可超過 500 個字元'];
            }
            if (!is_bool($o['is_correct'] ?? null)) {
                $errors[] = ['field' => "options[{$i}].is_correct", 'reason' => '必須是 true 或 false'];
            }
            $sort = $o['sort_order'] ?? ($i + 1);
            if (!is_int($sort)) {
                $errors[] = ['field' => "options[{$i}].sort_order", 'reason' => '必須是整數'];
                $sort = $i + 1;
            }
            $items[] = ['content' => $content, 'is_correct' => (bool) ($o['is_correct'] ?? false), 'sort_order' => $sort, 'i' => $i];
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }

        // ---- 題型規則（與考卷發佈檢查共用 Domain\QuestionRules）----
        $problem = QuestionRules::problem($type, array_map(
            static fn (array $o): array => ['content' => $o['content'], 'is_correct' => $o['is_correct']],
            $items
        ));
        if ($problem !== null) {
            throw self::shape('options', $problem);
        }

        // 依 sort_order（相同則依送來的順序）排序後，重新編號為 1..n
        usort($items, static fn (array $a, array $b): int => [$a['sort_order'], $a['i']] <=> [$b['sort_order'], $b['i']]);
        $out = [];
        foreach ($items as $n => $o) {
            $out[] = ['content' => $o['content'], 'is_correct' => $o['is_correct'], 'sort_order' => $n + 1];
        }
        return $out;
    }

    private static function shape(string $field, string $reason): AppException
    {
        return new AppException(400, 'INVALID_QUESTION_SHAPE', '題型與選項不符', [['field' => $field, 'reason' => $reason]]);
    }
}
