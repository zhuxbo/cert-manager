<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\CertExpireMailNotificationBuilder;
use App\Services\Notification\Builders\CertExpireSmsNotificationBuilder;
use App\Services\Notification\Builders\CertIssuedMailNotificationBuilder;
use App\Services\Notification\Builders\CertIssuedSmsNotificationBuilder;
use App\Services\Notification\Builders\DefaultNotificationBuilder;
use App\Services\Notification\Builders\FinanceAuditAlertMailNotificationBuilder;
use App\Services\Notification\Builders\TaskFailedMailNotificationBuilder;
use App\Services\Notification\Guards\BuilderChannelGuard;
use App\Services\Notification\Guards\ContactChannelGuard;
use App\Services\Notification\Guards\SystemChannelGuard;
use App\Services\Notification\Guards\UserPreferenceGuard;

return [
    'available_channels' => ['mail', 'sms'],

    'notifiables' => [
        'user' => User::class,
        'admin' => Admin::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Builders
    |--------------------------------------------------------------------------
    |
    | 通知构建器配置，格式为 'code.channel' => BuilderClass
    | - 如果配置为空字符串 ''，表示明确禁用该通道组合
    | - 如果未配置，将使用 default_builder
    | - Builder 负责验证必需参数并组装 payload
    |
    | 示例：
    |   'cert_issued.mail' => CertIssuedMailBuilder::class,
    |   'cert_issued.sms' => CertIssuedSmsBuilder::class,
    |   'cert_issued.whatsapp' => '', // 禁用 WhatsApp 通道
    |
    */

    'builders' => [
        'cert_issued.mail' => CertIssuedMailNotificationBuilder::class,
        'cert_issued.sms' => CertIssuedSmsNotificationBuilder::class,
        'cert_expire.mail' => CertExpireMailNotificationBuilder::class,
        'cert_expire.sms' => CertExpireSmsNotificationBuilder::class,
        'task_failed.mail' => TaskFailedMailNotificationBuilder::class,
        'finance_audit_alert.mail' => FinanceAuditAlertMailNotificationBuilder::class,
    ],

    'default_builder' => DefaultNotificationBuilder::class,

    'guards' => [
        BuilderChannelGuard::class,      // 检查 builder 配置是否有效（必须在最前面）
        SystemChannelGuard::class,        // 检查通道是否全局启用
        ContactChannelGuard::class,       // 检查用户是否有对应联系方式
        UserPreferenceGuard::class,       // 检查用户通知偏好设置
    ],

    'user_default_preferences' => [
        'mail' => [
            'cert_issued' => true,
            'cert_expire' => true,
            'security' => true,
        ],
        'sms' => [
            'cert_issued' => false,
            'cert_expire' => false,
            'security' => false,
        ],
    ],
];
