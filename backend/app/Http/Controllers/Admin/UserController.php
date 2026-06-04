<?php

namespace App\Http\Controllers\Admin;

use App\Bootstrap\ApiExceptions;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\DirectLoginRequest;
use App\Http\Requests\User\GetIdsRequest;
use App\Http\Requests\User\IndexRequest;
use App\Http\Requests\User\StoreRequest;
use App\Http\Requests\User\UpdateRequest;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Utils\Random;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取用户列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = User::query();

        // 添加搜索条件
        if (! empty($validated['quickSearch'])) {
            $query->where(function ($query) use ($validated) {
                $query->where('username', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('email', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('mobile', 'like', "%{$validated['quickSearch']}%");
            });
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['username'])) {
            $query->where('username', $validated['username']);
        }
        if (! empty($validated['email'])) {
            $query->where('email', 'like', "%{$validated['email']}%");
        }
        if (! empty($validated['mobile'])) {
            $query->where('mobile', 'like', "%{$validated['mobile']}%");
        }
        if (! empty($validated['level_code'])) {
            $query->where('level_code', $validated['level_code']);
        }
        if (! empty($validated['custom_level_code'])) {
            $query->where('custom_level_code', $validated['custom_level_code']);
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }
        if (! empty($validated['balance'])) {
            if ($validated['balance'][0] !== null && $validated['balance'][0] !== '') {
                $query->where('balance', '>=', $validated['balance'][0]);
            }
            if ($validated['balance'][1] !== null && $validated['balance'][1] !== '') {
                $query->where('balance', '<=', $validated['balance'][1]);
            }
        }
        if (! empty($validated['credit_limit'])) {
            $query->where('credit_limit', '<', 0)->where('credit_limit', '>=', -abs($validated['credit_limit']));
        }

        $total = $query->count();
        $items = $query->with([
            'level' => function ($query) {
                $query->select(['code', 'name']);
            },
            'customLevel' => function ($query) {
                $query->select(['code', 'name']);
            },
        ])
            ->select([
                'id', 'username', 'email', 'mobile', 'balance', 'credit_limit', 'last_login_at', 'status', 'created_at',
                'level_code', 'custom_level_code',
            ])
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    /**
     * 添加用户
     */
    public function store(StoreRequest $request): void
    {
        $validated = $request->validated();

        $user = User::create([
            ...$validated,
            'join_at' => now(),
        ]);

        if (! $user->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取用户资料
     */
    public function show($id): void
    {
        $user = User::with([
            'level' => function ($query) {
                $query->select(['code', 'name']);
            },
            'customLevel' => function ($query) {
                $query->select(['code', 'name']);
            },
        ])->find($id);

        if (! $user) {
            $this->error('用户不存在');
        }

        $this->success($user->toArray());
    }

    /**
     * 批量获取用户资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $users = User::whereIn('id', $ids)->get();
        if ($users->isEmpty()) {
            $this->error('用户不存在');
        }

        $this->success($users->toArray());
    }

    /**
     * 更新用户资料
     */
    public function update(UpdateRequest $request, $id): void
    {
        $user = User::find($id);
        if (! $user) {
            $this->error('用户不存在');
        }

        $validated = $request->validated();

        // 如果没有传递 custom_level_code 字段，设置为 null 以清空该字段
        if (! $request->has('custom_level_code')) {
            $validated['custom_level_code'] = null;
        }

        $user->fill($validated);
        $user->save();

        $this->success();
    }

    /**
     * 删除用户
     */
    public function destroy($id): void
    {
        $user = User::find($id);
        if (! $user) {
            $this->error('用户不存在');
        }

        $user->delete();

        $this->success();
    }

    /**
     * 批量删除用户
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $users = User::whereIn('id', $ids)->get();
        if ($users->isEmpty()) {
            $this->error('用户不存在');
        }

        User::destroy($ids);

        $this->success();
    }

    /**
     * 管理员直接登录用户
     */
    public function directLogin(DirectLoginRequest $request): void
    {
        $userId = $request->validated('user_id');

        $user = User::find($userId);
        if (! $user) {
            $this->error('用户不存在');
        }

        if ($user->status === 0) {
            $this->error('用户已被禁用');
        }

        // 使用 JWTAuth 生成令牌
        $accessToken = JWTAuth::fromUser($user);

        if (empty($accessToken)) {
            $this->error('登录令牌生成失败');
        }

        // 创建刷新令牌
        $refreshToken = UserRefreshToken::createToken($user->id);

        // 更新最后登录信息（不触发事件）
        User::where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        // 获取会员中心URL
        $siteUrl = get_system_setting('site', 'url');
        if (empty($siteUrl)) {
            $this->error('会员中心网址未配置');
        }

        // 构建登录URL，将token作为参数传递
        $directLoginUrl = rtrim($siteUrl, '/').'/user/login?auto_token='.$accessToken;

        $this->success([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => now()->addMinutes(config('jwt.ttl', 60))->toISOString(),
            'direct_login_url' => $directLoginUrl,
            'username' => $user->username,
            'balance' => $user->balance,
        ]);
    }

    /**
     * 创建用户
     */
    public function createUser(CreateUserRequest $request): void
    {
        $validated = $request->validated();

        $password = Random::build('alnum', 10);

        $user = User::create([
            'username' => $validated['username'] ?? Random::build('alnum', 10),
            'password' => $password,
            'email' => $validated['email'],
            'level_code' => 'platinum',
            'join_at' => now(),
        ]);

        if (! $user->exists) {
            $this->error('创建失败');
        }

        try {
            $intent = new NotificationIntent(
                'user_created',
                'user',
                $user->id,
                [
                    'username' => $user->username,
                    'password' => $password,
                    'site_name' => get_system_setting('site', 'name', 'SSL证书管理系统'),
                    'site_url' => get_system_setting('site', 'url', '/'),
                    'email' => $user->email,
                ]
            );

            app(NotificationCenter::class)->dispatch($intent);
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);
        }

        $this->success();
    }
}
