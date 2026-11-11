<?php
declare(strict_types=1);

/**
 * M2 冒煙測試：課程(F-03)、修課與退選(F-04)、章節(F-05)、題庫(F-06)。
 *
 * 需要三個老師／管理員帳號（來自 seed.sql）：teacher1、teacher2，管理員為選用。
 * 密碼會依序從這些地方找：環境變數 TEACHER1_PASSWORD / TEACHER2_PASSWORD / ADMIN_PASSWORD，
 * 或 credentials-private.csv（放在專案根目錄或上一層，或用 CREDENTIALS_CSV 指定路徑）。
 *
 * 用法：
 *   php tests/smoke_m2.php
 *   php tests/smoke_m2.php http://localhost/quiz-system/public/api/v1
 *
 * 測試資料都以 [smoke] 開頭，結束時會透過 .env 的資料庫帳號自動清除（需要 DELETE 權限）。
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

function loginAs(string $base, string $email, string $password): array
{
    $c = new Client($base);
    $r = $c->call('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    if ($r[0] !== 200) {
        fwrite(STDERR, "登入失敗：{$email}（HTTP {$r[0]} " . ($r[1]['error']['code'] ?? '') . "）\n");
        exit(2);
    }
    return [$c, (string) $r[1]['data']['csrf_token'], (int) $r[1]['data']['user']['user_id']];
}

function registerStudent(string $base, string $tag): array
{
    $c = new Client($base);
    $email = strtolower('smoke+' . $tag . bin2hex(random_bytes(2)) . '@example.test');   // 系統會把 email 轉成小寫
    $pw = 'SmokeTest#' . bin2hex(random_bytes(3));
    $c->call('POST', '/auth/register', ['username' => "冒煙學生{$tag}", 'email' => $email, 'password' => $pw]);
    $r = $c->call('POST', '/auth/login', ['email' => $email, 'password' => $pw]);
    return [$c, (string) $r[1]['data']['csrf_token'], (int) $r[1]['data']['user']['user_id'], $email];
}

function opts(array ...$list): array
{
    return array_map(static fn (array $o): array => ['content' => $o[0], 'is_correct' => $o[1]], $list);
}

$pdo = App\Core\Database::connection();
$cleanup = static function () use ($pdo): void {
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM exams WHERE title LIKE '[smoke]%'");
    $pdo->exec("DELETE q FROM questions q JOIN chapters ch ON ch.chapter_id = q.chapter_id JOIN courses c ON c.course_id = ch.course_id WHERE c.name LIKE '[smoke]%'");
    $pdo->exec("DELETE FROM courses WHERE name LIKE '[smoke]%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'smoke+%@example.test'");
    $pdo->exec("DELETE FROM tags WHERE name LIKE '[smoke]%'");
    $pdo->commit();
};
$cleanup();   // 先清掉上次可能殘留的測試資料

[$t1, $t1C, $t1Id] = loginAs($base, $accounts['TEACHER1'], $passwords['TEACHER1']);
[$t2, $t2C, $t2Id] = loginAs($base, $accounts['TEACHER2'], $passwords['TEACHER2']);
[$s1, $s1C, $s1Id, $s1Email] = registerStudent($base, 'A');
[$s2, $s2C, $s2Id] = registerStudent($base, 'B');
$admin = null;
if ($passwords['ADMIN'] !== null) {
    $adminLogin = new Client($base);
    $r = $adminLogin->call('POST', '/auth/login', ['email' => $accounts['ADMIN'], 'password' => $passwords['ADMIN']]);
    if ($r[0] === 200 && ($r[1]['data']['must_change_password'] ?? true) === false) {
        $admin = [$adminLogin, (string) $r[1]['data']['csrf_token'], (int) $r[1]['data']['user']['user_id']];
    }
}

// ======================================================================
echo "\n[F-03] 課程管理\n";
$r = $s1->call('POST', '/courses', ['name' => '[smoke] 學生不能開課'], $s1C);
check('學生開課 → 403 FORBIDDEN', $r[0] === 403 && code($r) === 'FORBIDDEN');
$r = $t1->call('POST', '/courses', ['name' => ' '], $t1C);
check('課程名稱空白 → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED');
$r = $t1->call('POST', '/courses', ['name' => '[smoke] 課程A', 'description' => '冒煙測試用', 'teacher_id' => $t2Id], $t1C);
$A = (int) ($r[1]['data']['course_id'] ?? 0);
check('老師開課 → 201，開課老師是自己（忽略前端傳的 teacher_id）',
    $r[0] === 201 && ($r[1]['data']['teacher']['user_id'] ?? 0) === $t1Id && ($r[1]['data']['can_manage'] ?? false) === true, why($r));
check('建立時間為 ISO 8601（含時區）', (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', (string) ($r[1]['data']['created_at'] ?? '')));
check('老師看得到 student_count，學生看不到', isset($r[1]['data']['student_count']));
$r = $t2->call('POST', '/courses', ['name' => '[smoke] 課程B'], $t2C);
$B = (int) ($r[1]['data']['course_id'] ?? 0);
check('另一位老師開課 → 201', $r[0] === 201 && $B > 0);
$r = $t1->call('POST', '/courses', ['name' => '[smoke] 課程C（等一下刪除）'], $t1C);
$C = (int) ($r[1]['data']['course_id'] ?? 0);
if ($admin) {
    [$a, $aC, $aId] = $admin;
    $r = $a->call('POST', '/courses', ['name' => '[smoke] 管理員代開', 'teacher_id' => $t2Id], $aC);
    check('管理員可代指定開課老師 → 201', $r[0] === 201 && ($r[1]['data']['teacher']['user_id'] ?? 0) === $t2Id, why($r));
    $r = $a->call('POST', '/courses', ['name' => '[smoke] 錯誤的老師', 'teacher_id' => $s1Id], $aC);
    check('teacher_id 指向學生 → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED');
} else {
    echo "  - 略過管理員檢查（找不到管理員密碼，或該帳號須先改密碼）\n";
}

$r = $s1->call('GET', '/courses?q=' . rawurlencode('[smoke]'));
$names = array_column($r[1]['data'] ?? [], 'name');
check('學生瀏覽課程列表，可用名稱搜尋', $r[0] === 200 && in_array('[smoke] 課程A', $names, true) && ($r[1]['meta']['total'] ?? 0) >= 3, why($r));
$courseA = array_values(array_filter($r[1]['data'] ?? [], static fn ($c) => $c['course_id'] === $A))[0] ?? [];
check('學生看到 enrolled=false、can_manage=false', ($courseA['enrolled'] ?? null) === false && ($courseA['can_manage'] ?? null) === false);
$r = $s1->call('GET', '/courses?q=' . rawurlencode('[smoke]') . '&per_page=1&page=2');
check('分頁：per_page=1&page=2 → 1 筆，meta 正確', count($r[1]['data'] ?? []) === 1 && ($r[1]['meta']['page'] ?? 0) === 2 && ($r[1]['meta']['per_page'] ?? 0) === 1);
$r = $s1->call('GET', '/courses?q=%25');
check('搜尋字串的 % 當成一般字元，不會比對到全部', $r[0] === 200 && ($r[1]['meta']['total'] ?? -1) === 0, why($r));
$r = $s1->call('GET', "/courses/{$A}");
check('學生看課程詳情 → 200，沒有 student_count', $r[0] === 200 && !array_key_exists('student_count', $r[1]['data'] ?? []) && ($r[1]['data']['chapter_count'] ?? -1) === 0);
$r = $t1->call('GET', "/courses/{$A}");
check('課程擁有者看詳情 → 有 student_count、question_count、exam_count', isset($r[1]['data']['student_count'], $r[1]['data']['question_count'], $r[1]['data']['exam_count']));
check('課程 ID 不合法或不存在 → 404', $s1->call('GET', '/courses/abc')[0] === 404 && $s1->call('GET', '/courses/99999999')[0] === 404);

$r = $t2->call('PATCH', "/courses/{$A}", ['name' => '[smoke] 偷改'], $t2C);
check('別的老師修改我的課程 → 403 FORBIDDEN', $r[0] === 403 && code($r) === 'FORBIDDEN');
$r = $s1->call('PATCH', "/courses/{$A}", ['name' => '[smoke] 偷改'], $s1C);
check('學生修改課程 → 403 FORBIDDEN', $r[0] === 403);
$r = $t1->call('PATCH', "/courses/{$A}", ['name' => '[smoke] 課程A（已改名）'], $t1C);
check('擁有者修改課程名稱 → 200', $r[0] === 200 && ($r[1]['data']['name'] ?? '') === '[smoke] 課程A（已改名）');
$r = $t1->call('PATCH', "/courses/{$A}", ['description' => null], $t1C);
check('description 傳 null 可以清空', $r[0] === 200 && array_key_exists('description', $r[1]['data']) && $r[1]['data']['description'] === null, why($r));
$r = $t1->call('PATCH', "/courses/{$A}", [], $t1C);
check('什麼都沒給 → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED');

// ======================================================================
echo "\n[F-04] 修課與退選\n";
$r = $s1->call('POST', "/courses/{$A}/enrollment", null, $s1C);
check('學生加入課程 → 201', $r[0] === 201 && ($r[1]['data']['course_id'] ?? 0) === $A, why($r));
$r = $s1->call('POST', "/courses/{$A}/enrollment", null, $s1C);
check('重複加入 → 409 ALREADY_ENROLLED', $r[0] === 409 && code($r) === 'ALREADY_ENROLLED');
check('老師不能修課 → 403 FORBIDDEN', $t1->call('POST', "/courses/{$A}/enrollment", null, $t1C)[0] === 403);
check('加入不存在的課程 → 404', $s1->call('POST', '/courses/99999999/enrollment', null, $s1C)[0] === 404);
$r = $s1->call('GET', "/courses/{$A}");
check('詳情顯示 enrolled=true', ($r[1]['data']['enrolled'] ?? null) === true);
$r = $s1->call('GET', '/courses?mine=1');
check('mine=1：學生只看到自己修的課', ($r[1]['meta']['total'] ?? -1) === 1 && ($r[1]['data'][0]['course_id'] ?? 0) === $A, why($r));
$r = $t1->call('GET', '/courses?mine=1&q=' . rawurlencode('[smoke]'));
check('mine=1：老師只看到自己開的課', ($r[1]['meta']['total'] ?? -1) === 2, why($r));

$r = $t1->call('GET', "/courses/{$A}/students");
$emails = array_column($r[1]['data'] ?? [], 'email');
check('擁有者看修課名單', $r[0] === 200 && in_array($s1Email, $emails, true), why($r));
$r = $t1->call('GET', "/courses/{$A}/students?q=" . rawurlencode(substr($s1Email, 0, 12)));
check('修課名單可搜尋', ($r[1]['meta']['total'] ?? 0) === 1);
check('別的老師看我的修課名單 → 404（名單不公開）', $t2->call('GET', "/courses/{$A}/students")[0] === 404);
check('學生看修課名單 → 403', $s1->call('GET', "/courses/{$A}/students")[0] === 403);
if ($admin) {
    check('管理員可以看任何課程的修課名單', $admin[0]->call('GET', "/courses/{$A}/students")[0] === 200);
}

$s2->call('POST', "/courses/{$A}/enrollment", null, $s2C);
check('別的老師移除我課程的學生 → 404', $t2->call('DELETE', "/courses/{$A}/students/{$s2Id}", null, $t2C)[0] === 404);
check('擁有者移除學生 → 204', $t1->call('DELETE', "/courses/{$A}/students/{$s2Id}", null, $t1C)[0] === 204);
check('再移除一次 → 404', $t1->call('DELETE', "/courses/{$A}/students/{$s2Id}", null, $t1C)[0] === 404);
check('學生退選 → 204', $s1->call('DELETE', "/courses/{$A}/enrollment", null, $s1C)[0] === 204);
$r = $s1->call('DELETE', "/courses/{$A}/enrollment", null, $s1C);
check('沒修的課再退選 → 403 NOT_ENROLLED', $r[0] === 403 && code($r) === 'NOT_ENROLLED');
$s1->call('POST', "/courses/{$A}/enrollment", null, $s1C);      // 之後測「刪課連動刪修課紀錄」

// ======================================================================
echo "\n[F-05] 章節管理\n";
$ch = [];
foreach (['第一章', '第二章', '第三章'] as $i => $name) {
    $r = $t1->call('POST', "/courses/{$A}/chapters", ['name' => $name], $t1C);
    $ch[] = (int) ($r[1]['data']['chapter_id'] ?? 0);
    check("新增「{$name}」→ 201，sort_order 自動為 " . ($i + 1), $r[0] === 201 && ($r[1]['data']['sort_order'] ?? 0) === $i + 1, why($r));
}
[$c1, $c2, $c3] = $ch;
check('別的老師新增章節 → 403', $t2->call('POST', "/courses/{$A}/chapters", ['name' => 'x'], $t2C)[0] === 403);
check('學生新增章節 → 403', $s1->call('POST', "/courses/{$A}/chapters", ['name' => 'x'], $s1C)[0] === 403);
check('章節名稱空白 → 400', $t1->call('POST', "/courses/{$A}/chapters", ['name' => ''], $t1C)[0] === 400);
check('在不存在的課程新增章節 → 404', $t1->call('POST', '/courses/99999999/chapters', ['name' => 'x'], $t1C)[0] === 404);
$r = $s1->call('GET', "/courses/{$A}/chapters");
check('學生看章節列表（依順序），沒有 question_count', $r[0] === 200 && array_column($r[1]['data'], 'name') === ['第一章', '第二章', '第三章'] && !isset($r[1]['data'][0]['question_count']), why($r));
$r = $t1->call('GET', "/courses/{$A}/chapters");
check('老師看章節列表，多了 question_count', isset($r[1]['data'][0]['question_count']));

$r = $t1->call('PUT', "/courses/{$A}/chapters/order", ['chapter_ids' => [$c3, $c1, $c2]], $t1C);
check('調整順序 → 200，順序已改變', $r[0] === 200 && array_column($r[1]['data'], 'chapter_id') === [$c3, $c1, $c2]
    && array_column($r[1]['data'], 'sort_order') === [1, 2, 3], why($r));
check('順序缺少章節 → 400', $t1->call('PUT', "/courses/{$A}/chapters/order", ['chapter_ids' => [$c1, $c2]], $t1C)[0] === 400);
check('順序有重複 → 400', $t1->call('PUT', "/courses/{$A}/chapters/order", ['chapter_ids' => [$c1, $c1, $c2]], $t1C)[0] === 400);
check('別的老師調整順序 → 403', $t2->call('PUT', "/courses/{$A}/chapters/order", ['chapter_ids' => [$c1, $c2, $c3]], $t2C)[0] === 403);

$r = $t1->call('PATCH', "/chapters/{$c1}", ['name' => '第一章（改名）'], $t1C);
check('修改章節名稱 → 200', $r[0] === 200 && ($r[1]['data']['name'] ?? '') === '第一章（改名）');
check('別的老師修改章節 → 403', $t2->call('PATCH', "/chapters/{$c1}", ['name' => 'x'], $t2C)[0] === 403);
check('修改章節沒給欄位 → 400', $t1->call('PATCH', "/chapters/{$c1}", [], $t1C)[0] === 400);
check('別的老師刪除章節 → 403', $t2->call('DELETE', "/chapters/{$c3}", null, $t2C)[0] === 403);
check('刪除空章節 → 204', $t1->call('DELETE', "/chapters/{$c3}", null, $t1C)[0] === 204);
check('刪除不存在的章節 → 404', $t1->call('DELETE', "/chapters/{$c3}", null, $t1C)[0] === 404);
$r = $t1->call('GET', "/courses/{$A}/chapters");
check('刪除後只剩 2 章', ($r[1]['meta']['total'] ?? 0) === 2);

// ======================================================================
echo "\n[F-06] 題庫管理\n";
check('學生看題庫 → 403', $s1->call('GET', '/questions')[0] === 403);

$single = [
    'chapter_id' => $c1, 'type' => 'single', 'content' => '[smoke] 下列哪個指令用來查詢資料？', 'difficulty' => 1,
    'explanation' => 'SELECT 用於查詢。',
    'options' => opts(['SELECT', true], ['INSERT', false], ['UPDATE', false], ['DELETE', false]),
    'tags' => ['SQL', '[smoke]標籤一'],
];
$r = $t1->call('POST', '/questions', $single, $t1C);
$Q1 = (int) ($r[1]['data']['question_id'] ?? 0);
$d = $r[1]['data'] ?? [];
check('新增單選題 → 201，含 4 個選項', $r[0] === 201 && count($d['options'] ?? []) === 4, why($r));
check('老師看得到 is_correct，in_use 為 false', ($d['options'][0]['is_correct'] ?? null) === true && ($d['in_use'] ?? null) === false);
check('標籤自動建立並關聯', array_column($d['tags'] ?? [], 'name') !== [] && count($d['tags'] ?? []) === 2);
check('creator_id 是目前登入的老師', ($d['creator_id'] ?? 0) === $t1Id);

echo "  -- 題型規則 --\n";
$bad = static function (array $override, string $expectCode, string $name) use ($t1, $t1C, $single): void {
    $r = $t1->call('POST', '/questions', $override + $single, $t1C);
    check($name . " → 400 {$expectCode}", $r[0] === 400 && code($r) === $expectCode, why($r));
};
$bad(['options' => opts(['A', true], ['B', true], ['C', false])], 'INVALID_QUESTION_SHAPE', '單選題有 2 個正確答案');
$bad(['options' => opts(['A', false], ['B', false], ['C', false])], 'INVALID_QUESTION_SHAPE', '單選題沒有正確答案');
$bad(['options' => opts(['A', true])], 'INVALID_QUESTION_SHAPE', '單選題只有 1 個選項');
$bad(['options' => opts(['A', true], ['a', false])], 'INVALID_QUESTION_SHAPE', '選項內容重複（不分大小寫）');
$bad(['type' => 'truefalse', 'options' => opts(['Yes', true], ['No', false])], 'INVALID_QUESTION_SHAPE', '是非題選項不是「對」「錯」');
$bad(['type' => 'truefalse', 'options' => opts(['對', true], ['錯', true])], 'INVALID_QUESTION_SHAPE', '是非題兩個都正確');
$bad(['type' => 'multiple', 'options' => opts(['A', true], ['B', false])], 'INVALID_QUESTION_SHAPE', '多選題只有 2 個選項');
$bad(['type' => 'multiple', 'options' => opts(['A', false], ['B', false], ['C', false])], 'INVALID_QUESTION_SHAPE', '多選題沒有正確答案');
$bad(['type' => 'short', 'options' => opts(['A', true])], 'INVALID_QUESTION_SHAPE', '簡答題不可有選項');
$bad(['difficulty' => 5], 'VALIDATION_FAILED', '難度 5');
$bad(['type' => 'essay'], 'VALIDATION_FAILED', '題型不存在');
$bad(['content' => '   '], 'VALIDATION_FAILED', '題幹空白');
$bad(['options' => [['content' => 'A'], ['content' => 'B', 'is_correct' => true]]], 'VALIDATION_FAILED', '選項缺少 is_correct');
$bad(['tags' => array_map(static fn ($i) => "[smoke]t{$i}", range(1, 11))], 'VALIDATION_FAILED', '超過 10 個標籤');
$r = $t1->call('POST', '/questions', ['chapter_id' => 99999999] + $single, $t1C);
check('章節不存在 → 404', $r[0] === 404);
$r = $t2->call('POST', '/questions', $single, $t2C);
check('別的老師在我的章節出題 → 404（題庫不公開）', $r[0] === 404, why($r));
check('驗證失敗時，不會留下任何半成品題目',
    (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE content = '[smoke] 下列哪個指令用來查詢資料？'")->fetchColumn() === 1);

echo "  -- 其他題型 --\n";
$r = $t1->call('POST', '/questions', [
    'chapter_id' => $c1, 'type' => 'multiple', 'content' => '[smoke] 下列哪些是 DML？', 'difficulty' => 2,
    'options' => opts(['SELECT', true], ['INSERT', true], ['CREATE', false]), 'tags' => ['sql', '[smoke]標籤二'],
], $t1C);
$Q2 = (int) ($r[1]['data']['question_id'] ?? 0);
check('新增多選題 → 201（標籤 sql 與 SQL 視為同一個）', $r[0] === 201 && count($r[1]['data']['tags'] ?? []) === 2, why($r));
$r = $t1->call('POST', '/questions', [
    'chapter_id' => $c2, 'type' => 'truefalse', 'content' => '[smoke] WHERE 用來過濾群組。', 'difficulty' => 1,
    'options' => opts(['對', false], ['錯', true]),
], $t1C);
$Q3 = (int) ($r[1]['data']['question_id'] ?? 0);
check('新增是非題 → 201', $r[0] === 201 && ($r[1]['data']['type'] ?? '') === 'truefalse', why($r));
$r = $t1->call('POST', '/questions', ['chapter_id' => $c2, 'type' => 'short', 'content' => '[smoke] 說明 WHERE 與 HAVING 的差異。', 'difficulty' => 3], $t1C);
$Q4 = (int) ($r[1]['data']['question_id'] ?? 0);
check('新增簡答題（不給選項）→ 201，options 為空', $r[0] === 201 && ($r[1]['data']['options'] ?? null) === [], why($r));
$tagsInDb = (int) $pdo->query("SELECT COUNT(*) FROM tags WHERE name = 'SQL'")->fetchColumn();
check('標籤沒有重複建立（SQL 只有一筆）', $tagsInDb === 1);

echo "  -- 查詢 --\n";
$q = static fn (string $qs) => $t1->call('GET', "/questions?course_id={$A}" . $qs);
check('列表：課程篩選 → 4 題', ($q('')[1]['meta']['total'] ?? 0) === 4);
check('type=multiple → 1 題', ($q('&type=multiple')[1]['meta']['total'] ?? 0) === 1);
check('difficulty=3 → 1 題', ($q('&difficulty=3')[1]['meta']['total'] ?? 0) === 1);
check('chapter_id 篩選 → 該章 2 題', ($q("&chapter_id={$c2}")[1]['meta']['total'] ?? 0) === 2);
check('tag=SQL → 2 題（單選與多選）', ($q('&tag=SQL')[1]['meta']['total'] ?? 0) === 2);
check('q 搜尋題幹 → 1 題', ($q('&q=' . rawurlencode('HAVING'))[1]['meta']['total'] ?? 0) === 1);
check('is_active=0 → 0 題', ($q('&is_active=0')[1]['meta']['total'] ?? -1) === 0);
$r = $q('&per_page=2&page=2');
check('分頁：每頁 2 筆的第 2 頁 → 2 筆，total=4', count($r[1]['data'] ?? []) === 2 && ($r[1]['meta']['total'] ?? 0) === 4);
check('type 不合法 → 400', $q('&type=essay')[0] === 400);
check('列表項目有標籤、沒有選項', isset($q('')[1]['data'][0]['tags']) && !isset($q('')[1]['data'][0]['options']));
$r = $t2->call('GET', "/questions?course_id={$A}");
check('別的老師查我課程的題庫 → 空清單', $r[0] === 200 && ($r[1]['meta']['total'] ?? -1) === 0);
foreach ([['GET', null], ['PUT', $single], ['PATCH', ['difficulty' => 2]], ['DELETE', null]] as [$m, $body]) {
    check("別的老師 {$m} 我的題目 → 404", $t2->call($m, "/questions/{$Q1}", $body, $t2C)[0] === 404);
}
if ($admin) {
    check('管理員可以看任何題目', $admin[0]->call('GET', "/questions/{$Q1}")[0] === 200);
}
$r = $t1->call('GET', "/questions/{$Q1}");
check('題目詳情含選項與正確答案', $r[0] === 200 && count($r[1]['data']['options']) === 4);
$oldOptionIds = array_column($r[1]['data']['options'], 'option_id');

echo "  -- 整題取代（PUT）--\n";
$put = [
    'chapter_id' => $c2, 'type' => 'single', 'content' => '[smoke] 改寫後的題目', 'difficulty' => 2, 'explanation' => '新解析',
    'options' => opts(['甲', false], ['乙', true], ['丙', false]), 'tags' => ['[smoke]新標籤'],
];
$r = $t1->call('PUT', "/questions/{$Q1}", $put, $t1C);
$d = $r[1]['data'] ?? [];
check('尚未被使用的題目可以整題取代 → 200', $r[0] === 200 && ($d['content'] ?? '') === '[smoke] 改寫後的題目' && count($d['options'] ?? []) === 3, why($r));
check('選項已整組換成新的', array_intersect($oldOptionIds, array_column($d['options'] ?? [], 'option_id')) === []);
check('標籤整組取代', array_column($d['tags'] ?? [], 'name') === ['[smoke]新標籤']);
check('章節可改到同課程的另一章', ($d['chapter']['chapter_id'] ?? 0) === $c2);
check('creator_id 不會被改掉', ($d['creator_id'] ?? 0) === $t1Id);
$r = $t1->call('POST', '/courses/' . $C . '/chapters', ['name' => '別門課的章節'], $t1C);
$cOther = (int) ($r[1]['data']['chapter_id'] ?? 0);
$r = $t1->call('PUT', "/questions/{$Q1}", ['chapter_id' => $cOther] + $put, $t1C);
check('題目不能搬到別門課程 → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED', why($r));
$r = $t1->call('PUT', "/questions/{$Q1}", ['options' => opts(['x', true], ['y', true])] + $put, $t1C);
check('PUT 送出不合法的選項 → 400，且原內容不變', $r[0] === 400
    && count($t1->call('GET', "/questions/{$Q1}")[1]['data']['options']) === 3);

echo "  -- 部分更新（PATCH）與軟刪除 --\n";
$r = $t1->call('PATCH', "/questions/{$Q1}", ['difficulty' => 3, 'explanation' => null, 'tags' => []], $t1C);
$d = $r[1]['data'] ?? [];
check('PATCH 難度、清空解析與標籤 → 200', $r[0] === 200 && ($d['difficulty'] ?? 0) === 3 && array_key_exists('explanation', $d) && $d['explanation'] === null && ($d['tags'] ?? ['x']) === [], why($r));
check('PATCH 不能改題幹 → 400', $t1->call('PATCH', "/questions/{$Q1}", ['content' => 'x'], $t1C)[0] === 400);
check('PATCH 不能改選項 → 400', $t1->call('PATCH', "/questions/{$Q1}", ['options' => []], $t1C)[0] === 400);
check('PATCH 沒給欄位 → 400', $t1->call('PATCH', "/questions/{$Q1}", [], $t1C)[0] === 400);
check('PATCH 的 is_active 型別不對 → 400', $t1->call('PATCH', "/questions/{$Q1}", ['is_active' => 'maybe'], $t1C)[0] === 400);
check('刪除題目（軟刪除）→ 204', $t1->call('DELETE', "/questions/{$Q1}", null, $t1C)[0] === 204);
$r = $t1->call('GET', "/questions/{$Q1}");
check('軟刪除後仍查得到，is_active=false', $r[0] === 200 && ($r[1]['data']['is_active'] ?? null) === false);
check('列表 is_active=0 找得到它', ($q('&is_active=0')[1]['meta']['total'] ?? 0) === 1);
$r = $t1->call('PATCH', "/questions/{$Q1}", ['is_active' => true], $t1C);
check('PATCH is_active=true 可以復原', $r[0] === 200 && ($r[1]['data']['is_active'] ?? null) === true);

echo "  -- 已被使用的題目（docs/04 §4.3）--\n";
$mkExam = static function (string $title, string $status, int $questionId) use ($pdo, $A, $t1Id): int {
    $pdo->prepare('INSERT INTO exams (course_id, creator_id, title, duration_min, status) VALUES (?, ?, ?, 10, ?)')->execute([$A, $t1Id, $title, $status]);
    $examId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO exam_questions (exam_id, question_id, score, sort_order) VALUES (?, ?, 10, 1)')->execute([$examId, $questionId]);
    return $examId;
};
$mkExam('[smoke] 已發佈考卷', 'published', $Q2);
$mkExam('[smoke] 草稿考卷', 'draft', $Q3);
$r2 = $t1->call('GET', "/questions/{$Q2}");
$r3 = $t1->call('GET', "/questions/{$Q3}");
check('出現在已發佈考卷的題目 → in_use=true', ($r2[1]['data']['in_use'] ?? null) === true);
check('只出現在草稿考卷的題目 → in_use=false', ($r3[1]['data']['in_use'] ?? null) === false);
$cur = $r2[1]['data'];
$same = [
    'chapter_id' => $cur['chapter']['chapter_id'], 'type' => $cur['type'], 'content' => $cur['content'],
    'difficulty' => 3, 'explanation' => '使用中仍可改解析',
    'options' => array_map(static fn ($o) => ['content' => $o['content'], 'is_correct' => $o['is_correct']], $cur['options']),
    'tags' => ['[smoke]使用中新標籤'],
];
$r = $t1->call('PUT', "/questions/{$Q2}", $same, $t1C);
check('使用中：題型、題幹、選項不變，只改解析／難度／標籤 → 200', $r[0] === 200 && ($r[1]['data']['explanation'] ?? '') === '使用中仍可改解析', why($r));
check('使用中：選項沒有被重建（option_id 不變）', array_column($r[1]['data']['options'] ?? [], 'option_id') === array_column($cur['options'], 'option_id'));
$r = $t1->call('PUT', "/questions/{$Q2}", ['content' => '[smoke] 偷改題幹'] + $same, $t1C);
check('使用中：改題幹 → 409 QUESTION_IN_USE', $r[0] === 409 && code($r) === 'QUESTION_IN_USE', why($r));
$changed = $same;
$changed['options'][0]['content'] = '被改過的選項';
check('使用中：改選項文字 → 409 QUESTION_IN_USE', $t1->call('PUT', "/questions/{$Q2}", $changed, $t1C)[0] === 409);
$flipped = $same;
$flipped['options'][0]['is_correct'] = !$flipped['options'][0]['is_correct'];
check('使用中：改正確答案 → 409 QUESTION_IN_USE', $t1->call('PUT', "/questions/{$Q2}", $flipped, $t1C)[0] === 409);
check('使用中：PATCH 難度仍可 → 200', $t1->call('PATCH', "/questions/{$Q2}", ['difficulty' => 1], $t1C)[0] === 200);
$r = $t1->call('PUT', "/questions/{$Q3}", [
    'chapter_id' => $c2, 'type' => 'truefalse', 'content' => '[smoke] 草稿中可以改題幹', 'options' => opts(['對', true], ['錯', false]),
], $t1C);
check('只在草稿考卷中的題目 → 可以改題幹 → 200', $r[0] === 200 && ($r[1]['data']['content'] ?? '') === '[smoke] 草稿中可以改題幹', why($r));

// ======================================================================
echo "\n[F-05 / F-03] 刪除的限制\n";
$r = $t1->call('DELETE', "/chapters/{$c1}", null, $t1C);
check('章節底下有題目 → 409 CHAPTER_NOT_EMPTY', $r[0] === 409 && code($r) === 'CHAPTER_NOT_EMPTY', why($r));
$r = $t1->call('GET', "/courses/{$A}/chapters");
$counts = array_column($r[1]['data'], 'question_count', 'chapter_id');
check('章節的 question_count 正確（第一章 1 題、第二章 3 題）', ($counts[$c1] ?? -1) === 1 && ($counts[$c2] ?? -1) === 3, json_encode($counts));
$r = $t1->call('DELETE', "/courses/{$A}", null, $t1C);
check('課程底下有章節與考卷 → 409 COURSE_NOT_EMPTY', $r[0] === 409 && code($r) === 'COURSE_NOT_EMPTY', why($r));
check('別的老師刪除我的課程 → 403', $t2->call('DELETE', "/courses/{$C}", null, $t2C)[0] === 403);
check('課程底下有章節時不能刪除（課程 C 還有一章）', $t1->call('DELETE', "/courses/{$C}", null, $t1C)[0] === 409);
check('先刪除空章節 → 204', $t1->call('DELETE', "/chapters/{$cOther}", null, $t1C)[0] === 204);
check('空課程可以刪除 → 204', $t1->call('DELETE', "/courses/{$C}", null, $t1C)[0] === 204);
check('刪除後查不到 → 404', $t1->call('GET', "/courses/{$C}")[0] === 404);
$s2->call('POST', "/courses/{$B}/enrollment", null, $s2C);
$enrolledBefore = (int) $pdo->query("SELECT COUNT(*) FROM course_enrollments WHERE course_id = {$B}")->fetchColumn();
check('刪除空課程 B（有 1 位學生修課）→ 204', $t2->call('DELETE', "/courses/{$B}", null, $t2C)[0] === 204 && $enrolledBefore === 1);
check('修課紀錄隨課程一併刪除（外鍵 CASCADE）', (int) $pdo->query("SELECT COUNT(*) FROM course_enrollments WHERE course_id = {$B}")->fetchColumn() === 0);

// ======================================================================
$cleanup();
$left = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM courses WHERE name LIKE '[smoke]%') + (SELECT COUNT(*) FROM users WHERE email LIKE 'smoke+%@example.test') + (SELECT COUNT(*) FROM tags WHERE name LIKE '[smoke]%')")->fetchColumn();
check('測試資料已清除', $left === 0);

exit(summary());
