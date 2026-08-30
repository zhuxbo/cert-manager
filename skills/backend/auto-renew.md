# 自动续费 / 重签

订单级 + 用户级自动续费/重签开关，`AutoRenewCommand` 每天 00:00 执行，到期前触发续费或重签、延时提交分散上游压力。

**核心安全**：① 续费/重签 `reuse_csr=0` 重新生成 CSR 时必须**继承原算法防静默降级**（ECDSA/SM2 不得被降级为 RSA）；② 任何失败都**兜底必发**通知，堵住 `ExpireCommand` 排除自动续签订单后的静默过期洞。

## 开关与触发

- `orders.auto_renew`: 订单级自动续费开关（null 时回落到用户设置）
- `orders.auto_reissue`: 订单级自动重签开关（null 时回落到用户设置）
- `users.auto_settings`: 用户级默认设置 `{"auto_renew": false, "auto_reissue": false}`
- `AutoRenewCommand` 每天 00:00 执行：证书到期前 14 天触发，订单剩余 ≤15 天续费、>15 天重签；API channel 订单由下游控制，不处理
- **延时提交**：Command 创建续费/重签 + 支付后不立即 commit，通过 Task 表创建延时 commit 任务（随机 0~8 小时），分散上游压力，8 点后人工可检查状态
- **产品条件**：续费要求 `product.status=1 && renew=1`；重签仅要求 `reissue=1`（产品禁用仍可重签）
- **仅 ssl 产品（A3）**：选单 `getRenewOrders`/`getReissueOrders` 与判定 `willAuto{Renew,Reissue}Execute` 四处均加 ssl 白名单（`product_type IS NULL OR ='ssl'`，`Product::isSSL()` null→ssl）；smime/codesign/docsign 退出自动续费/重签选单，改由 `cert_expire` 到期提醒。**四处必须同步改**——`willAuto*` 是 `ExpireCommand::willBeHandledByAutoRenew` 与 `CertExpireNotificationBuilder` 的单一源，只改选单不改 `willAuto*` 会「选单排除但仍被判会处理」→ 两腿断静默过期
- **参数继承**：从原订单提取 period/contact/organization/domains；CSR 按 `product.reuse_csr` 决定重用或生成

## 算法继承（防静默降级）

- 续费/重签 `reuse_csr=0` 重新生成 CSR 时，`ActionTrait::initParams` 在 `encryption.alg` 缺失时从 `last_cert` 继承 alg/bits/digest（列存大写，`strtolower` 归一），覆盖自动路径（`AutoRenewCommand` 不传 encryption）与 API 省略；前端 `loadOrderInfo` 回填原算法为表单默认（用户仍可改）。**继承值在 `ValidatorUtil::validate` 之后才注入 `$params`**——不让当前产品 `encryption_alg` 菜单校验阻断存量证书续签（显式传入的 encryption 仍照常 validate）；但 SM2 能力 gate `guardSm2Capable` 早触发，国密 openssl 不可用则报错（保持 SM2，绝不静默降级为 RSA）。`CsrUtil::getEncryptionParams` 归一返回小写 alg（修大写算法失配 bug）。前端 ECDSA 密钥长度选项 `512→521` 对齐后端 `secp521r1`。否则原 ECDSA/SM2 证书会在 reuse_csr=0 续签后静默降级为 RSA

## 委托前置条件

- 优先从源证书 validation 的 `delegation_id` 加载同一逻辑委托，先按“完整默认域优先、其余完整配置回落”做全局检测，再用检测结果生成续费/重签 validation 快照；旧数据缺少有效 ID 时才按 CA 派生 zone 回落查找或创建
- 委托失效通知只在本命令真正准备发起续签/重签且前置检查失败时发送，复用 `auto_renew_failed` 及其 14/7/3/1 天节点 gate；`delegation:check` 周巡检不单独向用户发委托通知

## 失败通知 + 到期去重

