<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\AutoRenewFailedNotificationBuilder;
use App\Services\Notification\Builders\CertExpireNotificationBuilder;
use App\Services\Notification\Builders\CertIssuedNotificationBuilder;
use App\Services\Notification\Builders\DefaultNotificationBuilder;
use App\Services\Notification\Builders\FinanceAuditNotificationBuilder;
use App\Services\Notification\Builders\SecurityNotificationBuilder;
use App\Services\Notification\Builders\SystemAlertNotificationBuilder;
use App\Services\Notification\Builders\TaskFailedNotificationBuilder;
use App\Services\Notification\Builders\UserCreatedNotificationBuilder;

return [
    'notifiables' => [
        'user' => User::class,
        'admin' => Admin::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Builders
    |--------------------------------------------------------------------------
    |
    | 通知构建器配置，格式为 'code' => BuilderClass
    | - 如果配置为空字符串 ''，表示明确禁用该事件类型
    | - 如果未配置，将使用 default_builder
    | - Builder 负责验证必需参数并组装 payload（payload.data 对所有通道通用，
    |   mail 用 _meta.attachments，插件通道按需读取 data 中的变量）
    |
    */

    'builders' => [
        // 自动续费/重签失败：专用 Builder 注入系统设置 site.url（登录控制台按钮），
        // 不进模板 variables、测试发送无需手填 site_url（与 cert_expire 一致）
        'auto_renew_failed' => AutoRenewFailedNotificationBuilder::class,
        'cert_issued' => CertIssuedNotificationBuilder::class,
        'cert_expire' => CertExpireNotificationBuilder::class,
        'task_failed' => TaskFailedNotificationBuilder::class,
        'finance_audit' => FinanceAuditNotificationBuilder::class,
        // 账号安全变更（改密/重置）：专用 Builder 白名单 username/event/email 入库，
        // 不回落 DefaultNotificationBuilder（避免调用方误传敏感字段被直通进 notifications.data）
        'security' => SecurityNotificationBuilder::class,
        // 携带初始密码：用专用 Builder 把密码走 transient（仅渲染、不入库），
        // 不能回落 DefaultNotificationBuilder（会把明文密码直通进 notifications.data）
        'user_created' => UserCreatedNotificationBuilder::class,
        // 通用运维/健康告警（admin-only，E1~E6 监控与 F/G/H 复用）：专用 Builder 对 details
        // 做标量化 + 敏感键 denylist + PEM 掩码/截断，携密不入库；显式不回落 DefaultNotificationBuilder
        'system_alert' => SystemAlertNotificationBuilder::class,
    ],

    'default_builder' => DefaultNotificationBuilder::class,

    /*
    |--------------------------------------------------------------------------
    | 用户邮件通知默认开关（扁平结构：code → bool）
    |--------------------------------------------------------------------------
    |
    | 主系统仅服务 mail 通道。插件通道的偏好由插件自治存储。
    |
    */
    'user_default_preferences' => [
        'cert_issued' => true,
        'cert_expire' => true,
        'security' => true,
    ],
];
