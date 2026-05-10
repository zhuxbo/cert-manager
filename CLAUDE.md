# Manager Monorepo

> **维护指引**：保持本文件精简，仅包含项目概览和快速参考。详细规范写入 `skills/` 目录。

## 项目结构

```
frontend/
├── shared/ # 共享代码库（@shared/*）
├── admin/ # 管理端应用
├── user/ # 用户端应用
└── base/ # 上游框架（只读）
backend/ # Laravel 11 后端
plugins/ # 插件目录（独立功能模块）
build/ # 构建系统
deploy/ # 部署脚本
skills/ # 开发规范（详细文档）
```

## 核心指令

- **不要自动提交** - 完成修改后等待用户确认"提交"再执行 git commit/push
- **提交前格式化** - 仅对本次 PR 改动的文件跑（避免污染 PR）：
  - PHP：`./vendor/bin/pint`（backend/）
  - 前端 TS/Vue/CSS：`pnpm lint`（含 ESLint + Prettier + Stylelint）
  - Markdown：`git diff --name-only | grep "\.md$" | xargs npx --prefix frontend/admin prettier --write`
  - Shell：`git diff --name-only | grep "\.sh$" | xargs shfmt -i 4 -ci -w`（`brew install shfmt`）
- **base 目录只读** - 通过 git subtree 同步上游代码，不要修改
- **PHP 8.3+** - 双引号变量不加大括号（如 `"$var"` 而非 `"{$var}"`）；例外：变量后紧跟中文等非 ASCII 字符时必须加花括号（`"{$var}，中文"` 而非 `"$var，中文"`），因为 PHP 变量名匹配 `\x80-\xff` 字节
- **测试发现 bug 必须修复代码** - 测试的目的是发现 bug 并修复，绝不修改测试去迎合错误的代码

## 开发规范

详细规范见 `skills/SKILL.md`，按领域组织：

| Skill                     | 内容                                      |
| ------------------------- | ----------------------------------------- |
| `skills/backend-dev.md`   | Laravel API、升级系统、迁移幂等           |
| `skills/acme-module.md`   | ACME 订阅管理（封装下单 + 交付 EAB 模式） |
| `skills/source-api.md`    | 新增上游来源（Order\\Api / Acme\\Api）    |
| `skills/frontend-dev.md`  | Vue 3、Monorepo、共享组件                 |
| `skills/deploy-ops.md`    | 宝塔部署、安全基线                        |
| `skills/build-release.md` | 版本发布、打包、releases.json 校验链      |
| `skills/plugin-dev.md`    | 插件系统、IIFE 打包、安装/更新/卸载       |
| `skills/acme-e2e-test/`   | certbot 端到端测试（Manager + 上游系统）  |

## 知识积累

开发中确定的信息写入对应 skill 文件：

- 新的架构约定或设计模式
- 疑难问题的解决方案
- 文档中缺失的重要信息

## 功能特性

### 委托验证 (delegation)

- `delegation` 提交到 CA 时转换为 `txt`，通过 `dcv.is_delegate` 标记区分
- 产品同步时保留本地的 `delegation` 验证方法
- **委托前缀**：`_dnsauth`（DigiCert 系，精确匹配子域）、`_pki-validation`（Sectigo，模糊匹配回落根域）、`_certum`（Certum，同 Sectigo）。已移除 `_acme-challenge`（ACME 使用独立体系）
- 详见 `skills/backend-dev.md` 委托验证章节

### 插件系统

