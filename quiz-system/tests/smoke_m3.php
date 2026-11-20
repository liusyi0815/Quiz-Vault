<?php
declare(strict_types=1);

/**
 * M3 冒煙測試：考卷建立與手動組卷(F-08)、隨機抽題(F-09)、發佈／撤回／關閉(F-10)。
 *
 * 需要 teacher1、teacher2（管理員選用）的密碼，取得方式與 smoke_m2.php 相同：
 * 環境變數 TEACHER1_PASSWORD / TEACHER2_PASSWORD / ADMIN_PASSWORD，或 credentials-private.csv。
 *
 *   php tests/smoke_m3.php
 *   php tests/smoke_m3.php http://localhost/quiz-system/public/api/v1
 *
 * 測試資料都以 [smoke] 開頭，結束時自動清除。
 */

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$base = rtrim($argv[1] ?? 'http://localhost:8000/api/v1', '/');
require $root . '/src/autoload.php';
App\Core\Config::load($root);
date_default_timezone_set(App\Core\Config::get('APP_TIMEZONE', 'Asia/Taipei'));

$accounts = [
    'TEACHER1' => getenv('TEACHER1_EMAIL') ?: 'teacher1@example.edu.tw',
    'TEACHER2' => getenv('TEACHER2_EMAIL') ?: 'teacher2@example.edu.tw',
    'ADMIN'    => getenv('ADMIN_EMAIL') ?: 'admin@example.edu.tw',
];
$passwords = [];
foreach ($accounts as $prefix => $email) {
    $passwords[$prefix] = testPassword($prefix, $email, $root);
}
foreach (['TEACHER1', 'TEACHER2'] as $must) {
    if ($passwords[$must] === null) {
        fwrite(STDERR, "找不到 {$accounts[$must]} 的密碼。\n請設定環境變數 {$must}_PASSWORD，或把 credentials-private.csv 放在專案根目錄。\n");
        exit(2);
    }
}

$pdo = App\Core\Database::connection();
smokeCleanup($pdo);

[$t1, $t1C, $t1Id] = loginAs($base, $accounts['TEACHER1'], $passwords['TEACHER1']);
[$t2, $t2C] = loginAs($base, $accounts['TEACHER2'], $passwords['TEACHER2']);
[$s1, $s1C, $s1Id] = registerStudent($base, 'A');       // 修課的學生
[$s2, $s2C] = registerStudent($base, 'B');               // 沒修課的學生
$admin = null;
if ($passwords['ADMIN'] !== null) {
    $c = new Client($base);
    $r = $c->call('POST', '/auth/login', ['email' => $accounts['ADMIN'], 'password' => $passwords['ADMIN']]);
    if ($r[0] === 200 && ($r[1]['data']['must_change_password'] ?? true) === false) {
        $admin = [$c, (string) $r[1]['data']['csrf_token']];
    }
}

// ---------------------------------------------------------------- 準備資料（透過 API）
$id = static fn (array $r, string $key) => (int) ($r[1]['data'][$key] ?? 0);

$A = $id($t1->call('POST', '/courses', ['name' => '[smoke] 課程A'], $t1C), 'course_id');
$B = $id($t2->call('POST', '/courses', ['name' => '[smoke] 課程B'], $t2C), 'course_id');
$c1 = $id($t1->call('POST', "/courses/{$A}/chapters", ['name' => '第一章'], $t1C), 'chapter_id');
$c2 = $id($t1->call('POST', "/courses/{$A}/chapters", ['name' => '第二章'], $t1C), 'chapter_id');
$b1 = $id($t2->call('POST', "/courses/{$B}/chapters", ['name' => '乙課第一章'], $t2C), 'chapter_id');
$s1->call('POST', "/courses/{$A}/enrollment", null, $s1C);

$mkQ = static function (Client $c, string $csrf, int $chapter, string $type, string $name, int $difficulty, array $tags = []): int {
    $options = match ($type) {
        'single'    => opts(['甲', true], ['乙', false], ['丙', false]),
        'multiple'  => opts(['甲', true], ['乙', true], ['丙', false]),
        'truefalse' => opts(['對', true], ['錯', false]),
        default     => [],
    };
    $r = $c->call('POST', '/questions', [
        'chapter_id' => $chapter, 'type' => $type, 'content' => "[smoke] 題目 {$name}", 'difficulty' => $difficulty,
        'options' => $options, 'tags' => $tags,
    ], $csrf);
    return (int) ($r[1]['data']['question_id'] ?? 0);
};
$e1 = $mkQ($t1, $t1C, $c1, 'single', 'e1', 1, ['[smoke]甲']);
$e2 = $mkQ($t1, $t1C, $c1, 'single', 'e2', 1, ['[smoke]乙']);
$e3 = $mkQ($t1, $t1C, $c1, 'single', 'e3', 1);
$e4 = $mkQ($t1, $t1C, $c2, 'truefalse', 'e4', 1);
$m1 = $mkQ($t1, $t1C, $c1, 'multiple', 'm1', 2, ['[smoke]甲']);
$m2 = $mkQ($t1, $t1C, $c1, 'multiple', 'm2', 2);
$h1 = $mkQ($t1, $t1C, $c2, 'short', 'h1', 3);
$x1 = $mkQ($t1, $t1C, $c1, 'single', 'x1（會被停用）', 1);
$qB = $mkQ($t2, $t2C, $b1, 'single', 'B課的題目', 1);
$t1->call('DELETE', "/questions/{$x1}", null, $t1C);          // x1 停用：不能加入考卷、不會被抽到
// 共 7 題啟用中：簡單 4（e1 e2 e3 e4）、中等 2（m1 m2）、困難 1（h1）

