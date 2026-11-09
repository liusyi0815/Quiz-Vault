<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    /** GET /health：確認 PHP 與資料庫都正常（連不上資料庫會由 ErrorHandler 回 503） */
    public function index(Request $request): Response
    {
        Database::connection()->query('SELECT 1')->fetchColumn();

        return Response::ok([
            'status'      => 'ok',
            'database'    => 'ok',
            'server_time' => date('c'),
        ]);
    }
}
