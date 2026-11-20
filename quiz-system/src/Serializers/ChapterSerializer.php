<?php
declare(strict_types=1);

namespace App\Serializers;

final class ChapterSerializer
{
    public static function item(array $row): array
    {
        $out = [
            'chapter_id' => (int) $row['chapter_id'],
            'course_id'  => (int) $row['course_id'],
            'name'       => $row['name'],
            'sort_order' => (int) $row['sort_order'],
        ];
        if (isset($row['question_count'])) {          // 只有能管理該課程的人才會有這個欄位
            $out['question_count'] = (int) $row['question_count'];
        }
        return $out;
    }
}