$n = 0;
$exam = static function (Client $c, string $csrf, array $override = []) use (&$n, $A): array {
    $n++;
    return $c->call('POST', '/exams', $override + [
        'course_id' => $A, 'title' => "[smoke] 考卷 {$n}", 'duration_min' => 30,
        'start_time' => isoAt(-86400), 'end_time' => isoAt(86400 * 30), 'pass_score' => 60, 'max_attempts' => 3,
    ], $csrf);
};
$newExam = static fn (array $override = []): int => (int) ($exam($GLOBALS['t1'], $GLOBALS['t1C'], $override)[1]['data']['exam_id'] ?? 0);
$qids = static fn (array $r): array => array_column($r[1]['data']['questions'] ?? [], 'question_id');
$rulesOf = static fn (array $r): array => array_column($r[1]['error']['details'] ?? [], 'rule');
$put = static fn (int $examId, array $items, ?array $client = null) => ($client[0] ?? $GLOBALS['t1'])->call('PUT', "/exams/{$examId}/questions", ['questions' => $items], $client[1] ?? $GLOBALS['t1C']);
$draw = static fn (int $examId, array $body) => $GLOBALS['t1']->call('POST', "/exams/{$examId}/questions/random", $body, $GLOBALS['t1C']);

// ======================================================================
echo "\n[F-08] 建立考卷\n";
check('學生建立考卷 → 403 FORBIDDEN', $s1->call('POST', '/exams', ['course_id' => $A, 'title' => 'x', 'duration_min' => 10], $s1C)[0] === 403);
$bad = static function (array $override, string $name) use ($exam, $t1, $t1C): void {
    $r = $exam($t1, $t1C, $override);
    check($name . ' → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED', why($r));
};
$bad(['title' => '  '], '考卷名稱空白');
$bad(['duration_min' => 0], '限時 0 分鐘');
$bad(['duration_min' => 'abc'], '限時不是數字');
$bad(['duration_min' => 1441], '限時超過 24 小時');
$bad(['max_attempts' => 0], '作答次數 0');
$bad(['pass_score' => -1], '及格分數為負');
$bad(['start_time' => '明天'], '開始時間格式錯誤');
$bad(['start_time' => isoAt(3600), 'end_time' => isoAt(60)], '結束時間早於開始時間');
check('別的老師在我的課程建立考卷 → 403', $exam($t2, $t2C)[0] === 403);
check('課程不存在 → 404', $exam($t1, $t1C, ['course_id' => 99999999])[0] === 404);

$r = $exam($t1, $t1C, ['title' => '[smoke] 主要考卷', 'start_time' => '2026-12-01 09:00:00', 'end_time' => '2026-12-01T03:00:00Z', 'pass_score' => 60]);
$E1 = (int) ($r[1]['data']['exam_id'] ?? 0);
$d = $r[1]['data'] ?? [];
check('建立考卷 → 201，狀態為 draft', $r[0] === 201 && ($d['status'] ?? '') === 'draft' && ($d['effective_status'] ?? '') === 'draft', why($r));
check('沒有時區的時間視為本地時間；UTC 時間會轉成 +08:00', ($d['start_time'] ?? '') === '2026-12-01T09:00:00+08:00' && ($d['end_time'] ?? '') === '2026-12-01T11:00:00+08:00', json_encode([$d['start_time'] ?? null, $d['end_time'] ?? null]));
check('新考卷：0 題、總分 0、尚未開放', ($d['question_count'] ?? -1) === 0 && ($d['total_score'] ?? -1) == 0.0 && ($d['is_open'] ?? null) === false && ($d['questions'] ?? null) === []);
check('creator_id 是目前登入的老師', ($d['creator_id'] ?? 0) === $t1Id);
$r = $t1->call('POST', '/exams', ['course_id' => $A, 'title' => '[smoke] 用預設值', 'duration_min' => 15], $t1C);
check('沒給的欄位用預設值：及格 60、次數 1、不限時段', ($r[1]['data']['pass_score'] ?? 0) == 60.0 && ($r[1]['data']['max_attempts'] ?? 0) === 1 && isNullField($r, 'start_time') && isNullField($r, 'end_time'), why($r));

echo "\n[F-08] 查詢\n";
$r = $t1->call('GET', "/exams?course_id={$A}");
check('老師看自己課程的考卷列表', $r[0] === 200 && ($r[1]['meta']['total'] ?? 0) >= 2 && in_array($E1, array_column($r[1]['data'], 'exam_id'), true), why($r));
check('列表可用 status 篩選', ($t1->call('GET', "/exams?course_id={$A}&status=published")[1]['meta']['total'] ?? -1) === 0 && ($t1->call('GET', "/exams?course_id={$A}&status=draft")[1]['meta']['total'] ?? 0) >= 2);
check('status 不合法 → 400', $t1->call('GET', '/exams?status=weird')[0] === 400);
check('分頁：per_page=1 → 1 筆', count($t1->call('GET', "/exams?course_id={$A}&per_page=1")[1]['data'] ?? []) === 1);
check('別的老師查我課程的考卷 → 空清單', ($t2->call('GET', "/exams?course_id={$A}")[1]['meta']['total'] ?? -1) === 0);
check('學生看不到草稿考卷', ($s1->call('GET', "/exams?course_id={$A}")[1]['meta']['total'] ?? -1) === 0);
if ($admin) {
    check('管理員看得到所有考卷', ($admin[0]->call('GET', "/exams?course_id={$A}")[1]['meta']['total'] ?? 0) >= 2);
}
check('未登入看考卷列表 → 401', (new Client($base))->call('GET', '/exams')[0] === 401);
check('老師看考卷詳情 → 200，含題目清單', ($t1->call('GET', "/exams/{$E1}")[1]['data']['questions'] ?? null) === []);
check('別的老師看我的考卷 → 404', $t2->call('GET', "/exams/{$E1}")[0] === 404);
check('學生（有修課）看草稿考卷 → 404', $s1->call('GET', "/exams/{$E1}")[0] === 404);
check('考卷 ID 不合法或不存在 → 404', $t1->call('GET', '/exams/abc')[0] === 404 && $t1->call('GET', '/exams/99999999')[0] === 404);

