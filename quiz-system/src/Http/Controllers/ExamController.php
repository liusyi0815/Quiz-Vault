<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ExamService;

final class ExamController
{
    public function __construct(private readonly ExamService $service = new ExamService())
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
        return Response::ok($this->service->get(Auth::user(), $this->id($request)));
    }

    public function update(Request $request): Response
    {
        return Response::ok($this->service->update(Auth::user(), $this->id($request), $request->body()));
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(Auth::user(), $this->id($request));
        return Response::noContent();
    }

    public function replaceQuestions(Request $request): Response
    {
        return Response::ok($this->service->replaceQuestions(Auth::user(), $this->id($request), $request->body()));
    }

    public function drawRandom(Request $request): Response
    {
        [$items, $meta] = $this->service->drawRandom(Auth::user(), $this->id($request), $request->body());
        return Response::ok($items, $meta);
    }

    public function publish(Request $request): Response
    {
        return Response::ok($this->service->publish(Auth::user(), $this->id($request)));
    }

    public function unpublish(Request $request): Response
    {
        return Response::ok($this->service->unpublish(Auth::user(), $this->id($request)));
    }

    public function close(Request $request): Response
    {
        return Response::ok($this->service->close(Auth::user(), $this->id($request)));
    }

    private function id(Request $request): int
    {
        return Validator::id($request->params['exam_id'] ?? null);
    }
}
