<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\Admin;
use App\Models\AdminRefreshToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    use \App\Http\Traits\AuthController;

    /**
     * 获取当前管理员信息
     */
    public function me(): void
    {
        /** @var Admin $admin */
        $admin = $this->guard->user();

        $this->success([
            'id' => $admin->id,
            'username' => $admin->username,
            'email' => $admin->email,
            'mobile' => $admin->mobile,
            'last_login_at' => $admin->last_login_at,
            'last_login_ip' => $admin->last_login_ip,
        ]);
    }

    /**
     * 更新管理员资料
     */
    public function updateProfile(UpdateProfileRequest $request): void
    {
        /** @var Admin $admin */
        $admin = $this->guard->user();

        $admin->email = $request->input('email');
        $admin->mobile = $request->input('mobile');
        $admin->save();
        $this->success();
    }

    /**
     * 修改管理员密码
     */
    public function updatePassword(UpdatePasswordRequest $request): void
    {
        /** @var Admin $admin */
        $admin = $this->guard->user();

        if (! Hash::check($request->input('oldPassword'), $admin->password)) {
            $this->error('旧密码错误');
        }

        // 改密 + 吊销全部现存会话（三件套：bump token_version + logout_at + 清 refresh token）同事务原子：
        // 避免"密码已改但会话未吊销"窄窗；密码随 revokeAllSessions 的 save 一并落库（同 $admin 实例），与 User 侧对称。
        DB::transaction(function () use ($admin, $request) {
            $admin->password = $request->input('newPassword');
            $admin->revokeAllSessions();
        });

        $this->success();
    }

    /**
     * 登录
     */
    public function login(LoginRequest $request): void
    {
        $credentials = $this->getCredentials(
            $request->input('account'),
            $request->input('password')
        );
        $accessToken = $this->attemptLogin($credentials);

        $refreshToken = AdminRefreshToken::createToken($this->guard->id());

        /** @var Admin $admin */
        $admin = $this->guard->user();

        $admin->last_login_at = now();
        $admin->last_login_ip = request()->ip();
        $admin->save();

        $this->success([
            'access_token' => $accessToken,
            'expires_in' => now()->addMinutes(config('jwt.ttl')),
            'refresh_token' => $refreshToken,
            'username' => $admin->username,
            'roles' => null,
            'permissions' => null,
        ]);
    }

    /**
     * 刷新 token
     */
    public function refreshToken(): void
    {
        /** @var AdminRefreshToken $refreshToken */
        $refreshToken = Auth::guard('admin-refresh-token')->user();
        $admin = Admin::find($refreshToken->admin_id);
        $accessToken = $this->guard->login($admin);

        $newRefreshToken = AdminRefreshToken::createToken($admin->id);

        $refreshToken->delete();

        $this->success([
            'access_token' => $accessToken,
            'expires_in' => now()->addMinutes(config('jwt.ttl')),
            'refresh_token' => $newRefreshToken,
            'username' => $admin->username,
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
        /** @var AdminRefreshToken|null $refreshToken */
        $refreshToken = Auth::guard('admin-refresh-token')->user();

        if ($refreshToken && $refreshToken->admin_id === $this->guard->id()) {
            $refreshToken->delete();
        } else {
            /** @var Admin $admin */
            $admin = $this->guard->user();

            // 全设备登出：三件套统一走 revokeAllSessions（bump token_version + logout_at + 清 refresh token）
            $admin->revokeAllSessions();
        }

        $this->guard->logout();
        $this->success();
    }
}