echo "\n[F-08] 修改設定（草稿）\n";
$r = $t1->call('PATCH', "/exams/{$E1}", ['title' => '[smoke] 主要考卷（改）', 'duration_min' => 45, 'pass_score' => 59.5, 'max_attempts' => 3, 'start_time' => isoAt(-86400), 'end_time' => isoAt(86400 * 30)], $t1C);
check('草稿可以修改所有設定 → 200', $r[0] === 200 && ($r[1]['data']['duration_min'] ?? 0) === 45 && ($r[1]['data']['pass_score'] ?? 0) === 59.5 && ($r[1]['data']['max_attempts'] ?? 0) === 3, why($r));
check('改成現在就開放：is_open=false（草稿不開放）', ($r[1]['data']['is_open'] ?? null) === false);
check('只改結束時間，早於既有的開始時間 → 400', $t1->call('PATCH', "/exams/{$E1}", ['end_time' => isoAt(-172800)], $t1C)[0] === 400);
check('PATCH 沒給欄位 → 400', $t1->call('PATCH', "/exams/{$E1}", [], $t1C)[0] === 400);
check('PATCH 不能改 course_id → 400', $t1->call('PATCH', "/exams/{$E1}", ['course_id' => $B], $t1C)[0] === 400);
check('PATCH 傳 null 可以清除時間限制', isNullField($t1->call('PATCH', "/exams/{$E1}", ['start_time' => null, 'end_time' => null], $t1C), 'start_time'));
$t1->call('PATCH', "/exams/{$E1}", ['start_time' => isoAt(-86400), 'end_time' => isoAt(86400 * 30)], $t1C);
check('別的老師修改我的考卷 → 404', $t2->call('PATCH', "/exams/{$E1}", ['title' => 'x'], $t2C)[0] === 404);
check('學生修改考卷 → 403', $s1->call('PATCH', "/exams/{$E1}", ['title' => 'x'], $s1C)[0] === 403);

// ======================================================================
echo "\n[F-08] 手動組卷（整份取代題目與配分）\n";
$r = $put($E1, [
    ['question_id' => $e1, 'score' => 10, 'sort_order' => 3],
    ['question_id' => $e2, 'score' => 10, 'sort_order' => 1],
    ['question_id' => $m1, 'score' => 20.5, 'sort_order' => 2],
    ['question_id' => $h1, 'score' => 9.5],
]);
check('組卷 → 200，依 sort_order 排序', $r[0] === 200 && $qids($r) === [$e2, $m1, $e1, $h1], why($r));
check('順序重新編號為 1..n', array_column($r[1]['data']['questions'], 'sort_order') === [1, 2, 3, 4]);
check('配分可以有小數，總分自動加總', ($r[1]['data']['questions'][1]['score'] ?? 0) === 20.5 && ($r[1]['data']['total_score'] ?? 0) == 50.0 && ($r[1]['data']['question_count'] ?? 0) === 4);
check('題目清單含章節、題型、難度，但不含選項與答案', isset($r[1]['data']['questions'][0]['chapter']['name'], $r[1]['data']['questions'][0]['difficulty']) && !str_contains(json_encode($r[1]), 'is_correct'));
$r = $put($E1, [['question_id' => $e3, 'score' => 100]]);
check('再次 PUT 是「整份取代」，舊題目全部移除', $r[0] === 200 && $qids($r) === [$e3] && ($r[1]['data']['total_score'] ?? 0) == 100.0, why($r));
$r = $put($E1, [['question_id' => $e1, 'score' => 25], ['question_id' => $e2, 'score' => 25], ['question_id' => $m1, 'score' => 25], ['question_id' => $e4, 'score' => 25]]);
check('最終題目：e1 e2 m1 e4，總分 100', $r[0] === 200 && $qids($r) === [$e1, $e2, $m1, $e4] && ($r[1]['data']['total_score'] ?? 0) == 100.0);

$badPut = static function (array $items, int $status, string $codeExpect, string $name) use ($put, $E1, $qids, $t1, $e1, $e2, $m1, $e4): void {
    $r = $put($E1, $items);
    $same = $qids($t1->call('GET', "/exams/{$E1}")) === [$e1, $e2, $m1, $e4];
    check($name . " → {$status} {$codeExpect}，且原本的題目不變", $r[0] === $status && code($r) === $codeExpect && $same, why($r));
};
$badPut([['question_id' => $e1, 'score' => 5], ['question_id' => $e1, 'score' => 5]], 400, 'VALIDATION_FAILED', '同一題出現兩次');
$badPut([['question_id' => $e1, 'score' => 0]], 400, 'VALIDATION_FAILED', '配分 0');
$badPut([['question_id' => $e1, 'score' => 1000]], 400, 'VALIDATION_FAILED', '配分超過 999.99');
$badPut([['question_id' => $e1]], 400, 'VALIDATION_FAILED', '沒有配分');
$badPut([['score' => 5]], 400, 'VALIDATION_FAILED', '沒有 question_id');
$badPut([['question_id' => 99999999, 'score' => 5]], 400, 'VALIDATION_FAILED', '題目不存在');
$badPut([['question_id' => $qB, 'score' => 5]], 400, 'VALIDATION_FAILED', '別的課程（別的老師）的題目');
$badPut([['question_id' => $x1, 'score' => 5]], 409, 'QUESTION_INACTIVE', '已停用的題目');
$r = $t1->call('PUT', "/exams/{$E1}/questions", ['nothing' => 1], $t1C);
check('沒有 questions 欄位 → 400', $r[0] === 400 && code($r) === 'VALIDATION_FAILED');
$r = $put($E1, [['question_id' => $qB, 'score' => 5]]);
check('錯誤訊息指出是哪一題', ($r[1]['error']['details'][0]['field'] ?? '') === 'questions[0].question_id');
check('別的老師組卷 → 404', $put($E1, [], [$t2, $t2C])[0] === 404);
check('學生組卷 → 403', $put($E1, [], [$s1, $s1C])[0] === 403);
$E0 = $newExam();
$r = $put($E0, [['question_id' => $e1, 'score' => 5]]);
$r = $put($E0, []);
check('可以用空清單清空題目 → 200', $r[0] === 200 && $qids($r) === []);

