<?php
declare(strict_types=1);

/**
 * M5 冒煙測試：標籤（F-07）、簡答題評分（F-13）、統計（F-15）、收藏與錯題本（F-16）、使用者管理（F-17）。
 *
 * 需要 teacher1、teacher2 的密碼；管理員（ADMIN_PASSWORD）是選用的，沒有的話會跳過需要管理員的項目。
 * 取得方式與 smoke_m2.php 相同：環境變數，或 credentials-private.csv。
 *
 *   php tests/smoke_m5.php
 *   php tests/smoke_m5.php http://localhost/quiz-system/public/api/v1
 *
 * 測試資料都以 [smoke] 開頭（使用者是 smoke+...@example.test），結束時自動清除。
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
[$s1, $s1C, $s1Id] = registerStudent($base, 'A');     // 主角：全對，簡答滿分
[$s2, $s2C]        = registerStudent($base, 'B');     // 沒修課、沒作答
[$s3, $s3C, $s3Id, $s3Mail, $s3Pw] = registerStudent($base, 'C');     // 全錯
[$s4, $s4C, $s4Id] = registerStudent($base, 'D');     // 選擇題全對，簡答題空白
[$s5, $s5C, $s5Id] = registerStudent($base, 'E');     // 簡答題一直沒被評分

$admin = null;
$adminId = 0;
if ($passwords['ADMIN'] !== null) {
    $c = new Client($base);
    $r = $c->call('POST', '/auth/login', ['email' => $accounts['ADMIN'], 'password' => $passwords['ADMIN']]);
    if ($r[0] === 200 && ($r[1]['data']['must_change_password'] ?? true) === false) {
        $admin = [$c, (string) $r[1]['data']['csrf_token']];
        $adminId = (int) $r[1]['data']['user']['user_id'];
    }
}

// ---------------------------------------------------------------- 準備資料
$idOf = static fn (array $r, string $key): int => (int) ($r[1]['data'][$key] ?? 0);
$A = $idOf($t1->call('POST', '/courses', ['name' => '[smoke] 課程A'], $t1C), 'course_id');
$B = $idOf($t2->call('POST', '/courses', ['name' => '[smoke] 課程B'], $t2C), 'course_id');
$c1 = $idOf($t1->call('POST', "/courses/{$A}/chapters", ['name' => '第一章'], $t1C), 'chapter_id');
$c2 = $idOf($t1->call('POST', "/courses/{$A}/chapters", ['name' => '第二章'], $t1C), 'chapter_id');
$b1 = $idOf($t2->call('POST', "/courses/{$B}/chapters", ['name' => '乙課第一章'], $t2C), 'chapter_id');
foreach ([$s1C => $s1, $s3C => $s3, $s4C => $s4, $s5C => $s5] as $csrf => $client) {
    $client->call('POST', "/courses/{$A}/enrollment", null, $csrf);
}

$mkQ = static function (int $chapter, string $type, string $name, array $options, array $tags = []) use ($t1, $t1C): int {
    $r = $t1->call('POST', '/questions', [
        'chapter_id' => $chapter, 'type' => $type, 'content' => "[smoke] 題目 {$name}", 'difficulty' => 1,
        'explanation' => "SMOKE-EXPLAIN-{$name}", 'options' => $options, 'tags' => $tags,
    ], $t1C);
    return (int) ($r[1]['data']['question_id'] ?? 0);
};
$S1 = $mkQ($c1, 'single', 'S1', opts(['甲', true], ['乙', false]));
$S2 = $mkQ($c1, 'single', 'S2', opts(['甲', false], ['乙', true]));
$S3 = $mkQ($c2, 'single', 'S3', opts(['甲', true], ['乙', false]));
$H1 = $mkQ($c1, 'short', 'H1', []);
$QB = (int) ($t2->call('POST', '/questions', ['chapter_id' => $b1, 'type' => 'single', 'content' => '[smoke] 別課的題目', 'options' => opts(['甲', true], ['乙', false])], $t2C)[1]['data']['question_id'] ?? 0);

$opt = [];
foreach ([$S1, $S2, $S3] as $q) {
    foreach ($t1->call('GET', "/questions/{$q}")[1]['data']['options'] as $o) {
        $opt[$q][$o['content']] = $o['option_id'];
    }
}
$right = [$S1 => $opt[$S1]['甲'], $S2 => $opt[$S2]['乙'], $S3 => $opt[$S3]['甲']];
$wrong = [$S1 => $opt[$S1]['乙'], $S2 => $opt[$S2]['甲'], $S3 => $opt[$S3]['乙']];

$r = $t1->call('POST', '/exams', [
    'course_id' => $A, 'title' => '[smoke] 主考卷', 'duration_min' => 30,
    'start_time' => isoAt(-86400), 'end_time' => isoAt(86400 * 30), 'pass_score' => 60, 'max_attempts' => 2,
], $t1C);
$E = $idOf($r, 'exam_id');
$t1->call('PUT', "/exams/{$E}/questions", ['questions' => [
    ['question_id' => $S1, 'score' => 20], ['question_id' => $S2, 'score' => 20], ['question_id' => $S3, 'score' => 10], ['question_id' => $H1, 'score' => 50],
]], $t1C);
$t1->call('POST', "/exams/{$E}/publish", null, $t1C);

/** 學生作答並交卷：$choices = [題目 => option_id]、$text = 簡答內容（null 代表不寫）；回傳 [attempt_id, submit 回應] */
$sit = static function (Client $c, string $csrf, array $choices, ?string $text) use ($E): array {
    $r = $c->call('POST', "/exams/{$E}/attempts", null, $csrf);
    $id = (int) ($r[1]['data']['attempt_id'] ?? 0);
    $answers = [];
    foreach ($choices as $q => $o) {
        $answers[] = ['question_id' => $q, 'option_ids' => [$o]];
    }
    if ($text !== null) {
        $answers[] = ['question_id' => $GLOBALS['H1'], 'answer_text' => $text];
    }
    return [$id, $c->call('POST', "/attempts/{$id}/submit", ['answers' => $answers], $csrf)];
};
$grade = static fn (Client $c, string $csrf, int $attempt, int $q, array $body): array => $c->call('PATCH', "/attempts/{$attempt}/answers/{$q}/grade", $body, $csrf);
$dbAttempt = static fn (int $id): array => $pdo->query("SELECT status, total_score FROM exam_attempts WHERE attempt_id = {$id}")->fetch();
$dbAnswer = static fn (int $a, int $q): array => $pdo->query("SELECT is_correct, score_awarded FROM attempt_answers WHERE attempt_id = {$a} AND question_id = {$q}")->fetch();