- **插件目录**：`plugins/` 下按名称组织，每个插件包含 `plugin.json`
- **动态加载**：`PluginServiceProvider` 自动扫描、注册命名空间和 ServiceProvider
- **安全机制**：autoload 使用 `realpath()` 防路径遍历；公共端点仅返回 bundle/css 路径
- **前端加载**：公共 `GET /api/plugins` 返回 bundle 路径，管理端返回完整信息；`plugin-loader.ts`（`@shared/utils/plugin-loader`）统一加载，校验 URL 必须以 `/` 开头
- **共享依赖**：`exposeSharedDeps()` 暴露 Vue/Router/ElementPlus/Pinia + `getAccessToken()`
- **Widget 插槽**：`__registerPlugin` 支持 `widgets` 字段，插件可向已有页面注入组件（如 Dashboard 横幅）；已定义插槽：`user-dashboard-top`（用户端 Dashboard 顶部）
- **版本兼容**：`PluginManager.checkCompatibility()` 通过 `requires` 字段（如 `>=1.0.0`）检查主系统版本
- **解耦原则**：主系统不硬引用插件代码/表，通过动态扫描（`_logs` 后缀表、`user_id` 字段）兼容插件数据
- **插件打包**：`plugins/{name}/build.sh` + `release.json` 独立打包
- **插件管理**：`PluginManager` 提供安装/更新/卸载/检查更新，管理端 `/plugin` 页面操作
- **更新地址优先级**：`plugin.json.release_url`（第三方）→ `{主系统 release_url}/plugins/{name}`（官方）
- **插件 API**：`GET /api/admin/plugin/installed`、`GET /api/admin/plugin/check-updates`、`POST /api/admin/plugin/install`、`POST /api/admin/plugin/update`、`POST /api/admin/plugin/uninstall`
- **数据库约定**：仅支持 MySQL/MariaDB；插件迁移和代码与主系统同等约束（禁用 `->json()` 列，用 `text` + `array` cast；禁用 raw 方言字面量，统一走 Eloquent / Query Builder）。CI 各插件独立 job（`backend-{name}-plugin-test`），无自带 tests 的插件也跑 migrate + schema 检查。详见 `skills/plugin-dev.md`

### ACME 订阅管理

- **模型**：单一 `Acme` 模型（`App\Models\Acme`，表 `acmes`），`eab_hmac` 加密存储且默认 hidden；`eab_kid` 建索引；`plus` 列（赠送时间 0/1）；`contact_email` 列（`VARCHAR(254) NULL`，ACME 账号邮箱 — RFC 8555 `contact`）
- **字段映射**：上游响应 `data.order_id` → 本地 `acmes.api_id` 列（**切勿用 `api_id` 键读上游响应**，老代码踩过坑）
- **计费流程**：`Action` 三步流程：`new(array $params)`（unpaid/待支付）→ `pay(int $id, bool $autoCommit = true)`（Admin/User 入口默认"先支付独立事务，再单独事务调 commit"——commit 失败 **不回滚扣费**，订单保留 pending 可走 `commit` 接口重试；`$autoCommit=false` 仅置 pending，由 batchPay 统一入队 commit）→ `commit(int $id)`（提交 上游系统 → active）；`newAndCommit(array $params)` 一步完成三步（**单事务原子，失败回滚**，API 入口使用）
- **上游 `/acme/new` 入参**：`source`（路由用，上游忽略）/ `contact_email`（= `acmes.contact_email`，**所有入口必填**：User/Admin 表单 / API Token / Deploy Token；上游正常返回会覆盖回写，缺失则保持本地值）/ `product_code` / `plus`（赠送时间）/ `refer_id`（幂等键），**不传** `period`/`purchased_*count`/`product_type` 等。**字段名与多级代理链路全程对齐**；Certum 侧的 `customer` 术语仅存在于 Gateway → Certum SDK 的最后一跳
- **directory_url 缓存**：Laravel `Cache::forever("acme_directory_url:{ca}")` 按签发 CA 聚合；commit/sync 刷新、show 缺失时回源一次性回填；不入 system_setting、不落库
- **取消流程**：Web 入口走延时 — `commitCancel(int $id)`（标记 cancelling + 创建 Task `cancel_acme` + TaskJob 延时 123s）→ `cancel(int $id)`（由 TaskJob 调用，调 Api->cancel() + 退费）；下游 API（`/api/acme/cancel`）走 `cancelNow(int $id)`，不创建 Task、同步调 `cancel()` 立即返回
- **撤回取消**：`revokeCancel(int $id)` 在 acme.status=cancelling 且延时任务未执行时生效 — 悲观锁回滚 status→active、清空 cancelled_at、删除 executing/stopped 的 `cancel_acme` Task（已 dispatch 的 TaskJob 唤醒后找不到任务直接跳过）
- **Transaction 类型**：`acme_order`/`acme_cancel`（一对一防重，禁止重复 `transaction_id`；仅传统 `order` 因证书重签增域名场景允许重复）
- **产品标识**：`products.product_type = 'acme'`
- **Source API 层**：`Services/Acme/Api/` 按 `product.source` 路由，仅 `default` 源（和 Order 一致），`AcmeSourceApiInterface` 统一 `new`/`get`/`cancel`/`getProducts` 接口，`default/Sdk` 通过系统设置 `ca.acme_url`/`ca.acme_token`（回落到 `ca.url`/`ca.token`）调用 上游系统 `/api/acme/*` 端点
- **产品导入**：`Order\Action::importProduct()` 同时查询 Order 和 ACME 两端产品，合并后按 `api_id` 去重
- **控制器路由**：
  - API：`/api/acme/` — new, get, cancel, get-products（对下游代理，与 上游系统 对齐）
  - Admin：`/api/admin/acme/` — index, show, new, pay, commit, sync, commit-cancel, revoke-cancel, remark（管理员备注）
  - User：`/api/user/acme/` — index, show, new, pay, commit, commit-cancel, revoke-cancel, remark（用户自己的备注，限当前用户）
  - Deploy：`/api/deploy/acme/` — new（一步到位：创建+支付+提交）, get（含 EAB + directory_url）
