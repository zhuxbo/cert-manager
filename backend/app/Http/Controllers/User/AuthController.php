<?php

namespace App\Http\Controllers\User;

use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Utils\VerifyCodeHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    use \App\Http\Traits\AuthController;

    /**
     * 登录
     */
    public function login(): void
    {
        $request = request();
        $account = $request->input('account', '');
        $password = $request->input('password', '');

        $credentials = $this->getCredentials($account, $password);
        $accessToken = $this->attemptLogin($credentials);

        $refreshToken = UserRefreshToken::createToken($this->guard->id());

        /** @var User $user */
        $user = $this->guard->user();

        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->save();

        $this->success([
            'access_token' => $accessToken,
            'expires_in' => now()->addMinutes(config('jwt.ttl')),
            'refresh_token' => $refreshToken,
            'username' => $user->username,
            'balance' => $user->balance,
            'roles' => null,
            'permissions' => null,
        ]);
    }

    /**
     * 用户注册
     */
    public function register(): void
    {
        $request = request();
        $username = $request->input('username', '');
        $email = $request->input('email', '');
        $password = $request->input('password', '');
        $code = $request->input('code', '');
        $source = $request->input('source', '');

        $data = ['username' => $username, 'email' => $email, 'password' => $password];

        $validator = Validator::make($data, [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'unique:users',
                function ($attribute, $value, $fail) {
                    if (! preg_match('/^[a-zA-Z0-9\x{4e00}-\x{9fa5}_-]+$/u', $value)) {
                        $fail('用户名只能包含字母、数字、中文、下划线和短横线');
                    }
                    if (preg_match('/^1[3-9]\d{9}$/', $value)) {
                        $fail('用户名不能是手机号');
                    }
                },
            ],
            'email' => 'required|string|email|max:50|unique:users',
            'password' => 'required|string|min:6|max:32',
        ]);

        if ($validator->fails()) {
            $this->error('验证失败', $validator->errors()->toArray());
        }

        // 验证邮箱验证码
        if (empty($code)) {
            $this->error('验证码不能为空');
        }

        if (! VerifyCodeHelper::verifyEmailCode($email, $code, 'register')) {
            $this->error('验证码无效或已过期');
        }

        $data['email_verified_at'] = now();

        $user = User::create($data);

        $accessToken = $this->guard->login($user);
        $refreshToken = UserRefreshToken::createToken($user->id);

        if (is_string($source) && $source !== '') {
            $user->source = $source;

            $sourceLevel = get_system_setting('site', 'sourceLevel', []);

            if (isset($sourceLevel[$source])) {
                $user->level_code = $sourceLevel[$source];
            }
        }

        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->save();

        $this->success([
            'access_token' => $accessToken,
            'expires_in' => now()->addMinutes(config('jwt.ttl')),
            'refresh_token' => $refreshToken,
            'username' => $user->username,
            'balance' => $user->balance,
            'roles' => null,
            'permissions' => null,
        ]);
    }

    /**
     * 使用手机号注册
     */
    public function registerWithMobile(): void
    {
        $request = request();
        $username = $request->input('username', '');
        $mobile = $request->input('mobile', '');
        $code = $request->input('code', '');
        $password = $request->input('password', '');

        $data = [
            'username' => $username,
            'password' => $password,
            'mobile' => $mobile,
        ];

        // 验证字段
        $validator = Validator::make($data, [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'unique:users',
                function ($attribute, $value, $fail) {
                    if (! preg_match('/^[a-zA-Z0-9\x{4e00}-\x{9fa5}_-]+$/u', $value)) {
                        $fail('用户名只能包含字母、数字、中文、下划线和短横线');
                    }
                    if (preg_match('/^1[3-9]\d{9}$/', $value)) {
                        $fail('用户名不能是手机号');
                    }
                },
            ],
            'mobile' => 'required|string|regex:/^1[3-9]\d{9}$/|unique:users',
            'password' => 'required|string|min:6|max:32',
        ]);

        if ($validator->fails()) {
            $this->error('验证失败', $validator->errors()->toArray());
        }

        // 验证码验证
        if (empty($code)) {
            $this->error('验证码不能为空');
        }

        // 校验短信验证码
        if (! VerifyCodeHelper::verifySmsCode($mobile, $code, 'register')) {
            $this->error('短信验证码无效或已过期');
        }

        $data['mobile_verified_at'] = now();

        $user = User::create($data);

        $accessToken = $this->guard->login($user);
        $refreshToken = UserRefreshToken::createToken($user->id);

        $source = $request->input('source', '');
        if (is_string($source) && $source !== '') {
            $user->source = $source;

            $sourceLevel = get_system_setting('site', 'sourceLevel', []);

            if (isset($sourceLevel[$source])) {
                $user->level_code = $sourceLevel[$source];
            }
        }

        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->save();

        $this->success([
            'access_token' => $accessToken,
            'expires_in' => now()->addMinutes(config('jwt.ttl')),
            'refresh_token' => $refreshToken,
            'username' => $user->username,
            'balance' => $user->balance,
            'roles' => null,
            'permissions' => null,
        ]);
    }

    /**
     * 获取用户信息
     */
    public function me(): void
    {
        /** @var User $user */
        $user = $this->guard->user();

        $this->success([
            'username' => $user->username,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'balance' => $user->balance,
            'last_login_at' => $user->last_login_at,
            'last_login_ip' => $user->last_login_ip,
        ]);
    }

    /**
     * 修改用户名
     */
    public function updateUsername(): void
    {
        $request = request();
        $username = $request->input('username', '');

        /** @var User $user */
        $user = $this->guard->user();

        $validator = Validator::make([
            'username' => $username,
        ], [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'unique:users,username,'.$user->id,
                function ($attribute, $value, $fail) {
                    if (! preg_match('/^[a-zA-Z0-9\x{4e00}-\x{9fa5}_-]+$/u', $value)) {
                        $fail('用户名只能包含字母、数字、中文、下划线和短横线');
                    }
                    if (preg_match('/^1[3-9]\d{9}$/', $value)) {
                        $fail('用户名不能是手机号');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            $this->error('验证失败', $validator->errors()->toArray());
        }

        $user->username = $username;
        $user->save();

        $this->success();
    }

    /**
     * 修改用户密码
     */
    public function updatePassword(): void
    {
        $request = request();
        $oldPassword = $request->input('oldPassword', '');
        $newPassword = $request->input('newPassword', '');

        $validator = Validator::make([
            'password' => $newPassword,
        ], [
            'password' => 'required|string|min:6|max:32',
        ]);

        if ($validator->fails()) {
            $this->error('密码必须大于6位小于32位', $validator->errors()->toArray());
        }

        /** @var User $user */
        $user = $this->guard->user();

        if (empty($oldPassword)) {
            $this->error('旧密码不能为空');
        }

        if (! Hash::check($oldPassword, $user->password)) {
            $this->error('旧密码错误');
        }

        if ($oldPassword === $newPassword) {
            $this->error('新密码不能与旧密码相同');
        }

        DB::transaction(function () use ($user, $newPassword) {
            $user->password = $newPassword;
            // 改密后吊销所有旧会话：bump token_version 使旧 access token 失效，并清除全部 refresh token（与 logout 全设备登出一致）
            $user->token_version = ($user->token_version ?? 0) + 1;
            $user->logout_at = now();
            $user->save();

            UserRefreshToken::deleteTokenByUserId($user->id);
        });

        // 改密成功后发安全提醒邮件（用户关闭 security 偏好 / 无邮箱则由通道 shouldSend 自动跳过）
        $this->notifySecurityChange($user, '登录密码已修改');

        $this->success();
    }

    /**
     * 绑定邮箱
     */
    public function bindEmail(): void
    {
        $request = request();
        $email = $request->input('email', '');
        $code = $request->input('code', '');

        // 验证码验证
        if (empty($code)) {
            $this->error('验证码不能为空');
        }

        /** @var User $user */
        $user = $this->guard->user();

        $validator = Validator::make([
            'email' => $email,
        ], [
            'email' => 'required|string|email|max:50|unique:users,email,'.$user->id,
        ]);

        if ($validator->fails()) {
            $this->error('验证失败', $validator->errors()->toArray());
        }

        // 校验邮箱验证码
        if (! VerifyCodeHelper::verifyEmailCode($email, $code, 'bind')) {
            $this->error('邮箱验证码无效或已过期');
        }

        $user->email = $email;
        $user->email_verified_at = now();
        $user->save();

        $this->success();
    }

    /**
     * 绑定手机号
     */
    public function bindMobile(): void
    {
        $request = request();
        $mobile = $request->input('mobile', '');
        $code = $request->input('code', '');

        // 验证码验证
        if (empty($code)) {
            $this->error('验证码不能为空');
        }

        /** @var User $user */
        $user = $this->guard->user();

        $validator = Validator::make([
            'mobile' => $mobile,
        ], [
            'mobile' => 'required|string|regex:/^1[3-9]\d{9}$/|unique:users,mobile,'.$user->id,
        ]);

        if ($validator->fails()) {
            $this->error('验证失败', $validator->errors()->toArray());
        }

        // 校验短信验证码
        if (! VerifyCodeHelper::verifySmsCode($mobile, $code, 'bind')) {
            $this->error('短信验证码无效或已过期');
        }

        $user->mobile = $mobile;
        $user->mobile_verified_at = now();
        $user->save();

        $this->success();
    }

    /**
     * 重置密码（忘记密码）
     */
    public function resetPassword(): void
    {
        $request = request();
        $email = $request->input('email', '');
        $code = $request->input('code', '');
        $password = $request->input('password', '');

        $data = ['email' => $email, 'password' => $password];

        // 不用 exists:users,email：避免通过校验错误暴露邮箱是否已注册（账号枚举）
        $validator = Validator::make($data, [
            'email' => 'required|string|email|max:50',
            'password' => 'required|string|min:6|max:32',
        ]);

        if ($validator->fails()) {
            $this->error('验证失败', $validator->errors()->toArray());
        }

        // 验证邮箱验证码
        if (empty($code)) {
            $this->error('验证码不能为空');
        }

        if (! VerifyCodeHelper::verifyEmailCode($email, $code, 'reset')) {
            $this->error('验证码无效或已过期');
        }

        // 仅对已注册邮箱真正改密；对外不区分邮箱是否存在，统一返回成功式响应，防止账号枚举
        $user = User::where('email', $email)->first();
        if ($user) {
            DB::transaction(function () use ($user, $password) {
                $user->password = $password;
                // 忘记密码重置后吊销所有旧会话（账号可能已失陷）：bump token_version 使旧 access token
                // 失效 + 写 logout_at + 清除全部 refresh token（与登录态改密 updatePassword 一致）
                $user->token_version = ($user->token_version ?? 0) + 1;
                $user->logout_at = now();
                $user->save();

                UserRefreshToken::deleteTokenByUserId($user->id);
            });

            // 重置成功后发安全提醒邮件（已过邮箱验证码必有邮箱）；dispatch 异步入队、响应仍统一 success，不放大账号枚举
            $this->notifySecurityChange($user, '登录密码已通过邮箱验证码重置');
        }

        $this->success();
    }

    /**
     * 刷新 token
     */
    public function refreshToken(): void
    {
        /** @var UserRefreshToken $refreshToken */
        $refreshToken = Auth::guard('user-refresh-token')->user();
        $user = User::find($refreshToken->user_id);
        $accessToken = $this->guard->login($user);

        $newRefreshToken = UserRefreshToken::createToken($user->id);

        $refreshToken->delete();

        $this->success([
            'access_token' => $accessToken,
            'expires_in' => now()->addMinutes(config('jwt.ttl')),
            'refresh_token' => $newRefreshToken,
            'username' => $user->username,
            'balance' => $user->balance,
            'roles' => null,
            'permissions' => null,
        ]);
    }

    /**
     * 退出登录
     *
     * 如果提供正确地刷新令牌，则仅注销当前用户
     * 否则注销所有设备
     */
    public function logout(): void
    {
        // 获取刷新token
        /** @var UserRefreshToken|null $refreshToken */
        $refreshToken = Auth::guard('user-refresh-token')->user();

        if ($refreshToken) {
            // 仅废除当前刷新token
            $refreshToken->delete();
        } else {
            /** @var User $user */
            $user = $this->guard->user();

            // 更新token版本使所有token都失效
            $user->token_version = ($user->token_version ?? 0) + 1;
            $user->logout_at = now();
            $user->save();

            // 清除该用户所有刷新token
            UserRefreshToken::deleteTokenByUserId($user->id);
        }

        $this->guard->logout();

        $this->success();
    }

    /**
     * 派发账号安全变更通知（仅 mail 通道；用户关闭 security 偏好或无邮箱时由 shouldSend 自动跳过）。
     *
     * 在改密事务提交后调用；NotificationJob 经 ->afterCommit() 入队，发送失败不影响改密主流程。
     * event 仅传安全事件的可读描述，绝不含密码等凭据。
     */
    private function notifySecurityChange(User $user, string $event): void
    {
        app(NotificationCenter::class)->dispatch(new NotificationIntent(
            'security',
            'user',
            $user->id,
            ['event' => $event, 'email' => (string) $user->email]
        ));
    }
}
