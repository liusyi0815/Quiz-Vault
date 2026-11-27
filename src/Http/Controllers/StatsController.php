<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\StatsService;

final class StatsController
{
    public function __construct(private readonly StatsService $service = new StatsService())
    {
    }

    /** GET /exams/{exam_id}/stats */
    public function exam(Request $request): Response
    {
        return Response::ok($this->service->exam(Auth::user(), Validator::id($request->params['exam_id'] ?? null)));
    }

    /** GET /courses/{course_id}/stats/questions */
    public function questions(Request $request): Response
    {
        [$items, $meta] = $this->service->questions(
            Auth::user(), Validator::id($request->params['course_id'] ?? null), $request->query, Page::fromRequest($request)
        );
        return Response::ok($items, $meta);
    }

    /** GET /courses/{course_id}/stats/chapters */
    public function courseChapters(Request $request): Response
    {
        return Response::ok($this->service->courseChapters(Auth::user(), Validator::id($request->params['course_id'] ?? null)));
    }

    /** GET /me/stats/chapters */
    public function myChapters(Request $request): Response
    {
        return Response::ok($this->service->myChapters(Auth::user(), $request->query));
    }
}
