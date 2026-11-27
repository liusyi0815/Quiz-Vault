<?php
declare(strict_types=1);

/**
 * M4 冒煙測試：作答（開始、續答、暫存、交卷）、自動批改、成績與解析、逾時處理（F-11、F-12、F-14、F-18）。
 *
 * 需要 teacher1、teacher2（管理員選用）的密碼，取得方式與 smoke_m2.php 相同：
 * 環境變數 TEACHER1_PASSWORD / TEACHER2_PASSWORD / ADMIN_PASSWORD，或 credentials-private.csv。
 *
 *   php tests/smoke_m4.php
 *   php tests/smoke_m4.php http://localhost/quiz-system/public/api/v1
 *
 * 測試資料都以 [smoke] 開頭，結束時自動清除。逾時情境是直接把資料庫裡的 started_at 往前調來模擬。
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

[$t1, $t1C] = loginAs($base, $accounts['TEACHER1'], $passwords['TEACHER1']);
[$t2, $t2C] = loginAs($base, $accounts['TEACHER2'], $passwords['TEACHER2']);
[$s1, $s1C, $s1Id] = registerStudent($base, 'A');      // 主角
[$s2, $s2C] = registerStudent($base, 'B');             // 沒修課
[$s3, $s3C, $s3Id] = registerStudent($base, 'C');
[$s4, $s4C, $s4Id] = registerStudent($base, 'D');
[$s5, $s5C, $s5Id] = registerStudent($base, 'E');
$admin = null;
if ($passwords['ADMIN'] !== null) {
    $c = new Client($base);
    $r = $c->call('POST', '/auth/login', ['email' => $accounts['ADMIN'], 'password' => $passwords['ADMIN']]);
    if ($r[0] === 200 && ($r[1]['data']['must_change_password'] ?? true) === false) {
        $admin = [$c, (string) $r[1]['data']['csrf_token']];
    }
}

// ---------------------------------------------------------------- 準備資料（透過 API）
$idOf = static fn (array $r, string $key): int => (int) ($r[1]['data'][$key] ?? 0);
$A = $idOf($t1->call('POST', '/courses', ['name' => '[smoke] 課程A'], $t1C), 'course_id');
$B = $idOf($t2->call('POST', '/courses', ['name' => '[smoke] 課程B'], $t2C), 'course_id');
$c1 = $idOf($t1->call('POST', "/courses/{$A}/chapters", ['name' => '第一章'], $t1C), 'chapter_id');
$b1 = $idOf($t2->call('POST', "/courses/{$B}/chapters", ['name' => '乙課第一章'], $t2C), 'chapter_id');
foreach ([$s1C => $s1, $s3C => $s3, $s4C => $s4, $s5C => $s5] as $csrf => $client) {
    $client->call('POST', "/courses/{$A}/enrollment", null, $csrf);
}

$mkQ = static function (string $type, string $name, array $options) use ($t1, $t1C, $c1): int {
    $r = $t1->call('POST', '/questions', [
        'chapter_id' => $c1, 'type' => $type, 'content' => "[smoke] 題目 {$name}", 'difficulty' => 1,
        'explanation' => "SMOKE-EXPLAIN-{$name}", 'options' => $options,
    ], $t1C);
    return (int) ($r[1]['data']['question_id'] ?? 0);
};
$S1 = $mkQ('single', 'S1', opts(['甲', true], ['乙', false], ['丙', false]));
$S2 = $mkQ('single', 'S2', opts(['甲', false], ['乙', true], ['丙', false]));
$M1 = $mkQ('multiple', 'M1', opts(['甲', true], ['乙', true], ['丙', false]));
$F1 = $mkQ('truefalse', 'F1', opts(['對', false], ['錯', true]));
$H1 = $mkQ('short', 'H1', []);
$QB = (int) ($t2->call('POST', '/questions', ['chapter_id' => $b1, 'type' => 'single', 'content' => '[smoke] 別課的題目', 'options' => opts(['甲', true], ['乙', false])], $t2C)[1]['data']['question_id'] ?? 0);

$opt = [];      // $opt[題目][選項文字] = option_id
foreach ([$S1, $S2, $M1, $F1] as $q) {
    foreach ($t1->call('GET', "/questions/{$q}")[1]['data']['options'] as $o) {
        $opt[$q][$o['content']] = $o['option_id'];
    }
}

$n = 0;
/** 建立考卷、組卷、（預設）發佈；回傳 exam_id */
$mkExam = static function (array $items, array $override = [], bool $publish = true) use (&$n, $t1, $t1C, $A): int {
    $n++;
    $r = $t1->call('POST', '/exams', $override + [
        'course_id' => $A, 'title' => "[smoke] 考卷 {$n}", 'duration_min' => 30,
        'start_time' => isoAt(-86400), 'end_time' => isoAt(86400 * 30), 'pass_score' => 60, 'max_attempts' => 2,
    ], $t1C);
    $id = (int) ($r[1]['data']['exam_id'] ?? 0);
    $t1->call('PUT', "/exams/{$id}/questions", ['questions' => $items], $t1C);
    if ($publish) {
        $t1->call('POST', "/exams/{$id}/publish", null, $t1C);
    }
    return $id;
};
$full = [['question_id' => $S1, 'score' => 20], ['question_id' => $S2, 'score' => 20], ['question_id' => $M1, 'score' => 30], ['question_id' => $F1, 'score' => 10], ['question_id' => $H1, 'score' => 20]];