// ================================================================== F-13 簡答題評分
echo "\n[F-13] 簡答題人工評分\n";
[$a1, $r1] = $sit($s1, $s1C, [$S1 => $right[$S1], $S2 => $right[$S2], $S3 => $wrong[$S3]], '我的簡答');   // S3 故意答錯
check('交卷後：選擇題 40 分，簡答題待評分 → status=submitted、passed=null', ($r1[1]['data']['status'] ?? '') === 'submitted'
    && (float) ($r1[1]['data']['total_score'] ?? -1) === 40.0 && isNullField($r1, 'passed') && ($r1[1]['data']['pending_manual_grading'] ?? 0) === 1, why($r1));
$list = $t1->call('GET', "/exams/{$E}/attempts?pending=1");
check('老師用 ?pending=1 找得到待評分的作答', $list[0] === 200 && in_array($a1, array_column($list[1]['data'], 'attempt_id'), true), why($list));

check('未登入評分 → 401', (new Client($base))->call('PATCH', "/attempts/{$a1}/answers/{$H1}/grade", ['score_awarded' => 1])[0] === 401);
check('學生評分 → 403', $grade($s1, $s1C, $a1, $H1, ['score_awarded' => 50])[0] === 403);
$r = $grade($t2, $t2C, $a1, $H1, ['score_awarded' => 50]);
check('別的老師評分 → 404（看不到別人課程的作答）', $r[0] === 404, why($r));
check('作答單不存在 → 404', $grade($t1, $t1C, 99999999, $H1, ['score_awarded' => 1])[0] === 404);
check('這題不屬於這次作答 → 404', $grade($t1, $t1C, $a1, $QB, ['score_awarded' => 1])[0] === 404);
$r = $grade($t1, $t1C, $a1, $S1, ['score_awarded' => 5]);
check('對選擇題評分 → 409 NOT_GRADABLE', $r[0] === 409 && code($r) === 'NOT_GRADABLE', why($r));
$r = $grade($t1, $t1C, $a1, $H1, ['score_awarded' => 50.01]);
check('超過配分 → 400 SCORE_OUT_OF_RANGE，details 帶上限', $r[0] === 400 && code($r) === 'SCORE_OUT_OF_RANGE' && ($r[1]['error']['details'][0]['max'] ?? 0) == 50, why($r));
check('負分 → 400 VALIDATION_FAILED', code($grade($t1, $t1C, $a1, $H1, ['score_awarded' => -1])) === 'VALIDATION_FAILED');
check('沒給分數 → 400 VALIDATION_FAILED', code($grade($t1, $t1C, $a1, $H1, [])) === 'VALIDATION_FAILED');
check('分數不是數字 → 400 VALIDATION_FAILED', code($grade($t1, $t1C, $a1, $H1, ['score_awarded' => 'abc'])) === 'VALIDATION_FAILED');
check('三位小數 → 400 VALIDATION_FAILED', code($grade($t1, $t1C, $a1, $H1, ['score_awarded' => 1.234])) === 'VALIDATION_FAILED');
check('失敗的評分都沒有動到資料：仍是 submitted、40 分', $dbAttempt($a1)['status'] === 'submitted' && (float) $dbAttempt($a1)['total_score'] === 40.0);

$r = $grade($t1, $t1C, $a1, $H1, ['score_awarded' => 30]);
$d = $r[1]['data'] ?? [];
check('給 30 分 → 200：作答轉為 graded，總分 70，通過', $r[0] === 200 && ($d['attempt_status'] ?? '') === 'graded'
    && (float) ($d['total_score'] ?? 0) === 70.0 && ($d['passed'] ?? null) === true && ($d['pending_manual_grading'] ?? -1) === 0, why($r));
check('部分給分：is_correct=false，max_score=50', ($d['is_correct'] ?? null) === false && ($d['max_score'] ?? 0) == 50);
check('資料庫：score_awarded=30、is_correct=0，作答 graded、70 分', (float) $dbAnswer($a1, $H1)['score_awarded'] === 30.0 && (int) $dbAnswer($a1, $H1)['is_correct'] === 0
    && $dbAttempt($a1)['status'] === 'graded' && (float) $dbAttempt($a1)['total_score'] === 70.0);
