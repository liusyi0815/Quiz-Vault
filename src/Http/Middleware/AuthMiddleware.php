<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Support\AppException;

/** 已登入、帳號啟用、閒置未逾時；通過後載入目前使用者 */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        $userId = Session::get('user_id');
        if (!is_int($userId)) {
            throw AppException::unauthenticated();
        }

        // 閒置逾時（不依賴 PHP 垃圾回收的時機，行為固定）
        $lifetime = Config::int('SESSION_LIFETIME', 7200);
        if (time() - (int) Session::get('last_seen', 0) > $lifetime) {
            Session::destroy();
            throw AppException::unauthenticated('登入已逾時，請重新登入');
        }

        $user = (new UserRepository())->findById($userId);
        if ($user === null) {
            Session::destroy();
            throw AppException::unauthenticated();
        }
        if (!(bool) $user['is_active']) {
            Session::destroy();
            throw new AppException(403, 'ACCOUNT_DISABLED', '帳號已停用');
        }

        Session::set('last_seen', time());
        Auth::setUser($user);
    }
}