$startAs = static fn (Client $c, string $csrf, int $exam): array => $c->call('POST', "/exams/{$exam}/attempts", null, $csrf);
$put = static fn (Client $c, string $csrf, int $attempt, int $q, array $body): array => $c->call('PUT', "/attempts/{$attempt}/answers/{$q}", $body, $csrf);
$pick = static fn (int ...$ids): array => ['option_ids' => $ids];
$backdate = static function (int $attemptId, int $secondsAgo) use ($pdo): void {
    $pdo->prepare('UPDATE exam_attempts SET started_at = ? WHERE attempt_id = ?')->execute([date('Y-m-d H:i:s', time() - $secondsAgo), $attemptId]);
};
$rowsOf = static function (int $attemptId) use ($pdo): array {
    $r = [];
    foreach ($pdo->query("SELECT question_id, is_correct, score_awarded FROM attempt_answers WHERE attempt_id = {$attemptId}") as $row) {
        $r[(int) $row['question_id']] = [$row['is_correct'] === null ? null : (int) $row['is_correct'], (float) $row['score_awarded']];
    }
    return $r;
};
$attemptRow = static fn (int $id): array => $pdo->query("SELECT status, total_score, submitted_at, attempt_no FROM exam_attempts WHERE attempt_id = {$id}")->fetch();

$E1 = $mkExam($full, ['title' => '[smoke] 主考卷', 'max_attempts' => 2]);

// ======================================================================
echo "\n[F-11] 開始作答\n";
check('老師開始作答 → 403 FORBIDDEN', $startAs($t1, $t1C, $E1)[0] === 403);
check('未登入開始作答 → 401', (new Client($base))->call('POST', "/exams/{$E1}/attempts")[0] === 401);
$r = $startAs($s2, $s2C, $E1);
check('沒修課的學生 → 403 NOT_ENROLLED', $r[0] === 403 && code($r) === 'NOT_ENROLLED', why($r));
$Edraft = $mkExam([['question_id' => $S1, 'score' => 100]], [], false);
check('草稿考卷 → 404', $startAs($s1, $s1C, $Edraft)[0] === 404);
check('考卷不存在 → 404', $startAs($s1, $s1C, 99999999)[0] === 404);
$Efuture = $mkExam([['question_id' => $S1, 'score' => 100]], ['start_time' => isoAt(86400), 'end_time' => isoAt(86400 * 2)]);
$r = $startAs($s1, $s1C, $Efuture);
check('還沒到開始時間 → 409 EXAM_NOT_OPEN（reason=not_started）', $r[0] === 409 && code($r) === 'EXAM_NOT_OPEN' && ($r[1]['error']['details'][0]['reason'] ?? '') === 'not_started', why($r));
$Eended = $mkExam([['question_id' => $S1, 'score' => 100]]);
$pdo->prepare('UPDATE exams SET end_time = ? WHERE exam_id = ?')->execute([date('Y-m-d H:i:s', time() - 3600), $Eended]);
$r = $startAs($s1, $s1C, $Eended);
check('已超過結束時間 → 409 EXAM_NOT_OPEN（reason=ended）', $r[0] === 409 && ($r[1]['error']['details'][0]['reason'] ?? '') === 'ended', why($r));
$Eclosed = $mkExam([['question_id' => $S1, 'score' => 100]]);
$t1->call('POST', "/exams/{$Eclosed}/close", null, $t1C);
$r = $startAs($s1, $s1C, $Eclosed);
check('已關閉的考卷 → 409 EXAM_NOT_OPEN（reason=not_published）', $r[0] === 409 && ($r[1]['error']['details'][0]['reason'] ?? '') === 'not_published', why($r));

$r = $startAs($s1, $s1C, $E1);
$A1 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$d = $r[1]['data'] ?? [];
check('開始作答 → 201，第 1 次、status=in_progress', $r[0] === 201 && ($d['attempt_no'] ?? 0) === 1 && ($d['status'] ?? '') === 'in_progress', why($r));
check('題目依考卷順序：S1 S2 M1 F1 H1，附配分', array_column($d['questions'] ?? [], 'question_id') === [$S1, $S2, $M1, $F1, $H1] && array_column($d['questions'], 'score') == [20, 20, 30, 10, 20]);
$span = strtotime($d['deadline_at'] ?? '') - strtotime($d['started_at'] ?? '');
check('deadline_at = 開始時間 + 限時 30 分鐘', $span === 1800, (string) $span);
check('回傳 server_time 與 remaining_seconds（倒數以伺服器為準）', isset($d['server_time']) && ($d['remaining_seconds'] ?? 0) > 1790 && $d['remaining_seconds'] <= 1800);
$json = json_encode($r[1], JSON_UNESCAPED_UNICODE);
check('作答中的回應「沒有」is_correct，也沒有任何解析', !str_contains($json, 'is_correct') && !str_contains($json, 'explanation') && !str_contains($json, 'SMOKE-EXPLAIN'));
check('選擇題有選項（只含 option_id 與文字），簡答題沒有選項', count($d['questions'][2]['options'] ?? []) === 3 && array_keys($d['questions'][2]['options'][0]) === ['option_id', 'content'] && ($d['questions'][4]['options'] ?? null) === []);
check('每題都有空白的 answer', ($d['questions'][0]['answer'] ?? null) === ['option_ids' => [], 'answer_text' => null]);
check('資料庫：1 張作答單 + 5 列預先建立的作答明細（尚未批改）', $attemptRow($A1)['status'] === 'in_progress'
    && count($rowsOf($A1)) === 5 && array_values(array_unique(array_map('json_encode', $rowsOf($A1)))) === ['[null,0]']);
