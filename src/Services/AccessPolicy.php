<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\AppException;

/**
 * 「老師只能管理自己開的課」的判斷都集中在這裡（管理員不受限）。
 * 傳入的 $course 必須含 teacher_id。
 *
 * $hide = true  → 沒有權限時回 404（用在「別人看不到的資料」：修課名單、題庫）
 * $hide = false → 回 403（用在「大家都看得到的資源」：課程本身、章節）
 */
final class AccessPolicy
{
    public static function canManageCourse(array $user, array $course): bool
    {
        return $user['role'] === 'admin'
            || ($user['role'] === 'teacher' && (int) $course['teacher_id'] === (int) $user['user_id']);
    }

    public static function assertManageCourse(array $user, array $course, bool $hide = false): void
    {
        if (self::canManageCourse($user, $course)) {
            return;
        }
        throw $hide ? AppException::notFound() : AppException::forbidden('只能管理自己開設的課程');
    }
}