// ======================================================================
echo "\n[F-09] 隨機抽題\n";
$E2 = $newExam();
$r = $draw($E2, ['by_difficulty' => ['1' => 2, '2' => 1], 'score_each' => 5]);
$diffs = array_count_values(array_column($r[1]['data'] ?? [], 'difficulty'));
check('抽 簡單 2 + 中等 1 → 200，回傳新加入的 3 題', $r[0] === 200 && count($r[1]['data']) === 3 && ($diffs[1] ?? 0) === 2 && ($diffs[2] ?? 0) === 1, why($r));
check('meta 回報新增數、總題數與總分', ($r[1]['meta']['added'] ?? 0) === 3 && ($r[1]['meta']['question_count'] ?? 0) === 3 && ($r[1]['meta']['total_score'] ?? 0) == 15.0);
check('每題配分都是 score_each，順序從 1 開始', array_unique(array_column($r[1]['data'], 'score')) == [5.0] && array_column($r[1]['data'], 'sort_order') === [1, 2, 3]);
check('抽到的題目都是這門課、啟用中的題目', array_diff(array_column($r[1]['data'], 'question_id'), [$e1, $e2, $e3, $e4, $m1, $m2, $h1]) === []);
$r = $draw($E2, ['by_difficulty' => ['1' => 2]]);
check('再抽 簡單 2 題：不會抽到已經在考卷裡的題目', $r[0] === 200 && count($r[1]['data']) === 2, why($r));
$allQ = $qids($t1->call('GET', "/exams/{$E2}"));
check('考卷內沒有重複的題目，順序接在原本之後（4、5）', count($allQ) === count(array_unique($allQ)) && count($allQ) === 5 && array_slice(array_column($r[1]['data'], 'sort_order'), 0, 2) === [4, 5]);
check('預設 score_each 為 1', ($r[1]['data'][0]['score'] ?? 0) == 1.0);
$r = $draw($E2, ['by_difficulty' => ['1' => 1]]);
check('簡單題已經抽完 → 409 INSUFFICIENT_QUESTIONS，列出缺口', $r[0] === 409 && code($r) === 'INSUFFICIENT_QUESTIONS'
    && ($r[1]['error']['details'][0] ?? []) === ['difficulty' => 1, 'requested' => 1, 'available' => 0], why($r));
check('抽題失敗不會留下任何題目', count($qids($t1->call('GET', "/exams/{$E2}"))) === 5);

$E3 = $newExam();
$r = $draw($E3, ['by_difficulty' => ['1' => 1, '3' => 2]]);
check('困難題只有 1 題卻要 2 題 → 409，缺口只列出不足的難度', $r[0] === 409 && ($r[1]['error']['details'] ?? []) === [['difficulty' => 3, 'requested' => 2, 'available' => 1]], why($r));
check('有一項不足時「整批都不寫入」（連夠的簡單題也沒加）', $qids($t1->call('GET', "/exams/{$E3}")) === []);

$E4 = $newExam();
$r = $draw($E4, ['count' => 2, 'tags' => ['[smoke]甲']]);
$got = array_column($r[1]['data'] ?? [], 'question_id');
sort($got);
$want = [$e1, $m1];
sort($want);
check('依標籤抽題：標籤「甲」只有 e1 與 m1', $r[0] === 200 && $got === $want, why($r));
$r = $draw($E4, ['count' => 1, 'tags' => ['[smoke]乙', '[smoke]甲']]);
check('多個標籤是「任何一個符合」：再抽到 e2（e1、m1 已在考卷）', $r[0] === 200 && array_column($r[1]['data'], 'question_id') === [$e2], why($r));
$r = $draw($E4, ['count' => 1, 'tags' => ['[smoke]不存在的標籤']]);
check('標籤沒有任何題目 → 409，available 為 0', $r[0] === 409 && ($r[1]['error']['details'][0]['available'] ?? -1) === 0);

$E5 = $newExam();
$r = $draw($E5, ['count' => 2, 'chapter_ids' => [$c2]]);
$got = array_column($r[1]['data'] ?? [], 'question_id');
sort($got);
$want = [$e4, $h1];
sort($want);
check('依章節抽題：第二章只有 e4 與 h1', $r[0] === 200 && $got === $want, why($r));
check('章節不屬於這門課程 → 400', $draw($newExam(), ['count' => 1, 'chapter_ids' => [$b1]])[0] === 400);
$E6 = $newExam();
$r = $draw($E6, ['count' => 8]);
check('count 模式：7 題可抽，要 8 題 → 409（停用的 x1 不算）', $r[0] === 409 && ($r[1]['error']['details'][0] ?? []) === ['requested' => 8, 'available' => 7], why($r));
$r = $draw($E6, ['count' => 7]);
check('count=7 → 200，剛好抽完所有啟用中的題目', $r[0] === 200 && count($r[1]['data']) === 7 && !in_array($x1, array_column($r[1]['data'], 'question_id'), true), why($r));
check('隨機抽題的結果可以再用 PUT 調整', $put($E6, [['question_id' => $e1, 'score' => 100]])[0] === 200);

