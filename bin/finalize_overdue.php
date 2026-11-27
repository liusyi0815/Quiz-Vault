<?php
declare(strict_types=1);

/**
 * 逾時作答處理（F-18）：把「已經超過截止時間」卻還在作答中的作答，依已暫存的答案交卷並批改。
 * 建議每分鐘執行一次：
 *   Linux 的 cron：  * * * * * php /路徑/quiz-system/bin/finalize_overdue.php
 *   Windows：工作排程器，每 1 分鐘執行  C:\xampp\php\php.exe C:\...\quiz-system\bin\finalize_overdue.php
 *
 * 可重複執行、可同時執行多份：每筆都在 transaction 內先鎖住再重新檢查狀態，不會重複批改。
 * 選用參數：--limit=N（一次最多處理幾筆，預設 100）
 */

use App\Core\Config;
use App\Services\AttemptService;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

Config::load($root);
date_default_timezone_set(Config::get('APP_TIMEZONE', 'Asia/Taipei'));

$limit = 100;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1 && (int) $m[1] > 0) {
        $limit = (int) $m[1];
    }
}

try {
    $n = (new AttemptService())->finalizeOverdue($limit);
    echo date('c') . "  已結算 {$n} 筆逾時作答\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . '  失敗：' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