$r = $startAs($s1, $s1C, $E1);
check('已有進行中的作答再開始 → 409 ATTEMPT_IN_PROGRESS，附上 attempt_id', $r[0] === 409 && code($r) === 'ATTEMPT_IN_PROGRESS' && ($r[1]['error']['details'][0]['attempt_id'] ?? 0) === $A1, why($r));
check('資料庫：仍然只有 1 張作答單', (int) $pdo->query("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = {$E1} AND user_id = {$s1Id}")->fetchColumn() === 1);

echo "\n[F-11] 續答\n";
$r = $s1->call('GET', "/attempts/{$A1}");
check('續答 → 200，題目與剛才相同', $r[0] === 200 && array_column($r[1]['data']['questions'], 'question_id') === [$S1, $S2, $M1, $F1, $H1], why($r));
check('續答的回應同樣沒有 is_correct 與解析', !str_contains(json_encode($r[1]), 'is_correct') && !str_contains(json_encode($r[1]), 'SMOKE-EXPLAIN'));
check('別的學生讀我的作答 → 404', $s3->call('GET', "/attempts/{$A1}")[0] === 404);
check('老師讀作答（續答是學生專用）→ 403', $t1->call('GET', "/attempts/{$A1}")[0] === 403);
check('作答單不存在或 ID 不合法 → 404', $s1->call('GET', '/attempts/99999999')[0] === 404 && $s1->call('GET', '/attempts/abc')[0] === 404);
$d = $s1->call('GET', "/exams/{$E1}")[1]['data']['my_attempts'] ?? [];
check('M3 的考卷詳情：my_attempts 反映進行中的作答（can_start=false）', ($d['used'] ?? 0) === 1 && ($d['in_progress_attempt_id'] ?? 0) === $A1 && ($d['can_start'] ?? null) === false, json_encode($d));

// ======================================================================
echo "\n[F-11] 暫存答案\n";
$r = $put($s1, $s1C, $A1, $S1, $pick($opt[$S1]['乙']));
check('暫存單選題 → 200，回傳 saved_at', $r[0] === 200 && isset($r[1]['data']['saved_at']) && ($r[1]['data']['question_id'] ?? 0) === $S1, why($r));
$ans = static fn (Client $c, int $attempt, int $i) => $c->call('GET', "/attempts/{$attempt}")[1]['data']['questions'][$i]['answer'] ?? null;
check('續答時看得到已暫存的答案', ($ans($s1, $A1, 0)['option_ids'] ?? null) === [$opt[$S1]['乙']]);
$put($s1, $s1C, $A1, $S1, $pick($opt[$S1]['甲']));
check('再存一次是「整題取代」：只剩最後勾的那個', ($ans($s1, $A1, 0)['option_ids'] ?? null) === [$opt[$S1]['甲']]);
$r = $put($s1, $s1C, $A1, $S1, $pick($opt[$S1]['甲'], $opt[$S1]['乙']));
check('單選題勾 2 個 → 400 INVALID_OPTION，原答案不變', $r[0] === 400 && code($r) === 'INVALID_OPTION' && ($ans($s1, $A1, 0)['option_ids'] ?? null) === [$opt[$S1]['甲']], why($r));
$r = $put($s1, $s1C, $A1, $S1, $pick($opt[$S2]['乙']));
check('勾了別題的選項 → 400 INVALID_OPTION，details 指出 option_id', $r[0] === 400 && code($r) === 'INVALID_OPTION' && ($r[1]['error']['details'][0]['option_id'] ?? 0) === $opt[$S2]['乙'], why($r));
$r = $put($s1, $s1C, $A1, $M1, $pick($opt[$M1]['甲'], $opt[$M1]['甲']));
check('多選題選項重複 → 400 INVALID_OPTION', $r[0] === 400 && code($r) === 'INVALID_OPTION');
check('選項不是整數 → 400 VALIDATION_FAILED', code($put($s1, $s1C, $A1, $S1, ['option_ids' => ['x']])) === 'VALIDATION_FAILED');
check('option_ids 不是陣列 → 400 VALIDATION_FAILED', code($put($s1, $s1C, $A1, $S1, ['option_ids' => 5])) === 'VALIDATION_FAILED');
check('選擇題送文字答案 → 400 VALIDATION_FAILED', code($put($s1, $s1C, $A1, $S1, ['answer_text' => 'abc'])) === 'VALIDATION_FAILED');
$r = $put($s1, $s1C, $A1, $M1, $pick($opt[$M1]['甲'], $opt[$M1]['乙']));
check('多選題可以勾多個 → 200', $r[0] === 200 && count($ans($s1, $A1, 2)['option_ids']) === 2);
check('是非題 → 200', $put($s1, $s1C, $A1, $F1, $pick($opt[$F1]['對']))[0] === 200);
$r = $put($s1, $s1C, $A1, $H1, ['answer_text' => '第一版簡答']);
check('簡答題 → 200，文字存進 answer_text', $r[0] === 200 && ($ans($s1, $A1, 4)['answer_text'] ?? '') === '第一版簡答');
check('簡答題送 option_ids → 400 INVALID_OPTION', code($put($s1, $s1C, $A1, $H1, $pick($opt[$S1]['甲']))) === 'INVALID_OPTION');
check('簡答題超過 5000 字 → 400 VALIDATION_FAILED', code($put($s1, $s1C, $A1, $H1, ['answer_text' => str_repeat('字', 5001)])) === 'VALIDATION_FAILED');
check('簡答題剛好 5000 字 → 200', $put($s1, $s1C, $A1, $H1, ['answer_text' => str_repeat('字', 5000)])[0] === 200);
$put($s1, $s1C, $A1, $H1, ['answer_text' => '我的簡答']);
check('空陣列代表清除這題的答案', $put($s1, $s1C, $A1, $M1, ['option_ids' => []])[0] === 200 && ($ans($s1, $A1, 2)['option_ids'] ?? null) === []);
check('這題不屬於這次作答（別課的題目）→ 404', $put($s1, $s1C, $A1, $QB, $pick(1))[0] === 404);
check('題目 ID 不合法 → 404', $put($s1, $s1C, $A1, 0, [])[0] === 404);
check('別的學生暫存我的作答 → 404', $put($s3, $s3C, $A1, $S1, $pick($opt[$S1]['甲']))[0] === 404);
check('沒帶 CSRF → 403', $s1->call('PUT', "/attempts/{$A1}/answers/{$S1}", $pick($opt[$S1]['甲']))[0] === 403);
check('老師暫存答案 → 403', $put($t1, $t1C, $A1, $S1, $pick($opt[$S1]['甲']))[0] === 403);
$r = $s1->callRaw('PUT', "/attempts/{$A1}/answers/{$S1}", '{bad json', $s1C);
check('Body 不是合法 JSON → 400 INVALID_JSON', $r[0] === 400 && code($r) === 'INVALID_JSON', why($r));
// 交卷前的最後狀態：S1 答對、S2 答錯（甲）、M1 只勾甲（缺乙）、F1 先不答、H1 有答
$put($s1, $s1C, $A1, $S1, $pick($opt[$S1]['甲']));
$put($s1, $s1C, $A1, $S2, $pick($opt[$S2]['甲']));
$put($s1, $s1C, $A1, $M1, $pick($opt[$M1]['甲']));
$put($s1, $s1C, $A1, $F1, ['option_ids' => []]);

