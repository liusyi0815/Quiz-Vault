<?php
declare(strict_types=1);

// 簡易 PSR-4 自動載入：App\Foo\Bar → src/Foo/Bar.php（不需要 Composer）
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