$r = $grade($t1, $t1C, $a1, $H1, ['score_awarded' => 50]);
check('重新評分（graded 可再評）：給滿分 → 總分 90、is_correct=true、仍是 graded', $r[0] === 200 && (float) $r[1]['data']['total_score'] === 90.0
    && $r[1]['data']['is_correct'] === true && $r[1]['data']['attempt_status'] === 'graded', why($r));
$r = $grade($t1, $t1C, $a1, $H1, ['score_awarded' => 0]);
check('再改成 0 分 → 總分 40、passed=false、仍是 graded', (float) ($r[1]['data']['total_score'] ?? -1) === 40.0 && ($r[1]['data']['passed'] ?? null) === false && $dbAttempt($a1)['status'] === 'graded');
$r = $grade($admin[0] ?? $t1, $admin[1] ?? $t1C, $a1, $H1, ['score_awarded' => 50]);
check(($admin ? '管理員' : '老師') . '也能評分 → 200（最終簡答 50 分，總分 90）', $r[0] === 200 && (float) $r[1]['data']['total_score'] === 90.0, why($r));
check('分數可以是字串數字（"12.5"）→ 200', $grade($t1, $t1C, $a1, $H1, ['score_awarded' => '12.5'])[0] === 200);
$grade($t1, $t1C, $a1, $H1, ['score_awarded' => 50]);

$inProg = $idOf($s5->call('POST', "/exams/{$E}/attempts", null, $s5C), 'attempt_id');
$r = $grade($t1, $t1C, $inProg, $H1, ['score_awarded' => 5]);
check('作答中（還沒交卷）評分 → 409 ATTEMPT_NOT_SUBMITTED', $r[0] === 409 && code($r) === 'ATTEMPT_NOT_SUBMITTED', why($r));
$s5->call('PUT', "/attempts/{$inProg}/answers/{$H1}", ['answer_text' => '一直沒人評'], $s5C);
foreach ($right as $q => $o) {
    $s5->call('PUT', "/attempts/{$inProg}/answers/{$q}", ['option_ids' => [$o]], $s5C);
}
$s5->call('POST', "/attempts/{$inProg}/submit", null, $s5C);

// s3：全錯，簡答 20 分
[$a3, ] = $sit($s3, $s3C, $wrong, '亂寫的');
$grade($t1, $t1C, $a3, $H1, ['score_awarded' => 20]);
// s4：選擇題全對，簡答題空白 → 空白簡答直接 0 分，不必等老師
[$a4, $r4] = $sit($s4, $s4C, $right, null);
check('簡答題空白：交卷就直接 graded、50 分、不需要老師評分', ($r4[1]['data']['status'] ?? '') === 'graded' && (float) ($r4[1]['data']['total_score'] ?? 0) === 50.0 && ($r4[1]['data']['pending_manual_grading'] ?? -1) === 0, why($r4));
check('空白簡答題的作答不會出現在「待評分」列表', !in_array($a4, array_column($t1->call('GET', "/exams/{$E}/attempts?pending=1")[1]['data'], 'attempt_id'), true));
$r = $grade($t1, $t1C, $a4, $H1, ['score_awarded' => 10]);
check('老師仍可替空白的簡答題給分（例如紙本補交）→ 200，總分 60', $r[0] === 200 && (float) $r[1]['data']['total_score'] === 60.0, why($r));
$grade($t1, $t1C, $a4, $H1, ['score_awarded' => 0]);
check('改回 0 分，總分 50', (float) $dbAttempt($a4)['total_score'] === 50.0);

// ================================================================== F-15 統計
echo "\n[F-15] 統計報表\n";
$r = $t1->call('GET', "/exams/{$E}/stats");
$d = $r[1]['data'] ?? [];
check('考卷統計 → 200', $r[0] === 200, why($r));
check('只納入已批改的 3 次作答（s5 的簡答還沒評，不算）', ($d['attempts'] ?? 0) === 3 && ($d['pending_attempts'] ?? 0) === 1, json_encode($d));
check('平均 53.33、最高 90、最低 20、及格率 33.3', abs(($d['avg_score'] ?? 0) - 53.33) < 0.01 && ($d['max_score'] ?? 0) == 90 && ($d['min_score'] ?? 0) == 20 && abs(($d['pass_rate'] ?? 0) - 33.3) < 0.01, json_encode($d));
check('分數分佈：0-59 有 2 人、60-79 有 0 人、80-100 有 1 人', array_column($d['distribution'] ?? [], 'count', 'range') === ['0-59' => 2, '60-79' => 0, '80-100' => 1], json_encode($d['distribution'] ?? null));
check('附上配分總和 100 與及格分數 60', ($d['total_points'] ?? 0) == 100 && ($d['pass_score'] ?? 0) == 60);
check('別的老師看 → 404', $t2->call('GET', "/exams/{$E}/stats")[0] === 404);
check('學生看 → 403', $s1->call('GET', "/exams/{$E}/stats")[0] === 403);
check('考卷不存在 → 404', $t1->call('GET', '/exams/99999999/stats')[0] === 404);
$Eempty = $idOf($t1->call('POST', '/exams', ['course_id' => $A, 'title' => '[smoke] 空考卷', 'duration_min' => 10, 'pass_score' => 1], $t1C), 'exam_id');
$r = $t1->call('GET', "/exams/{$Eempty}/stats");
check('沒有任何作答的考卷：次數 0，平均等欄位為 null', ($r[1]['data']['attempts'] ?? -1) === 0 && isNullField($r, 'avg_score') && isNullField($r, 'pass_rate'), why($r));

