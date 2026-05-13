# 后端开发规范

## 技术栈

- **框架**: Laravel 11.x
- **PHP**: 8.3+（双引号变量不加大括号）
- **数据库**: MySQL 8.0
- **缓存/队列**: Redis
- **认证**: JWT (tymon/jwt-auth)
- **代码规范**: PSR-12、PHP Pint、PHPStan

## 目录结构

```
backend/
├── app/
│   ├── Http/Controllers/
│   │   ├── User/           # 用户端 /api/*
│   │   ├── Admin/          # 管理端 /api/admin/*
│   │   ├── V1/             # API v1
│   │   ├── V2/             # API v2
│   │   └── Callback/       # 回调处理
│   ├── Models/
│   ├── Services/           # 业务逻辑层
│   │   ├── Acme/          # ACME 订阅管理（封装下单 + 交付 EAB，不实现 RFC 8555）
│   │   ├── Order/         # 订单服务
│   │   └── Upgrade/       # 升级系统
│   ├── Jobs/               # 队列任务
│   └── Utils/
├── routes/
│   ├── api.user.php
│   ├── api.admin.php
│   ├── api.v1.php
│   ├── api.v2.php
│   └── api.callback.php
└── database/
    ├── migrations/
    └── seeders/
```

## 架构设计

### 纯 API 架构

- 无 session/cookie 依赖，完全前后端分离
- 统一响应格式：成功 `{"code": 1, "data": {...}}`，失败 `{"code": 0, "msg": "..."}`
- 统一异常处理：`ApiResponseException`

### JWT 多端认证

| 端        | 路由前缀               | 认证方式 |
| --------- | ---------------------- | -------- |
| 用户端    | `/api/`                | JWT      |
| 管理端    | `/api/admin/`          | JWT      |
| API v1/v2 | `/api/V1/`, `/api/v2/` | Token    |

---

## 升级系统

### 升级冻结契约

升级期间应用进入只读维护态，避免 in-flight HTTP/Job 半执行：

- **freeze 文件锁**：`storage/framework/upgrade.lock` 文件存在=已 freeze；与 cache driver 完全解耦（`cache:clear` 不会清掉它）
- **HTTP**：`MaintenanceMode` 中间件返回 503 + Retry-After，白名单 `/api/health` / `/api/meta` / `/api/admin/upgrade/*` / admin 会话保活
- **Queue**：`SkipWhenUpgradeFrozen` middleware 在 Job 执行业务前 `release(60)` 早退
- **Schedule**：`routes/console.php` 所有 `Schedule::command(...)` 链 `->skip(fn () => UpgradeFreezeLock::isFrozen())`，freeze 期间不触发 Command
- **smoke test 失败处理**：不 unfreeze，回滚代码到旧版本，旧版 smoke 通过后再恢复服务；都失败保持 freeze + 报警

### 关键服务

| 服务                   | 职责                                     |
| ---------------------- | ---------------------------------------- |
| `UpgradeService`       | 升级主逻辑，`performUpgradeWithStatus()` |
| `UpgradeStatusManager` | 状态管理，动态步骤计算                   |
| `PackageExtractor`     | 包解压和应用，权限检查                   |
| `ReleaseClient`        | Release 获取                             |
| `BackupManager`        | 备份和恢复                               |
| `VersionManager`       | 版本比较，环境检测                       |

### 升级模式

| 特性     | PHP API 升级  | Shell 脚本升级      |
| -------- | ------------- | ------------------- |
| 触发方式 | 管理后台 API  | `deploy/upgrade.sh` |
| 升级包   | `upgrade` 包  | `full` 包           |
| 维护模式 | 自动进入/退出 | 自动进入/退出       |

### 数据库结构校验

升级后自动校验数据库结构与标准 `structure.json` 是否一致。

**配置项** (`config/upgrade.php`):

| 配置                   | 说明                                   |
| ---------------------- | -------------------------------------- |
| `auto_structure_check` | 是否自动校验（默认 true）              |
| `auto_structure_fix`   | 是否自动修复 ADD 类型差异（默认 true） |

**校验流程**:

