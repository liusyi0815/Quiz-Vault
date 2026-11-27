<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Page;
use App\Core\Validator;
use App\Repositories\UserRepository;
use App\Serializers\UserSerializer;
use App\Support\AppException;
use App\Support\PasswordGenerator;

/** F-17 使用者管理、F-22 管理員重設他人密碼 */
final class AdminUserService
{
    public function __construct(private readonly UserRepository $users = new UserRepository())
    {
    }

    /** 回傳臨時密碼（只有這一次能看到，資料庫只存雜湊，也不寫入 log） */
    public function resetPassword(int $adminId, int $targetId): array
    {
        $target = $this->users->findById($targetId) ?? throw AppException::notFound('找不到此使用者');

        if ($targetId === $adminId) {
            throw new AppException(409, 'CANNOT_MODIFY_SELF', '不能重設自己的密碼，請使用「修改密碼」');
        }

        $temp = PasswordGenerator::temporary(12);
        $this->users->updatePassword($targetId, password_hash($temp, PASSWORD_DEFAULT), true);

        return [
            'user_id'              => (int) $target['user_id'],
            'username'             => $target['username'],
            'temp_password'        => $temp,
            'must_change_password' => true,
        ];
    }

    // ================================================================ F-17

    /** @return array{0:list<array>, 1:array} */
    public function list(array $query, Page $page): array
    {
        $f = Validator::validate($query, [
            'role'      => 'in:student,teacher,admin',
            'q'         => 'trim|max:100',
            'is_active' => 'bool',
        ]);
        [$rows, $total] = $this->users->search($f, $page->perPage, $page->offset());
        return [array_map([UserSerializer::class, 'forAdmin'], $rows), $page->meta($total)];
    }

    /** 建立老師或管理員帳號：密碼由系統產生並只回傳這一次，帳號預設「須先改密碼」 */
    public function create(array $input): array
    {
        $v = Validator::validate($input, [
            'username' => 'required|trim|max:50',
            'email'    => 'required|email|trim|max:100',
            'role'     => 'required|in:teacher,admin',                  // 學生請自行註冊
        ]);

        $temp = PasswordGenerator::temporary(12);
        try {
            $id = $this->users->create($v['username'], strtolower($v['email']), password_hash($temp, PASSWORD_DEFAULT), $v['role'], true);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new AppException(409, 'EMAIL_TAKEN', '這個 Email 已經有人使用', [['field' => 'email']]);
            }
            throw $e;
        }

        return ['user' => UserSerializer::forAdmin($this->users->findForAdmin($id)), 'temp_password' => $temp];
    }

    /** 改角色、停用／啟用。不能動自己；系統至少保留一位啟用中的管理員。 */
    public function update(int $adminId, int $targetId, array $input): array
    {
        $v = Validator::validate($input, ['role' => 'in:student,teacher,admin', 'is_active' => 'bool']);
        if ($v === []) {
            throw AppException::validation([['field' => 'role', 'reason' => '至少要提供 role 或 is_active 其中一項']]);
        }
        $this->users->findById($targetId) ?? throw AppException::notFound('找不到此使用者');
        if ($targetId === $adminId) {
            throw new AppException(409, 'CANNOT_MODIFY_SELF', '不能停用或降級自己');
        }

        Database::transaction(function () use ($targetId, $v): void {
            // 先鎖住所有啟用中的管理員，鎖到之後才判斷「是不是最後一位」
            $activeAdmins = $this->users->lockActiveAdminIds();
            $target = $this->users->lockById($targetId) ?? throw AppException::notFound('找不到此使用者');

            $role = $v['role'] ?? $target['role'];
            $active = array_key_exists('is_active', $v) ? (bool) $v['is_active'] : (bool) $target['is_active'];

            $wasActiveAdmin = $target['role'] === 'admin' && (bool) $target['is_active'];
            $staysActiveAdmin = $role === 'admin' && $active;
            if ($wasActiveAdmin && !$staysActiveAdmin && count(array_diff($activeAdmins, [$targetId])) === 0) {
                throw new AppException(409, 'LAST_ADMIN', '系統至少要保留一位啟用中的管理員');
            }

            if ($role !== $target['role']) {
                $this->assertRoleChangeAllowed($targetId, $target['role'], $role);
            }
            $this->users->updateRoleAndActive($targetId, $role, $active);
        });

        return ['user' => UserSerializer::forAdmin($this->users->findForAdmin($targetId))];
    }

    /** 有歷史資料時不能在「學生」與「老師／管理員」之間換角色，否則會出現學生開課、老師修課這種不合理的資料 */
    private function assertRoleChangeAllowed(int $targetId, string $from, string $to): void
    {
        $fromStudent = $from === 'student';
        $toStudent = $to === 'student';
        if ($fromStudent === $toStudent) {
            return;                                                     // 老師 ↔ 管理員：兩者權限一致，可直接換
        }
        $h = $this->users->historyCounts($targetId);
        $blocking = $fromStudent
            ? array_filter(['enrollments' => $h['enrollments'], 'attempts' => $h['attempts']])
            : array_filter(['courses' => $h['courses'], 'questions' => $h['questions'], 'exams' => $h['exams']]);
        if ($blocking !== []) {
            throw new AppException(409, 'ROLE_IN_USE', '這個帳號已有相關的課程或作答紀錄，不能在學生與老師之間換角色', [$blocking]);
        }
    }
}