- **搜索**：Admin/User 控制器 `index` 支持 quickSearch / id / status / brand / period / **eab_kid 前缀匹配（走索引）** / amount 范围 / product_name / created_at / period_till 范围；Admin 额外 user_id/username
- **产品 API 分离**：`/api/v2/get-products` 排除 ACME 产品，`/api/acme/get-products` 仅返回 ACME 产品；下单页面产品选择器通过 `exclude_product_type=acme` 过滤
- **传统流程完全隔离**：ACME 通过独立控制器、服务和前端模块处理，与传统订单无交集；V2 API `new` 和 `Order\Action::initParams` 拒绝 ACME 产品
- **批量操作**：列表页 6 个批量按钮
  - `POST /api/{admin,user}/acme/batch-pay` — 同步逐条扣费（pay autoCommit=false），成功的 id 批量入队 `commit_acme`；状态限 unpaid，返回 `{success_count, commit_count, errors}`
  - `POST /api/{admin,user}/acme/batch-commit` — 创建 `commit_acme` Task 立即入队，状态限 pending；`checkRepeat` 存在 executing 任务时整体报错
  - `POST /api/{admin,user}/acme/batch-sync` — 创建 `sync_acme` Task 立即入队，状态限 active/cancelling（必须有 api_id），pending/unpaid 无 api_id 无法同步
  - `POST /api/{admin,user}/acme/batch-commit-cancel` — 同步逐条调单体 commitCancel（混合直接退费 + 延时 Task），状态限 unpaid/pending/active；单体 commitCancel 已扩展支持 unpaid（未扣费无需 refund）
  - `POST /api/{admin,user}/acme/batch-revoke-cancel` — 同步逐条调单体 revokeCancel，状态限 cancelling
  - `POST /api/{admin,user}/acme/batch-copy-eab` — 纯读返回 EAB 文本（`directory_url\neab_kid\neab_hmac`，条目间空行），**Admin 端跨用户拒绝**；User 端 UserScope 自动限制
- **TaskJob 分发**：`commit_acme / sync_acme / cancel_acme` 统一去 `_acme` 后缀调 `Acme\Action::{commit,sync,cancel}`；其余 action 走 `Order\Action`
- **User 端同步**：`POST /api/user/acme/sync/{id}`（与 Admin 对齐）
- **checkRepeat 并发限制**：与 Order 相同，`checkRepeat` 和 `createTasks` 之间无事务锁，并发情况下可能产生双份 executing Task；沿用 Order 设计，属已知限制

## 系统架构约定

