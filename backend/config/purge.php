<?php

/**
 * 运行时表保留期清理配置
 *
 * 由 schedule:purge 命令（PurgeCommand）读取，清理 tasks / notifications 两张
 * 运行时表的终态历史行（tasks {successful,failed}、notifications {sent,failed}）。
 * 非日志表（logs.php）、非健康监控域（monitoring.php），单列一个负责域。
 *
 * - retention.tasks：对账痕迹保留期（天）。90 天 >> 已收尾订单的对账/取证窗口（到顶转人工 /
 *   O4 收尾）。**未收尾的 pending 卡单 task 由 PurgeCommand::purgeTerminalTasks 显式排除、不受本保留期约束**
 *   （否则失败 commit task 归零 → 到顶计数复位 → 卡单周期性复活重打上游 + 重发去重通知，见该方法注释）——
 *   故 90 天对已收尾订单成立、对卡单不适用（卡单收尾后其 task 才进入清理）。
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
        'tasks' => (int) env('PURGE_RETENTION_TASKS_DAYS', 90),
        'notifications' => (int) env('PURGE_RETENTION_NOTIFICATIONS_DAYS', 90),
        'auto_deploy_reports' => (int) env('PURGE_RETENTION_AUTO_DEPLOY_REPORTS_DAYS', 90),
    ],

    'chunk' => (int) env('PURGE_CHUNK_SIZE', 1000),
];
