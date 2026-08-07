<?php

return [
    /*
    |--------------------------------------------------------------------------
    | dnsTools 全挂安全网阈值（F2-1）
    |--------------------------------------------------------------------------
    |
    | dnstools_down_sync_threshold（N）：配置 dnsTools 后，某订单连续 N 次「全部节点不可达且本地
    |   DCV（DNS 或文件）兜底不可判定」时，建一个 sync 任务把订单拉回 CA 完成态（附加安全网，不扰动验证节奏）。
    |   计数按订单自身档位递增（仅 next_check_at 到点才检测），非墙钟连续；键 TTL=48h ≥ N×最大档位(12h)
    |   + 余量，避免老单 12h 档在计数达标前 key 过期重置。
    |
    */

    'dnstools_down_sync_threshold' => (int) env('VALIDATION_DNSTOOLS_DOWN_SYNC_THRESHOLD', 3),
];
