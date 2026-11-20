<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ChapterService;

final class ChapterController
{
    public function __construct(private readonly ChapterService $service = new ChapterService())
    {
    }

    public function index(Request $request): Response
    {
        $items = $this->service->list(Auth::user(), Validator::id($request->params['course_id'] ?? null));
        return Response::ok($items, ['total' => count($items)]);
    }

    public function store(Request $request): Response
    {
        return Response::created($this->service->create(
            Auth::user(), Validator::id($request->params['course_id'] ?? null), $request->body()
        ));
    }

    public function reorder(Request $request): Response
    {
        $items = $this->service->reorder(Auth::user(), Validator::id($request->params['course_id'] ?? null), $request->body());
        return Response::ok($items, ['total' => count($items)]);
    }

    public function update(Request $request): Response
    {
        return Response::ok($this->service->update(
            Auth::user(), Validator::id($request->params['chapter_id'] ?? null), $request->body()
        ));
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(Auth::user(), Validator::id($request->params['chapter_id'] ?? null));
        return Response::noContent();
    }
}
