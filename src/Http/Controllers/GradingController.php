<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\GradingService;

final class GradingController
{
    public function __construct(private readonly GradingService $service = new GradingService())
    {
    }

    /** PATCH /attempts/{attempt_id}/answers/{question_id}/grade */
    public function grade(Request $request): Response
    {
        return Response::ok($this->service->grade(
            Auth::user(),
            Validator::id($request->params['attempt_id'] ?? null),
            Validator::id($request->params['question_id'] ?? null),
            $request->body()
        ));
    }
}