- **`$order->latestCert` 非空保证**：由系统架构保证 latestCert 关系非空，查询时加 `with('latestCert')` 预加载即可，无需额外空值判断
- **`$this->error()` 方法**：来自 `ApiResponse` trait，调用后抛出异常终止执行，不会继续后续代码
- **取消/吊销不静默成功**：上游接口未返回明确成功时，一律返回失败；不允许跳过上游调用直接标记本地状态
- **ACME 计费流程**：`Action` 三步流程 `new→pay→commit` 详见"ACME 订阅管理"章节
- **ACME 取消策略**：未提交上游（无 api_id）的 pending 订单直接退费取消；已提交上游的订单通过延时任务调 Api->cancel() 后退费
- **Action 无 userId 构造参数**：`Acme\Action` 和 `Order\Action` 均无 `userId` 构造参数，通过 `app(Action::class)` 获取实例。用户隔离由 UserScope 全局作用域保证（`Authenticate`/`ApiAuthenticate` 中间件注册 Acme、ApiToken、Callback、CnameDelegation、Order、Fund、Transaction、Organization、Contact、OrderDocument），控制器在创建方法的 params 中传入 `user_id`。UserScope `apply()` 无条件执行 `where('user_id', ...)`，不做零值跳过
- **ACME Action 统一封装上游 API 调用**：所有上游 API 调用（new/get/cancel 等）必须通过 `Services/Acme/Action`，不允许控制器直接调 `Api`。操作方法接收 ID（int），创建方法接收参数数组。内部负责模型查询、参数过滤、返回值校正、重复提交防护、状态入库。控制器仅做请求验证 + 一行调用 Action
- **资金/状态变更必须在事务 + 行锁内**：任何涉及 `Transaction::create`/余额变动/状态机变更的路径都要 `DB::transaction` + 目标行 `lock()`/`lockForUpdate()`，且**状态检查放在锁内**（锁外校验会被并发绕过）。Order 走 `Order::with(['latestCert'])->whereHas('latestCert')->lock()` 约定锁 + 所有变更走 `$order->latestCert->update()` 路径；ACME 走 `Acme::lock()` 直接锁 status 所在行。`$this->success()` 必须放在事务闭包**外**（它抛 `ApiResponseException` 会触发回滚）；`$this->error()` 放闭包内正好触发回滚。unpaid 等无资金流水的状态清理路径可免锁（双击第二次自然报错无资金损害）。TaskJob::handle 必须整体包 `DB::transaction`，否则 `lockForUpdate` 在自动提交模式下是"假锁"（SELECT 返回即释放）。**支付路径必须同时锁 user 行**（否则同一用户跨订单并发支付会绕过 credit_limit 校验）：ACME `User::lockForUpdate()`，Order `with(['user' => fn ($q) => $q->lockForUpdate(), 'latestCert'])`。**ACME commit 也需要锁内调用上游**：与 commitCancel 串行化防止"上游已建单 + 本地被改 cancelled 后又被 commit 覆盖回 active"的资金错乱。
- **资金安全四道网（确定性体系）**：分两类 — **物理阻断**（INSERT/UPDATE 之前挡住错账发生）：(1) DB 唯一索引（`funds(pay_method, pay_sn)` / `transactions(type, transaction_id) WHERE type != 'order'`，旧 3 列 `funds_type_pay_method_pay_sn_unique` 已被替换为 2 列）；(2) `Fund::transitionToSuccessful` 完整 5 字段 WHERE（id + amount + type + pay_method + status=0）的 CAS UPDATE 替代 SELECT-then-UPDATE，CAS WHERE 不能简化为 status 单一条件，否则金额/支付方式不匹配的回调也会把本地 fund 标成功；(3) 事务 + 锁 + 锁内二次校验（destroy/batchDestroy 等"删除已入账"路径无法被 CAS 或唯一索引拦截，删除 SQL 本身合法）；(4) `Transaction.php` 防重豁免列表仅 `'order'`（ACME 一对一交付 EAB，不存在重签增域名）。**事后发现**（不阻止发生但保证发现）：(5) `tests/Pest.php` afterEach hook 自动跑 `App\Services\FundAudit\FundInvariants::all()` 4 条 SQL（L1 账目恒等 / L2 事件唯一 / L3 状态-事件配对 / L4 金额配对），动了 funds/transactions/users.balance 的测试自动守门；(6) 每天 03:00 `finance:audit` cron 全量对账，违反走 `NotificationCenter` 邮件告警 + `Log::error` 兜底，可选 `--freeze-on-violation` 自动禁用涉事用户。**关键**：物理阻断不能被替代为事后发现 — 已删除的 fund 即便 invariant 报 orphan transaction，钱已入账、订单已消失，损失已发生。新增资金路径必须保证：状态转换走 CAS 而非 SELECT-then-UPDATE；写 transaction 不依赖应用层 `exists` 防重，靠 DB 唯一索引兜底；修改 user.balance 必须在 `DB::transaction(fn)` 内同事务创建 transaction；CI tearDown invariant 自动校验，新测试无需手写资金断言。详见 `skills/backend-dev.md` 资金确定性体系章节。
- **事务内 dispatch Job 必须加 `->afterCommit()`**：`config/queue.php` 所有连接默认 `after_commit=false`，事务内 dispatch 的 Job 会立即入队，worker 可能在事务提交前消费 Job，读不到事务内新建的行/状态导致任务静默丢失。`createTask`/`createTasks` 等所有 TaskJob::dispatch 调用都已加 `->afterCommit()`，新增 Job dispatch 点也要跟进。
- **统一锁顺序 task→order/acme 防死锁**：`TaskJob::handle` 是 task→order/acme 顺序（先锁 task，action 内再锁业务行）；所有 DELETE/修改 task 的业务路径（Order `revokeCancel`/`commitCancel(active)`/`batchCommitCancel`、ACME `revokeCancel` 等）都必须按同一顺序，先 `Task::where(...)->lockForUpdate()->get()` 拿 task 锁再锁业务行，再做 DELETE。否则 InnoDB 会周期性触发死锁回滚，用户看到随机失败。
- **Transaction::create 必须在 DB::transaction 内调用**：Transaction::creating 钩子内不再开自己的嵌套事务/savepoint——直接使用外层事务保证 balance 修改与 INSERT 的原子性。非事务内调用会抛异常提示。Fund::updating 同理。
- **资金事务优先用 `DB::transaction(fn)` 闭包**：Laravel 自动管 commit/rollback，避免"$row=null 控制流穿透"导致的事务计数器漂移。如必须手写 `DB::beginTransaction` + try/catch（如需在 catch 内捕获 `ApiResponseException` 后再写任务状态等场景），**所有控制流分支必须 commit 或 rollback**（含 no-row、early-return、异常路径），并补单元测试覆盖这些分支——禁止控制流穿透到方法末尾。

