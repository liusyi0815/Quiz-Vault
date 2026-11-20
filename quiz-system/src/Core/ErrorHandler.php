<?php
declare(strict_types=1);

namespace App\Core;

use App\Support\AppException;

/** 把所有例外統一轉成 { "error": { code, message, details } }，不外洩 SQL 與堆疊 */
final class ErrorHandler
{
    /** 這些 MySQL 錯誤碼代表「連不上資料庫」 */
    private const DB_UNAVAILABLE = [1044, 1045, 1049, 2002, 2003, 2006, 2013];

    public static function register(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(\Throwable $e): void
    {
        $requestId = bin2hex(random_bytes(6));
        header('X-Request-Id: ' . $requestId);

        if ($e instanceof AppException) {
            Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details)->send();
            return;
        }

        self::log($requestId, $e);
        $debug = Config::bool('APP_DEBUG') ? ['debug' => get_class($e) . ': ' . $e->getMessage()] : [];

        if ($e instanceof \PDOException && in_array((int) $e->getCode(), self::DB_UNAVAILABLE, true)) {
            Response::error(503, 'DB_UNAVAILABLE', '資料庫暫時無法連線', [], ['request_id' => $requestId] + $debug)->send();
            return;
        }

        Response::error(500, 'INTERNAL_ERROR', '伺服器發生未預期的錯誤', [], ['request_id' => $requestId] + $debug)->send();
    }

    private static function log(string $requestId, \Throwable $e): void
    {
        $dir = Config::root() . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // 注意：絕不記錄 request body 與密碼；index.php 已關閉例外堆疊中的函式參數
        $line = sprintf(
            "[%s] [%s] %s: %s in %s:%d\n%s\n\n",
            date('c'), $requestId, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
        );
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
