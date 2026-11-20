<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AdminUserService;

final class AdminUserController
{
    public function __construct(private readonly AdminUserService $service = new AdminUserService())
    {
    }

    /** POST /admin/users/{user_id}/reset-password */
    public function resetPassword(Request $request): Response
    {
        $targetId = Validator::id($request->params['user_id'] ?? null);
        return Response::ok($this->service->resetPassword(Auth::id(), $targetId));
    }
}
