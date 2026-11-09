<?php
declare(strict_types=1);

/**
 * M0 ~ M1 冒煙測試：health、註冊、登入、/me、CSRF、改密碼、登出，以及（選用）F-22 管理員重設密碼。
 *
 * 用法（伺服器要先啟動，例如 php -S localhost:8000 -t public public/router.php）：
 *   php tests/smoke_m1.php
 *   php tests/smoke_m1.php http://localhost/quiz-system/public/api/v1
 *   ADMIN_EMAIL=admin@example.edu.tw ADMIN_PASSWORD=你的管理員密碼 php tests/smoke_m1.php
 *
 * 每次執行會在 users 新增一個 smoke+xxxx@example.test 測試帳號，可用這行清掉：
 *   DELETE FROM users WHERE email LIKE 'smoke+%@example.test';
 */

$base = rtrim($argv[1] ?? 'http://localhost:8000/api/v1', '/');
$adminEmail = getenv('ADMIN_EMAIL') ?: '';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';

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

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ✔ " : "  ✘ ") . $name . ($ok || $detail === '' ? '' : "   ← {$detail}") . "\n";
}
function code(array $res): string
{
    return $res[1]['error']['code'] ?? '';
}

$email = 'smoke+' . bin2hex(random_bytes(3)) . '@example.test';
$pw1 = 'SmokeTest#1' . bin2hex(random_bytes(2));
$pw2 = 'SmokeTest#2' . bin2hex(random_bytes(2));
$s = new Client($base);

echo "\n[M0] 基礎\n";
$r = $s->call('GET', '/health');
check('GET /health → 200 且資料庫正常', $r[0] === 200 && ($r[1]['data']['database'] ?? '') === 'ok', "HTTP {$r[0]} " . json_encode($r[1], JSON_UNESCAPED_UNICODE));
check('回應帶有 Cache-Control: no-store', stripos($r[2], 'cache-control: no-store') !== false);
check('health 不會建立 session cookie', stripos($r[2], 'set-cookie') === false);
$r = $s->call('GET', '/not-exist');
check('不存在的路徑 → 404 NOT_FOUND', $r[0] === 404 && code($r) === 'NOT_FOUND');
$r = $s->call('DELETE', '/health');
check('方法不支援 → 405 METHOD_NOT_ALLOWED', $r[0] === 405 && code($r) === 'METHOD_NOT_ALLOWED');
$r = $s->call('GET', '/me');
check('未登入讀 /me → 401 UNAUTHENTICATED', $r[0] === 401 && code($r) === 'UNAUTHENTICATED');

echo "\n[F-01] 註冊與登入\n";
$r = $s->call('POST', '/auth/register', ['username' => '', 'email' => 'bad', 'password' => '123']);
check('註冊資料不合法 → 400 VALIDATION_FAILED（列出欄位）', $r[0] === 400 && code($r) === 'VALIDATION_FAILED' && count($r[1]['error']['details'] ?? []) === 3);
$r = $s->call('POST', '/auth/register', ['username' => '冒煙測試', 'email' => $email, 'password' => $pw1, 'role' => 'admin']);
$studentId = (int) ($r[1]['data']['user']['user_id'] ?? 0);
check('註冊成功 → 201，且 role 被強制為 student（忽略前端傳的 admin）', $r[0] === 201 && ($r[1]['data']['user']['role'] ?? '') === 'student', "HTTP {$r[0]}");
check('回應不含 password_hash', !str_contains(json_encode($r[1]), 'password_hash'));
$r = $s->call('POST', '/auth/register', ['username' => 'x', 'email' => strtoupper($email), 'password' => $pw1]);
check('Email 重複（不分大小寫）→ 409 EMAIL_TAKEN', $r[0] === 409 && code($r) === 'EMAIL_TAKEN', "HTTP {$r[0]} " . code($r));
$r = $s->call('POST', '/auth/login', ['email' => $email, 'password' => 'wrong-password']);
check('密碼錯誤 → 401 INVALID_CREDENTIALS', $r[0] === 401 && code($r) === 'INVALID_CREDENTIALS');
$r2 = $s->call('POST', '/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever123']);
check('帳號不存在 → 同樣的 INVALID_CREDENTIALS 與訊息（不透露帳號是否存在）', $r2[0] === 401 && ($r2[1]['error']['message'] ?? 'a') === ($r[1]['error']['message'] ?? 'b'));
$r = $s->call('POST', '/auth/login', ['email' => $email, 'password' => $pw1]);
$csrf = (string) ($r[1]['data']['csrf_token'] ?? '');
check('登入成功 → 200、有 csrf_token、must_change_password=false', $r[0] === 200 && strlen($csrf) === 64 && ($r[1]['data']['must_change_password'] ?? null) === false, "HTTP {$r[0]}");
check('session cookie 為 HttpOnly 與 SameSite=Lax', stripos($r[2], 'httponly') !== false && stripos($r[2], 'samesite=lax') !== false);

