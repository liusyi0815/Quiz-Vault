<?php
declare(strict_types=1);

/** 冒煙測試共用的小工具：HTTP 客戶端（自動保存 Cookie）、check()、取得測試帳號密碼 */

final class Client
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'smoke');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{0:int,1:array,2:string} [HTTP 狀態碼, 解析後的 JSON, 原始標頭] */
    public function call(string $method, string $path, ?array $body = null, ?string $csrf = null): array
    {
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $csrf;
        }
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            fwrite(STDERR, "連線失敗：" . curl_error($ch) . "\n請確認伺服器已啟動，且網址正確（目前：{$this->base}）\n");
            exit(2);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$status, json_decode(substr($raw, $size), true) ?? [], substr($raw, 0, $size)];
    }
}

$GLOBALS['smoke_pass'] = 0;
$GLOBALS['smoke_fail'] = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    $ok ? $GLOBALS['smoke_pass']++ : $GLOBALS['smoke_fail']++;
    echo ($ok ? "  ✔ " : "  ✘ ") . $name . ($ok || $detail === '' ? '' : "   ← {$detail}") . "\n";
}

/** 錯誤回應的 error.code */
function code(array $res): string
{
    return $res[1]['error']['code'] ?? '';
}

/** 失敗時方便除錯的簡短描述 */
function why(array $res): string
{
    return "HTTP {$res[0]} " . substr(json_encode($res[1], JSON_UNESCAPED_UNICODE), 0, 160);
}

/**
 * 取得測試帳號的密碼：先看環境變數（例如 TEACHER1_PASSWORD），
 * 再從 credentials-private.csv 找（可用 CREDENTIALS_CSV 指定路徑，或放在專案根目錄／上一層）。
 */
function testPassword(string $envPrefix, string $email, string $root): ?string
{
    $fromEnv = getenv($envPrefix . '_PASSWORD');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    $candidates = array_filter([
        getenv('CREDENTIALS_CSV') ?: null,
        $root . '/credentials-private.csv',
        dirname($root) . '/credentials-private.csv',
    ]);
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $fh = fopen($path, 'rb');
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if ($header === false) {
            continue;
        }
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");           // 去掉 Excel 的 BOM
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $r = array_combine($header, array_pad($row, count($header), ''));
            if (($r['email'] ?? '') === $email && str_starts_with($r['environment'] ?? '', 'demo')) {
                fclose($fh);
                return $r['password'];
            }
        }
        fclose($fh);
    }
    return null;
}

function summary(): int
{
    echo "\n結果：通過 {$GLOBALS['smoke_pass']} 項，失敗 {$GLOBALS['smoke_fail']} 項\n";
    return $GLOBALS['smoke_fail'] === 0 ? 0 : 1;
}

/** 登入並回傳 [Client, csrf_token, user_id]；失敗直接結束 */
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

/** 註冊一位測試學生並登入，回傳 [Client, csrf_token, user_id, email] */
function registerStudent(string $base, string $tag): array
{
    $c = new Client($base);
    $email = strtolower('smoke+' . $tag . bin2hex(random_bytes(2)) . '@example.test');   // 系統會把 email 轉成小寫
    $pw = 'SmokeTest#' . bin2hex(random_bytes(3));
    $c->call('POST', '/auth/register', ['username' => "冒煙學生{$tag}", 'email' => $email, 'password' => $pw]);
    $r = $c->call('POST', '/auth/login', ['email' => $email, 'password' => $pw]);
    return [$c, (string) $r[1]['data']['csrf_token'], (int) $r[1]['data']['user']['user_id'], $email];
}

/** opts(['A', true], ['B', false]) → 題目選項陣列 */
function opts(array ...$list): array
{
    return array_map(static fn (array $o): array => ['content' => $o[0], 'is_correct' => $o[1]], $list);
}

/** 現在加減幾秒後的 ISO 8601 時間（含時區） */
function isoAt(int $offsetSeconds): string
{
    $tz = new DateTimeZone(App\Core\Config::get('APP_TIMEZONE', 'Asia/Taipei'));
    return (new DateTimeImmutable('now', $tz))->modify(($offsetSeconds >= 0 ? '+' : '') . $offsetSeconds . ' seconds')->format('c');
}

/**
 * 清除所有冒煙測試資料：以 [smoke] 開頭的課程（連同章節、題目、考卷、作答、修課）、
 * smoke+...@example.test 的使用者、[smoke] 開頭的標籤。需要 .env 的資料庫帳號有 DELETE 權限。
 */
function smokeCleanup(PDO $pdo): void
{
    $courses = "(SELECT course_id FROM courses WHERE name LIKE '[smoke]%')";
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM exam_attempts WHERE exam_id IN (SELECT exam_id FROM exams WHERE course_id IN {$courses} OR title LIKE '[smoke]%')");
    $pdo->exec("DELETE FROM exams WHERE course_id IN {$courses} OR title LIKE '[smoke]%'");
    $pdo->exec("DELETE q FROM questions q JOIN chapters ch ON ch.chapter_id = q.chapter_id WHERE ch.course_id IN {$courses}");
    $pdo->exec("DELETE FROM courses WHERE name LIKE '[smoke]%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'smoke+%@example.test'");
    $pdo->exec("DELETE FROM tags WHERE name LIKE '[smoke]%'");
    $pdo->commit();
}

/** 回應的 data 裡有這個欄位，而且值是 null（?? 會把 null 當成「沒有」，所以不能用它判斷） */
function isNullField(array $res, string $key): bool
{
    return array_key_exists($key, $res[1]['data'] ?? []) && $res[1]['data'][$key] === null;
}

