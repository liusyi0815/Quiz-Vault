<?php
declare(strict_types=1);

/**
 * 前端控制器：所有 /api/v1/* 請求都從這裡進來。
 * Apache 由 public/api/.htaccess 轉送；php -S 開發伺服器由 public/router.php 轉送。
 */

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

$root = dirname(__DIR__, 2);
require $root . '/src/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');                 // 錯誤一律由 ErrorHandler 輸出 JSON，不直接印給使用者
ini_set('zend.exception_ignore_args', '1');     // 例外堆疊不含函式參數，避免密碼寫進 log

Config::load($root);
date_default_timezone_set(Config::get('APP_TIMEZONE', 'Asia/Taipei'));
ErrorHandler::register();

$request = Request::capture();
Session::init($request);

$router = new Router();
(require $root . '/config/routes.php')($router);

$router->dispatch($request)->send();