1. 迁移完成后调用 `DatabaseStructureService::check()`
2. 无差异 → 记录日志
3. 有差异且可自动修复 → 执行 `fix()`（仅 ADD 操作）
4. 有差异但无法自动修复 → 记录警告（不阻断升级）

**手动命令**:

```bash
php artisan db:structure --check        # 检测差异
php artisan db:structure --fix          # 自动修复（仅 ADD）
php artisan db:structure --export       # 导出标准结构
```

**注意**: 每次迁移变更后需重新导出 `structure.json`。

---

## 代码规范

### 命令

```bash
./vendor/bin/pint         # 代码格式化
./vendor/bin/phpstan analyse  # 静态分析
php artisan test          # 测试
```

### 开发流程

1. 遵循功能优先开发
2. PSR-12 编码规范
3. 统一异常处理
4. 分类日志记录

---

## 常用 Artisan 命令

```bash
php artisan upgrade:check     # 检查更新
php artisan upgrade:run       # 执行升级
php artisan upgrade:rollback  # 回滚
php artisan db:structure --check   # 数据库结构校验
php artisan db:structure --fix     # 自动修复结构
php artisan queue:work --queue tasks,notifications  # 队列 worker（消费 TaskJob / NotificationJob）
```

---

## 缓存与日志架构

### 缓存驱动

- 默认使用 `file` 驱动，不强制依赖 Redis
- 生产环境推荐使用 Redis 提升性能
- SnowFlake、RateLimiter 通过 `Cache` facade 操作

### 日志批量写入

- `LogBuffer` 服务：收集请求期间的日志，请求结束后批量写入
- `FlushLogs` 中间件：响应发送后触发日志刷入
- 所有日志模型使用默认数据库连接

---

## Token 认证体系

| Token 类型  | 中间件              | 路由前缀               | 用途             |
| ----------- | ------------------- | ---------------------- | ---------------- |
| ApiToken    | `api.v1` / `api.v2` | `/api/v1/`, `/api/v2/` | 第三方 API 调用  |
| DeployToken | `api.deploy`        | `/api/deploy/`         | 部署工具证书管理 |

### 共同特性

- Token 使用 SHA-256 hash 存储
- 支持 IP 白名单（最多 100 个，`allowed_ips` 字段 2000 字符）
- 支持速率限制（`rate_limit` 字段，每分钟请求数）
- 请求结束后异步更新 `last_used_at` 和 `last_ip`

### DeployToken 特性

- 每个用户仅一个 DeployToken（唯一约束）
- 通过 `UserScope` 限制只能访问用户自己的 Order
- 支持查询证书、续费/重签、部署回调

### certimate URL 拉取模式

- `GET /api/deploy/?order={id|domain}&field=certificate|private_key`：返回纯 PEM 文本（`Content-Type: text/plain`），适配 certimate `BizUpload` 节点 URL 源
- `field=certificate` 返回 `cert + intermediate_cert`（fullchain）；`field=private_key` 返回私钥
- `order` 支持单个数字 ID（跟随 renewed 链）或单个域名（按 `common_name` 精确匹配，`issued_at` 降序取最新 active 证书），续费后 certimate URL 无需变更
- 不带 `field` 时走原 JSON 分页逻辑（向后兼容）

---


## 资金确定性体系（4 道网）

> **目的**：把资金安全从"LLM 审 + 单测 + 锁/事务"的**抽样**强度，升级为"DB 约束 + 应用层 CAS + 自动不变式校验"的**确定性**强度。当 LLM/审核找不到新问题、单测覆盖不到新路径时，多道独立网仍能拦住资金错账或在小时级被发现。

### 设计原则

区分**物理阻断**和**事后发现**两类能力，它们不互相替代：

- **物理阻断**：INSERT/UPDATE 之前挡住错账发生 — DB 唯一索引、CAS UPDATE、事务 + 锁
- **事后发现**：不阻止发生但保证发现 — Pest afterEach hook、每日 cron 对账

**关键**：已删除的 fund 即便 invariant 报 orphan transaction，钱已入账、订单已消失，损失已发生。事后发现仅作"代码 bug + 物理层未覆盖路径"的兜底。

### 物理阻断层

#### 1. DB 唯一索引

文件：`database/migrations/2026_05_07_*_add_fund_transaction_unique_indexes.php`

