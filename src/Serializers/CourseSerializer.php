<?php
declare(strict_types=1);

namespace App\Serializers;

use App\Services\AccessPolicy;
use App\Support\Dates;

final class CourseSerializer
{
    /** 列表用 */
    public static function item(array $row, array $viewer): array
    {
        return [
            'course_id'   => (int) $row['course_id'],
            'name'        => $row['name'],
            'description' => $row['description'],
            'teacher'     => ['user_id' => (int) $row['teacher_id'], 'username' => $row['teacher_name']],
            'created_at'  => Dates::iso($row['created_at']),
            'enrolled'    => (bool) ($row['enrolled'] ?? false),   // 只有學生會是 true
            'can_manage'  => AccessPolicy::canManageCourse($viewer, $row),
        ];
    }

    /** 詳情用：課程擁有者與管理員多看到修課人數、題目數、考卷數 */
    public static function detail(array $row, array $viewer, array $counts, bool $enrolled): array
    {
        $out = self::item($row, $viewer);
        $out['enrolled'] = $enrolled;
        $out['chapter_count'] = $counts['chapters'];
        if ($out['can_manage']) {
            $out['student_count'] = $counts['students'];
            $out['question_count'] = $counts['questions'];
            $out['exam_count'] = $counts['exams'];
        }
        return $out;
    }
}