$r = $t1->call('GET', "/courses/{$A}/stats/questions");
$d = $r[1]['data'] ?? [];
check('各題答對率 → 200，共 4 題', $r[0] === 200 && count($d) === 4 && ($r[1]['meta']['total'] ?? 0) === 4, why($r));
check('由低到高排序：S3、H1（各 33.3）→ S1、S2（各 66.7）', array_column($d, 'question_id') === [$S3, $H1, $S1, $S2], json_encode(array_column($d, 'question_id')));
check('S1：3 人作答、2 人答對、66.7%', ($d[2]['answered'] ?? 0) === 3 && ($d[2]['correct'] ?? 0) === 2 && abs(($d[2]['correct_rate'] ?? 0) - 66.7) < 0.01);
check('每題附章節與題型', ($d[0]['chapter']['chapter_id'] ?? 0) === $c2 && ($d[1]['type'] ?? '') === 'short');
check('?chapter_id 篩選：第二章只有 S3', array_column($t1->call('GET', "/courses/{$A}/stats/questions?chapter_id={$c2}")[1]['data'], 'question_id') === [$S3]);
check('?exam_id 篩選：同一份考卷 → 4 題', count($t1->call('GET', "/courses/{$A}/stats/questions?exam_id={$E}")[1]['data']) === 4);
check('?exam_id 是別課的考卷 → 404', $t1->call('GET', "/courses/{$A}/stats/questions?exam_id=" . $idOf($t2->call('POST', '/exams', ['course_id' => $B, 'title' => '[smoke] 乙課考卷', 'duration_min' => 10, 'pass_score' => 1], $t2C), 'exam_id'))[0] === 404);
check('別的老師看這門課 → 404；學生 → 403', $t2->call('GET', "/courses/{$A}/stats/questions")[0] === 404 && $s1->call('GET', "/courses/{$A}/stats/questions")[0] === 403);
check('分頁：per_page=2 → 2 筆、total 仍是 4', count($t1->call('GET', "/courses/{$A}/stats/questions?per_page=2")[1]['data']) === 2);
check('課程不存在 → 404', $t1->call('GET', '/courses/99999999/stats/questions')[0] === 404);

$r = $t1->call('GET', "/courses/{$A}/stats/chapters");
$ch = $r[1]['data']['chapters'] ?? [];
check('班級章節弱點：第二章 33.3% 在前、第一章 55.6% 在後', $r[0] === 200 && array_column($ch, 'chapter_id') === [$c2, $c1]
    && abs($ch[0]['correct_rate'] - 33.3) < 0.01 && abs($ch[1]['correct_rate'] - 55.6) < 0.01 && $ch[1]['total'] === 9 && $ch[1]['correct'] === 5, why($r));
check('別的老師 → 404；學生 → 403', $t2->call('GET', "/courses/{$A}/stats/chapters")[0] === 404 && $s1->call('GET', "/courses/{$A}/stats/chapters")[0] === 403);
check('別的老師的課沒有作答 → 章節列表為空', ($t2->call('GET', "/courses/{$B}/stats/chapters")[1]['data']['chapters'] ?? null) === []);

$r = $s1->call('GET', "/me/stats/chapters?course_id={$A}");
check('個人章節弱點：考卷還沒結束 → 空（避免考試期間用統計推測答案）', $r[0] === 200 && ($r[1]['data']['chapters'] ?? null) === [], why($r));
check('老師看個人章節弱點 → 403', $t1->call('GET', '/me/stats/chapters')[0] === 403);

// ================================================================== F-16 考卷結束前：收藏與錯題本都不開放
echo "\n[F-16] 考卷結束前\n";
$r = $s1->call('PUT', "/me/bookmarks/{$S3}", ['note' => '太早'], $s1C);
check('考卷還沒結束就收藏 → 404（不能繞過答案揭露規則）', $r[0] === 404, why($r));
check('錯題本：考卷還沒結束 → 空', ($s1->call('GET', '/me/wrong-questions')[1]['data'] ?? null) === []);
check('收藏列表一開始是空的', ($s1->call('GET', '/me/bookmarks')[1]['data'] ?? null) === []);

// s3 再考一次（全錯），供「同一題多次答錯合併」使用
[$a3b, ] = $sit($s3, $s3C, $wrong, null);
check('第二次作答：簡答空白、全錯 → graded、0 分', $dbAttempt($a3b)['status'] === 'graded' && (float) $dbAttempt($a3b)['total_score'] === 0.0);

// ================================================================== 關閉考卷
$t1->call('POST', "/exams/{$E}/close", null, $t1C);

echo "\n[F-15] 個人章節弱點（考卷已關閉）\n";
$r = $s1->call('GET', "/me/stats/chapters?course_id={$A}");
$ch = $r[1]['data']['chapters'] ?? [];
check('第二章 0%（0/1）在前、第一章 100%（3/3）在後', array_column($ch, 'chapter_id') === [$c2, $c1] && $ch[0]['correct_rate'] == 0 && $ch[1]['correct_rate'] == 100 && $ch[1]['total'] === 3, why($r));
check('附上課程資訊', ($ch[0]['course']['course_id'] ?? 0) === $A);
check('不給 course_id → 全部課程，結果相同', count($s1->call('GET', '/me/stats/chapters')[1]['data']['chapters']) === 2);
check('指定別的課程 → 空', ($s1->call('GET', "/me/stats/chapters?course_id={$B}")[1]['data']['chapters'] ?? null) === []);
check('課程不存在 → 404', $s1->call('GET', '/me/stats/chapters?course_id=99999999')[0] === 404);
check('沒作答過的學生 → 空', ($s2->call('GET', '/me/stats/chapters')[1]['data']['chapters'] ?? null) === []);