| 索引                                                                | 含义                                                                                                                                                                                               |
| ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `funds(pay_method, pay_sn)` 唯一                                    | 防"不同 fund 同一支付编号"。MySQL BTREE 索引中 NULL 互不相等，处理中订单 (pay_sn=NULL) 多行合法；落地后的 (pay_method, pay_sn) 才进入唯一性判定                                                     |
| `transactions(type, transaction_id) WHERE type != 'order'` 部分唯一 | 防"同一事件被重复入账"。MySQL generated VIRTUAL 列 + 完全唯一索引模拟（`CASE WHEN type='order' THEN NULL ELSE CONCAT(type,':',transaction_id) END`，NULL 不参与唯一约束，效果等价于部分索引）       |

旧 3 列索引 `funds_type_pay_method_pay_sn_unique` 已被本 migration 删除（语义弱于新 2 列、且 refunds/reverse 走 UPDATE 同行不冲突）。

唯一冲突由 `app/Bootstrap/ApiExceptions.php::causedByDuplicateKey` 翻译为业务消息（"支付编号重复请勿重复支付" / "交易记录已存在"）+ 状态码 409。识别方式：`PDOException` + SQLSTATE 23000 + MySQL errcode 1062 + 约束名识别表。

`Fund::creating` / `Transaction::creating` 钩子内的 `exists` 校验**保留**作为前端速失败提示，不是防重主屏障 — DB 唯一索引才是兜底。

#### 2. Fund.status CAS UPDATE

签名：

```php
Fund::transitionToSuccessful(
    int|string $id,
    string $expectedAmount,      // 上游回调金额
    string $expectedType,        // 'addfunds'（保留扩展空间）
    string $expectedPayMethod,   // 'alipay' / 'wechat'
    string $paySn                // 写入的支付编号
): ?self
```

**关键约束**：CAS WHERE 必须保留 5 字段完整匹配（id + amount + type + pay_method + status=0）— 否则金额/支付方式不匹配的回调也会把本地 fund 标成功并按本地 amount 入账，造成攻击面。

实现要点：

- 查询构建器 `update()` 不触发 Eloquent `updating` 钩子 — 这是 CAS 的优势（避免 SELECT-then-UPDATE 模式），但调用方必须**显式**调 createRecord 等价逻辑写 transaction
- 调用方契约：必须在 `DB::transaction(fn)` 内调用，让 CAS UPDATE + Transaction::create + balance 修改原子提交
- `Fund::updating` 钩子里现存的 `getOriginal('status')` 校验仍要保留 — 给走 Eloquent save 路径的代码（如 Admin update fund 备注、refunds/reverse）兜底

新加资金状态转换路径**禁止**用 `lockForUpdate + 重读 + save` 模式 — 用 CAS。

三处现有调用：`User\TopUpController` / `Admin\FundController` / `User\FundController` 的 `addfundsSuccessful`。

#### 3. 事务 + 锁 + 锁内二次校验

destroy/batchDestroy/Order::delete 等"删除已入账 fund"路径无法被 CAS 或唯一索引拦截（删除 SQL 本身合法）。必须事务内 `lockForUpdate` + 锁内 status/created_at 重读校验。已落地：

- `app/Http/Controllers/Admin/FundController.php::destroy / batchDestroy`
- `app/Services/Order/Traits/ActionTrait.php::delete`

#### 4. Transaction 防重豁免

`app/Models/Transaction.php` `creating` 钩子的 `exists` 校验排除列表收紧到 `['order']` — 仅 SSL 证书重签增域名场景允许重复 `transaction_id`。`acme_order` 是一对一交付 EAB，必须防重。

DB 部分唯一索引 `WHERE type != 'order'` 与此一致，覆盖应用层漏失。

### 事后发现层

#### 5. CI Pest afterEach hook

文件：`tests/Pest.php`

注册 hook，限定到资金相关测试目录（`Feature/FundAudit`、`Feature/Http/Controllers/{User,Admin}/{Fund,TopUp}*`、`Unit/Services/{Order,Acme}/ActionTest.php`、`Feature/Models/{Transaction,Fund,User}Test.php`）。

