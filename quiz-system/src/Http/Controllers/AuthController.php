<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

/** Controller 只做：取參數 → 呼叫 Service → 決定狀態碼。業務規則都在 Service。 */
final class AuthController
{
    public function __construct(private readonly AuthService $auth = new AuthService())
    {
    }

    public function register(Request $request): Response
    {
        return Response::created($this->auth->register($request->body()));
    }

    public function login(Request $request): Response
    {
        return Response::ok($this->auth->login($request->body()));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::ok(['logged_out' => true]);
    }

    public function me(Request $request): Response
    {
        return Response::ok($this->auth->sessionPayload(Auth::user()));
    }

    public function updateMe(Request $request): Response
    {
        return Response::ok($this->auth->updateProfile(Auth::id(), $request->body()));
    }

    public function changePassword(Request $request): Response
    {
        return Response::ok($this->auth->changePassword(Auth::id(), $request->body()));
    }
}
