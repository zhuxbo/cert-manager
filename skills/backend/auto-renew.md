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
- **参数继承**：从原订单提取 period/contact/organization/domains；CSR 按 `product.reuse_csr` 决定重用或生成

## 算法继承（防静默降级）

- 续费/重签 `reuse_csr=0` 重新生成 CSR 时，`ActionTrait::initParams` 在 `encryption.alg` 缺失时从 `last_cert` 继承 alg/bits/digest（列存大写，`strtolower` 归一），覆盖自动路径（`AutoRenewCommand` 不传 encryption）与 API 省略；前端 `loadOrderInfo` 回填原算法为表单默认（用户仍可改）。**继承值在 `ValidatorUtil::validate` 之后才注入 `$params`**——不让当前产品 `encryption_alg` 菜单校验阻断存量证书续签（显式传入的 encryption 仍照常 validate）；但 SM2 能力 gate `guardSm2Capable` 早触发，国密 openssl 不可用则报错（保持 SM2，绝不静默降级为 RSA）。`CsrUtil::getEncryptionParams` 归一返回小写 alg（修大写算法失配 bug）。前端 ECDSA 密钥长度选项 `512→521` 对齐后端 `secp521r1`。否则原 ECDSA/SM2 证书会在 reuse_csr=0 续签后静默降级为 RSA

## 委托前置条件

- 缺失委托记录时自动创建（zone 由 ca 派生：`exact` 精确域名 / 非 exact 根域，见 `delegation.md` 委托验证章节）；DNS 验证采用宽松策略（所有 dnsTools + 本地全部尝试，任一匹配即有效），目的是尽可能发起续签

## 失败通知 + 到期去重

- （`auto_renew_failed` 模板，仅 seeder、db:seed 幂等可达）：续费/重签失败（含 IP、无委托等跳过类）按到期节点（14/7/3/1 天）发邮件给订单用户。**失败文案归一**：仅「余额不足」「委托无效」两类用户可行动失败给专属清晰文案，IP/上游系统类错误统一走兜底常量 `FALLBACK_REASON`「自动续签未成功，请尽快手动续期」（原始异常仅进 cron 日志、不泄露给用户）；邮件用证书 `common_name` 标识（非 order_id），处理方式按失败类型逐条列出 + 联系客服兜底 + 「登录控制台」按钮（`site_url` 由专用 `AutoRenewFailedNotificationBuilder` 从系统设置注入，**不进模板 variables、Admin 测试发送无需手填**）；**余额检查仅 renew**（reissue 不扣费、不检查余额）；**兜底必发**——任何失败都发通知以堵 `ExpireCommand` 排除自动续签订单后的静默过期洞。`ExpireCommand` 反向排除「会被自动续签/重签处理」的订单（`cert.channel≠api 且 willAutoRenew‖willAutoReissue`）避免同节点重复发到期通知；节点常量与 `isExpireNotifyNode` 由 `Console\Commands\Concerns\ExpireNotifyWindow` trait 两命令共用；`CertExpireNotificationBuilder`/`AcmeExpireNotificationBuilder` 重查窗口上界亦 `use` 该 trait 由 `max(EXPIRE_NOTIFY_NODES)` 派生（非硬编码 14，对齐 `StalledRenewalQuery::forUser`），与派发侧节点同源防漂移（漂移后果：节点扩含 >14 天时派发侧发了 intent、Builder 重查为空 → 整封静默漏发）。修复点：`willAutoReissueExecute` 改判 `product.reissue`（重签不限产品状态），与 `getReissueOrders` 对齐

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

**调度配置**：每小时执行（`routes/console.php`）

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

4. `autoPayAndCommit()` 自动支付提交：
   - 调用 `Action::pay($orderId, true)` 完成支付并提交到 CA

### 相关命令

`php artisan schedule:auto-renew` - 同时处理续费和重签

---
