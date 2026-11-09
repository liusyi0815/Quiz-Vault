<?php
declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;

/**
 * 路由表。middleware 依序執行：auth → must_change → csrf → role
 * 端點清單與權限見 docs/03-api.md。
 */
return static function (Router $r): void {
    // 一般受保護端點
    $user  = ['auth', 'must_change', 'csrf'];
    // 「須先改密碼」狀態下仍可使用：查自己、改密碼、登出（不掛 must_change）
    $allow = ['auth', 'csrf'];
    $admin = ['auth', 'must_change', 'csrf', 'role:admin'];

    // 公開
    $r->add('GET',  '/health',        [HealthController::class, 'index']);
    $r->add('POST', '/auth/register', [AuthController::class, 'register']);
    $r->add('POST', '/auth/login',    [AuthController::class, 'login']);

    // 登入後
    $r->add('POST',  '/auth/logout', [AuthController::class, 'logout'],         $allow);
    $r->add('GET',   '/me',          [AuthController::class, 'me'],             $allow);
    $r->add('PUT',   '/me/password', [AuthController::class, 'changePassword'], $allow);
    $r->add('PATCH', '/me',          [AuthController::class, 'updateMe'],       $user);

    // 管理員（F-22）
    $r->add('POST', '/admin/users/{user_id}/reset-password', [AdminUserController::class, 'resetPassword'], $admin);
};