// ======================================================================
echo "\n[F-11 / F-12] 交卷與自動批改\n";
$r = $s1->call('POST', "/attempts/{$A1}/submit", ['answers' => [['question_id' => $S2, 'option_ids' => [$opt[$S2]['乙']]], ['question_id' => $F1, 'option_ids' => [$opt[$F1]['錯']]]]], $s1C);
$d = $r[1]['data'] ?? [];
check('交卷 → 200', $r[0] === 200, why($r));
check('交卷時一併送來的最後答案有被採計：S2、F1 改對了', ($rowsOf($A1)[$S2][0] ?? -1) === 1 && ($rowsOf($A1)[$F1][0] ?? -1) === 1);
check('總分 50：S1 20 + S2 20 + F1 10（M1 缺乙 → 0 分；H1 待評分）', ($d['total_score'] ?? 0) == 50, why($r));
check('含有答案的簡答題 → status=submitted，待評分 1 題', ($d['status'] ?? '') === 'submitted' && ($d['pending_manual_grading'] ?? 0) === 1);
check('尚未及格且還有待評分 → passed=null（未定），late=false', array_key_exists('passed', $d) && $d['passed'] === null && ($d['late'] ?? null) === false && ($d['pass_score'] ?? 0) == 60);
$rows = $rowsOf($A1);
check('資料庫逐題結果：S1 對、S2 對、M1 錯、F1 對、H1 尚未批改（NULL）', [$rows[$S1][0], $rows[$S2][0], $rows[$M1][0], $rows[$F1][0], $rows[$H1][0]] === [1, 1, 0, 1, null], json_encode($rows));
check('資料庫逐題得分：20、20、0、10、0', [$rows[$S1][1], $rows[$S2][1], $rows[$M1][1], $rows[$F1][1], $rows[$H1][1]] == [20, 20, 0, 10, 0]);
$row = $attemptRow($A1);
check('資料庫：total_score=50、status=submitted、submitted_at 已填', (float) $row['total_score'] === 50.0 && $row['status'] === 'submitted' && $row['submitted_at'] !== null);

$r = $s1->call('POST', "/attempts/{$A1}/submit", ['answers' => [['question_id' => $M1, 'option_ids' => [$opt[$M1]['甲'], $opt[$M1]['乙']]]]], $s1C);
check('重複交卷是冪等的：回傳同一份結果，不會重複批改', $r[0] === 200 && ($r[1]['data']['total_score'] ?? 0) == 50 && ($rowsOf($A1)[$M1][0] ?? -1) === 0, why($r));
check('交卷後暫存答案 → 409 ATTEMPT_NOT_EDITABLE', code($put($s1, $s1C, $A1, $S1, $pick($opt[$S1]['乙']))) === 'ATTEMPT_NOT_EDITABLE');
check('交卷後續答 → 409 ATTEMPT_NOT_EDITABLE', code($s1->call('GET', "/attempts/{$A1}")) === 'ATTEMPT_NOT_EDITABLE');
check('別的學生交我的卷 → 404', $s3->call('POST', "/attempts/{$A1}/submit", null, $s3C)[0] === 404);
check('老師交卷 → 403', $t1->call('POST', "/attempts/{$A1}/submit", null, $t1C)[0] === 403);
check('沒帶 CSRF → 403', $s1->call('POST', "/attempts/{$A1}/submit")[0] === 403);

