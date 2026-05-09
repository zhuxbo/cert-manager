<?php

/*
|--------------------------------------------------------------------------
| 路由 Channel 开关
|--------------------------------------------------------------------------
|
| 控制四类路由文件是否注册：
|   - admin  → routes/api.admin.php
|   - user   → routes/api.user.php
|   - api    → routes/api.v1.php / api.v2.php / api.acme.php
|   - deploy → routes/api.deploy.php
|
| 与 channel 解耦、永远启用：
|   - routes/api.health.php   公开运维健康检查
|   - routes/api.meta.php     前端启动期 channel/plugin 元信息
|
| 切换需重启 PHP-FPM/worker；Route 注册期决策（每请求不重读 env）。
*/

return [
    'admin' => env('CHANNELS_ADMIN', true),
    'user' => env('CHANNELS_USER', true),
    'api' => env('CHANNELS_API', true),
    'deploy' => env('CHANNELS_DEPLOY', true),
];
