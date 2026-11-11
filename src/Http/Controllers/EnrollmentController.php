<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\EnrollmentService;

final class EnrollmentController
{
    public function __construct(private readonly EnrollmentService $service = new EnrollmentService())
    {
    }

    public function enroll(Request $request): Response
    {
        return Response::created($this->service->enroll(Auth::user(), Validator::id($request->params['course_id'] ?? null)));
    }

    public function unenroll(Request $request): Response
    {
        $this->service->unenroll(Auth::user(), Validator::id($request->params['course_id'] ?? null));
        return Response::noContent();
    }

    public function students(Request $request): Response
    {
        $q = isset($request->query['q']) && is_string($request->query['q']) ? trim($request->query['q']) : null;
        [$items, $meta] = $this->service->students(
            Auth::user(), Validator::id($request->params['course_id'] ?? null), $q, Page::fromRequest($request)
        );
        return Response::ok($items, $meta);
    }

    public function removeStudent(Request $request): Response
    {
        $this->service->removeStudent(
            Auth::user(),
            Validator::id($request->params['course_id'] ?? null),
            Validator::id($request->params['user_id'] ?? null),
        );
        return Response::noContent();
    }
}