// ======================================================================
echo "\n[F-14] 成績與解析（考卷結束前）\n";
$r = $s1->call('GET', "/attempts/{$A1}/result");
$d = $r[1]['data'] ?? [];
check('學生看成績 → 200，有總分、及格分、狀態', $r[0] === 200 && ($d['total_score'] ?? 0) == 50 && ($d['pass_score'] ?? 0) == 60 && ($d['status'] ?? '') === 'submitted', why($r));
check('考卷還沒結束：answers_revealed=false，沒有逐題結果', ($d['answers_revealed'] ?? null) === false && !array_key_exists('answers', $d));
$json = json_encode($r[1], JSON_UNESCAPED_UNICODE);
check('此時回應中沒有 is_correct、解析、正確答案', !str_contains($json, 'is_correct') && !str_contains($json, 'SMOKE-EXPLAIN'));
check('回應帶有考卷與課程資訊', ($d['exam']['title'] ?? '') === '[smoke] 主考卷' && ($d['exam']['course']['course_id'] ?? 0) === $A);
$r = $t1->call('GET', "/attempts/{$A1}/result");
$d = $r[1]['data'] ?? [];
check('老師隨時看得到完整答案：answers_revealed=true、5 題', ($d['answers_revealed'] ?? null) === true && count($d['answers'] ?? []) === 5, why($r));
$byQ = array_column($d['answers'] ?? [], null, 'question_id');
check('老師版逐題結果：S2 答對，附正確選項與解析', ($byQ[$S2]['is_correct'] ?? null) === true && ($byQ[$S2]['my_option_ids'] ?? []) === [$opt[$S2]['乙']] && str_contains($byQ[$S2]['explanation'] ?? '', 'SMOKE-EXPLAIN'));
check('老師版：M1 缺乙 → is_correct=false、得 0 分，選項標出哪些才是正確', ($byQ[$M1]['is_correct'] ?? null) === false && ($byQ[$M1]['score_awarded'] ?? -1) == 0 && ($byQ[$M1]['score'] ?? 0) == 30
    && array_column(array_filter($byQ[$M1]['options'] ?? [], static fn ($o) => $o['is_correct']), 'content') === ['甲', '乙']);
check('老師版：簡答題 is_correct=null，附上學生的文字答案', array_key_exists('is_correct', $byQ[$H1] ?? []) && $byQ[$H1]['is_correct'] === null && ($byQ[$H1]['answer_text'] ?? '') === '我的簡答');
check('別的學生看我的成績 → 404', $s3->call('GET', "/attempts/{$A1}/result")[0] === 404);
check('別的老師看 → 404', $t2->call('GET', "/attempts/{$A1}/result")[0] === 404);
check('作答單不存在 → 404', $s1->call('GET', '/attempts/99999999/result')[0] === 404);
if ($admin) {
    check('管理員可以看任何作答的完整結果', ($admin[0]->call('GET', "/attempts/{$A1}/result")[1]['data']['answers_revealed'] ?? null) === true);
}

echo "\n[F-14] 我的作答紀錄、全班作答列表\n";
$r = $s1->call('GET', '/me/attempts');
$it = $r[1]['data'][0] ?? [];
check('我的作答紀錄：1 筆，附考卷、課程、狀態、總分', $r[0] === 200 && ($r[1]['meta']['total'] ?? 0) === 1 && ($it['exam']['title'] ?? '') === '[smoke] 主考卷' && ($it['total_score'] ?? 0) == 50 && ($it['status'] ?? '') === 'submitted', why($r));
check('列表也標示 passed=null、待評分 1 題', array_key_exists('passed', $it) && $it['passed'] === null && ($it['pending_manual_grading'] ?? 0) === 1);
check('可依 exam_id、course_id 篩選', ($s1->call('GET', "/me/attempts?exam_id={$E1}")[1]['meta']['total'] ?? 0) === 1 && ($s1->call('GET', "/me/attempts?exam_id={$Eclosed}")[1]['meta']['total'] ?? -1) === 0 && ($s1->call('GET', "/me/attempts?course_id={$A}")[1]['meta']['total'] ?? 0) === 1);
check('別的學生看不到我的紀錄', ($s3->call('GET', '/me/attempts')[1]['meta']['total'] ?? -1) === 0);
check('老師看 /me/attempts → 403（學生專用）', $t1->call('GET', '/me/attempts')[0] === 403);
$r = $t1->call('GET', "/exams/{$E1}/attempts");
$it = $r[1]['data'][0] ?? [];
check('老師看全班作答：學生、第幾次、狀態、總分、待評分', $r[0] === 200 && ($r[1]['meta']['total'] ?? 0) === 1 && ($it['student']['user_id'] ?? 0) === $s1Id && ($it['pending_manual_grading'] ?? 0) === 1, why($r));
check('?pending=1 只列出有簡答題待評分的', ($t1->call('GET', "/exams/{$E1}/attempts?pending=1")[1]['meta']['total'] ?? 0) === 1 && ($t1->call('GET', "/exams/{$E1}/attempts?status=graded")[1]['meta']['total'] ?? -1) === 0);
check('status 不合法 → 400', $t1->call('GET', "/exams/{$E1}/attempts?status=weird")[0] === 400);
check('別的老師看 → 404；學生看 → 403', $t2->call('GET', "/exams/{$E1}/attempts")[0] === 404 && $s1->call('GET', "/exams/{$E1}/attempts")[0] === 403);
if ($admin) {
    check('管理員可以看全班作答', $admin[0]->call('GET', "/exams/{$E1}/attempts")[0] === 200);
}
$d = $s1->call('GET', "/exams/{$E1}")[1]['data']['my_attempts'] ?? [];
check('M3 的考卷詳情：交卷後 used=1、best_score=50、可再作答', ($d['used'] ?? 0) === 1 && ($d['best_score'] ?? 0) == 50 && ($d['can_start'] ?? null) === true, json_encode($d));

