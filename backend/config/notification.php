<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\CertExpireNotificationBuilder;
use App\Services\Notification\Builders\CertIssuedNotificationBuilder;
use App\Services\Notification\Builders\DefaultNotificationBuilder;
use App\Services\Notification\Builders\FinanceAuditNotificationBuilder;
use App\Services\Notification\Builders\TaskFailedNotificationBuilder;

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
        'cert_issued' => CertIssuedNotificationBuilder::class,
        'cert_expire' => CertExpireNotificationBuilder::class,
        'task_failed' => TaskFailedNotificationBuilder::class,
        'finance_audit' => FinanceAuditNotificationBuilder::class,
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