每个测试 afterEach 自动调 `app(\App\Services\FundAudit\FundInvariants::class)->all()`，违反 → `test()->fail($msg)`。`RefreshDatabase` 包外层事务，hook 在 rollback 之前跑 — 能看到测试期间的所有变更。

新增动了资金的测试**不需要**手写资金断言 — hook 自动守门。

#### 6. 每天 03:00 finance:audit cron

文件：`app/Console/Commands/FundAuditCommand.php` + `routes/console.php` 注册 `Schedule::command('finance:audit')->dailyAt('03:00')`。

命令调 `FundInvariants::all()` 全量对账，违反 → 走 `NotificationCenter`（`code=finance_audit_alert`）发邮件给 `site.adminEmail` + `Log::error` 兜底。可选 `--freeze-on-violation` 自动把涉事 user.status=0 禁用。

命令本身始终返回 0（不被 retry）；仅 invariant 自身崩溃返回 1。错开 AutoRenew 00:00 时段。

### 4 条 invariant SQL

`App\Services\FundAudit\FundInvariants` 4 个公开方法：

| Layer | 方法                   | 含义                                                                                                                                     |
| ----- | ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| L1    | `accountingIdentity()` | 每个 user：`SUM(transactions.amount per user) == users.balance`                                                                          |
| L2    | `eventUniqueness()`    | `(type, transaction_id) WHERE type != 'order'` 不允许重复（DB 唯一索引的事后监控）                                                       |
| L3    | `statePairing()`       | 每条 `funds.status ∈ (1,2)` 必须有对应 transaction（`user_id + type + transaction_id=fund.id`），双向：fund 缺 tx 与 tx 缺 fund 都报警   |
| L4    | `amountPairing()`      | 每条 `funds.status ∈ (1,2)` 与对应 transaction.amount **按业务符号严格匹配**（addfunds/reverse 同号、deduct/refunds 反号），不仅比绝对值 |

返回 `array<InvariantViolation>`，空数组表示通过。

**L1 不变式前提**：所有 `user.balance` 变更必须经 `Transaction::create` 路径（`Transaction::creating` 钩子内 `bcadd` 修改 balance + 写流水原子）。**禁止**直接 SQL/Eloquent 改 balance；生产历史余额迁移走 admin "添加资金记录" 手工充值（`POST /api/admin/fund` type=addfunds），自然产生 transaction 流水。测试 helper（`UserFactory::withBalance` / `CreatesTestData::createTestUser`）也通过 `Transaction::create` 走钩子路径，**不直写 balance**——直写会被 Pest invariant hook 立即拦下。

**性能**：起步规模（500 user / 1 万 transactions）单次 < 200ms。索引前提：`transactions(user_id)`（外键已有）、`transactions(type, transaction_id)`（Task 2 唯一索引顺带覆盖）。

### 已知"order 类型允许重复 transaction_id"特例

仅 SSL 证书重签增域名场景：一笔 order 在重签时新增了 N 个域名 → 再次扣费 → 共享同一 `transaction_id`。`Transaction.php` 防重排除 `'order'` 类型，DB 部分唯一索引 `WHERE type != 'order'` 也排除。

### 新增资金路径的 checklist

1. 状态转换用 CAS UPDATE 而非 SELECT-then-UPDATE，CAS WHERE 必须完整字段匹配（不能简化为单一 status 条件）
2. 写 transaction 不依赖应用层 `exists` 防重 — DB 唯一索引兜底
3. 修改 user.balance 必须在 `DB::transaction(fn)` 内 + 同事务内创建对应 transaction
4. 测试只需直接调用业务路径，invariant hook 自动守门 — 不需要手写"transaction 已写"等资金断言
5. 删除已入账 fund 路径必须事务内 `lockForUpdate` + 锁内 status/created_at 二次校验
6. 上线前先跑 `php artisan finance:audit` 确认现有数据干净，否则改约束之后下一次相关 INSERT 触发"交易记录已存在"误报

## MySQL 兼容性

- 兼容 MySQL 5.7，不使用 `json` 字段类型
- 数组类型字段使用 `string` 存储，由 Laravel 模型 `'array'` cast 自动 JSON 序列化

## 迁移规范