echo "\n[F-16] 收藏\n";
check('未登入收藏 → 401', (new Client($base))->call('PUT', "/me/bookmarks/{$S3}", [])[0] === 401);
check('老師收藏 → 403', $t1->call('PUT', "/me/bookmarks/{$S3}", [], $t1C)[0] === 403);
check('沒作答過的學生收藏 → 404', $s2->call('PUT', "/me/bookmarks/{$S3}", [], $s2C)[0] === 404);
check('別課的題目 → 404', $s1->call('PUT', "/me/bookmarks/{$QB}", [], $s1C)[0] === 404);
check('題目不存在 → 404', $s1->call('PUT', '/me/bookmarks/99999999', [], $s1C)[0] === 404);
check('筆記超過 255 字 → 400 VALIDATION_FAILED', code($s1->call('PUT', "/me/bookmarks/{$S3}", ['note' => str_repeat('字', 256)], $s1C)) === 'VALIDATION_FAILED');
$r = $s1->call('PUT', "/me/bookmarks/{$S3}", ['note' => '這題要再看'], $s1C);
check('收藏 → 200，附筆記', $r[0] === 200 && ($r[1]['data']['note'] ?? '') === '這題要再看' && ($r[1]['data']['question_id'] ?? 0) === $S3, why($r));
$r = $s1->call('PUT', "/me/bookmarks/{$S3}", ['note' => '改過的筆記'], $s1C);
check('重複收藏＝更新筆記（冪等），資料庫仍只有 1 筆', $r[0] === 200 && ($r[1]['data']['note'] ?? '') === '改過的筆記'
    && (int) $pdo->query("SELECT COUNT(*) FROM bookmarks WHERE user_id = {$s1Id}")->fetchColumn() === 1);
$s1->call('PUT', "/me/bookmarks/{$S1}", [], $s1C);
check('不給筆記 → note 為 null', ($pdo->query("SELECT note FROM bookmarks WHERE user_id = {$s1Id} AND question_id = {$S1}")->fetch(PDO::FETCH_ASSOC) ?: ['note' => 'x'])['note'] === null);
$pdo->exec("UPDATE bookmarks SET created_at = created_at - INTERVAL 60 SECOND WHERE user_id = {$s1Id} AND question_id = {$S3}");   // 同一秒收藏的順序無法分辨，拉開時間
$r = $s1->call('GET', '/me/bookmarks');
$d = $r[1]['data'] ?? [];
check('收藏列表：2 筆，最新收藏的在前', $r[0] === 200 && array_column($d, 'question_id') === [$S1, $S3] && ($r[1]['meta']['total'] ?? 0) === 2, why($r));
$b = $d[1] ?? [];
check('每筆附題目、章節、課程、筆記、正確答案與解析', ($b['answers_revealed'] ?? false) === true && $b['note'] === '改過的筆記' && $b['explanation'] === 'SMOKE-EXPLAIN-S3'
    && ($b['chapter']['chapter_id'] ?? 0) === $c2 && ($b['course']['course_id'] ?? 0) === $A && count($b['options'] ?? []) === 2 && $b['options'][0]['is_correct'] === true);
check('?course_id 篩選：別的課程 → 空', ($s1->call('GET', "/me/bookmarks?course_id={$B}")[1]['data'] ?? null) === []);
check('別人看不到我的收藏', ($s3->call('GET', '/me/bookmarks')[1]['data'] ?? null) === []);
check('取消收藏 → 204', $s1->call('DELETE', "/me/bookmarks/{$S1}", null, $s1C)[0] === 204);
check('再取消一次 → 404', $s1->call('DELETE', "/me/bookmarks/{$S1}", null, $s1C)[0] === 404);
check('取消後只剩 1 筆', ($s1->call('GET', '/me/bookmarks')[1]['meta']['total'] ?? 0) === 1);
check('老師取消收藏 → 403', $t1->call('DELETE', "/me/bookmarks/{$S3}", null, $t1C)[0] === 403);

echo "\n[F-16] 錯題本\n";
$r = $s1->call('GET', '/me/wrong-questions');
$d = $r[1]['data'] ?? [];
check('s1 只答錯 S3 → 1 題（簡答拿滿分不算錯）', $r[0] === 200 && array_column($d, 'question_id') === [$S3], why($r));
$w = $d[0] ?? [];
check('附答錯次數 1、最近答錯時間、已收藏、正確答案與解析', ($w['wrong_times'] ?? 0) === 1 && isset($w['last_wrong_at']) && ($w['bookmarked'] ?? null) === true
    && $w['explanation'] === 'SMOKE-EXPLAIN-S3' && $w['options'][0]['is_correct'] === true);
check('附「我當時選的答案」：選了乙', ($w['last_answer']['option_ids'] ?? null) === [$wrong[$S3]] && ($w['last_answer']['attempt_id'] ?? 0) === $a1, json_encode($w['last_answer'] ?? null));

