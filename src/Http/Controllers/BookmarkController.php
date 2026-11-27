<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\BookmarkService;

final class BookmarkController
{
    public function __construct(private readonly BookmarkService $service = new BookmarkService())
    {
    }

    /** GET /me/bookmarks */
    public function index(Request $request): Response
    {
        [$items, $meta] = $this->service->list(Auth::user(), $request->query, Page::fromRequest($request));
        return Response::ok($items, $meta);
    }

    /** PUT /me/bookmarks/{question_id} */
    public function put(Request $request): Response
    {
        return Response::ok($this->service->put(Auth::user(), Validator::id($request->params['question_id'] ?? null), $request->body()));
    }

    /** DELETE /me/bookmarks/{question_id} */
    public function destroy(Request $request): Response
    {
        $this->service->delete(Auth::user(), Validator::id($request->params['question_id'] ?? null));
        return Response::noContent();
    }

    /** GET /me/wrong-questions */
    public function wrongQuestions(Request $request): Response
    {
        [$items, $meta] = $this->service->wrongQuestions(Auth::user(), $request->query, Page::fromRequest($request));
        return Response::ok($items, $meta);
    }
}