- **enum vs string**：系统核心字段用 `enum` 保证约束（如 `product_type`、`status`）；插件可扩展的字段用 `string`，方便插件写入自定义值
- **优先 Laravel 系统方法**：迁移优先使用 `Schema::table` + Blueprint 方法，避免 `DB::statement` 裸 SQL
- **up 幂等**：修改表结构的迁移必须先检查当前状态（`Schema::hasColumn`/`Schema::hasTable`），避免重复执行报错
- **不写 down**：迁移只写 `up()`，不写 `down()`。生产环境不做回滚，回滚用新迁移前进修复
- **structure.json 不手动改**：迁移变动后发布前通过 `php artisan db:structure --export` 重新导出
- **导出前用干净测试库**：避免开发库脏数据或插件表干扰

### 迁移幂等示例

```php
// 添加字段
if (! Schema::hasColumn('orders', 'new_field')) {
    Schema::table('orders', function (Blueprint $table) {
        $table->string('new_field')->nullable()->after('existing_field');
    });
}

// 删除字段
$columns = ['col_a', 'col_b'];
$toDrop = array_filter($columns, fn ($col) => Schema::hasColumn('orders', $col));
if (! empty($toDrop)) {
    Schema::table('orders', function (Blueprint $table) use ($toDrop) {
        $table->dropColumn($toDrop);
    });
}

// 创建表
if (Schema::hasTable('new_table')) {
    return;
}
Schema::create('new_table', function (Blueprint $table) { ... });

// 修改 enum 值（扩展/缩减）
Schema::table('products', function (Blueprint $table) {
    $table->enum('product_type', ['ssl', 'codesign', 'smime', 'docsign', 'acme'])->default('ssl')->change();
});
```

---

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

## 委托验证

### 验证方法转换

用户选择 `delegation` 验证方法时：

1. `ActionTrait::generateDcv()` 将 method 转换为 `txt`
2. 设置 `dcv['is_delegate'] = true` 和 `dcv['ca']` 标记
3. `generateValidation()` 查找用户的 CnameDelegation 记录
4. validation 数组包含 `delegation_id`、`delegation_target`、`delegation_valid`、`delegation_zone`

### 委托前缀

| 前缀              | CA                  | 匹配规则           |
| ----------------- | ------------------- | ------------------ |
| `_dnsauth`        | DigiCert、TrustAsia | 严格子域匹配       |
| `_pki-validation` | Sectigo             | 优先子域，回落根域 |
| `_certum`         | Certum              | 优先子域，回落根域 |

> ACME 通道证书由客户端自行验证，不走委托体系，不使用 `_acme-challenge` 前缀。

### TXT 记录自动写入

**触发时机**：订单创建时，`ActionTrait::generateCsr()` 调用 `writeDelegationTxtRecords()`

**处理流程**：

1. 检查 `dcv['is_delegate'] = true`
2. 按 `delegation_id` 分组收集验证 tokens
3. 跳过无效委托（`delegation_valid = false`）或已写入的记录
4. 调用 `DelegationDnsService::setTxtByLabel()` 批量写入 TXT 记录
5. 更新 validation 中的 `auto_txt_written` 和 `auto_txt_written_at` 标记

**validation 字段说明**：

| 字段                  | 说明            |
| --------------------- | --------------- |
| `delegation_id`       | 委托记录 ID     |
| `delegation_target`   | CNAME 目标 FQDN |
| `delegation_valid`    | 委托是否有效    |
| `delegation_zone`     | 委托的根域名    |
| `auto_txt_written`    | TXT 是否已写入  |
| `auto_txt_written_at` | 写入时间        |

### 即时检测

`ValidateCommand::checkDelegationValidity()` 在验证前即时检测委托记录状态。

### DCV 数据合并

从上游 API 更新 dcv 时必须保留委托标记，使用 `ActionTrait::mergeDcv()` 方法：

```php
// 保留 is_delegate 和 ca 标记
$cert->dcv = $this->mergeDcv($result['data']['dcv'] ?? null, $cert->dcv);
```

涉及位置（`Action.php`）：

- 提交订单后更新 dcv
- 同步订单时更新 dcv
- 修改验证方法时（processing 状态）

### 前端判断逻辑

