<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Support\AppException;

/**
 * must_change_password = 1 時擋下所有端點。
 * 只有 GET /me、PUT /me/password、POST /auth/logout 不掛這個 middleware（見 config/routes.php）。
 */
final class MustChangePasswordMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        if ((bool) Auth::user()['must_change_password']) {
            throw new AppException(403, 'PASSWORD_CHANGE_REQUIRED', '請先修改密碼');
        }
    }
}
