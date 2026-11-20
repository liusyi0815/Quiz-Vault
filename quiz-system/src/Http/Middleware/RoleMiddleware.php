<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Support\AppException;

/** 用法：'role:admin' 或 'role:teacher,admin' */
final class RoleMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        if (!in_array(Auth::user()['role'], $args, true)) {
            throw AppException::forbidden();
        }
    }
}