- （`auto_renew_failed` 模板，仅 seeder、db:seed 幂等可达）：续费/重签失败（含 IP、无委托等跳过类）按到期节点（14/7/3/1 天）发邮件给订单用户。**失败文案归一**：仅「余额不足」「委托无效」两类用户可行动失败给专属清晰文案，IP/上游系统类错误统一走兜底常量 `FALLBACK_REASON`「自动续签未成功，请尽快手动续期」（原始异常仅进 cron 日志、不泄露给用户）；邮件用证书 `common_name` 标识（非 order_id），处理方式按失败类型逐条列出 + 联系客服兜底 + 「登录控制台」按钮（`site_url` 由专用 `AutoRenewFailedNotificationBuilder` 从系统设置注入，**不进模板 variables、Admin 测试发送无需手填**）；**余额检查仅 renew**（reissue 不扣费、不检查余额）；**兜底必发**——任何失败都发通知以堵 `ExpireCommand` 排除自动续签订单后的静默过期洞。`ExpireCommand` 反向排除「会被自动续签/重签处理」的订单（`cert.channel≠api 且 willAutoRenew‖willAutoReissue`）避免同节点重复发到期通知；节点常量与 `isExpireNotifyNode` 由 `Console\Commands\Concerns\ExpireNotifyWindow` trait 两命令共用；`CertExpireNotificationBuilder`/`AcmeExpireNotificationBuilder` 重查窗口上界亦 `use` 该 trait 由 `max(EXPIRE_NOTIFY_NODES)` 派生（非硬编码 14，对齐 `StalledRenewalQuery::forUser`），与派发侧节点同源防漂移（漂移后果：节点扩含 >14 天时派发侧发了 intent、Builder 重查为空 → 整封静默漏发）。修复点：`willAutoReissueExecute` 改判 `product.reissue`（重签不限产品状态），与 `getReissueOrders` 对齐

### 余额不足独立去重（A2）

- 「余额不足」这一类失败**脱离节点 gate**、走 per-user 独立去重键 `auto_renew_balance_notified:{user_id}`（`sendBalanceFailureNotification`）：常规 `Cache::add` 每 `BALANCE_NOTIFY_INTERVAL_DAYS=3` 天一封（防同用户多单风暴）；**到期前 ≤1 天窗口（`isFinalExpireNotifyNode`，由 `min(EXPIRE_NOTIFY_NODES)` 派生）豁免去重必发**（`Cache::put` 同时刷键抑制同轮叠发），保住「到期前 1 天必达」下界。**余额检查通过即 `Cache::forget` 清键**（恢复后再欠费立即告警，不等 TTL）。仅余额分支改闸门，IP/委托/兜底仍走 `sendFailureNotification` 节点 gate；两路径共用 `dispatchAutoRenewFailed`

### 零价成单守卫（A4）

- 续费前置校验 `OrderUtil::hasPriceConfigured(userId, productId, period)`（**行存在性**判定，与 `getMinPrice` 共用 `fetchPriceRows` 取行源防漂移）：缺价（无任何 `ProductPrice` 行）→ **跳过、不建单**（前置于 `renew()`/旧证书终态化之前，杜绝 `getMinPrice ?? '0'` 传导出 0 元静默续费）；**显式免费产品**（行存在 `price=0.00`）放行。`price` 列 NOT NULL default 0，故「行在=有价 / 无行=缺价」二分健全。缺价告警复用包0 `SystemAlert::send('missing_price',…,dedupeKey="missing_price:{product}:{period}",72,'missing')`（固定指纹防 details churn，72h≥3×日巡检），healthy 分支 `clearDedupe` 复位；用户端走 `FALLBACK_REASON`。**守卫只在 AutoRenewCommand renew 路径**——不改 `getMinPrice` 契约、不碰手工/V1/V2/展示；reissue 基础价本就置 0，不校验

### 余额前瞻预警（A1）

- `BalanceForecastCommand`（`schedule:balance-forecast`，周一 09:30，只读）：聚合每用户「未来 30 天到期 + 付费续费轨道」订单估价上限 vs 可用额（`balance + |credit_limit|`），不足则一封 `balance_forecast`（per-user 聚合、weekly 天然去重）。**权威判定单一源**：DB 粗筛（ssl 白名单 + `expires_at<now+30` + 非 api + auto 回落 + `period_till<=now+15`）+ PHP 层 `willAutoRenewExecute` 过滤，排除免费重签单（消除系统性高估）。`required` 是**预估上限**（委托将失败单仍计入），文案「预计最多需要」。三件套：`BalanceForecastNotificationBuilder` + seeder 模板 + config builder；**不入 `user_default_preferences`**（强制发，比照 `auto_renew_failed`，前端零改动）。JSON 查询助手 `whereJsonBoolEq`/`whereJsonKeyMissing` 抽入 `Concerns\QueriesUserJsonSettings` trait 两命令共用

