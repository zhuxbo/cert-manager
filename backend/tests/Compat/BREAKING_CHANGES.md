- [2026-06-01T20:05:55+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DeployTokenControllerTest::\_*pest_evaluable*管理员可以查看部署令牌详情
    - reason: A1: admin 详情接口移除 token 明文字段，防跨用户泄漏部署凭据

- [2026-06-01T20:05:55+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DeployTokenControllerTest::\_*pest_evaluable*管理员可以批量获取部署令牌
    - reason: A1: admin 批量详情接口移除 token 明文字段

- [2026-07-17T18:50:30+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\User\AuthControllerTest::\_*pest_evaluable*重置密码\_验证码无效返回错误
    - reason: audit-2026-06: reset-password 移除 exists:users,email 枚举校验，未注册邮箱不再返回 errors.email

- [2026-07-17T18:50:30+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\User\AuthControllerTest::\_*pest_evaluable*重置密码\_不暴露邮箱是否注册（账号枚举消歧）
    - reason: audit-2026-06: reset-password 对存在/不存在邮箱统一返回成功式响应
- [2026-07-22T13:07:09+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\MetaControllerTest::\_*pest_evaluable*平台设置缺失时返回与现有静态配置一致的默认值
    - reason: platform-config-2026-07: site.dnsTools 缺失时移除程序硬编码回落并返回空数组
- [2026-07-24T12:57:25+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\MetaControllerTest::\_*pest_evaluable*平台设置缺失时返回与现有静态配置一致的默认值
    - reason: platform-config-2026-07: /api/meta 的 platform 新增 LoginImage 字段，未配置时返回空字符串
- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable*恢复要求*mode*参数合法
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable*恢复入队*RestoreBackupJob*并返回\_token
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable*恢复不存在的备份返回错误
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable*删除移除*sql_gz*与\_schema_json
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable*下载*token*签发后可一次性消费
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::**pest*evaluable_schemaDiff*无*schema*时返回\_has**schema_false
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::**pest_evaluable_schemaDiff**schema*与当前一致时\_has\_\_diff_false*且返回表概览
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::**pest_evaluable_schemaDiff**schema*与当前不一致时\_has\_\_diff_true*含\_missing_extra_modified
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:16+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable_restore*在*mysql*不可用时立即返回错误，不入队\_Job
    - reason: 路由风格统一:backups/{id} 占位符更名 {backupId}(仅路由参数语义名,URL 实际形状不变)

- [2026-07-25T20:44:20+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\NotificationControllerTest::\_*pest_evaluable*管理员可以重发通知
    - reason: 路由风格统一:重发通知改为 POST /notification/resend/{id}(动作路由统一动词在前)

- [2026-07-25T20:44:20+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\NotificationControllerTest::\_*pest_evaluable*重发不存在的通知返回错误
    - reason: 路由风格统一:重发通知改为 POST /notification/resend/{id}(动作路由统一动词在前)

- [2026-07-25T20:44:24+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\ProductPriceInitializationControllerTest::\_*pest_evaluable*未认证管理员不能调用价格初始化接口
    - reason: 路由风格统一:价格初始化改为 POST /product-price/initialize(动作统一动词命名)

- [2026-07-25T20:44:24+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\ProductPriceInitializationControllerTest::\_*pest_evaluable*预览成功返回令牌且即使开启强制和倍率同步也绝不写库
    - reason: 路由风格统一:价格初始化改为 POST /product-price/initialize(动作统一动词命名)

- [2026-07-25T20:44:24+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\ProductPriceInitializationControllerTest::\_*pest_evaluable*成本告警仍以*code*一的结构化成功响应返回
    - reason: 路由风格统一:价格初始化改为 POST /product-price/initialize(动作统一动词命名)

- [2026-07-25T20:44:24+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\ProductPriceInitializationControllerTest::\_*pest_evaluable*代表性参数失败返回结构化校验错误
    - reason: 路由风格统一:价格初始化改为 POST /product-price/initialize(动作统一动词命名)

- [2026-07-25T20:44:24+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\ProductPriceInitializationControllerTest::\_*pest_evaluable*正式执行只信任服务端数据且响应统计与数据库一致
    - reason: 路由风格统一:价格初始化改为 POST /product-price/initialize(动作统一动词命名)

- [2026-07-25T20:44:24+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\ProductPriceInitializationControllerTest::\_*pest_evaluable*状态变化后执行返回结构化*stale*且零写
    - reason: 路由风格统一:价格初始化改为 POST /product-price/initialize(动作统一动词命名)

- [2026-07-25T20:44:28+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\SettingControllerTest::\_*pest_evaluable*管理员可以批量更新设置
    - reason: 路由风格统一:setting/batch-update 由 PUT 改为 PATCH(批量局部更新语义)

- [2026-07-25T20:44:32+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\User\TopUpControllerTest::\_*pest_evaluable*检查充值状态\_订单不存在返回成功
    - reason: 路由风格统一:/top-up/check/{id} 由 GET 改为 POST(查单成功会补记充值,副作用不应挂 GET)

- [2026-07-25T20:44:33+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\User\TopUpControllerTest::\_*pest_evaluable*检查充值状态\_已完成订单返回\_successful
    - reason: 路由风格统一:/top-up/check/{id} 由 GET 改为 POST(查单成功会补记充值,副作用不应挂 GET)

- [2026-07-25T20:44:33+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\User\TopUpControllerTest::\_*pest_evaluable*检查充值状态\_无权访问他人订单返回\_successful
    - reason: 路由风格统一:/top-up/check/{id} 由 GET 改为 POST(查单成功会补记充值,副作用不应挂 GET)

- [2026-07-25T20:44:33+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\User\TopUpControllerTest::\_*pest_evaluable*检查充值状态*微信查单参数带\_Wechatpay_Serial*公钥序列号
    - reason: 路由风格统一:/top-up/check/{id} 由 GET 改为 POST(查单成功会补记充值,副作用不应挂 GET)

- [2026-07-26T16:00:29+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Deploy\OrderControllerTest::**pest*evaluable_query*响应不含\_total_page_page**size\_字段
    - reason: deploy-boundary-2026-07: GET /api/deploy 响应移除 total / page / page_size 三个分页字段（同时取消空参数列全量与域名查询，order 仅接受订单 ID）；本条覆盖该端点全部用例的同一处 schema 变更

- [2026-08-30T22:56:45+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable*恢复入队*RestoreBackupJob*并返回\_token
    - reason: atomic-restore-2026-08: restore body removes mode and adds allow_schema_difference

- [2026-08-30T22:56:45+08:00] 1.0.0
    - test: P\Tests\Feature\Http\Controllers\Admin\DatabaseBackupControllerTest::\_*pest_evaluable*恢复不存在的备份返回错误
    - reason: atomic-restore-2026-08: restore body removes mode and adds allow_schema_difference