$E7 = $newExam();
$r = $draw($E7, ['count' => 3]);
$r = $draw($E7, ['count' => 2]);
$bad = static function (array $body, string $name) use ($draw, $newExam): void {
    $r = $draw($newExam(), $body);
    check($name . ' → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED', why($r));
};
$bad([], '沒有指定 by_difficulty 也沒有 count');
$bad(['by_difficulty' => ['1' => 1], 'count' => 1], 'by_difficulty 與 count 同時給');
$bad(['by_difficulty' => ['4' => 1]], '難度 4');
$bad(['by_difficulty' => ['1' => 0]], '所有難度題數都是 0');
$bad(['by_difficulty' => [1, 2]], 'by_difficulty 用陣列而不是物件');
$bad(['by_difficulty' => ['1' => -1]], '題數為負');
$bad(['by_difficulty' => ['1' => 'abc']], '題數不是整數');
$bad(['count' => 0], 'count 為 0');
$bad(['count' => 1, 'score_each' => 0], 'score_each 為 0');
$bad(['count' => 1, 'chapter_ids' => ['a']], 'chapter_ids 不是整數');
check('別的老師抽題 → 404', $t2->call('POST', "/exams/{$E7}/questions/random", ['count' => 1], $t2C)[0] === 404);
check('學生抽題 → 403', $s1->call('POST', "/exams/{$E7}/questions/random", ['count' => 1], $s1C)[0] === 403);

// ======================================================================
echo "\n[F-10] 發佈檢查\n";
$E8 = $newExam();
$r = $t1->call('POST', "/exams/{$E8}/publish", null, $t1C);
check('沒有題目 → 409 EXAM_PUBLISH_CHECK_FAILED，rule=no_questions', $r[0] === 409 && code($r) === 'EXAM_PUBLISH_CHECK_FAILED' && $rulesOf($r) === ['no_questions'], why($r));
check('發佈失敗，考卷維持草稿', ($t1->call('GET', "/exams/{$E8}")[1]['data']['status'] ?? '') === 'draft');

$E9 = $newExam(['pass_score' => 60]);
$put($E9, [['question_id' => $e1, 'score' => 10], ['question_id' => $e2, 'score' => 10]]);
$r = $t1->call('POST', "/exams/{$E9}/publish", null, $t1C);
check('及格分 60 > 總分 20 → pass_score_exceeds_total，附上數字', $rulesOf($r) === ['pass_score_exceeds_total'] && ($r[1]['error']['details'][0]['total_score'] ?? 0) == 20.0 && ($r[1]['error']['details'][0]['pass_score'] ?? 0) == 60.0, why($r));
$t1->call('PATCH', "/exams/{$E9}", ['pass_score' => 20], $t1C);
check('及格分改成 20（等於總分）後可以發佈', $t1->call('POST', "/exams/{$E9}/publish", null, $t1C)[0] === 200);

$E10 = $newExam();
$put($E10, [['question_id' => $e3, 'score' => 100]]);
$t1->call('DELETE', "/questions/{$e3}", null, $t1C);              // 組卷後，題目才被停用
$r = $t1->call('POST', "/exams/{$E10}/publish", null, $t1C);
check('考卷裡的題目已被停用 → question_inactive，帶題目編號', $rulesOf($r) === ['question_inactive'] && ($r[1]['error']['details'][0]['question_id'] ?? 0) === $e3, why($r));
$t1->call('PATCH', "/questions/{$e3}", ['is_active' => true], $t1C);
check('題目復原啟用後即可發佈', $t1->call('POST', "/exams/{$E10}/publish", null, $t1C)[0] === 200);

// 題目結構被弄壞（直接寫資料庫，繞過 API 的驗證）→ 發佈時擋下
$pdo->prepare("INSERT INTO questions (chapter_id, creator_id, type, content, difficulty) VALUES (?, ?, 'single', '[smoke] 壞掉的單選題', 1)")->execute([$c1, $t1Id]);
$badQ = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO question_options (question_id, content, is_correct, sort_order) VALUES (?, ?, 1, 1), (?, ?, 1, 2)')->execute([$badQ, '甲', $badQ, '乙']);
$E11 = $newExam();
$pdo->prepare('INSERT INTO exam_questions (exam_id, question_id, score, sort_order) VALUES (?, ?, 100, 1)')->execute([$E11, $badQ]);
$r = $t1->call('POST', "/exams/{$E11}/publish", null, $t1C);
check('題目有 2 個正確答案的單選題 → question_shape_invalid，附上原因', $rulesOf($r) === ['question_shape_invalid'] && ($r[1]['error']['details'][0]['reason'] ?? '') !== '', why($r));

$E12 = $newExam(['start_time' => isoAt(-86400 * 3), 'end_time' => isoAt(-86400)]);
$r = $t1->call('POST', "/exams/{$E12}/publish", null, $t1C);
check('結束時間已過、又沒有題目 → 兩條規則一次全部列出', $r[0] === 409 && $rulesOf($r) === ['end_in_past', 'no_questions'], implode(',', $rulesOf($r)));

