<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Admin extends BaseModel implements AuthenticatableContract, JWTSubject
{
    use Authenticatable, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'mobile',
        'last_login_at',
        'last_login_ip',
        'password',
        'token_version',
        'logout_at',
        'status',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
        'logout_at' => 'datetime',
        'status' => 'integer',
    ];

    /**
     * 使用自定义通知模型，保持与用户一致的通知工作流
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    /**
     * 获取 JWT 标识
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * 获取 JWT 自定义声明
     */
    public function getJWTCustomClaims(): array
    {
        return ['token_version' => $this->getAttribute('token_version') ?? 0];
    }

    /**
     * 吊销该管理员的全部现存会话（单点）。
     *
     * 与 User::revokeAllSessions() 对称，完整动作三件套，缺一不可：
     *  1. bump token_version —— 旧 access token 凭 JWT claim 的旧版本进入永久黑名单；
     *  2. 写 logout_at = now() —— 中间件 checkTokenVersionGraceful 据此起算宽限期，
     *     漏写会让旧令牌从 1970 起算（time()-0 远超宽限期）导致行为异常；
     *  3. 清除该管理员全部 refresh token —— 旧会话无法再续期。
     *
     * 改密/重置/全设备登出等入口统一调用本方法，杜绝“漏改一个入口”的回归。
     */
    public function revokeAllSessions(): void
    {
        $this->token_version = ($this->token_version ?? 0) + 1;
        $this->logout_at = now();
        $this->save();

        AdminRefreshToken::deleteTokenByAdminId($this->id);
    }

    /**
     * 设置密码
     */
    public function setPasswordAttribute(string $password): void
    {
        $this->attributes['password'] = Hash::make($password);
    }

    /**
     * 解析运维告警投递目标（原 TaskJob/FundAuditCommand/SystemAlert 多份内联收敛为单一源，
     * 杜绝解析规则演进漏改致投错地址）。
     *
     * 规则：site.adminEmail 优先；已配置时匹配同邮箱 Admin，未命中则回落任一 Admin 作为通知归属；
     * 未配置时逐个查找邮箱非空的 Admin，找到第一条即可。
     * 零新增 Cache 依赖：get_system_setting 经 Setting 已「cache 故障回落 DB」，Admin 查询直读 DB；
     * 调用本方法不额外引入 cache 依赖。
     *
     * @return array{admin: ?Admin, email: ?string}
     *                                              admin: 配置邮箱命中的 Admin、任一归属 Admin，或首个邮箱非空的 Admin（可能 null）；
     *                                              email: adminEmail（配了别名即使无 Admin 记录也优先）或选中 Admin 的邮箱（可能 null）。
     *                                              调用方按需判空。
     */
    public static function resolveAlertTarget(): array
    {
        $adminEmail = get_system_setting('site', 'adminEmail');
        if ($adminEmail) {
            $admin = static::where('email', $adminEmail)->first() ?? static::first();
        } else {
            $admin = static::whereNotNull('email')
                ->where('email', '<>', '')
                ->first();
        }

        return [
            'admin' => $admin,
            'email' => $adminEmail ?: $admin?->email,
        ];
    }
}