// ======================================================================
echo "\n[F-11] 第二次作答與次數上限\n";
$r = $startAs($s1, $s1C, $E1);
$A2 = (int) ($r[1]['data']['attempt_id'] ?? 0);
check('交卷後可以再開始：第 2 次', $r[0] === 201 && ($r[1]['data']['attempt_no'] ?? 0) === 2, why($r));
check('新的作答是空白的，不會帶到上一次的答案', ($r[1]['data']['questions'][0]['answer']['option_ids'] ?? null) === []);
$put($s1, $s1C, $A2, $S1, $pick($opt[$S1]['甲']));
$put($s1, $s1C, $A2, $S2, $pick($opt[$S2]['乙']));
$put($s1, $s1C, $A2, $M1, $pick($opt[$M1]['甲'], $opt[$M1]['乙']));
$put($s1, $s1C, $A2, $F1, $pick($opt[$F1]['錯']));
$r = $s1->call('POST', "/attempts/{$A2}/submit", null, $s1C);
$d = $r[1]['data'] ?? [];
check('全部答對、簡答題空白 → 總分 80，空白簡答直接 0 分', ($d['total_score'] ?? 0) == 80 && ($rowsOf($A2)[$H1] ?? []) === [0, 0.0], why($r));
check('沒有待評分的簡答題 → status=graded，passed=true', ($d['status'] ?? '') === 'graded' && ($d['pending_manual_grading'] ?? -1) === 0 && ($d['passed'] ?? null) === true);
$r = $startAs($s1, $s1C, $E1);
check('已達次數上限（2/2）→ 409 ATTEMPT_LIMIT_REACHED', $r[0] === 409 && code($r) === 'ATTEMPT_LIMIT_REACHED' && ($r[1]['error']['details'][0] ?? []) === ['used' => 2, 'max_attempts' => 2], why($r));
$r = $s1->call('GET', '/me/attempts');
check('我的作答紀錄：2 筆，新的在前', ($r[1]['meta']['total'] ?? 0) === 2 && array_column($r[1]['data'], 'attempt_no') === [2, 1]);
$d = $s1->call('GET', "/exams/{$E1}")[1]['data']['my_attempts'] ?? [];
check('考卷詳情：used=2、best_score=80、can_start=false', ($d['used'] ?? 0) === 2 && ($d['best_score'] ?? 0) == 80 && ($d['can_start'] ?? null) === false);
check('全班列表：2 筆，有 passed=true 的', ($t1->call('GET', "/exams/{$E1}/attempts")[1]['meta']['total'] ?? 0) === 2);

// ======================================================================
echo "\n[F-12] 批改規則（多選題必須完全相同才給分）\n";
$E2 = $mkExam([['question_id' => $M1, 'score' => 50], ['question_id' => $S1, 'score' => 50]], ['max_attempts' => 5, 'pass_score' => 50]);
$take = static function (array $answers) use ($s3, $s3C, $E2, $put, $startAs): array {
    $r = $startAs($s3, $s3C, $E2);
    $id = (int) ($r[1]['data']['attempt_id'] ?? 0);
    foreach ($answers as $q => $ids) {
        $put($s3, $s3C, $id, $q, ['option_ids' => $ids]);
    }
    return $s3->call('POST', "/attempts/{$id}/submit", null, $s3C);
};
$r = $take([$M1 => [$opt[$M1]['甲'], $opt[$M1]['乙']], $S1 => [$opt[$S1]['甲']]]);
check('多選題完全相同 + 單選答對 → 100，graded，passed=true', ($r[1]['data']['total_score'] ?? 0) == 100 && ($r[1]['data']['status'] ?? '') === 'graded' && ($r[1]['data']['passed'] ?? null) === true, why($r));
$r = $take([$M1 => [$opt[$M1]['甲'], $opt[$M1]['乙'], $opt[$M1]['丙']], $S1 => [$opt[$S1]['甲']]]);
check('多選題多勾一個（超集）→ 該題 0 分，總分 50（剛好及格）', ($r[1]['data']['total_score'] ?? 0) == 50 && ($r[1]['data']['passed'] ?? null) === true, why($r));
$r = $take([$M1 => [$opt[$M1]['甲']], $S1 => [$opt[$S1]['乙']]]);
check('多選題少勾（子集）+ 單選答錯 → 0 分，passed=false（沒有待評分，結論確定）', ($r[1]['data']['total_score'] ?? 1) == 0 && ($r[1]['data']['passed'] ?? null) === false, why($r));
$r = $take([]);
check('完全沒作答 → 0 分，仍是 graded（沒有簡答題）', ($r[1]['data']['total_score'] ?? 1) == 0 && ($r[1]['data']['status'] ?? '') === 'graded', why($r));

echo "\n[F-11] 交卷時送來的答案不合法\n";
$E3 = $mkExam($full, ['max_attempts' => 6, 'duration_min' => 10]);
$r = $startAs($s4, $s4C, $E3);
$A3 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$put($s4, $s4C, $A3, $S1, $pick($opt[$S1]['甲']));
$r = $s4->call('POST', "/attempts/{$A3}/submit", ['answers' => [['question_id' => $QB, 'option_ids' => [1]]]], $s4C);
check('answers 裡有不屬於這次作答的題目 → 400，details 指出第幾筆', $r[0] === 400 && code($r) === 'VALIDATION_FAILED' && ($r[1]['error']['details'][0]['field'] ?? '') === 'answers[0].question_id', why($r));
$r = $s4->call('POST', "/attempts/{$A3}/submit", ['answers' => [['question_id' => $S2, 'option_ids' => [$opt[$S2]['乙']]], ['question_id' => $S1, 'option_ids' => [$opt[$S2]['甲']]]]], $s4C);
check('answers 裡有不合法的選項 → 400 INVALID_OPTION', $r[0] === 400 && code($r) === 'INVALID_OPTION' && str_starts_with($r[1]['error']['details'][0]['field'] ?? '', 'answers[1]'), why($r));
check('驗證失敗時「整批都不寫入、也不批改」：作答仍在進行中，S2 沒被寫入', $attemptRow($A3)['status'] === 'in_progress' && ($ans($s4, $A3, 1)['option_ids'] ?? null) === [] && ($ans($s4, $A3, 0)['option_ids'] ?? null) === [$opt[$S1]['甲']]);
check('answers 不是陣列 → 400', code($s4->call('POST', "/attempts/{$A3}/submit", ['answers' => 'abc'], $s4C)) === 'VALIDATION_FAILED');
$put($s4, $s4C, $A3, $M1, $pick($opt[$M1]['甲'], $opt[$M1]['乙']));