$r = $s3->call('GET', '/me/wrong-questions');
$d = $r[1]['data'] ?? [];
$byQ = array_column($d, null, 'question_id');
check('s3 兩次作答全錯：4 題都在錯題本', count($d) === 4 && isset($byQ[$S1], $byQ[$S2], $byQ[$S3], $byQ[$H1]), why($r));
check('同一題多次答錯合併：S1 答錯 2 次，最近一次答案取自第二次作答', ($byQ[$S1]['wrong_times'] ?? 0) === 2 && ($byQ[$S1]['last_answer']['attempt_id'] ?? 0) === $a3b);
check('簡答題只拿部分分數也算錯（H1 兩次：20 分、空白）', ($byQ[$H1]['wrong_times'] ?? 0) === 2);
check('?chapter_id 篩選：第二章只有 S3', array_column($s3->call('GET', "/me/wrong-questions?chapter_id={$c2}")[1]['data'], 'question_id') === [$S3]);
check('?course_id 篩選：別的課程 → 空', ($s3->call('GET', "/me/wrong-questions?course_id={$B}")[1]['data'] ?? null) === []);
check('分頁：per_page=2 → 2 筆、total=4', count($s3->call('GET', '/me/wrong-questions?per_page=2')[1]['data']) === 2 && ($s3->call('GET', '/me/wrong-questions?per_page=2')[1]['meta']['total'] ?? 0) === 4);
$d = $s4->call('GET', '/me/wrong-questions')[1]['data'] ?? [];
check('s4：只有空白的簡答題算錯，last_answer 的文字是空的', array_column($d, 'question_id') === [$H1] && array_key_exists('answer_text', $d[0]['last_answer'] ?? []) && in_array($d[0]['last_answer']['answer_text'], [null, ''], true), json_encode($d[0]['last_answer'] ?? null));
check('s5：簡答題還沒評分（未定）不算錯，選擇題全對 → 空', ($s5->call('GET', '/me/wrong-questions')[1]['data'] ?? null) === []);
check('老師看錯題本 → 403', $t1->call('GET', '/me/wrong-questions')[0] === 403);

// ================================================================== F-07 標籤
echo "\n[F-07] 標籤管理\n";
check('未登入看標籤 → 401', (new Client($base))->call('GET', '/tags')[0] === 401);
check('學生看標籤 → 403', $s1->call('GET', '/tags')[0] === 403);
check('老師看標籤 → 200', $t1->call('GET', '/tags')[0] === 200);
check('學生新增標籤 → 403', $s1->call('POST', '/tags', ['name' => '[smoke] x'], $s1C)[0] === 403);
$r = $t1->call('POST', '/tags', ['name' => '[smoke] Tag'], $t1C);
$tagId = $idOf($r, 'tag_id');
check('老師新增標籤 → 201，question_count=0', $r[0] === 201 && $tagId > 0 && ($r[1]['data']['question_count'] ?? -1) === 0, why($r));
$r = $t1->call('POST', '/tags', ['name' => '[smoke] Tag'], $t1C);
check('重複 → 409 TAG_EXISTS，details 帶既有標籤', $r[0] === 409 && code($r) === 'TAG_EXISTS' && ($r[1]['error']['details'][0]['tag_id'] ?? 0) === $tagId, why($r));
check('大小寫不同也算重複 → 409', $t1->call('POST', '/tags', ['name' => '[SMOKE] tag'], $t1C)[0] === 409);
check('前後空白會被忽略 → 409', $t1->call('POST', '/tags', ['name' => '  [smoke] Tag  '], $t1C)[0] === 409);
check('空白名稱 → 400', $t1->call('POST', '/tags', ['name' => '   '], $t1C)[0] === 400);
check('超過 50 字 → 400', $t1->call('POST', '/tags', ['name' => '[smoke]' . str_repeat('字', 50)], $t1C)[0] === 400);
check('另一位老師也能用同一個標籤（標籤是全站共用的）', $t2->call('POST', '/tags', ['name' => '[smoke] tag'], $t2C)[0] === 409);
$Qt = $mkQ($c1, 'single', 'Tagged', opts(['甲', true], ['乙', false]), ['[smoke] Tag']);
$list = $t1->call('GET', '/tags?q=' . rawurlencode('[smoke]'));
$found = array_column($list[1]['data'] ?? [], null, 'tag_id')[$tagId] ?? [];
check('出題時使用標籤後，列表的 question_count=1（同一個標籤，不會重複建立）', ($found['question_count'] ?? 0) === 1 && count($list[1]['data']) === 1, why($list));
check('?q 搜尋（含 % _ 這類萬用字元也當成一般字元）', ($t1->call('GET', '/tags?q=' . rawurlencode('%'))[1]['meta']['total'] ?? -1) === 0);
check('列表有分頁 meta', isset($list[1]['meta']['total'], $list[1]['meta']['per_page']));
check('老師刪標籤 → 403（只有管理員）', $t1->call('DELETE', "/tags/{$tagId}", null, $t1C)[0] === 403);
if ($admin !== null) {
    check('標籤不存在 → 404', $admin[0]->call('DELETE', '/tags/99999999', null, $admin[1])[0] === 404);
    check('管理員刪標籤 → 204', $admin[0]->call('DELETE', "/tags/{$tagId}", null, $admin[1])[0] === 204);
    check('刪標籤只移除關聯，題目還在', $t1->call('GET', "/questions/{$Qt}")[0] === 200 && ($t1->call('GET', "/questions/{$Qt}")[1]['data']['tags'] ?? null) === []);
    check('再刪一次 → 404', $admin[0]->call('DELETE', "/tags/{$tagId}", null, $admin[1])[0] === 404);
} else {
    echo "  （沒有管理員密碼，略過刪除標籤的項目）\n";
}

