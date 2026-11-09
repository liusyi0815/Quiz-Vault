<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use App\Support\AppException;
use App\Support\PasswordGenerator;

/** F-22：管理員重設他人密碼 */
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
}