`validation.vue` 的 `getDisplayMethod()` 根据 `dcv.is_delegate` 返回验证方法：

```javascript
const getDisplayMethod = dcv => {
  if (dcv?.is_delegate) return "delegation";
  return dcv?.method;
};
```

### 委托验证自动续签数据流

```
用户创建订单（validation_method=delegation）
    ↓
ActionTrait::generateDcv()
    → method 转换为 txt
    → 设置 is_delegate=true, ca=xxx
    ↓
ActionTrait::generateValidation()
    → CnameDelegationService::findValidDelegation() 查找委托
    → 找不到则 createOrGet() 创建
    → 填充 delegation_id, delegation_target, delegation_valid
    ↓
ActionTrait::writeDelegationTxtRecords()
    → 按 delegation_id 分组
    → DelegationDnsService::setTxtByLabel() 批量写入
    → 标记 auto_txt_written=true
    ↓
订单提交到 CA（dcv.method=txt）
    ↓
ValidateCommand 定时验证
    → checkDelegationValidity() 即时检测
    → 触发 CA 验证
    ↓
证书签发完成
    ↓
[到期前 15 天] AutoRenewCommand（排除 acme 通道）
    → checkDelegationValidity() 即时检查委托有效性
    → 无有效委托 → 跳过，不发起续费/重签
    → 有有效委托 → 强制使用 delegation 验证方法
    → 重新走上述流程
```

### 委托 DNS 清理

`DelegationCleanupCommand` 每天 06:00 清理无效的委托 TXT 记录：

- **保留**：`processing` 状态订单使用的委托记录
- **删除**：代理域名下所有其他 TXT 记录
- **清理数据库标记**：移除已删除记录对应的 `auto_txt_written` 标记

### 相关服务

| 服务                     | 文件位置               | 职责                     |
| ------------------------ | ---------------------- | ------------------------ |
| `CnameDelegationService` | `Services/Delegation/` | 委托记录管理、有效性检测 |
| `DelegationDnsService`   | `Services/Delegation/` | DNS TXT 记录操作         |
| `AutoDcvTxtService`      | `Services/Delegation/` | 订单维度的自动 TXT 写入  |

---

## 关键文件索引

### 委托验证与自动续签

| 文件                                             | 关键方法/位置                 | 说明                                  |
| ------------------------------------------------ | ----------------------------- | ------------------------------------- |
| `Services/Order/Traits/ActionTrait.php`          | `generateDcv()`               | delegation→txt 转换，设置 is_delegate |
| `Services/Order/Traits/ActionTrait.php`          | `generateValidation()`        | 委托记录查找/创建                     |
| `Services/Order/Traits/ActionTrait.php`          | `writeDelegationTxtRecords()` | 订单创建时写入 TXT                    |
| `Services/Order/Traits/ActionTrait.php`          | `getDelegationPrefixForCa()`  | CA 前缀映射                           |
| `Services/Order/Traits/ActionTrait.php`          | `mergeDcv()`                  | API 响应合并保留委托标记              |
| `Services/Delegation/CnameDelegationService.php` | `findDelegation()`            | 智能匹配委托记录（用于即时验证场景）  |
| `Services/Delegation/CnameDelegationService.php` | `findValidDelegation()`       | 智能匹配有效委托记录（已弃用）        |
| `Services/Delegation/CnameDelegationService.php` | `checkAndUpdateValidity()`    | 即时检测 CNAME 并更新有效性           |
| `Services/Delegation/DelegationDnsService.php`   | `setTxtByLabel()`             | 批量写入 TXT 记录                     |
| `Services/Delegation/AutoDcvTxtService.php`      | `handleOrder()`               | 订单级 TXT 处理                       |
| `Console/Commands/AutoRenewCommand.php`          | `checkDelegationValidity()`   | 发起前即时检查委托有效性              |
| `Console/Commands/AutoRenewCommand.php`          | `processOrder()`              | 自动续费/重签处理                     |
| `Console/Commands/AutoRenewCommand.php`          | `autoPayAndCommit()`          | 自动支付提交                          |
| `Console/Commands/ValidateCommand.php`           | `checkDelegationValidity()`   | 验证前即时检测                        |
| `Console/Commands/DelegationCleanupCommand.php`  | `handle()`                    | 清理非 processing 状态的 DNS 记录     |

