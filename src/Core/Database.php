<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::int('DB_PORT', 3306),
            Config::get('DB_NAME', 'quiz_system'),
            Config::get('DB_CHARSET', 'utf8mb4'),
        );

        $pdo = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // 真正的 prepared statement
        ]);

        // 讓 MySQL 的 NOW() 與 PHP 使用同一個時區
        $offset = (new \DateTimeImmutable('now', new \DateTimeZone(Config::get('APP_TIMEZONE', 'Asia/Taipei'))))->format('P');
        $pdo->exec("SET time_zone = '" . $offset . "'");

        return self::$pdo = $pdo;
    }

    /** 在 transaction 內執行；例外一律 rollback 後重新拋出 */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
