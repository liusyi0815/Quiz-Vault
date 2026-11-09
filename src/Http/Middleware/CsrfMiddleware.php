<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Session;
use App\Support\AppException;

/** POST / PUT / PATCH / DELETE 必須帶 X-CSRF-Token，與 session 內的 token 以 hash_equals 比對 */
final class CsrfMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        if (!$request->isUnsafeMethod()) {
            return;
        }
        $expected = Session::get('csrf_token');
        $given = $request->header('X-CSRF-Token');
        if (!is_string($expected) || $given === null || !hash_equals($expected, $given)) {
            throw new AppException(403, 'CSRF_FAILED', 'CSRF token 缺少或不正確');
        }
    }
}
