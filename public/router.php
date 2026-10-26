<?php
// 僅供開發用：php -S localhost:8000 -t public public/router.php
// 內建伺服器不讀 .htaccess，這裡手動把 /api/v1/* 轉給前端控制器。
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/api/v1/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

return false; // 其他請求（html、css、js）交給內建伺服器處理靜態檔