echo "\n[F-02] 個人資料與 CSRF\n";
$r = $s->call('GET', '/me');
check('GET /me → 200，email 為小寫', $r[0] === 200 && ($r[1]['data']['user']['email'] ?? '') === strtolower($email));
$r = $s->call('PATCH', '/me', ['username' => '改過的名字']);
check('PATCH /me 沒帶 CSRF → 403 CSRF_FAILED', $r[0] === 403 && code($r) === 'CSRF_FAILED');
$r = $s->call('PATCH', '/me', ['username' => '改過的名字'], 'wrong-token');
check('PATCH /me 帶錯誤的 CSRF → 403 CSRF_FAILED', $r[0] === 403 && code($r) === 'CSRF_FAILED');
$r = $s->call('PATCH', '/me', ['username' => '改過的名字'], $csrf);
check('PATCH /me 帶正確 CSRF → 200，姓名已更新', $r[0] === 200 && ($r[1]['data']['user']['username'] ?? '') === '改過的名字');
$r = $s->call('PATCH', '/me', [], $csrf);
check('PATCH /me 什麼都沒給 → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED');

echo "\n[F-02] 修改密碼\n";
$r = $s->call('PUT', '/me/password', ['old_password' => 'wrong-old-pass', 'new_password' => $pw2], $csrf);
check('舊密碼錯誤 → 400 WRONG_PASSWORD', $r[0] === 400 && code($r) === 'WRONG_PASSWORD');
$r = $s->call('PUT', '/me/password', ['old_password' => $pw1, 'new_password' => $pw1], $csrf);
check('新舊密碼相同 → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED');
$r = $s->call('PUT', '/me/password', ['old_password' => $pw1, 'new_password' => 'short'], $csrf);
check('新密碼太短 → 400 VALIDATION_FAILED', $r[0] === 400 && code($r) === 'VALIDATION_FAILED');
$r = $s->call('PUT', '/me/password', ['old_password' => $pw1, 'new_password' => $pw2], $csrf);
check('修改成功 → 200', $r[0] === 200 && ($r[1]['data']['must_change_password'] ?? null) === false, "HTTP {$r[0]} " . code($r));
$r = $s->call('GET', '/me');
check('改密碼後原本的登入仍有效（session id 已更新）', $r[0] === 200);

echo "\n登出\n";
$r = $s->call('POST', '/auth/logout', null, $csrf);
check('POST /auth/logout → 200', $r[0] === 200);
$r = $s->call('GET', '/me');
check('登出後讀 /me → 401', $r[0] === 401);
$r = $s->call('POST', '/auth/login', ['email' => $email, 'password' => $pw1]);
check('舊密碼不能再登入', $r[0] === 401);
$r = $s->call('POST', '/auth/login', ['email' => $email, 'password' => $pw2]);
$csrf = (string) ($r[1]['data']['csrf_token'] ?? '');
check('新密碼可以登入', $r[0] === 200);

echo "\n[權限] 學生不能呼叫管理員端點\n";
$r = $s->call('POST', '/admin/users/' . $studentId . '/reset-password', null, $csrf);
check('學生 POST /admin/.../reset-password → 403 FORBIDDEN', $r[0] === 403 && code($r) === 'FORBIDDEN');
$r = $s->call('POST', '/admin/users/abc/reset-password', null, $csrf);
check('學生呼叫、ID 不合法 → 仍先檢查權限（403）', $r[0] === 403);