## 续费事务化与余额预检（O1/O2/O3，P0-1 包 O）

- **O1 事务原子**：`processOrder` 把「创建续费/重签 + `pay(false)`」包进单个 `DB::transaction`，pay 段 charge 失败连同已翻转的旧证书（active→renewed）+ 新单一并回滚，杜绝「旧证书 renewed 终态 + 新单卡 unpaid」静默孤儿（P0-1 路径 1）；延时 commit 移**事务外**。**attempts=1 必需**（`checkDuplicate` SETNX 回滚不清键，事务级重试必自败）；`success()` 抛 `ApiResponseException` 是成功信号（取 order_id）、业务失败 rethrow 触发回滚。
- **O2 余额预检实时化**：续费预检前 `$user->refresh()` 消 00:00 `with('user')` 预载的 stale balance（同用户多单共享内存实例、前序单 charge 改的是 DB 另取行）。
- 事务边界 / O3 Deploy update 移植 / O4 sweep-orphan-orders 孤儿收尾 / PendingReconcileQuery 共享判据详见 `skills/backend/order-fund.md`「续费孤儿止血 + 卡单对账扩展」。

## 续期停滞孤儿止血（cert_renew_stalled，P0-1 包 X）

续费/重签把前驱证书**终态化**（renewed/reissued）后，接替证书长期卡在非 active 停滞态、前驱即将到期——`cert_expire` 对 renewed/reissued 前驱抑制、AutoRenew 因 active 前置不再处理 → 这是审计四条 critical 触发路径的**唯一止血**（尤其路径 3：DCV 长期不过的 processing 形态）。

- **证书为轴、前驱侧扫描**（`Services/Order/StalledRenewalQuery`，非审计字面反查）：前驱状态集 `{renewed,reissued}` + `whereHas('nextCert', 停滞 5 态 + 48h 门槛)`。`Cert::nextCert`（HasOne，`last_cert_id` nullable UNIQUE → 至多一条接替）单点表达「存在接替」，避免多处裸 `whereExists` 拼写漂移。
- **接替 5 态**（`SUCCESSOR_STALLED_STATUSES`，「非 active」的显式工程化非字面 `!= 'active'`）：`unpaid`（pay 前中断未扣费）/ `pending`（commit 卡单已扣费）/ `processing`/`approving`（DCV/审核长期不过已扣费）/ `failed`（CA 拒签终态）。排除 cancelled/revoked（已终止非停滞、误报不可静音）、renewed/reissued（链延长、接替曾签发）、expired（接替曾 active 走完生命周期）、cancelling（过渡态）。
- **48h 在途年龄门槛**（`IN_FLIGHT_AGE_HOURS`）：接替 `created_at` 早于此才算停滞。挡两类误报——① 健康在途（续费当天创建当天 processing 正常、手工单跨日完成 DCV）② `auto_renew_failed` 当日重叠。对主人群（到期前 14 天建单的自动续费孤儿）首封恒落 node-7（age≫48h），零节点代价。
- **markRenewed 结构性免疫**：手工标记单**无接替链**（nextCert EXISTS 恒 falsy）→ 结构性排除，绝不对存量已续签客户群发（比审计字面反查更强的双向互斥）。已完成续签接替=active（不在 5 态集）同理排除。
- **节点窗口复发**：派发侧 `forDispatch` 施加离散节点窗口（14/7/3/1，防每日重复），停滞持续则随前驱逼近到期在各节点复发提醒。收件人经前驱 `order->user` 解析（certs 表无 user_id：续费前驱在旧订单、重签前驱在同订单，均正确指向本人）。三件套 / 双侧同源 / 5 态文案见 `skills/backend/notification.md`。
- **与 O4 时序三 regime**：pending 卡单同时被 X（到期止血提醒）与 O4（arm 后自动取消退款）覆盖——**关闭期** X node-7/3/1 + T5 user 中性通知接住；**开启期** O4 到顶自动 `cancelPending` 后接替转 cancelled/删除（脱离 5 态）、X 自然停发；**armed 窄窗** X 与 O4 无冲突（X 只提醒不改状态）。

## 接替单取消止血（cert_renew_cancelled）

