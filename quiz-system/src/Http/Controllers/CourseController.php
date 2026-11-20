<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CourseService;

final class CourseController
{
    public function __construct(private readonly CourseService $service = new CourseService())
    {
    }

    public function index(Request $request): Response
    {
        [$items, $meta] = $this->service->list(Auth::user(), $request->query, Page::fromRequest($request));
        return Response::ok($items, $meta);
    }

    public function store(Request $request): Response
    {
        return Response::created($this->service->create(Auth::user(), $request->body()));
    }

    public function show(Request $request): Response
    {
        return Response::ok($this->service->get(Auth::user(), Validator::id($request->params['course_id'] ?? null)));
    }

    public function update(Request $request): Response
    {
        return Response::ok($this->service->update(
            Auth::user(), Validator::id($request->params['course_id'] ?? null), $request->body()
        ));
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(Auth::user(), Validator::id($request->params['course_id'] ?? null));
        return Response::noContent();
    }
}