if ($adminEmail !== '' && $adminPassword !== '') {
    echo "\n[F-22] 管理員重設密碼\n";
    $a = new Client($base);
    $r = $a->call('POST', '/auth/login', ['email' => $adminEmail, 'password' => $adminPassword]);
    $aCsrf = (string) ($r[1]['data']['csrf_token'] ?? '');
    $adminId = (int) ($r[1]['data']['user']['user_id'] ?? 0);
    if ($r[0] !== 200) {
        check('管理員登入', false, "HTTP {$r[0]} " . code($r));
    } elseif (($r[1]['data']['must_change_password'] ?? false) === true) {
        echo "  - 這個管理員帳號被標記為須先改密碼，請先登入改掉密碼再跑 F-22 測試（略過）\n";
    } else {
        check('管理員登入成功', ($r[1]['data']['user']['role'] ?? '') === 'admin');
        $r = $a->call('POST', '/admin/users/' . $adminId . '/reset-password', null, $aCsrf);
        check('重設自己的密碼 → 409 CANNOT_MODIFY_SELF', $r[0] === 409 && code($r) === 'CANNOT_MODIFY_SELF');
        $r = $a->call('POST', '/admin/users/99999999/reset-password', null, $aCsrf);
        check('重設不存在的使用者 → 404 NOT_FOUND', $r[0] === 404 && code($r) === 'NOT_FOUND');
        $r = $a->call('POST', '/admin/users/' . $studentId . '/reset-password');
        check('管理員沒帶 CSRF → 403 CSRF_FAILED', $r[0] === 403 && code($r) === 'CSRF_FAILED');
        $r = $a->call('POST', '/admin/users/' . $studentId . '/reset-password', null, $aCsrf);
        $temp = (string) ($r[1]['data']['temp_password'] ?? '');
        check('重設成功 → 200，臨時密碼 12 碼、不含易混淆字元', $r[0] === 200 && strlen($temp) === 12 && !preg_match('/[0OIl1]/', $temp), "HTTP {$r[0]} " . code($r));
        check('回應帶有 Cache-Control: no-store', stripos($r[2], 'cache-control: no-store') !== false);

        $t = new Client($base);
        $r = $t->call('POST', '/auth/login', ['email' => $email, 'password' => $pw2]);
        check('舊密碼在重設後失效', $r[0] === 401);
        $r = $t->call('POST', '/auth/login', ['email' => $email, 'password' => $temp]);
        $tCsrf = (string) ($r[1]['data']['csrf_token'] ?? '');
        check('用臨時密碼登入 → 200，且 must_change_password=true', $r[0] === 200 && ($r[1]['data']['must_change_password'] ?? null) === true);
        $r = $t->call('PATCH', '/me', ['username' => '偷改'], $tCsrf);
        check('須先改密碼時，其他端點 → 403 PASSWORD_CHANGE_REQUIRED', $r[0] === 403 && code($r) === 'PASSWORD_CHANGE_REQUIRED');
        $r = $t->call('GET', '/me');
        check('須先改密碼時，GET /me 仍可使用', $r[0] === 200 && ($r[1]['data']['must_change_password'] ?? null) === true);
        $r = $t->call('PUT', '/me/password', ['old_password' => $temp, 'new_password' => $pw1], $tCsrf);
        check('用臨時密碼當舊密碼改成新密碼 → 200', $r[0] === 200);
        $r = $t->call('PATCH', '/me', ['username' => '恢復正常'], $tCsrf);
        check('改完密碼後限制解除', $r[0] === 200);
    }
} else {
    echo "\n[F-22] 略過（要測管理員重設密碼，請設定 ADMIN_EMAIL 與 ADMIN_PASSWORD 環境變數）\n";
}

echo "\n結果：通過 {$pass} 項，失敗 {$fail} 項\n";
echo "（測試帳號 {$email} 留在 users，可用 DELETE FROM users WHERE email LIKE 'smoke+%@example.test'; 清除）\n\n";
exit($fail === 0 ? 0 : 1);
