<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\QuestionService;

final class QuestionController
{
    public function __construct(private readonly QuestionService $service = new QuestionService())
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
        return Response::ok($this->service->get(Auth::user(), Validator::id($request->params['question_id'] ?? null)));
    }

    public function replace(Request $request): Response
    {
        return Response::ok($this->service->replace(
            Auth::user(), Validator::id($request->params['question_id'] ?? null), $request->body()
        ));
    }

    public function patch(Request $request): Response
    {
        return Response::ok($this->service->patch(
            Auth::user(), Validator::id($request->params['question_id'] ?? null), $request->body()
        ));
    }

    public function destroy(Request $request): Response
    {
        $this->service->deactivate(Auth::user(), Validator::id($request->params['question_id'] ?? null));
        return Response::noContent();
    }
}