接替单（续费/重签）在**已提交上游**（processing/approving，含已签发 active）状态被取消后一律置 cancelled、前驱不恢复（收窄语义见 `skills/backend/order-fund.md`）——前驱证书（renewed/reissued 终态）同样脱离三重监控：`cert_expire` 对其抑制、AutoRenew 因前驱非 active 前置不处理、`cert_renew_stalled` 因接替已置 cancelled 脱离停滞 5 态集（`SUCCESSOR_STALLED_STATUSES` 明确排除 cancelled）不再覆盖。故取消现场发一次性 `cert_renew_cancelled` 止血：`Action::cancelLocked` 对有前驱的 renew/reissue 对称派发，启用 `autoRefundOnSync` 后 `refundForSyncedCancel` 对有前驱的 renew 同样派发，均经 `NotificationCenter` afterCommit 投递。

- **与 cert_renew_stalled 的分工**：stalled 针对接替**卡停滞态**（在途 5 态），周期强制发、随前驱逼近到期在 14/7/3/1 节点复发；cert_renew_cancelled 针对接替被**取消终结**，事件驱动一次性发。二者互补堵住前驱脱监控的两条路径（接替停滞 / 接替取消），通知三件套侧见 `skills/backend/notification.md`。

## 手工标记已续费

- （`Order\Action::markRenewed`，admin/user 双端）：**订单到期前 30 天内**（按 `orders.period_till` 判定、非单张 `cert.expires_at`，与手工续费 gate 的 `period_till>now+30` 一致）、仅 active 证书可手工标记 `renewed` 终态。场景：用户**另开新订单**续了证书 → 标旧订单 `renewed` 止住到期通知+自动续费；"原订单内重签"靠重签后 expires_at 推远自动止通知、无需本操作。不用 cert.expires_at：多年期/中途重签订单证书将到期但订单未到期，会被自动重签接管（ExpireCommand 的 willBeHandledByAutoRenew 已排除其到期通知），不应允许标记。事务+行锁+锁内二次校验，User 端 UserScope 限本人

---

## 「自动续费/重签」详细章节（原后端开发规范迁入）

## 自动续费/重签

### 数据结构

| 字段            | 位置      | 说明                |
| --------------- | --------- | ------------------- |
| `auto_renew`    | orders 表 | 订单级自动续费开关  |
| `auto_reissue`  | orders 表 | 订单级自动重签开关  |
| `auto_settings` | users 表  | 用户级默认设置 JSON |

### 回落逻辑

订单设置为 `null` 时回落到用户设置：

```php
->where(function ($query) {
    $query->where('auto_renew', true)
        ->orWhere(function ($q) {
            $q->whereNull('auto_renew')
              ->whereHas('user', fn ($u) => $u->where('auto_settings->auto_renew', true));
        });
})
```

### 续费 vs 重签判断

- `period_till - expires_at < 7天`：续费（订单周期与证书到期接近）
- `period_till - expires_at > 7天`：重签（订单周期内还有余量）

### AutoRenewCommand 执行流程

**调度配置**：每天 00:00 执行（`routes/console.php`），commit 延时分散 0~8h

**执行步骤**：

1. `getRenewOrders()` 查询续费订单：
   - `auto_renew = true`（订单级或用户级回落）
   - 证书即将到期（15天内）且未过期超过15天
   - 订单周期与证书到期时间差 < 7 天
   - 产品支持续费（`renew = 1`）
   - **排除 acme 通道**（由 ACME 客户端自行续签）

2. `getReissueOrders()` 查询重签订单：
   - `auto_reissue = true`（订单级或用户级回落）
   - 证书即将到期（15天内）且未过期超过15天
   - 订单周期与证书到期时间差 > 7 天
   - **排除 acme 通道**

3. `processOrder()` 处理单个订单：
   - **委托有效性检查**：`checkDelegationValidity()` 即时验证所有域名是否有有效委托
   - 无有效委托 → 跳过订单，不发起续费/重签
   - 续费时检查用户余额（`balance + |credit_limit|`）
   - 强制使用 `delegation` 验证方法
   - 调用 `Action::renew()` 或 `Action::reissue()`

4. 支付 + 延时提交（O1 事务化后）：
   - 「创建续费/重签 + `Action::pay($orderId, false)`」包进单个 `DB::transaction`（原子，charge 失败连同旧证书翻转一并回滚）
   - 事务外 `createTask($orderId, 'commit', $delay)`（随机 0~8h 延时提交，分散上游压力）——不再同步 `pay(true)` 立即 commit

### 相关命令

`php artisan schedule:auto-renew` - 同时处理续费和重签

---
