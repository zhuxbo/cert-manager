# Manager Monorepo

> **维护指引**：保持本文件精简，仅包含项目概览和快速参考。详细规范写入 `skills/` 目录。

## 项目结构

```
frontend/
├── shared/ # 共享代码库（@shared/*）
├── admin/ # 管理端应用
└── user/ # 用户端应用
backend/ # Laravel 13 后端
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
- **PHP 8.3+** - 双引号变量不加大括号（如 `"$var"` 而非 `"{$var}"`）；例外：变量后紧跟中文等非 ASCII 字符时必须加花括号（`"{$var}，中文"` 而非 `"$var，中文"`），因为 PHP 变量名匹配 `\x80-\xff` 字节
- **测试发现 bug 必须修复代码** - 测试的目的是发现 bug 并修复，绝不修改测试去迎合错误的代码
- **Plan 文档必须含杀手场景 + 对端检查** - 写 `.superpowers/` 下 plan 前先填这两栏（见 `skills/review-checklist.md` 设计期清单）；回答不出来视为设计未完成，不开始写代码
- **完成检查必跑 reviewer 循环** - `/finish-check` 阶段 8 强制委派 reviewer subagent，每轮报告落盘 `.superpowers/reviews/<run>/round-N.md`，对**落盘文件** `grep -F "REVIEW_PASS:"` 命中前缀签字才算通过（自己写一行再 grep 自己 = 流程未执行）；签字行随 commit/PR body 落库，`review-pass-gate.yml` 在 PR→main 时校验
- **前端 pnpm 11** - 本地用 `corepack enable` 启用（需 Node ≥22.13；CI 的 `pnpm/action-setup` 自动按 `packageManager` 字段跟随）。pnpm 11 默认开启供应链安全：`minimumReleaseAge`（拒绝 24h 内新发布的包，批量升级遇阻时删 `pnpm-lock.yaml` 重解析即可选到合规版本）+ `allowBuilds`（build 脚本白名单，在 `pnpm-workspace.yaml` 显式列出，已弃用 `onlyBuiltDependencies`）。详见 `skills/frontend/frontend-dev.md`

## 开发规范

详细规范见 `skills/SKILL.md`，按领域组织：

| Skill                                 | 内容                                                              |
| ------------------------------------- | ----------------------------------------------------------------- |
| **backend/**                          | 后端领域                                                          |
| `skills/backend/core.md`              | 技术栈/架构/代码规范/Artisan/缓存日志/关键文件索引/测试           |
| `skills/backend/order-fund.md`        | order 级互斥锁、下单韧性、资金四道网、支付验签、退款/Purge        |
| `skills/backend/auth.md`              | Token 认证、安全补强（tasks 死锁/归档解压/节流/凭据URL）          |
| `skills/backend/upgrade.md`           | 升级系统、freeze 契约（unfreeze 先于 up/watchdog）、BinaryLocator |
| `skills/backend/database.md`          | 迁移规范、列类型防溢出、MySQL 5.7/8.x 兼容、DB 时区固化           |
| `skills/backend/delegation.md`        | 委托验证、S/MIME 验证字段、清理白名单、失效周巡检+熔断            |
| `skills/backend/acme-module.md`       | ACME 订阅管理（封装下单 + 交付 EAB 模式）                         |
| `skills/backend/source-api.md`        | 新增上游来源（Order\\Api / Acme\\Api）                            |
| `skills/backend/auto-renew.md`        | 自动续费/重签、算法继承防降级、失败兜底通知                       |
| `skills/backend/sm2-cert.md`          | 国密 SM2 双证书、能力探测、fail-closed、下载包                    |
| `skills/backend/certum-document.md`   | Certum 验证文档上传、异步转发上游、跨级去重                       |
| `skills/backend/enterprise-lookup.md` | 工商查询（阿里云）+ 邮编查询、企业-联系人绑定                     |
| `skills/backend/notification.md`      | 通知体系（mail/插件通道/携密安全/SystemAlert 运维告警）           |
| **frontend/**                         | 前端领域                                                          |
| `skills/frontend/frontend-dev.md`     | Vue 3、Monorepo、共享组件                                         |
| **plugins/**                          | 插件领域                                                          |
| `skills/plugins/plugin-dev.md`        | 插件系统、IIFE 打包、安装/更新/卸载                               |
| **ops/**                              | 部署/发布                                                         |
| `skills/ops/deploy-ops.md`            | 宝塔部署、安全基线、升级中断恢复 runbook                          |
| `skills/ops/build-release.md`         | 版本发布、打包、releases.json 校验链                              |
| **根（跨领域）**                      |                                                                   |
| `skills/review-checklist.md`          | 设计期"杀手场景 + 对端检查" + finish-check 反模式扫描             |
| `skills/acme-e2e-test/`               | certbot 端到端测试（Manager + 上游系统）                          |

## 知识积累与文档分层

新知识按"红线常驻、详情下沉"记录，防止本文件重新臃肿：

- **详情只进 skill**：实现细节/坑/复现/示例/完整流程写对应领域 skill（`skills/backend|frontend|plugins|ops/`，跨领域如 review-checklist/acme-e2e-test 放 `skills/` 根，索引见 `skills/SKILL.md`）；CLAUDE.md **绝不复制这些细节**
- **CLAUDE.md 只留两类常驻内容**：① 全局核心指令/概览；② 安全铁律的**可执行红线一句**（"必须 X / 绝不 Y" + 一句为什么）后接 `详见 skills/<领域>/xxx.md` 指针。判定标准——只有"任何改动都可能踩、不读 skill 就会违规"的资金/死锁/事务/安全禁令才留红线，仅在改到该领域时才需要的说明直接写 skill、不在此留条目
- **改动后同步**：更新 `skills/SKILL.md` 与本文「开发规范」索引表，对改动的 md 跑 prettier

## 功能特性

### 委托验证 (delegation)

- `delegation` 提交到 CA 时转换为 `txt`，通过 `dcv.is_delegate` 标记区分
- 产品同步时保留本地的 `delegation` 验证方法
- **委托前缀（config 驱动）**：`backend/config/delegation.php` 的 `ca_map` 按 CA 映射 `{prefix, exact}` —— `_pki-validation`（Sectigo）、`_certum`（Certum）、`_dnsauth`（DigiCert/GlobalSign/TrustAsia/Sheca/CFCA/Wotrus 及未知 CA 的 default）。已移除 `_acme-challenge`（ACME 使用独立体系）
- **`exact` 是 CA 属性、非 prefix 属性**：`exact=true` 精确匹配子域且查找拒绝回落根域，`exact=false` 子域优先 + 回落根域；**默认全 false（含 `_dnsauth` 系）**，每家可由 `DELEGATION_<CA>_EXACT` env 覆盖为 true。所有委托创建/查找一律经 ca 派生 prefix+exact，禁止 `prefix === '_dnsauth'` 之类推断
- 详见 `skills/backend/delegation.md` 委托验证章节

### 插件系统

- **目录/加载**：`plugins/{name}/plugin.json`，`PluginServiceProvider` 自动扫描注册命名空间 + ServiceProvider；autoload 走 `realpath()` 防路径遍历，公共端点仅返回 bundle/css 路径
- **前端加载**：公共 `GET /api/plugins` 返回 bundle 路径、管理端返回完整信息；`plugin-loader.ts` 统一加载（URL 必须以 `/` 开头）；`exposeSharedDeps()` 暴露 Vue/Router/ElementPlus/Pinia + `getAccessToken()`；`__registerPlugin.widgets` 向已有页面注入组件（插槽如 `user-dashboard-top`）
- **管理**：`PluginManager` 安装/更新/卸载/检查更新（`/api/admin/plugin/*`），管理端 `/plugin` 页面操作；仅展示执行中/失败任务，失败需「重试」或「卸载」后再继续，避免堆叠失败任务
- **vendor 运行时安装**：带 `backend/composer.json` 的插件安装/更新时 `PluginManager` 自动 `composer install --no-dev`（`App\Services\Plugin\PluginComposerRunner`），vendor **不入 git、不进发布包**；无 composer.json 的插件跳过（零影响）
- **解耦/兼容**：主系统不硬引用插件代码/表，动态扫描（`_logs` 后缀表、`user_id` 字段）兼容；`checkCompatibility()` 按 `requires` 校版本；更新地址 `plugin.json.release_url`（第三方）→ `{release_url}/plugins/{name}`（官方）
- **数据库约定**：仅 MySQL/MariaDB，禁 `->json()` 列（用 `text`+`array` cast）、禁 raw 方言字面量；各插件 CI 独立 job（`backend-{name}-plugin-test`）
- 详见 `skills/plugins/plugin-dev.md`

### ACME 订阅管理

- **模型**：单一 `Acme`（`App\Models\Acme`，表 `acmes`），`eab_hmac` 加密+默认 hidden，`eab_kid` 索引，`plus` 列，`contact_email`(VARCHAR 254)；上游响应 `data.order_id` → 本地 `api_id` 列（**勿用 `api_id` 键读上游响应**，老坑）
- **计费流程**：`Action` 三步 `new`(unpaid)→`pay`(pending，commit 失败 **不回滚扣费**、留 pending 可走 `commit` 重试)→`commit`(→active)；`newAndCommit` 单事务原子（API 入口，失败回滚）；`contact_email` 所有入口必填、`refer_id` 端到端幂等键
- **控制器路由**：API `/api/v2/acme/`、Admin `/api/admin/acme/`、User `/api/user/acme/`（均含 index/show/batch/new/pay/commit/sync/commit-cancel/revoke-cancel/remark）、Deploy `/api/deploy/acme/new`（一步到位）+ `get`（含 EAB + directory_url）
- **取消/撤回**：Web 走延时 `commitCancel`→Task `cancel_acme`(123s)→`cancel`(退费)；下游 API 走 `cancelNow` 同步；`revokeCancel` 在 cancelling 且延时任务未执行时悲观锁回滚
- **字段暴露**：User show/batchShow 与 Deploy/V2 get 走 `makeHidden`（Deploy/V2 **保留 `refer_id`**，contract 一部分），Admin 全字段；搜索支持 `eab_kid` 前缀匹配（走索引）
- **批量操作**：7 个批量按钮（batch 详情/pay/commit/sync/commit-cancel/revoke-cancel/copy-eab）；上限 `config/batch.php`（`max_ids=100` / `max_upstream=20`）；`TaskJob` 去 `_acme` 后缀调 `Acme\Action::{commit,sync,cancel}`
- **Source API 层**：`Services/Acme/Api/` 按 `product.source` 路由（仅 `default` 源），经 `ca.acme_url`/`ca.acme_token`（回落 `ca.url`/`ca.token`）调上游 `/api/v2/acme/*`；`directory_url` 按 CA 聚合缓存（30 天 TTL 常量，过期经 show 读路径回源自刷）、不落库
- **隔离**：`products.product_type='acme'`；产品 API 分离（`/api/v2/get-products` 排除 acme、`/api/v2/acme/get-products` 仅 acme）；ACME 与传统订单完全隔离，V2 `new`/`Order\Action::initParams` 拒 ACME 产品；`Transaction` 类型 `acme_order`/`acme_cancel`（一对一防重）
- 详见 `skills/backend/acme-module.md`、`skills/backend/source-api.md`

## 系统架构约定

- **取消/吊销不静默成功**：上游接口未返回明确成功时，一律返回失败；不允许跳过上游调用直接标记本地状态
- **sync 终态守卫（防复活）**：`Order/Acme\Action::sync` 锁内回写前，本地已是终态（`cancelled/revoked/renewed/reissued/failed`）则 `unset($data['status'])`，不让上游旧状态复活订单；**守卫与写回必须按"写回目标 cert（外层 `$cert`）自身"判定（读=写同一行）**——并发重签会切 `latest_cert_id`，用 `lockedOrder->latestCert` 判定会漏判旧 cert 终态。详见 `skills/backend/auth.md`
- **资金/状态变更必须在事务 + 行锁内**：涉及 `Transaction::create`/余额/状态机的路径都要 `DB::transaction` + 目标行 `lockForUpdate()`，**状态检查放锁内**（锁外会被并发绕过）；`$this->success()` 放事务闭包**外**、`$this->error()` 放**内**；`TaskJob::handle` 整体包 `DB::transaction`（否则 lockForUpdate 是假锁）；**支付路径同时锁 user 行**（防跨订单并发绕过 credit_limit）；**锁内上游 HTTP 必须设 timeout < `innodb_lock_wait_timeout`(=50)**（否则挂起持锁事务 → 1205）；**进 DB 锁前先抢 `MutexLock` order 级 Cache 互斥锁**（方案 C，抢不到抛 `MutationBusyException`，根治 3+ 并发 1205）。详见 `skills/backend/order-fund.md`、`skills/backend/source-api.md`（Sdk 超时）
- **API 一条龙下单韧性（commit 超时不回滚扣费 + 卡单对账）**：V1/V2 `new/renew/reissue` 外层事务只包 `new + pay(commit=false)`（扣费落 `pending` 原子），`commit` 移到 `DB::commit()` 后独立调用；commit 超时/失败/抢锁忙只吞不回滚扣费（仅 `action==='commit'` 段吞 `code=0`+`MutationBusyException`），订单停 `pending`，靠 `schedule:reconcile-pending` cron + 下游 pull `get` 自愈；**扣费必须仍嵌套 new+pay 外层事务内**；**manager 卡单态是 `pending`（≠上游 `processing`），对账/幂等严禁照抄上游 processing**。Deploy update active 续费同范式移植（O3）、AutoRenew 续费/重签+pay 同事务原子（O1，`attempts=1` 必需——checkDuplicate SETNX 回滚不清键）。详见 `skills/backend/order-fund.md`
- **续费/重签取消的前驱恢复窗口仅 unpaid/pending**：processing+（已提交上游）取消一律 `cancelled` + 增量退款不恢复前驱、订单终结（重签/续费/取消三门齐闭），普通取消与 `autoRefundOnSync` 同步退款均配套 `cert_renew_cancelled` 一次性通知；`new(renew)`/`reissue` 必须持源订单行锁 + 前驱翻转 affected-rows 三态守卫（禁盲 UPDATE、`certs.last_cert_id` UNIQUE 兜底双开）。详见 `skills/backend/order-fund.md`
- **卡单对账共享判据单一真相源 + 受保护不变式**：pending 卡单「到顶/产品缺失」判据由 `Services/Order/PendingReconcileQuery` 三方法（`actionable`/`maxedOrProductMissing`/`maxedAndNotProductMissing`）正反同源，`schedule:reconcile-pending` 主扫描·转人工·`schedule:sweep-orphan-orders`（O4）三消费方**必须共用其 SQL 片段常量 + 传 `config('reconcile.max_attempts')` 同源值**，禁手抄 whereRaw/硬编码阈值（漂移致「判到顶告警但不收尾退款」裂缝）；**reconcile 主扫描绝不加 channel 过滤**（Deploy/api 孤儿唯一安全网，护栏测试守此）；O4 pending 退款分支金丝雀默认 off（`RECONCILE_ORPHAN_PENDING_ENABLED` arm-switch）。详见 `skills/backend/order-fund.md`
- **资金安全四道网（确定性体系）**：**物理阻断**（挡在错账发生前）= DB 唯一索引 + `Fund::transitionToSuccessful` 5 字段 CAS UPDATE（非 SELECT-then-UPDATE）+ 事务锁内二次校验 + Transaction 防重豁免仅 `'order'`；**事后发现** = Pest afterEach 跑 `FundInvariants` 4 条 SQL + 每天 `finance:audit` cron。**物理阻断不可被事后发现替代**（钱已入账损失已发生）；新增资金路径必须：CAS 转换、DB 唯一索引兜底、改 `user.balance` 必须同事务建 transaction。详见 `skills/backend/order-fund.md`
- **外部命令统一走 BinaryLocator**：所有 `exec(php/composer/openssl/mysqldump/curl …)` 必须经 `app(BinaryLocator::class)` 解析路径 + `escapeshellarg`，禁变量插值/裸命令（多版本 PHP 会走错 CLI，探测须 `proc_open` 非 `is_executable`）；失败分档：**用户显式请求的产物/算法（iis/tomcat/sm2）硬报错 + `Log::error`**，best-effort 聚合路径（type=all/DCV）可静默跳过但须双判 + Log。详见 `skills/backend/upgrade.md`
- **后台升级 binary preflight**：`upgrade/execute` 入口先跑 `UpgradePreflight::check()` 4 项（FPM/CLI `disable_functions`、PHP CLI 可探、composer phar 可探；CLI/FPM 各读独立 ini 须起子进程），阻塞返回 503；完整 PHP 环境校验（版本/extensions/functions）由升级流程内 `EnvironmentChecker::check()` 负责。详见 `skills/backend/upgrade.md`
- **redis 扩展动态必装**：`CACHE_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` 任一为 `redis` 时，`EnvironmentChecker` 与 `upgrade.sh` 同步把 redis 从 `recommended` 升为必装（两侧 .env 解析逻辑必须对称）。详见 `skills/backend/upgrade.md`
- **`TaskJob::dispatch` 一律加 `->afterCommit()`**：queue 默认 `after_commit=false`，事务内 dispatch 会立即入队致 worker 读不到未提交的行 → 任务静默丢失；统一"一律加"口径（无事务时立即派发、语义等价）。详见 `skills/backend/core.md`
- **异步 Job 必须显式 `->onQueue('tasks')` 或 `'notifications'`**：生产 worker 只监听 `tasks,notifications`，漏写 onQueue 的 Job 落 `default` 永无人消费（`SubmitDocumentJob` 曾踩坑：文档静默不上传）；业务 Job→`tasks`、通知→`notifications`，gateway 侧同。详见 `skills/backend/core.md`
- **升级冻结 release 计入 attempts，幂等 ShouldQueue 不可 `tries=1`**：freeze 期 `SkipWhenUpgradeFrozen` 对 Job `release(60)` 且不执行 handle，Laravel `release()` 把 attempts+1，`tries=1` 被 release 一次即 MaxAttemptsExceeded 误杀；约定 **handle 幂等的 ShouldQueue 用 `tries=5` + `maxExceptions=1`**（已用于 Create/RestoreBackupJob），无显式 tries 的走 worker `--tries 3`。详见 `skills/backend/upgrade.md`
- **统一锁顺序 task→order/acme 防死锁**：`TaskJob::handle` 及所有 DELETE/改 task 的路径（`revokeCancel`/`commitCancel(active)`/`refundForSyncedCancel`/`cancelPending` 等）必须**先锁 task 再锁业务行**；task 锁统一经 `Task::lockForMutation($orderId, $actions)`（强制复合索引 `tasks_order_action_status_index` + `select('id')` + `lockForUpdate`），孪生单列索引 `tasks_order_id_index` 已 schema 删除（防优化器退回扩大 next-key lock）；CI `finish-check-greps.sh` Z12/Z13/Z14 三层守卫。详见 `skills/backend/order-fund.md`
- **tasks 并发错误自愈边界**：纯本地 task→order/acme 变更用 `runTaskMutationTransaction()`（`DB::transaction(..,3)`）做 1213/1205 重试；**含上游副作用的 commit/cancel 不允许事务级重试**；`TaskJob` 并发错误必须重抛由 job 级 release/failed 处理，**禁在已回滚的死事务上继续 `$task->update()`**；**自愈重试期零日志、最终耗尽才记录**。详见 `skills/backend/order-fund.md`
- **Transaction::create 必须在 DB::transaction 内调用**：Transaction::creating 钩子内不再开自己的嵌套事务/savepoint——直接使用外层事务保证 balance 修改与 INSERT 的原子性。非事务内调用会抛异常提示。Fund::updating 同理。
- **资金事务优先用 `DB::transaction(fn)` 闭包**：Laravel 自动管 commit/rollback，避免"$row=null 控制流穿透"导致的事务计数器漂移。如必须手写 `DB::beginTransaction` + try/catch（如需在 catch 内捕获 `ApiResponseException` 后再写任务状态等场景），**所有控制流分支必须 commit 或 rollback**（含 no-row、early-return、异常路径），并补单元测试覆盖这些分支——禁止控制流穿透到方法末尾。

### Certum 验证文档上传

- **独立表**：`order_documents`（本地上传文档）；`Cert.documents` 只存 Certum 同步回的审核状态（只读、职责不同）；多级代理 base64 经 `POST /api/v2/upload-document` 逐级到上游 → Certum SOAP
- **显示/限制**：`brand==='certum'` 且 `validation_type!=='dv'`；单文件 5MB，类型 PDF/JPG/PNG/XADES；Admin/User 均可提交
- **安全·签发后禁止上传**：`latestCert.status==='active'` 后 `ActionDocumentTrait::uploadDocument`/`uploadDocumentFromBase64` 单点拦截（覆盖 UI + V2 三入口、全仓写 `order_documents` 仅此二方法），前端 active 态隐藏入口
- **上传即自动异步转发上游**：存档后自动派发 `SubmitDocumentJob`（`tries=3`、`backoff=[60,300]`、`->afterCommit()`），`content_hash`(sha256) + 唯一索引 `(order_id, content_hash)` 跨级去重（纯接收端、对端对称）；**依赖 queue worker 常驻**，无 worker 则文档不到上游
- 详见 `skills/backend/certum-document.md`

### 对外 API 接口文档

- **源**：`backend/resources/docs/api/{v2,acme,deploy}.yaml`（OpenAPI 3.1，单一来源，随版本发布打包）；编辑时逐端点对照控制器实际校验/返回核对，枚举值对系统字典（如 `validation_method` 对 `validationMethodOptions`）
- **后端端点**：`GET /api/meta/api-doc?surface=v2|acme|deploy`（公开无鉴权，返回 `application/yaml` 原文，供 curl / Scalar 渲染）；surface 白名单，非法 404。spec 描述主系统 v2/acme/deploy 契约、跟随主系统版本，**留主系统未拆进插件**
- **展示**：由 `api-docs` 插件（**纯前端、仅 user 端**）提供 —— 外壳 IIFE（~1KB，external vue）向「系统设置」注入「接口文档」菜单，页面用 **iframe(srcdoc)** 内嵌 Scalar 官方 standalone bundle（`scalar-standalone.js`，自带 Vue）渲染三套。iframe 隔离使 Scalar 的 ~1MB JS / 236KB CSS 仅在打开文档页时加载、不污染主系统、布局为 Scalar 原生。主系统**不再内置**渲染（已拆 `apiDocs.vue`×2 + `unplugin-vue-markdown` + `@apidoc`）。接入要点见 `skills/plugins/plugin-dev.md`

### 自动续费/重签

- **开关/触发**：`orders.auto_renew`/`auto_reissue`（null 回落 `users.auto_settings`）；`AutoRenewCommand` 每天 00:00，证书到期前 14 天触发（订单剩余 ≤15 天续费 / >15 天重签），API channel 不处理；延时 commit（随机 0~8h）分散上游；续费要求 `product.status=1 && renew=1`、重签仅 `reissue=1`
- **安全·算法继承防静默降级**：`reuse_csr=0` 重生成 CSR 时 `ActionTrait::initParams` 在 `encryption.alg` 缺失时从 `last_cert` 继承 alg/bits/digest（**在 `ValidatorUtil::validate` 之后注入**，不被当前产品菜单校验阻断存量续签）；SM2 走 `guardSm2Capable` 保持国密、绝不降级 RSA。否则原 ECDSA/SM2 会在续签后静默降级 RSA
- **安全·兜底必发**：任何失败都发 `auto_renew_failed` 通知（按 14/7/3/1 天节点），堵 `ExpireCommand` 排除自动续签订单后的静默过期洞；用户可行动失败（余额不足/委托无效）给专属文案，其余走兜底常量 `FALLBACK_REASON`、原始异常仅进 cron 日志；**余额检查仅 renew**；`ExpireCommand` 反向排除会被自动续签/重签处理的订单避免重复通知
- **手工标记已续费**：`Order\Action::markRenewed`（按 `orders.period_till` 到期前 30 天内、仅 active，事务+行锁+锁内二次校验），用于用户另开新订单续证后止住旧订单到期通知+自动续费
- **续期停滞止血（cert_renew_stalled，P0-1 包 X）**：续费/重签把前驱证书终态化（renewed/reissued）后接替卡非 active 停滞态、`cert_expire` 对该前驱抑制 + AutoRenew 因 active 前置不再处理 → 新 code `cert_renew_stalled`（**强制发不入偏好**、`StalledRenewalQuery` 派发/重查双侧同源、`markRenewed` 无接替链结构性免疫）是唯一止血，堵这一新静默过期洞（含审计 DCV 长期不过的 processing 形态）
- 详见 `skills/backend/auto-renew.md`

### 工商查询与企业-联系人绑定

- **多对一模型**：`organizations.contact_id` → `contacts.id`（应用层校验、无 DB 外键，避免误删 Contact 连锁清空绑定）；删 Contact 前校验引用；`POST/PUT organization` 支持嵌套 `contact_id + contact` 单事务 upsert；PUT 未传 contact 时保留原绑定（显式传 null 才清空）；订单缺 contact 时从 `organization.contact_id` 自动反查
- **工商查询服务**：`Services/EnterpriseLookup`（`LookupManager` + `AliyunDriver`，仅阿里云市场 AppCode 鉴权，timeout 10s）；`fieldMap`(dot path)/`queryField` 吸收接入商差异切商不改代码；Redis 缓存 24h 成功/1h 失败、cacheKey 含 fieldMap 指纹；全局每日上限（`enterprise:daily:*` 原子计数、超限 429）+ 30/min IP 节流
- **配置/启用判定**：`system_setting.enterprise.*`（`url`/`appCode`(base64 存)/`queryField`/`fieldMap`(标准 key 对齐入库字段)/`dailyLimit`）；`LookupManager::enabled()` 校验 url+appCode+queryField 非空且 fieldMap 配齐 `name`/`registration_number`/`address`；User 端未启用隐藏按钮、Admin 端禁用+tooltip
- **端点/前端**：`POST /api/{role}/enterprise-lookup`(30/min) + `GET .../status`；共享组件 `shared/components/OrganizationEditor`（企业/联系人 select + 查询按钮，`countryOptions` 由调用方注入）；`splitChineseName()` 法人姓名拆分回填
- 详见 `skills/backend/enterprise-lookup.md`

### 邮编查询（本地数据 + 县级市识别）

- **本地数据**：`backend/resources/data/china_city_zipcode.json`（2879 条省/市/区/县/县级市，自托管零依赖，直辖市 city 规范化为 province 名）；`ZipcodeLookup` 进程内 static 缓存 + 预计算每地级市最小 zipcode 代表
- **匹配策略**：`find(regionname, companyName)` 三步（最长 fullPath 匹配 → 公司名兜底县级市 → 市级回落），返回 `{zipcode,province,city,district}`；跨省误判防护（县级市 province/city 须现于 regionname）
- **端点/联动**：`POST /api/{role}/zipcode-lookup`(60/min)；`OrganizationEditor.onLookup()` 工商成功后联动回填 postcode（仅空时）+ 用返回 city 覆盖（县级市更精确）；`enterprise.fieldMap` 含 `regionname` 供路径
- 详见 `skills/backend/enterprise-lookup.md`

### 通知体系（主系统仅 mail，其他通道由插件注入）

- **业务事件归主系统、通道实现归插件**：主系统在 `Order/Action`/`AutoRenewCommand`/`ExpireCommand`/`TaskJob`/`FundAuditCommand`/`AuthController` 触发 `NotificationCenter::dispatch(NotificationIntent)`，`ChannelManager` 分发到所有已注册可用且 `shouldSend()` 的 channel；主系统仅内置 mail（无 channel 抽象冗余层，`code` 唯一）
- **安全·`ChannelManager` 必须绑 singleton**（`AppServiceProvider::register`）：否则插件 ServiceProvider 里 `register(...)` 的通道随新实例丢弃、NotificationCenter/Job 解析到只含 mail 的实例，插件通道端到端静默失效
- **用户偏好扁平按 code**：`users.notification_settings = {code: bool}`，`User::allowsNotification(code)`；Builder 按 code 单维度注册（`config/notification.builders`），输出 `NotificationPayload($data)`、mail-specific 放 `data._meta`
- **安全·携密不入库**：`DefaultNotificationBuilder` 直通 `$intent->context` 入库、不过滤敏感字段；携密 code（如 `user_created`）必须走专用 Builder + `NotificationPayload` 第二参 `transient`（仅渲染入邮件、**绝不入库**）；`NotificationJob implements ShouldBeEncrypted` 防明文落 jobs/failed_jobs；`security` 走专用 `SecurityNotificationBuilder` 白名单入库；多通道临时附件 `handle` 末尾统一清理 `cleanup_paths`
- **插件接入触点**（主系统承诺仅此）：ServiceProvider `register('feishu', new FeishuChannel)` + 实现 `ChannelInterface`（send/isAvailable/shouldSend）+ 偏好/UI/模板插件自治（主系统不预留 schema/UI/钩子）
- **系统告警（SystemAlert）**：运维/健康 admin 告警共享件（监控命令/watchdog/备份等消费），本体三件套契约冻结；去重指纹（计数型必须固定指纹）、healthy 分支 clearDedupe、details 键名避 denylist——消费方适配契约见 skill
- 详见 `skills/backend/notification.md`

### 国密 (SM2) 证书

- **能力 gate（探测，非开关）**：`guardSm2Capable` 对 `alg=sm2` 探测 `BinaryLocator::gmOpenssl()`，不可用即事务前拒绝（后端兜底防绕过、含 reuse_csr=1）；已移除 `site.gmEnabled` 人工开关——能否签由本机 openssl 能力决定
- **安全·fail-closed 绝不降级**：`probeSm2` 实签 CSR 校验 SPKI 为 id-ecPublicKey 标准编码（**功能探测非版本号比较**，防 OpenSSL 3.0.0~3.0.12 及 3.1~3.2.0 的 dual-sm2 被国密 CA 如 Keeptrust 拒）；不达标即 fail-closed 拒下单、绝不静默降级 RSA。运维下限 **OpenSSL ≥3.0.13**（已移除独立国密二进制硬编码候选与镜像编译，勿再加回）
- **双证书 + 存储**：签名证书（本地 `CsrUtil::generateSM2` 生成、临时文件强清）+ 加密证书（CA/KGC 下发）；`enc_cert`/`enc_key`/`enc_key2` 存 `certs` 表独立列（不入按 issuer 聚合的 `chains`）、跟随 `private_key` 暴露；**加密文件 enc_cert+enc_key 成对才出**，缺任一降级仅签名（杜绝残缺包）
- **透传/解析/下载/gate**：manager 走 `default` source 透传 enc（V2/Deploy get 均透传，**sync 终态守卫**本地终态时 unset enc）；`parseCert` 固定 SM2/SM3/256；下载走 `addSm2CertToZip` nginx 双证书包（不按 enc_cert、enc 空也强制国密包）；前端 install 只显 Nginx、Deploy API `field=certificate|private_key` 拒国密单证书拉取
- 详见 `skills/backend/sm2-cert.md`

### cloud-deploy 插件（证书自动推送云平台）

- **用途/架构**：证书 `latestCert.status=active` 后自动推送到各云平台资源（CDN/负载均衡/WAF/对象存储/函数计算等）。`plugins/cloud-deploy`（命名空间 `Plugins\CloudDeploy`），certimate 式封装（每 `(provider, product)` 一个 deployer，`AbstractDeployer` + `makeClient` 注入缝 + schema 驱动校验 + guardSdk 脱敏 + uploader 去重），**已对齐 certimate 149 端点 / 55 provider**（ssh/ftp/local 产品决策不做）；官方 SDK aliyun/tencent/aws/qiniu/baidu + 手写 `<Provider>RestClient` 签名（HMAC/JWT/OAuth2/OCI），古董依赖用 composer `replace` 挡在 vendor 外（0.0.1 曾因 psr/log 1.x 污染 Monolog 全站 500）
- **vendor 运行时安装**：`backend/vendor/`（云 SDK ~80M）**不入 git、不进发布 zip**（仅打包 composer.json/lock）；`PluginManager` 通用 composer hook 安装（见「插件系统」），缺 vendor 时 `loadPluginVendor`/`guardSdk` 降级不 fatal
- **主系统足迹**：backend 仅 `PluginManager` composer hook + `PluginComposerRunner`；功能侧复用既有 widget 插槽 `{admin,user}-order-detail-ssl-actions`（order 详情「云部署」卡片）
- **双端管理**：user/admin 共享 `Services\DeployService` 推送（`order_id` 按订单推 enabled / `target_ids` 直查两模式，admin `crossUser` 跨用户、空筛选 fail-closed）；target/access 双端增删改 + 启停 + 手动推送 + 部署历史；同用户同 `access_id+product+config_hash` 唯一（DB 唯一索引兜底）；admin targets 列表按 schema 逐键脱敏 `secret=true`、详情返回完整 config 供编辑
- **nginx 路由自定义**：`nginx/{default,custom,enabled}/` 三层，`render.sh` 合并渲染（custom 同名优先、抑制 duplicate location、`--reload` 带 `nginx -t`+回滚）；`custom/`+`enabled/` 不入发布包
- 详见 `plugins/cloud-deploy/skills/development.md`、`skills/ops/deploy-ops.md`（nginx）

## 测试

- 本地开发环境用容器（`compose.yaml` + `Makefile`，详见 `docker/README.md`）：后端 PHP 8.4 + MySQL 8.4 + Redis 7，`make test` 容器内并行跑（隔离库 `ssl_manager_test`，`--processes` 防 OOM）
- **MySQL 5.7 与 8.x 双版本**：生产二者都有，CI core 跑 `5.7×{8.3,8.4}` + `8.4×{8.4,8.5}` 矩阵、各 plugin 跑 5.7+8.4；本地默认 8.4（ARM 原生），复现 5.7 用内网实例或看 CI
- **collation 按版本自动选择**（三处统一：`bt-install.sh` 的 `_detect_db_collation` / 容器 `entrypoint.sh` / CI `matrix.collation`）：8.x→`utf8mb4_0900_ai_ci`、5.7→`utf8mb4_unicode_520_ci`、MariaDB→`utf8mb4_unicode_ci`；`structure.json` 以 8.4 为基准。新迁移/SQL 避开 8.0+ 保留字（`rank`/`groups`/`system`）与 5.7 不支持的语法
- 详见 `skills/backend/core.md` 测试章节