echo "\n[F-10] 發佈、狀態轉換\n";
$r = $t1->call('POST', "/exams/{$E1}/publish", null, $t1C);
check('E1 通過檢查 → 200，status=published，回傳總分', $r[0] === 200 && ($r[1]['data']['status'] ?? '') === 'published' && ($r[1]['data']['total_score'] ?? 0) == 100.0 && ($r[1]['data']['exam_id'] ?? 0) === $E1, why($r));
$r = $t1->call('POST', "/exams/{$E1}/publish", null, $t1C);
check('已發佈的考卷再發佈 → 409 EXAM_INVALID_STATE', $r[0] === 409 && code($r) === 'EXAM_INVALID_STATE', why($r));
check('別的老師發佈我的考卷 → 404', $t2->call('POST', "/exams/{$E8}/publish", null, $t2C)[0] === 404);
check('學生發佈考卷 → 403', $s1->call('POST', "/exams/{$E8}/publish", null, $s1C)[0] === 403);
$d = $t1->call('GET', "/exams/{$E1}")[1]['data'];
check('發佈後：effective_status=published，現在在開放時段內 is_open=true', ($d['effective_status'] ?? '') === 'published' && ($d['is_open'] ?? null) === true);

echo "\n[F-10] 已發佈考卷：題目與設定鎖定\n";
$r = $put($E1, [['question_id' => $e1, 'score' => 100]]);
check('已發佈：PUT 題目 → 409 EXAM_NOT_EDITABLE', $r[0] === 409 && code($r) === 'EXAM_NOT_EDITABLE', why($r));
check('已發佈：隨機抽題 → 409 EXAM_NOT_EDITABLE', $draw($E1, ['count' => 1])[0] === 409 && code($draw($E1, ['count' => 1])) === 'EXAM_NOT_EDITABLE');
$r = $t1->call('PATCH', "/exams/{$E1}", ['duration_min' => 99, 'pass_score' => 10], $t1C);
check('已發佈：改限時與及格分 → 409 EXAM_NOT_EDITABLE，列出被鎖定的欄位', $r[0] === 409 && code($r) === 'EXAM_NOT_EDITABLE'
    && array_column($r[1]['error']['details'], 'field') === ['duration_min', 'pass_score'], why($r));
check('已發佈：可以修改標題', ($t1->call('PATCH', "/exams/{$E1}", ['title' => '[smoke] 主要考卷（已發佈）'], $t1C)[1]['data']['title'] ?? '') === '[smoke] 主要考卷（已發佈）');
$r = $t1->call('PATCH', "/exams/{$E1}", ['end_time' => isoAt(86400 * 60)], $t1C);
check('已發佈：可以延長結束時間 → 200', $r[0] === 200, why($r));
$r = $t1->call('PATCH', "/exams/{$E1}", ['end_time' => isoAt(86400 * 10)], $t1C);
check('已發佈：縮短結束時間 → 409 EXAM_NOT_EDITABLE', $r[0] === 409 && code($r) === 'EXAM_NOT_EDITABLE', why($r));
$r = $t1->call('PATCH', "/exams/{$E1}", ['end_time' => null], $t1C);
check('已發佈：移除結束時間（不限時段）等於延長 → 200', $r[0] === 200 && isNullField($r, 'end_time'), why($r));
$r = $t1->call('PATCH', "/exams/{$E1}", ['end_time' => isoAt(86400 * 30)], $t1C);
check('已發佈且原本沒有結束時間：設定結束時間等於縮短 → 409', $r[0] === 409, why($r));
$r = $t1->call('DELETE', "/exams/{$E1}", null, $t1C);
check('已發佈：刪除 → 409 EXAM_NOT_EDITABLE', $r[0] === 409 && code($r) === 'EXAM_NOT_EDITABLE', why($r));
$q1 = $t1->call('GET', "/questions/{$e1}");
check('M2 規則：出現在已發佈考卷的題目 → in_use=true', ($q1[1]['data']['in_use'] ?? null) === true);
$r = $t1->call('PUT', "/questions/{$e1}", ['chapter_id' => $c1, 'type' => 'single', 'content' => '[smoke] 偷改題幹', 'options' => opts(['甲', true], ['乙', false])], $t1C);
check('M2 規則：已被已發佈考卷使用的題目不能改題幹 → 409 QUESTION_IN_USE', $r[0] === 409 && code($r) === 'QUESTION_IN_USE', why($r));

echo "\n[F-10] 學生看到的考卷\n";
check('沒修課的學生看已發佈考卷 → 403 NOT_ENROLLED', ($r = $s2->call('GET', "/exams/{$E1}"))[0] === 403 && code($r) === 'NOT_ENROLLED', why($r));
check('沒修課的學生的列表是空的', ($s2->call('GET', '/exams')[1]['meta']['total'] ?? -1) === 0);
$r = $s1->call('GET', "/exams/{$E1}");
$d = $r[1]['data'] ?? [];
check('修課的學生看詳情 → 200', $r[0] === 200 && ($d['exam_id'] ?? 0) === $E1, why($r));
check('學生版沒有題目清單，回應裡也沒有任何題目內容或答案', !array_key_exists('questions', $d) && !str_contains(json_encode($r[1], JSON_UNESCAPED_UNICODE), '[smoke] 題目') && !str_contains(json_encode($r[1]), 'is_correct'));
check('學生版有題數、總分、及格分、限時', ($d['question_count'] ?? 0) === 4 && ($d['total_score'] ?? 0) == 100.0 && ($d['duration_min'] ?? 0) === 45);
check('作答摘要：尚未作答 → used=0、best=null、can_start=true', ($d['my_attempts']['used'] ?? -1) === 0 && array_key_exists('best_score', $d['my_attempts'] ?? []) && $d['my_attempts']['best_score'] === null && ($d['my_attempts']['can_start'] ?? null) === true, json_encode($d['my_attempts'] ?? null));
$r = $s1->call('GET', "/exams?course_id={$A}");
$mine = array_values(array_filter($r[1]['data'] ?? [], static fn ($e) => $e['exam_id'] === $E1))[0] ?? null;
check('學生的列表只有已發佈／已關閉的考卷，且附上作答摘要', $mine !== null && isset($mine['my_attempts']['can_start']) && !in_array('draft', array_column($r[1]['data'], 'status'), true));

