<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\Middleware;
use App\Http\Middleware\MustChangePasswordMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Support\AppException;

final class Router
{
    private const MIDDLEWARE = [
        'auth'        => AuthMiddleware::class,
        'must_change' => MustChangePasswordMiddleware::class,
        'csrf'        => CsrfMiddleware::class,
        'role'        => RoleMiddleware::class,
    ];

    /** @var list<array{method:string, regex:string, handler:array, middleware:list<string>}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler  @param list<string> $middleware */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [
            'method' => $method, 'regex' => $regex, 'handler' => $handler, 'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $request->params[$key] = $value;
                }
            }
            foreach ($route['middleware'] as $spec) {
                $this->runMiddleware($spec, $request);
            }

            [$class, $action] = $route['handler'];
            return (new $class())->$action($request);
        }

        if ($pathMatched) {
            throw new AppException(405, 'METHOD_NOT_ALLOWED', '此路徑不支援 ' . $request->method . ' 方法');
        }
        throw AppException::notFound('找不到此端點');
    }

    private function runMiddleware(string $spec, Request $request): void
    {
        [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);
        $class = self::MIDDLEWARE[$name] ?? throw new \LogicException("未知的 middleware：{$name}");
        /** @var Middleware $middleware */
        $middleware = new $class();
        $middleware->handle($request, $arg === null ? [] : explode(',', $arg));
    }
}
