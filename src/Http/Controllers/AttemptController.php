<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AttemptService;

final class AttemptController
{
    public function __construct(private readonly AttemptService $service = new AttemptService())
    {
    }

    /** POST /exams/{exam_id}/attempts */
    public function start(Request $request): Response
    {
        return Response::created($this->service->start(Auth::user(), Validator::id($request->params['exam_id'] ?? null)));
    }

    /** GET /attempts/{attempt_id} */
    public function show(Request $request): Response
    {
        return Response::ok($this->service->resume(Auth::user(), $this->attemptId($request)));
    }

    /** PUT /attempts/{attempt_id}/answers/{question_id} */
    public function saveAnswer(Request $request): Response
    {
        return Response::ok($this->service->saveAnswer(
            Auth::user(), $this->attemptId($request), Validator::id($request->params['question_id'] ?? null), $request->body()
        ));
    }

    /** POST /attempts/{attempt_id}/submit */
    public function submit(Request $request): Response
    {
        return Response::ok($this->service->submit(Auth::user(), $this->attemptId($request), $request->body()));
    }

    /** GET /attempts/{attempt_id}/result */
    public function result(Request $request): Response
    {
        return Response::ok($this->service->result(Auth::user(), $this->attemptId($request)));
    }

    /** GET /me/attempts */
    public function mine(Request $request): Response
    {
        [$items, $meta] = $this->service->listMine(Auth::user(), $request->query, Page::fromRequest($request));
        return Response::ok($items, $meta);
    }

    /** GET /exams/{exam_id}/attempts */
    public function forExam(Request $request): Response
    {
        [$items, $meta] = $this->service->listForExam(
            Auth::user(), Validator::id($request->params['exam_id'] ?? null), $request->query, Page::fromRequest($request)
        );
        return Response::ok($items, $meta);
    }

    private function attemptId(Request $request): int
    {
        return Validator::id($request->params['attempt_id'] ?? null);
    }
}
