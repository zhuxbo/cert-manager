<?php

/**
 * 运行时表保留期清理配置
 *
 * 由 schedule:purge 命令（PurgeCommand）读取，清理 tasks / notifications 两张
 * 运行时表的终态历史行（tasks {successful,failed}、notifications {sent,failed}）。
 * 非日志表（logs.php）、非健康监控域（monitoring.php），单列一个负责域。
 *
 * - tasks 最近 7 天保留全部终态记录；commit/cancel/callback 类审计动作保留 180 天。
 *   pending 普通订单与 ACME 仅保护当前周期 failed commit 计数，其他诊断任务仍按 7 天清理。
 * - retention.notifications：交付记录保留期（天）。90 天 >> 自动重试窗口（1h）+
 *   admin 手动重发运维窗口（几天），清理与重发时间窗零重叠。
 * - retention.auto_deploy_reports：自动部署上报记录保留期（天）。报告随订单生命周期管理——
 *   订单终态后按 order_id 清理（PurgeCommand::purgeTerminalOrderReports 显式排除仍 active/在途的订单，
 *   仅清终态订单超保留期的历史行）；用户删除沿订单链走 UserDataTableRegistry，不按用户维度另建路径。
 * - chunk：单批删除行数上限。分批 + 每批独立事务避免单条大事务撑爆 binlog /
 *   长事务锁等待，与 UserDataPurger 既有分批范式一致。
 *
 * failed_jobs 不在此：走 Laravel 原生 queue:prune-failed（monitoring.php），另行落地。
 */
return [
    'retention' => [
        'tasks_full_days' => (int) env('PURGE_RETENTION_TASKS_FULL_DAYS', 7),
        'tasks_audit_days' => (int) env('PURGE_RETENTION_TASKS_AUDIT_DAYS', 180),
        'notifications' => (int) env('PURGE_RETENTION_NOTIFICATIONS_DAYS', 90),
        'auto_deploy_reports' => (int) env('PURGE_RETENTION_AUTO_DEPLOY_REPORTS_DAYS', 90),
    ],

    'chunk' => (int) env('PURGE_CHUNK_SIZE', 1000),
];
