<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;

interface Middleware
{
    /** 不符合條件就丟 AppException，符合就直接 return */
    public function handle(Request $request, array $args = []): void;
}
