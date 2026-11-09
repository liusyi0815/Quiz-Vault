<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Core\Validator;
use App\Repositories\UserRepository;
use App\Serializers\UserSerializer;
use App\Support\AppException;

/** 註冊、登入、登出、個人資料、改密碼的業務規則 */
final class AuthService
{
    private const MIN_PASSWORD = 8;
    private const MAX_PASSWORD_BYTES = 72;   // bcrypt 只處理前 72 位元組

    // 帳號不存在時也做一次 password_verify，避免用回應時間判斷帳號是否存在
    private const DUMMY_HASH = '$2y$10$8IMT31Zm7s89e2M1RBTdU.dJAZkhcSqyWDUCrlJG/ApAUlRLBbM6W';

    public function __construct(private readonly UserRepository $users = new UserRepository())
    {
    }

    public function register(array $input): array
    {
        $v = Validator::validate($input, [
            'username' => 'required|trim|max:50',
            'email'    => 'required|email|trim|max:100',
            'password' => 'required|min:' . self::MIN_PASSWORD . '|maxbytes:' . self::MAX_PASSWORD_BYTES,
        ]);

        try {
            // role 固定為 student：前端傳來的 role 一律忽略
            $id = $this->users->create(
                $v['username'], strtolower($v['email']), password_hash($v['password'], PASSWORD_DEFAULT), 'student'
            );
        } catch (\PDOException $e) {
            throw self::duplicateEmailOrRethrow($e);
        }

        return ['user' => UserSerializer::toArray($this->users->findById($id))];
    }

    public function login(array $input): array
    {
        $v = Validator::validate($input, [
            'email'    => 'required|email|trim|max:100',
            'password' => 'required|maxbytes:200',
        ]);

        $user = $this->users->findByEmail(strtolower($v['email']));
        $valid = password_verify($v['password'], $user['password_hash'] ?? self::DUMMY_HASH);

        // 帳號不存在與密碼錯誤回同一種訊息，不透露哪一個
        if ($user === null || !$valid) {
            throw new AppException(401, 'INVALID_CREDENTIALS', 'Email 或密碼錯誤');
        }
        if (!(bool) $user['is_active']) {
            throw new AppException(403, 'ACCOUNT_DISABLED', '帳號已停用');
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePassword(
                (int) $user['user_id'], password_hash($v['password'], PASSWORD_DEFAULT), (bool) $user['must_change_password']
            );
        }

        Session::regenerate();                                   // 防止 session fixation
        Session::set('user_id', (int) $user['user_id']);
        Session::set('csrf_token', bin2hex(random_bytes(32)));
        Session::set('last_seen', time());

        return $this->sessionPayload($user);
    }

    public function logout(): void
    {
        Session::destroy();
    }

    /** GET /me 與登入共用的回應結構 */
    public function sessionPayload(array $user): array
    {
        $csrf = Session::get('csrf_token');
        if (!is_string($csrf)) {                                 // 例如 session 檔被清掉後重建
            $csrf = bin2hex(random_bytes(32));
            Session::set('csrf_token', $csrf);
        }
        return [
            'user'                 => UserSerializer::toArray($user),
            'csrf_token'           => $csrf,
            'must_change_password' => (bool) $user['must_change_password'],
        ];
    }

    public function updateProfile(int $userId, array $input): array
    {
        $v = Validator::validate($input, [
            'username' => 'trim|max:50',
            'email'    => 'email|trim|max:100',
        ]);
        if ($v === []) {
            throw AppException::validation([['field' => 'username', 'reason' => '至少要提供 username 或 email 其中一項']]);
        }
        if (isset($v['email'])) {
            $v['email'] = strtolower($v['email']);
        }

        try {
            $this->users->updateProfile($userId, $v);
        } catch (\PDOException $e) {
            throw self::duplicateEmailOrRethrow($e);
        }

        return ['user' => UserSerializer::toArray($this->users->findById($userId))];
    }

    public function changePassword(int $userId, array $input): array
    {
        $v = Validator::validate($input, [
            'old_password' => 'required|maxbytes:200',
            'new_password' => 'required|min:' . self::MIN_PASSWORD . '|maxbytes:' . self::MAX_PASSWORD_BYTES,
        ]);
        if ($v['new_password'] === $v['old_password']) {
            throw AppException::validation([['field' => 'new_password', 'reason' => '不得與舊密碼相同']]);
        }

        $user = $this->users->findById($userId) ?? throw AppException::unauthenticated();
        if (!password_verify($v['old_password'], $user['password_hash'])) {
            throw new AppException(400, 'WRONG_PASSWORD', '舊密碼不正確');
        }

        // 同時清除「須先改密碼」旗標
        $this->users->updatePassword($userId, password_hash($v['new_password'], PASSWORD_DEFAULT), false);
        Session::regenerate();

        return ['must_change_password' => false];
    }

    private static function duplicateEmailOrRethrow(\PDOException $e): \Throwable
    {
        // MySQL / MariaDB 1062 = Duplicate entry；這裡唯一可能重複的是 email
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return new AppException(409, 'EMAIL_TAKEN', '此 Email 已被使用');
        }
        return $e;
    }
}