$E13 = $newExam(['start_time' => isoAt(86400), 'end_time' => isoAt(86400 * 2)]);
$put($E13, [['question_id' => $e2, 'score' => 100]]);
$t1->call('POST', "/exams/{$E13}/publish", null, $t1C);
$d = $s1->call('GET', "/exams/{$E13}")[1]['data'] ?? [];
check('開始時間還沒到：已發佈但 is_open=false、can_start=false', ($d['status'] ?? '') === 'published' && ($d['is_open'] ?? null) === false && ($d['my_attempts']['can_start'] ?? null) === false);

$E14 = $newExam();
$put($E14, [['question_id' => $e2, 'score' => 100]]);
$t1->call('POST', "/exams/{$E14}/publish", null, $t1C);
$pdo->prepare('UPDATE exams SET end_time = ? WHERE exam_id = ?')->execute([date('Y-m-d H:i:s', time() - 3600), $E14]);
$d = $t1->call('GET', "/exams/{$E14}")[1]['data'] ?? [];
check('超過結束時間：status 仍是 published，effective_status=ended、is_open=false', ($d['status'] ?? '') === 'published' && ($d['effective_status'] ?? '') === 'ended' && ($d['is_open'] ?? null) === false, json_encode([$d['status'] ?? null, $d['effective_status'] ?? null]));
check('超過結束時間：學生 can_start=false', ($s1->call('GET', "/exams/{$E14}")[1]['data']['my_attempts']['can_start'] ?? null) === false);
$r = $t1->call('PATCH', "/exams/{$E14}", ['end_time' => isoAt(86400)], $t1C);
check('已超過結束時間的已發佈考卷，可以延長結束時間重新開放', $r[0] === 200 && ($r[1]['data']['is_open'] ?? null) === true && ($r[1]['data']['effective_status'] ?? '') === 'published', why($r));

echo "\n[F-10] 作答摘要與撤回\n";
$pdo->prepare("INSERT INTO exam_attempts (exam_id, user_id, attempt_no, started_at, submitted_at, total_score, status) VALUES (?, ?, 1, NOW(), NOW(), 70.5, 'graded')")->execute([$E1, $s1Id]);
$pdo->prepare("INSERT INTO exam_attempts (exam_id, user_id, attempt_no, started_at, status) VALUES (?, ?, 2, NOW(), 'in_progress')")->execute([$E1, $s1Id]);
$a = $s1->call('GET', "/exams/{$E1}")[1]['data']['my_attempts'] ?? [];
check('有作答紀錄：used=2、最高分 70.5、有進行中的作答', ($a['used'] ?? 0) === 2 && ($a['best_score'] ?? 0) === 70.5 && ($a['in_progress_attempt_id'] ?? 0) > 0, json_encode($a));
check('有進行中的作答時不能再開始（can_start=false）', ($a['can_start'] ?? null) === false);
$pdo->exec("UPDATE exam_attempts SET status = 'submitted', submitted_at = NOW(), total_score = 40 WHERE exam_id = {$E1} AND attempt_no = 2");
$a = $s1->call('GET', "/exams/{$E1}")[1]['data']['my_attempts'] ?? [];
check('作答交卷後：used=2 < max_attempts=3 → can_start=true，最高分仍是 70.5', ($a['can_start'] ?? null) === true && ($a['best_score'] ?? 0) === 70.5, json_encode($a));
$pdo->prepare("INSERT INTO exam_attempts (exam_id, user_id, attempt_no, started_at, submitted_at, total_score, status) VALUES (?, ?, 3, NOW(), NOW(), 10, 'graded')")->execute([$E1, $s1Id]);
check('次數用完（3/3）→ can_start=false', ($s1->call('GET', "/exams/{$E1}")[1]['data']['my_attempts']['can_start'] ?? null) === false);
check('老師看到 attempt_count', ($t1->call('GET', "/exams/{$E1}")[1]['data']['attempt_count'] ?? 0) === 3);
$r = $t1->call('POST', "/exams/{$E1}/unpublish", null, $t1C);
check('已經有人作答 → 不能撤回：409 HAS_ATTEMPTS', $r[0] === 409 && code($r) === 'HAS_ATTEMPTS', why($r));

$r = $t1->call('POST', "/exams/{$E13}/unpublish", null, $t1C);
check('沒人作答的已發佈考卷 → 撤回成功，回到 draft', $r[0] === 200 && ($r[1]['data']['status'] ?? '') === 'draft', why($r));
check('撤回後學生看不到它（草稿）', $s1->call('GET', "/exams/{$E13}")[0] === 404);
check('撤回後可以重新編輯題目', $put($E13, [['question_id' => $e2, 'score' => 50], ['question_id' => $e4, 'score' => 50]])[0] === 200);
check('撤回後可以重新發佈', $t1->call('POST', "/exams/{$E13}/publish", null, $t1C)[0] === 200);
check('草稿不能撤回 → 409 EXAM_INVALID_STATE', code($t1->call('POST', "/exams/{$E8}/unpublish", null, $t1C)) === 'EXAM_INVALID_STATE');