// ======================================================================
echo "\n[F-18] 逾時處理\n";
$backdate($A3, 7200);
$r = $put($s4, $s4C, $A3, $S2, $pick($opt[$S2]['乙']));
check('已逾時暫存答案 → 409 ATTEMPT_EXPIRED', $r[0] === 409 && code($r) === 'ATTEMPT_EXPIRED', why($r));
$row = $attemptRow($A3);
check('逾時時系統已依「已暫存的答案」自動交卷並批改（S1 20 + M1 30 = 50，S2 沒存到；簡答題空白 → 直接 graded）', $row['status'] === 'graded' && (float) $row['total_score'] === 50.0 && ($rowsOf($A3)[$S2][0] ?? -1) === 0, json_encode($row));
$r = $s4->call('POST', "/attempts/{$A3}/submit", null, $s4C);
check('之後再交卷：回傳同一份結果，late=true', $r[0] === 200 && ($r[1]['data']['late'] ?? null) === true && ($r[1]['data']['total_score'] ?? 0) == 50, why($r));
check('逾時後續答 → 409 ATTEMPT_NOT_EDITABLE', code($s4->call('GET', "/attempts/{$A3}")) === 'ATTEMPT_NOT_EDITABLE');

$r = $startAs($s4, $s4C, $E3);
$A4 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$put($s4, $s4C, $A4, $S1, $pick($opt[$S1]['甲']));
$backdate($A4, 7200);
$r = $s4->call('POST', "/attempts/{$A4}/submit", ['answers' => [['question_id' => $M1, 'option_ids' => [$opt[$M1]['甲'], $opt[$M1]['乙']]]]], $s4C);
check('逾時才交卷 → 200、late=true，且送來的答案被忽略（只採計已暫存的）：總分 20', $r[0] === 200 && ($r[1]['data']['late'] ?? null) === true && ($r[1]['data']['total_score'] ?? 0) == 20 && ($rowsOf($A4)[$M1][0] ?? -1) === 0, why($r));

$r = $startAs($s4, $s4C, $E3);
$A5 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$put($s4, $s4C, $A5, $S1, $pick($opt[$S1]['甲']));
$backdate($A5, 10 * 60 + 1);                                  // 剛好超過截止 1 秒：還在 5 秒寬限內
$r1 = $put($s4, $s4C, $A5, $M1, $pick($opt[$M1]['甲'], $opt[$M1]['乙']));
$r2 = $s4->call('POST', "/attempts/{$A5}/submit", ['answers' => [['question_id' => $S2, 'option_ids' => [$opt[$S2]['乙']]]]], $s4C);
check('剛超過截止 1 秒（寬限 5 秒內）：暫存仍成功', $r1[0] === 200, why($r1));
check('寬限內交卷：late=false，最後送來的答案也被採計（S1 20 + S2 20 + M1 30 = 70）', ($r2[1]['data']['late'] ?? null) === false && ($r2[1]['data']['total_score'] ?? 0) == 70, why($r2));

// ---- 讀取時順手結算 ----
$E4 = $mkExam([['question_id' => $S1, 'score' => 100]], ['max_attempts' => 2, 'duration_min' => 10]);
$r = $startAs($s5, $s5C, $E4);
$A6 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$put($s5, $s5C, $A6, $S1, $pick($opt[$S1]['甲']));
$backdate($A6, 7200);
$r = $s5->call('GET', '/me/attempts');
check('學生讀 /me/attempts 時，逾時的作答已被結算（不再是 in_progress）', ($r[1]['data'][0]['status'] ?? '') !== 'in_progress' && ($r[1]['data'][0]['total_score'] ?? 0) == 100, why($r));
$r = $startAs($s3, $s3C, $E4);
$A7 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$backdate($A7, 7200);
$r = $t1->call('GET', "/exams/{$E4}/attempts");
$byUser = array_column($r[1]['data'] ?? [], null, 'attempt_id');
check('老師看全班列表時，逾時的作答也被結算（空白作答 → 0 分、graded）', ($byUser[$A7]['status'] ?? '') === 'graded' && ($byUser[$A7]['total_score'] ?? 1) == 0, json_encode($byUser[$A7] ?? null));
$E5 = $mkExam([['question_id' => $S1, 'score' => 100]], ['max_attempts' => 2, 'duration_min' => 10]);
$r = $startAs($s3, $s3C, $E5);
$A8 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$backdate($A8, 7200);
$r = $startAs($s3, $s3C, $E5);
check('舊作答已逾時 → 先結算，再成功開始第 2 次（不是 ATTEMPT_IN_PROGRESS）', $r[0] === 201 && ($r[1]['data']['attempt_no'] ?? 0) === 2 && $attemptRow($A8)['status'] !== 'in_progress', why($r));
$r = $startAs($s3, $s3C, $E5);
check('第 2 次還在進行中，再開始 → ATTEMPT_IN_PROGRESS', code($r) === 'ATTEMPT_IN_PROGRESS');