### 调度配置

| 命令                  | 调度       | 说明               |
| --------------------- | ---------- | ------------------ |
| `schedule:validate`   | 每分钟     | 证书验证任务       |
| `schedule:auto-renew` | 每小时     | 自动续费/重签      |
| `delegation:check`    | 每天 05:30 | CNAME 委托健康检查 |
| `delegation:cleanup`  | 每天 06:00 | 委托 DNS 清理      |

---

## 测试

### 运行测试

```bash
php artisan test --parallel                           # 全部测试（需 MySQL，推荐加 --parallel 与 CI 一致）
php artisan test --parallel --exclude-group=database  # 纯单元测试（无需数据库）
php artisan test --coverage --min=80                  # 覆盖率报告
```

> **CI 经验**：本地务必用 `--parallel` 跑测试，与 CI 保持一致。`paratest`（并行测试）对 PHP Warning 的处理比 `phpunit` 更严格——例如无命名空间文件中的 `use Mockery;`、`use ZipArchive;` 等全局类 use 语句，`phpunit` 仅输出 Warning 继续运行，而 `paratest` 会直接 fatal exit 导致 CI 失败。

### 测试分组

- `#[Group('database')]` - 需要数据库连接的集成测试
- 无标记 - 纯单元测试，可在任何环境运行

### 测试文件

| 目录/文件                                            | 类型   | 说明                  |
| ---------------------------------------------------- | ------ | --------------------- |
| `tests/Unit/Services/Order/Utils/DomainUtilTest.php` | 纯单元 | 域名工具类（66 测试） |
| `tests/Unit/Services/Order/Utils/CsrUtilTest.php`    | 纯单元 | CSR 工具类（39 测试） |
| `tests/Unit/Services/Delegation/*StaticTest.php`     | 纯单元 | 委托服务静态方法      |
| `tests/Unit/Services/Delegation/*Test.php`           | 集成   | 委托服务数据库操作    |
| `tests/Unit/Services/Order/AutoRenewServiceTest.php` | 集成   | 自动续费判定逻辑      |

### CreatesTestData Trait

`tests/Traits/CreatesTestData.php` 提供测试数据创建方法：

| 方法                     | 说明                         |
| ------------------------ | ---------------------------- |
| `createTestUser()`       | 创建测试用户                 |
| `createTestProduct()`    | 创建测试产品（使用 Factory） |
| `createTestOrder()`      | 创建测试订单                 |
| `createTestCert()`       | 创建测试证书                 |
| `createTestDelegation()` | 创建测试委托记录             |
| `generateTestCsr()`      | 生成测试 CSR                 |

### 编写测试规范

1. **纯单元测试**：测试静态方法、工具函数，不依赖数据库
2. **集成测试**：需要数据库时，添加 `#[Group('database')]` 标记
3. **使用 DataProvider**：参数化测试用例
4. **Mock 策略**：外部服务（DNS、上游 API）使用 Mockery 模拟
5. **测试必须反映真实约束**：不要为架构上不可能的场景编写测试（如 `latestCert` 为 null），也不要在代码中用防御性检查掩盖此类错误——如果真的发生，应让系统抛出异常暴露问题，而非静默返回

### 资金核心变异测试

`pest-plugin-mutate`（Pest 4 自带）针对资金核心代码做变异测试，验证测试质量没有静默退化（覆盖了但断言不强 → 改代码不报错）。

**范围**（6 个 class，spec 决策）：

- `App\Models\Fund`
- `App\Models\Transaction`
- `App\Services\Acme\Action`
- `App\Services\Order\Action`
- `App\Services\Order\AutoRenewService`（自动续费/重签的判断逻辑，资金敏感）
- `App\Services\FundAudit\FundInvariants`

**触发场景**：

- **改了上述 5 个 class 中任一文件 → 必须主动跑 `composer test:mutate` 自检**（最重要的触发点，开发者自我把关）
- 正式版（main 通道）release 前必跑（`/remote-release` 命令的 §3.0 步骤）
- **预发布版（dev 通道）不跑**（试错性质，门禁仅在正式版生效）
- **CI 不跑**（避免 PR 等待 5-7 min，且 release 前已有人工门禁兜底）