### Certum 验证文档上传

- **独立表**：`order_documents`（本地上传的文档）
- **不改 Cert.documents**：该字段仅存 Certum 同步回来的审核状态（只读），职责不同
- **多级代理传递**：用户/Admin 上传 → `order_documents` 表 → 提交到上游（base64 via V2 API）→ 逐级到 上游系统 → Certum SOAP
- **V2 端点**：`POST /api/v2/upload-document`（接收下游 base64）
- **显示条件**：`brand.toLowerCase() === 'certum'` 且 `validation_type !== 'dv'`
- **文件限制**：单文件 5MB，类型 PDF/JPG/JPEG/PNG/XADES，控制器层 `mimes` 验证
- **提交权限**：Admin 和 User 均可提交文档到上游

### 自动续费/重签

- `orders.auto_renew`: 订单级自动续费开关（null 时回落到用户设置）
- `orders.auto_reissue`: 订单级自动重签开关（null 时回落到用户设置）
- `users.auto_settings`: 用户级默认设置 `{"auto_renew": false, "auto_reissue": false}`
- `AutoRenewCommand` 每天 00:00 执行：证书到期前 14 天触发，订单剩余 ≤15 天续费、>15 天重签；API channel 订单由下游控制，不处理
- **延时提交**：Command 创建续费/重签 + 支付后不立即 commit，通过 Task 表创建延时 commit 任务（随机 0~8 小时），分散上游压力，8 点后人工可检查状态
- **产品条件**：续费要求 `product.status=1 && renew=1`；重签仅要求 `reissue=1`（产品禁用仍可重签）
- **参数继承**：从原订单提取 period/contact/organization/domains；CSR 按 `product.reuse_csr` 决定重用或生成
- **委托前置条件**：缺失委托记录时自动创建（`_dnsauth` 精确域名、回落前缀按根域）；DNS 验证采用宽松策略（所有 dnsTools + 本地全部尝试，任一匹配即有效），目的是尽可能发起续签

## 测试

- 单一库（MySQL）：本地需 mysql 5.7 容器（与 CI 对齐），跑 `php artisan test --parallel`
- 详见 `skills/backend-dev.md` 测试章节

### M4 测试覆盖

- **Commands**（55+ 用例）：AutoRenew、Expire、DelegationCheck、DelegationCleanup、Validate、Purge、ResetAdminPassword、ClearAllCache、UserData
- **Models**（14 文件）：Order、User、Cert、Admin、Product、Notification、NotificationTemplate、Contact、Organization、Fund、Transaction、CnameDelegation、ApiToken、Task
- **Middleware**（8 文件）：AdminAuthenticate、UserAuthenticate、ApiAuthenticate、DeployAuthenticate、LogOperation、RateLimiter、LoginRateLimiter、FlushLogs
- **ACME**：Unit/Services/Acme/ActionTest（32 用例）、Feature/Controllers/Admin/AcmeControllerTest（13 用例）、Feature/Controllers/User/AcmeControllerTest（11 用例）、Feature/Controllers/Deploy/AcmeControllerTest（5 用例）
- **Deploy**：Feature/Controllers/Deploy/OrderControllerTest（39 用例）：query（21）、callback（7）、update（8）、认证（2）、数据结构（1）
