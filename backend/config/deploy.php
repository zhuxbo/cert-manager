<?php

/**
 * 自动部署 / 签发失败告警去重配置
 *
 * 任一 auto_deploy_reports 的 failure 行（客户端部署回调失败或服务端自写签发失败）触发一封
 * SystemAlert，按订单固定指纹去重：
 *  - TTL 内同一订单重复失败只入表、不再通知；
 *  - TTL 到期仍未解决（最后一条报告仍为 failure、无后续 success）由 schedule:deploy-failure-reminder
 *    基于状态再提醒一封，覆盖客户端触顶静默期；证书已过期或订单终态后停止提醒。
 *
 * dedupe_ttl_hours 默认 168h（7 天），沿用基座改造前旧滑窗告警的 dedupe_ttl_hours 默认值；
 * 契约要求 TTL ≥ 3× 提醒巡检周期（本处巡检为日频，168h 远大于 3×24h）。
 */
return [
    'failure_alert' => [
        'dedupe_ttl_hours' => (int) env('DEPLOY_FAILURE_ALERT_DEDUPE_TTL_HOURS', 168),
    ],
];