**门槛**：

- baseline 文件 `backend/tests/.mutation-baseline.json` 入库，`min_msi` 字段为门槛
- pest 跑出的 MSI 必须 ≥ `min_msi`，否则 fail
- baseline **只升不降**：跑出更高 MSI 时手动上调，不允许靠下调 baseline 让发布通过

**baseline 演进规则**：

baseline 不是终点，而是逐步提升的安全网。演进发生在四个时机，每次都用**独立的 `chore:` commit**（不混进业务 PR）。

| 时机 | 触发者 | 操作 |
|---|---|---|
| **A) 补了测试** | 改资金代码的 PR 作者 | 本机 `composer test:mutate` 看 MSI；如果升高 ≥ 2%，单独 commit 调高 `min_msi`（最多 = `floor(实测 - 2)`，留 2% 缓冲） |
| **B) release 前发现自然升高** | 跑 `/remote-release` 的人 | 跑完看分数高于 baseline ≥ 3%，先 commit 调高 baseline 再走发布流程 |
| **C) 范围扩展** | 决策加新核心 class 的人 | 在 `backend/scripts/test-mutate.sh` 加新 `--class=...`，重跑出新 baseline，整体调整 `min_msi` |
| **D) 退步（MSI 跌破 baseline）** | 发现退步的人 | **禁止下调 baseline**——必须先补测试让 MSI 回升；除非该 untested mutation 已评估无害（如不可达分支），此时应在源码加 `// pest-mutate-ignore` 标记，而非动 baseline |

**长期阶段路线**：

| 阶段 | min_msi 目标 | 实测 | 重点 |
|---|---|---|---|
| 第一阶段（已完成） | 77 | 80.12 | 6 class baseline 落地 |
| 第二阶段（已完成） | 88 | 90.96 | FundInvariants 加 message 弱断言 + 加入 AutoRenewService |
| 第三阶段 | 93+ | — | 消化剩余 15 个 untested（多为 ConcatSwitchSides 等价突变，性价比低）或扩范围到退费明细 |

**首次跑出 baseline**：

```bash
cd backend
XDEBUG_MODE=coverage ./vendor/bin/pest --mutate \
    --class='App\Models\Fund' \
    --class='App\Models\Transaction' \
    --class='App\Services\Acme\Action' \
    --class='App\Services\Order\Action' \
    --class='App\Services\Order\AutoRenewService' \
    --class='App\Services\FundAudit\FundInvariants' \
    --covered-only --parallel
# 看 Score: X%，把 floor(X - 3) 写到 tests/.mutation-baseline.json 的 min_msi
```

**日常使用**：

```bash
cd backend
composer test:mutate                  # 跑全量门禁（按 baseline 门槛）
composer test:mutate -- --bail        # 遇到第一个 untested 立即停（debug 用）
composer test:mutate -- --class='App\Models\Fund'   # 仅跑某个 class
```

**依赖**：

- 本机 PHP 必须装 xdebug 或 pcov（变异测试需要 code coverage driver）
- 本机必须装 jq（`brew install jq` / `apt install jq`）

**为什么不入 CI**：

- 全量 5-7 min（用 `--covered-only` 优化后），单跑某个 class 约 6 min
- PR path-filter 触发会让改资金代码的 PR 等额外 5-7 min
- release 前门禁是关键时刻，本地跑足够保证质量；开发者改资金代码时主动跑做第一道把关

---

## PHP 8.x 废弃函数

项目要求 PHP 8.3+，以下函数已废弃，不要使用：

| 废弃函数                           | 替代方案                                                         | 废弃版本 |
| ---------------------------------- | ---------------------------------------------------------------- | -------- |
| `curl_close($ch)`                  | `unset($ch)` 或不调用（PHP 8.0 起 curl handle 是对象，自动释放） | PHP 8.4  |
| `$reflection->setAccessible(true)` | 直接删除（PHP 8.1 起反射默认可访问）                             | PHP 8.4  |

### TencentCloud SDK

`app/Services/Delegation/Sdk/TencentCloud/` 已从官方 SDK 复制为本地维护，需按当前 PHP 版本修复废弃调用。