// ================================================================== F-17 使用者管理
echo "\n[F-17] 使用者管理\n";
check('未登入 → 401', (new Client($base))->call('GET', '/admin/users')[0] === 401);
check('學生 → 403', $s1->call('GET', '/admin/users')[0] === 403);
check('老師 → 403', $t1->call('GET', '/admin/users')[0] === 403);
check('老師建立帳號 → 403', $t1->call('POST', '/admin/users', ['username' => 'x', 'email' => 'smoke+x@example.test', 'role' => 'teacher'], $t1C)[0] === 403);

if ($admin === null) {
    echo "  （沒有管理員密碼，略過使用者管理的項目。設定 ADMIN_PASSWORD 後重跑即可）\n";
} else {
    [$ad, $adC] = $admin;
    $r = $ad->call('GET', '/admin/users?per_page=100');
    $d = $r[1]['data'] ?? [];
    check('列表 → 200，附分頁 meta，包含停用狀態與建立時間', $r[0] === 200 && count($d) >= 20 && isset($d[0]['is_active'], $d[0]['created_at'], $r[1]['meta']['total']), why($r));
    check('回應裡沒有 password_hash', !str_contains(json_encode($r[1]), 'password_hash'));
    check('?role=teacher 篩選：全部都是老師', ($x = $ad->call('GET', '/admin/users?role=teacher')[1]['data']) !== [] && array_unique(array_column($x, 'role')) === ['teacher']);
    check('?q 搜尋 email：找得到剛註冊的 smoke 學生', count($ad->call('GET', '/admin/users?q=smoke%2B')[1]['data']) >= 5);
    check('?is_active=0 篩選：沒有啟用中的人', !in_array(true, array_column($ad->call('GET', '/admin/users?is_active=0')[1]['data'], 'is_active'), true));
    check('role 不合法 → 400', $ad->call('GET', '/admin/users?role=boss')[0] === 400);

    $mail = strtolower('smoke+newt' . bin2hex(random_bytes(2)) . '@example.test');
    $r = $ad->call('POST', '/admin/users', ['username' => '[smoke] 新老師', 'email' => strtoupper($mail), 'role' => 'teacher'], $adC);
    $d = $r[1]['data'] ?? [];
    $newT = (int) ($d['user']['user_id'] ?? 0);
    $temp = (string) ($d['temp_password'] ?? '');
    check('建立老師帳號 → 201，附臨時密碼，標記須先改密碼，email 轉小寫', $r[0] === 201 && $newT > 0 && strlen($temp) >= 12
        && ($d['user']['must_change_password'] ?? null) === true && $d['user']['role'] === 'teacher' && $d['user']['email'] === $mail, why($r));
    check('回應不含 password_hash；資料庫存的是雜湊，不是明碼', !str_contains(json_encode($r[1]), 'password_hash')
        && ($h = (string) $pdo->query("SELECT password_hash FROM users WHERE user_id = {$newT}")->fetchColumn()) !== $temp && password_verify($temp, $h));
    check('重複 email → 409 EMAIL_TAKEN', code($ad->call('POST', '/admin/users', ['username' => 'x', 'email' => $mail, 'role' => 'teacher'], $adC)) === 'EMAIL_TAKEN');
    check('role=student → 400（學生請自行註冊）', $ad->call('POST', '/admin/users', ['username' => 'x', 'email' => 'smoke+s@example.test', 'role' => 'student'], $adC)[0] === 400);
    check('email 格式不對、缺姓名 → 400', $ad->call('POST', '/admin/users', ['email' => 'abc', 'role' => 'teacher'], $adC)[0] === 400);

    $nt = new Client($base);
    $r = $nt->call('POST', '/auth/login', ['email' => $mail, 'password' => $temp]);
    $ntC = (string) ($r[1]['data']['csrf_token'] ?? '');
    check('新老師用臨時密碼登入 → 200，必須先改密碼', $r[0] === 200 && ($r[1]['data']['must_change_password'] ?? null) === true, why($r));
    $r = $nt->call('GET', '/courses');
    check('改密碼前其他功能被擋 → 403 PASSWORD_CHANGE_REQUIRED', $r[0] === 403 && code($r) === 'PASSWORD_CHANGE_REQUIRED', why($r));
    $newPw = 'NewTeacher#' . bin2hex(random_bytes(3));
    $nt->call('PUT', '/me/password', ['old_password' => $temp, 'new_password' => $newPw], $ntC);
    $ntC = (string) $nt->call('GET', '/me')[1]['data']['csrf_token'];
    $ntCourse = $idOf($nt->call('POST', '/courses', ['name' => '[smoke] 新老師的課'], $ntC), 'course_id');
    check('改完密碼後可以正常開課', $ntCourse > 0);

    echo "  -- 停用、啟用、改角色\n";
    $r = $ad->call('PATCH', "/admin/users/{$newT}", ['role' => 'student'], $adC);
    check('有開課的老師改成學生 → 409 ROLE_IN_USE', $r[0] === 409 && code($r) === 'ROLE_IN_USE' && ($r[1]['error']['details'][0]['courses'] ?? 0) === 1, why($r));
    $r = $ad->call('PATCH', "/admin/users/{$s5Id}", ['role' => 'teacher'], $adC);
    check('有修課與作答紀錄的學生改成老師 → 409 ROLE_IN_USE', $r[0] === 409 && code($r) === 'ROLE_IN_USE', why($r));
    check('被擋下的角色變更沒有生效', (string) $pdo->query("SELECT role FROM users WHERE user_id = {$s5Id}")->fetchColumn() === 'student');
    [$fresh, $freshC, $freshId, $freshMail] = registerStudent($base, 'F');
    $r = $ad->call('PATCH', "/admin/users/{$freshId}", ['role' => 'teacher'], $adC);
    check('沒有歷史資料的學生可以改成老師 → 200', $r[0] === 200 && ($r[1]['data']['user']['role'] ?? '') === 'teacher', why($r));
    check('改成管理員、再改回老師（兩者權限一致，直接換）→ 200', $ad->call('PATCH', "/admin/users/{$freshId}", ['role' => 'admin'], $adC)[0] === 200
        && $ad->call('PATCH', "/admin/users/{$freshId}", ['role' => 'teacher'], $adC)[0] === 200);

    $r = $ad->call('PATCH', "/admin/users/{$s3Id}", ['is_active' => false], $adC);
    check('停用學生 → 200，is_active=false', $r[0] === 200 && ($r[1]['data']['user']['is_active'] ?? null) === false, why($r));
    $r = $s3->call('GET', '/me/attempts');
    check('停用後，他已登入的連線立刻失效 → 403 ACCOUNT_DISABLED', $r[0] === 403 && code($r) === 'ACCOUNT_DISABLED', why($r));
    check('停用中的帳號不能登入 → 403 ACCOUNT_DISABLED', code((new Client($base))->call('POST', '/auth/login', ['email' => $s3Mail, 'password' => $s3Pw])) === 'ACCOUNT_DISABLED');
    check('資料庫：停用只改旗標，作答紀錄都還在（不實體刪除）', (int) $pdo->query("SELECT COUNT(*) FROM exam_attempts WHERE user_id = {$s3Id}")->fetchColumn() === 2);
    check('重新啟用 → 200', ($ad->call('PATCH', "/admin/users/{$s3Id}", ['is_active' => true], $adC)[1]['data']['user']['is_active'] ?? null) === true);
    check('啟用後可以重新登入（先前的連線已在停用時被登出）', (new Client($base))->call('POST', '/auth/login', ['email' => $s3Mail, 'password' => $s3Pw])[0] === 200);

    check('不能停用自己 → 409 CANNOT_MODIFY_SELF', code($ad->call('PATCH', "/admin/users/{$adminId}", ['is_active' => false], $adC)) === 'CANNOT_MODIFY_SELF');
    check('不能降級自己 → 409 CANNOT_MODIFY_SELF', code($ad->call('PATCH', "/admin/users/{$adminId}", ['role' => 'teacher'], $adC)) === 'CANNOT_MODIFY_SELF');
    check('資料庫：自己的角色與狀態沒被動到', $pdo->query("SELECT CONCAT(role, is_active) FROM users WHERE user_id = {$adminId}")->fetchColumn() === 'admin1');
    check('沒給任何欄位 → 400', $ad->call('PATCH', "/admin/users/{$s3Id}", [], $adC)[0] === 400);
    check('role 不合法 → 400', $ad->call('PATCH', "/admin/users/{$s3Id}", ['role' => 'boss'], $adC)[0] === 400);
    check('使用者不存在 → 404；ID 不合法 → 404', $ad->call('PATCH', '/admin/users/99999999', ['is_active' => false], $adC)[0] === 404 && $ad->call('PATCH', '/admin/users/abc', ['is_active' => false], $adC)[0] === 404);
    check('老師（非管理員）改別人角色 → 403', $t1->call('PATCH', "/admin/users/{$s3Id}", ['role' => 'teacher'], $t1C)[0] === 403);

    // 第二位管理員：A2 可以停用／降級第一位以外的管理員；而「最後一位」的規則靠鎖保證（見併發測試）
    $mail2 = strtolower('smoke+adm' . bin2hex(random_bytes(2)) . '@example.test');
    $r = $ad->call('POST', '/admin/users', ['username' => '[smoke] 第二管理員', 'email' => $mail2, 'role' => 'admin'], $adC);
    $a2 = (int) ($r[1]['data']['user']['user_id'] ?? 0);
    check('建立第二位管理員 → 201', $r[0] === 201 && $a2 > 0, why($r));
    $r = $ad->call('PATCH', "/admin/users/{$a2}", ['is_active' => false], $adC);
    check('停用第二位管理員（還有別的管理員）→ 200', $r[0] === 200, why($r));
    $r = $ad->call('PATCH', "/admin/users/{$a2}", ['role' => 'teacher'], $adC);
    check('已停用的管理員改成老師也可以 → 200', $r[0] === 200 && $r[1]['data']['user']['role'] === 'teacher', why($r));
    $nt->call('POST', '/auth/logout', null, $ntC);
    $r = $nt->call('POST', '/auth/login', ['email' => $mail, 'password' => $newPw]);
    check('新老師仍可正常登入', $r[0] === 200);
    $ad->call('PATCH', "/admin/users/{$newT}", ['is_active' => false], $adC);
    $r = (new Client($base))->call('POST', '/auth/login', ['email' => $mail, 'password' => $newPw]);
    check('停用的帳號登入 → 403 ACCOUNT_DISABLED', $r[0] === 403 && code($r) === 'ACCOUNT_DISABLED', why($r));
}

smokeCleanup($pdo);
$left = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM users WHERE email LIKE 'smoke+%@example.test') + (SELECT COUNT(*) FROM courses WHERE name LIKE '[smoke]%') + (SELECT COUNT(*) FROM tags WHERE name LIKE '[smoke]%')")->fetchColumn();
check('測試資料已清除', $left === 0, "剩 {$left} 筆");
exit(summary());