echo "\n[F-10] 關閉\n";
check('草稿不能關閉 → 409 EXAM_INVALID_STATE', code($t1->call('POST', "/exams/{$E8}/close", null, $t1C)) === 'EXAM_INVALID_STATE');
$r = $t1->call('POST', "/exams/{$E1}/close", null, $t1C);
check('已發佈的考卷關閉 → 200，status=closed', $r[0] === 200 && ($r[1]['data']['status'] ?? '') === 'closed', why($r));
check('已關閉：再關閉 → 409 EXAM_INVALID_STATE', code($t1->call('POST', "/exams/{$E1}/close", null, $t1C)) === 'EXAM_INVALID_STATE');
check('已關閉：發佈 → 409 EXAM_INVALID_STATE（關閉是終態）', code($t1->call('POST', "/exams/{$E1}/publish", null, $t1C)) === 'EXAM_INVALID_STATE');
check('已關閉：撤回 → 409 EXAM_INVALID_STATE', code($t1->call('POST', "/exams/{$E1}/unpublish", null, $t1C)) === 'EXAM_INVALID_STATE');
check('已關閉：可以修改標題', ($t1->call('PATCH', "/exams/{$E1}", ['title' => '[smoke] 已關閉的考卷'], $t1C)[0] ?? 0) === 200);
$r = $t1->call('PATCH', "/exams/{$E1}", ['end_time' => isoAt(86400)], $t1C);
check('已關閉：改結束時間 → 409 EXAM_NOT_EDITABLE', $r[0] === 409 && code($r) === 'EXAM_NOT_EDITABLE', why($r));
check('已關閉：PUT 題目 → 409 EXAM_NOT_EDITABLE', code($put($E1, [['question_id' => $e1, 'score' => 100]])) === 'EXAM_NOT_EDITABLE');
check('已關閉：刪除 → 409 EXAM_NOT_EDITABLE', code($t1->call('DELETE', "/exams/{$E1}", null, $t1C)) === 'EXAM_NOT_EDITABLE');
$d = $s1->call('GET', "/exams/{$E1}")[1]['data'] ?? [];
check('學生仍看得到已關閉的考卷：status=closed、is_open=false、can_start=false', ($d['status'] ?? '') === 'closed' && ($d['is_open'] ?? null) === false && ($d['my_attempts']['can_start'] ?? null) === false);
check('列表也看得到已關閉的考卷', in_array('closed', array_column($s1->call('GET', "/exams?course_id={$A}&status=closed")[1]['data'] ?? [], 'status'), true));
check('已關閉考卷中的題目仍是 in_use（歷史成績受保護）', ($t1->call('GET', "/questions/{$e1}")[1]['data']['in_use'] ?? null) === true);

echo "\n[F-08] 刪除草稿\n";
$E15 = $newExam();
$put($E15, [['question_id' => $e1, 'score' => 5], ['question_id' => $e2, 'score' => 5]]);
check('別的老師刪除我的草稿 → 404', $t2->call('DELETE', "/exams/{$E15}", null, $t2C)[0] === 404);
check('學生刪除 → 403', $s1->call('DELETE', "/exams/{$E15}", null, $s1C)[0] === 403);
check('老師刪除草稿 → 204', $t1->call('DELETE', "/exams/{$E15}", null, $t1C)[0] === 204);
check('刪除後查不到 → 404', $t1->call('GET', "/exams/{$E15}")[0] === 404);
check('題目清單隨考卷一併刪除（外鍵 CASCADE），題目本身還在', (int) $pdo->query("SELECT COUNT(*) FROM exam_questions WHERE exam_id = {$E15}")->fetchColumn() === 0
    && $t1->call('GET', "/questions/{$e1}")[0] === 200);
check('再刪除一次 → 404', $t1->call('DELETE', "/exams/{$E15}", null, $t1C)[0] === 404);
$E16 = $newExam();
$pdo->prepare("INSERT INTO exam_attempts (exam_id, user_id, attempt_no, started_at, status) VALUES (?, ?, 1, NOW(), 'in_progress')")->execute([$E16, $s1Id]);
$r = $t1->call('DELETE', "/exams/{$E16}", null, $t1C);
check('草稿卻有作答紀錄（異常資料）→ 不能刪除：409 HAS_ATTEMPTS', $r[0] === 409 && code($r) === 'HAS_ATTEMPTS', why($r));

if ($admin) {
    echo "\n[管理員]\n";
    $r = $admin[0]->call('POST', '/exams', ['course_id' => $A, 'title' => '[smoke] 管理員建立', 'duration_min' => 10], $admin[1]);
    $EA = (int) ($r[1]['data']['exam_id'] ?? 0);
    check('管理員可以在任何課程建立考卷 → 201', $r[0] === 201, why($r));
    check('管理員可以組卷、發佈', $admin[0]->call('PUT', "/exams/{$EA}/questions", ['questions' => [['question_id' => $e1, 'score' => 100]]], $admin[1])[0] === 200
        && $admin[0]->call('POST', "/exams/{$EA}/publish", null, $admin[1])[0] === 200);
    check('管理員可以看任何考卷詳情', $admin[0]->call('GET', "/exams/{$E1}")[0] === 200);
}

// ======================================================================
smokeCleanup($pdo);
$left = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM courses WHERE name LIKE '[smoke]%') + (SELECT COUNT(*) FROM exams WHERE title LIKE '[smoke]%') + (SELECT COUNT(*) FROM users WHERE email LIKE 'smoke+%@example.test') + (SELECT COUNT(*) FROM tags WHERE name LIKE '[smoke]%')")->fetchColumn();
check('測試資料已清除', $left === 0);

exit(summary());
