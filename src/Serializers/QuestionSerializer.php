<?php
declare(strict_types=1);

namespace App\Serializers;

use App\Support\Dates;

/** 老師／管理員看的題目結構（含 is_correct）。學生作答時用的版本會另外做，絕不共用這個類別。 */
final class QuestionSerializer
{
    public static function listItem(array $row, array $tags): array
    {
        return [
            'question_id' => (int) $row['question_id'],
            'chapter'     => ['chapter_id' => (int) $row['chapter_id'], 'name' => $row['chapter_name']],
            'course_id'   => (int) $row['course_id'],
            'type'        => $row['type'],
            'content'     => $row['content'],
            'difficulty'  => (int) $row['difficulty'],
            'is_active'   => (bool) $row['is_active'],
            'tags'        => $tags,
            'created_at'  => Dates::iso($row['created_at']),
        ];
    }

    public static function detail(array $row, array $options, array $tags, bool $inUse): array
    {
        return self::listItem($row, $tags) + [
            'creator_id'  => (int) $row['creator_id'],
            'explanation' => $row['explanation'],
            'in_use'      => $inUse,
            'options'     => array_map(static fn (array $o): array => [
                'option_id'  => (int) $o['option_id'],
                'content'    => $o['content'],
                'is_correct' => (bool) $o['is_correct'],
                'sort_order' => (int) $o['sort_order'],
            ], $options),
        ];
    }
}
