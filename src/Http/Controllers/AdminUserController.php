<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
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

    /** GET /admin/users */
    public function index(Request $request): Response
    {
        [$items, $meta] = $this->service->list($request->query, Page::fromRequest($request));
        return Response::ok($items, $meta);
    }

    /** POST /admin/users（臨時密碼只在這次回應出現，不可快取） */
    public function store(Request $request): Response
    {
        return Response::created($this->service->create($request->body()));
    }

    /** PATCH /admin/users/{user_id} */
    public function update(Request $request): Response
    {
        return Response::ok($this->service->update(Auth::id(), Validator::id($request->params['user_id'] ?? null), $request->body()));
    }
}