// ---- 考卷結束時間會縮短截止時間 ----
$E6 = $mkExam([['question_id' => $S1, 'score' => 100]], ['duration_min' => 60, 'end_time' => isoAt(600), 'max_attempts' => 2]);
$r = $startAs($s5, $s5C, $E6);
$d = $r[1]['data'] ?? [];
check('限時 60 分鐘但考卷 10 分鐘後結束 → deadline_at 取較早的（考卷結束時間）', abs(strtotime($d['deadline_at'] ?? '') - (time() + 600)) <= 3 && ($d['remaining_seconds'] ?? 9999) <= 600, json_encode([$d['deadline_at'] ?? null, $d['remaining_seconds'] ?? null]));

// ---- 排程腳本 ----
$E7 = $mkExam([['question_id' => $S1, 'score' => 100]], ['max_attempts' => 3, 'duration_min' => 10]);
$r = $startAs($s5, $s5C, $E7);
$A9 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$put($s5, $s5C, $A9, $S1, $pick($opt[$S1]['甲']));
$backdate($A9, 7200);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/finalize_overdue.php') . ' 2>&1') ?? '';
check('bin/finalize_overdue.php 結算逾時作答，並回報筆數', str_contains($out, '已結算') && $attemptRow($A9)['status'] === 'graded' && (float) $attemptRow($A9)['total_score'] === 100.0, trim($out));
$out2 = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/finalize_overdue.php') . ' 2>&1') ?? '';
check('重複執行是冪等的：第二次回報 0 筆', str_contains($out2, '已結算 0 筆'), trim($out2));

// ---- 關閉考卷不影響進行中的作答 ----
$E8 = $mkExam($full, ['max_attempts' => 2]);
$r = $startAs($s5, $s5C, $E8);
$A10 = (int) ($r[1]['data']['attempt_id'] ?? 0);
$t1->call('POST', "/exams/{$E8}/close", null, $t1C);
check('考卷被關閉後，進行中的作答仍可暫存', $put($s5, $s5C, $A10, $S1, $pick($opt[$S1]['甲']))[0] === 200);
$r = $s5->call('POST', "/attempts/{$A10}/submit", null, $s5C);
check('也仍可交卷、批改', $r[0] === 200 && ($r[1]['data']['total_score'] ?? 0) == 20, why($r));
check('但已關閉的考卷不能再開始新的作答', code($startAs($s5, $s5C, $E8)) === 'EXAM_NOT_OPEN');

// ======================================================================
echo "\n[F-14] 解析的開放（考卷結束後）\n";
$t1->call('POST', "/exams/{$E1}/close", null, $t1C);
$r = $s1->call('GET', "/attempts/{$A1}/result");
$d = $r[1]['data'] ?? [];
check('考卷關閉後：學生看得到逐題結果（answers_revealed=true）', ($d['answers_revealed'] ?? null) === true && count($d['answers'] ?? []) === 5, why($r));
$byQ = array_column($d['answers'] ?? [], null, 'question_id');
check('學生的逐題結果：自己的答案、對錯、得分、正確答案與解析', ($byQ[$M1]['my_option_ids'] ?? []) === [$opt[$M1]['甲']] && ($byQ[$M1]['is_correct'] ?? null) === false
    && array_column(array_filter($byQ[$M1]['options'], static fn ($o) => $o['is_correct']), 'content') === ['甲', '乙'] && str_contains($byQ[$S1]['explanation'] ?? '', 'SMOKE-EXPLAIN'));
check('逐題結果依考卷順序排列', array_column($d['answers'], 'question_id') === [$S1, $S2, $M1, $F1, $H1]);
$pdo->prepare('UPDATE exams SET end_time = ? WHERE exam_id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $E2]);
$take2 = $s3->call('GET', '/me/attempts?exam_id=' . $E2)[1]['data'][0]['attempt_id'] ?? 0;
check('已發佈但超過結束時間（有效狀態 ended）：同樣開放', ($s3->call('GET', "/attempts/{$take2}/result")[1]['data']['answers_revealed'] ?? null) === true);
$r = $startAs($s1, $s1C, $E1);
check('關閉後 M3 的列表仍看得到這份考卷，且 can_start=false', ($s1->call('GET', "/exams/{$E1}")[1]['data']['my_attempts']['can_start'] ?? null) === false);
$E9 = $mkExam([['question_id' => $S1, 'score' => 100]], ['max_attempts' => 2]);
$AX = (int) ($startAs($s5, $s5C, $E9)[1]['data']['attempt_id'] ?? 0);
$r = $s5->call('GET', "/attempts/{$AX}/result");
check('自己還在作答中的作答，看成績 → 409 ATTEMPT_NOT_SUBMITTED', $r[0] === 409 && code($r) === 'ATTEMPT_NOT_SUBMITTED', why($r));

// ======================================================================
smokeCleanup($pdo);
$left = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM courses WHERE name LIKE '[smoke]%') + (SELECT COUNT(*) FROM exams WHERE title LIKE '[smoke]%') + (SELECT COUNT(*) FROM users WHERE email LIKE 'smoke+%@example.test') + (SELECT COUNT(*) FROM tags WHERE name LIKE '[smoke]%')")->fetchColumn();
check('測試資料已清除', $left === 0);
exit(summary());
