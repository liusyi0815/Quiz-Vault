<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\TagService;

final class TagController
{
    public function __construct(private readonly TagService $service = new TagService())
    {
    }

    /** GET /tags */
    public function index(Request $request): Response
    {
        [$items, $meta] = $this->service->list($request->query, Page::fromRequest($request));
        return Response::ok($items, $meta);
    }

    /** POST /tags */
    public function store(Request $request): Response
    {
        return Response::created($this->service->create($request->body()));
    }

    /** DELETE /tags/{tag_id}（只有管理員） */
    public function destroy(Request $request): Response
    {
        $this->service->delete(Validator::id($request->params['tag_id'] ?? null));
        return Response::noContent();
    }
}
