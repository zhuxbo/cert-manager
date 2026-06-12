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
- **前端 pnpm 11** - 本地用 `corepack enable` 启用（需 Node ≥22.13；CI 的 `pnpm/action-setup` 自动按 `packageManager` 字段跟随）。pnpm 11 默认开启供应链安全：`minimumReleaseAge`（拒绝 24h 内新发布的包，批量升级遇阻时删 `pnpm-lock.yaml` 重解析即可选到合规版本）+ `allowBuilds`（build 脚本白名单，在 `pnpm-workspace.yaml` 显式列出，已弃用 `onlyBuiltDependencies`）。详见 `skills/frontend-dev.md`

## 开发规范

详细规范见 `skills/SKILL.md`，按领域组织：

| Skill                        | 内容                                                                    |
| ---------------------------- | ----------------------------------------------------------------------- |
| `skills/backend-dev.md`      | Laravel API、升级系统、迁移幂等                                         |
| `skills/acme-module.md`      | ACME 订阅管理（封装下单 + 交付 EAB 模式）                               |
| `skills/source-api.md`       | 新增上游来源（Order\\Api / Acme\\Api）                                  |
| `skills/frontend-dev.md`     | Vue 3、Monorepo、共享组件                                               |
| `skills/deploy-ops.md`       | 宝塔部署、安全基线                                                      |
| `skills/build-release.md`    | 版本发布、打包、releases.json 校验链                                    |
| `skills/plugin-dev.md`       | 插件系统、IIFE 打包、安装/更新/卸载                                     |
| `skills/acme-e2e-test/`      | certbot 端到端测试（Manager + 上游系统）                                |
| `skills/review-checklist.md` | 设计期"杀手场景 + 对端检查" + finish-check Reviewer Subagent 反模式扫描 |

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
- **插件 vendor 运行时安装**：安装/更新带 `backend/composer.json` 的插件时，`PluginManager` 自动 `composer install --no-dev`（封装在 `App\Services\Plugin\PluginComposerRunner`，复用 `BinaryLocator::composer()` + `UpgradePreflight` + 阿里云镜像）——使重依赖插件（如 cloud-deploy 的云 SDK 80M）vendor **不入 git、不进发布包**。**无 composer.json 的插件跳过**（零影响）；install 必装、update 当 `composer.lock` 变化**或目标 vendor 缺失**才装（update 删旧目录前把运行时 vendor 移出暂存，lock 未变则移回复用，避免重拉 80M 又防 vendor 永久丢失）；失败给明确文案（install/installFromZip 走 catch 清本次落地的半装目录、用 `$applied` 守卫不误删既有插件 / update 回滚备份）。要求目标机有 composer + CLI proc_open + packagist 可达
- **更新地址优先级**：`plugin.json.release_url`（第三方）→ `{主系统 release_url}/plugins/{name}`（官方）
- **插件 API**：`GET /api/admin/plugin/installed`、`GET /api/admin/plugin/check-updates`、`POST /api/admin/plugin/install`、`POST /api/admin/plugin/update`、`POST /api/admin/plugin/uninstall`
- **数据库约定**：仅支持 MySQL/MariaDB；插件迁移和代码与主系统同等约束（禁用 `->json()` 列，用 `text` + `array` cast；禁用 raw 方言字面量，统一走 Eloquent / Query Builder）。CI 各插件独立 job（`backend-{name}-plugin-test`），无自带 tests 的插件也跑 migrate + schema 检查。详见 `skills/plugin-dev.md`

### ACME 订阅管理

- **模型**：单一 `Acme` 模型（`App\Models\Acme`，表 `acmes`），`eab_hmac` 加密存储且默认 hidden；`eab_kid` 建索引；`plus` 列（赠送时间 0/1）；`contact_email` 列（`VARCHAR(254) NULL`，ACME 账号邮箱 — RFC 8555 `contact`）
- **字段映射**：上游响应 `data.order_id` → 本地 `acmes.api_id` 列（**切勿用 `api_id` 键读上游响应**，老代码踩过坑）
- **计费流程**：`Action` 三步流程：`new(array $params)`（unpaid/待支付）→ `pay(int $id, bool $autoCommit = true)`（Admin/User 入口默认"先支付独立事务，再单独事务调 commit"——commit 失败 **不回滚扣费**，订单保留 pending 可走 `commit` 接口重试；`$autoCommit=false` 仅置 pending，由 batchPay 统一入队 commit）→ `commit(int $id)`（提交 上游系统 → active）；`newAndCommit(array $params)` 一步完成三步（**单事务原子，失败回滚**，API 入口使用）
- **上游 `/acme/new` 入参**（manager 视角完整 schema）：`contact_email`（= `acmes.contact_email`，**所有入口必填**：User/Admin 表单 / API Token / Deploy Token；上游正常返回会覆盖回写，缺失则保持本地值）/ `product_code` / `period`(int，预留 Certum 多年期；gateway 当前 validate 暂不接收由 `product.periods[0]` 决定，但 manager 稳定外发) / `plus`(int 0/1，与传统 V2 Order 一致；gateway 端 `(bool)` cast 兼容) / `refer_id`（端到端幂等键，下游传则用之、未传则 manager 生成 32 字符 hex），**不传** `purchased_*count`/`product_type` 等。**字段名与多级代理链路全程对齐**；`source` 是 manager 内部 Api 路由参数，作为 `Api::new($data, $source)` 第二个独立参数，不混入 data；Certum 侧的 `customer` 术语仅存在于 Gateway → Certum SDK 的最后一跳
- **对外 API 入参契约**（manager 视角）：`/api/acme/new`（API Token）与 `/api/deploy/acme/new`（Deploy Token）入参同构，validate `product_code` required\|string\|max:50 + `contact_email` required\|email\|max:254 + `period` sometimes\|integer（未传则取产品默认周期 `product.periods[0]`，控制器不再硬编码 12）+ `plus` nullable\|integer\|in:0,1（与 V2 Order 风格一致）+ `refer_id` sometimes\|string\|max:64。`refer_id` 由 `App\Traits\AcmeReferIdCheck::checkAcmeReferId` 做应用层防重（按当前 user 范围 + DB `acmes.refer_id` unique 兜底）
- **directory_url 缓存**：Laravel `Cache::forever("acme_directory_url:{ca}")` 按签发 CA 聚合；commit/sync 刷新、show 缺失时回源一次性回填；不入 system_setting、不落库
- **取消流程**：Web 入口走延时 — `commitCancel(int $id)`（标记 cancelling + 创建 Task `cancel_acme` + TaskJob 延时 123s）→ `cancel(int $id)`（由 TaskJob 调用，调 Api->cancel() + 退费）；下游 API（`/api/acme/cancel`）走 `cancelNow(int $id)`，不创建 Task、同步调 `cancel()` 立即返回
- **撤回取消**：`revokeCancel(int $id)` 在 acme.status=cancelling 且延时任务未执行时生效 — 悲观锁回滚 status→active、清空 cancelled_at、删除 executing/stopped 的 `cancel_acme` Task（已 dispatch 的 TaskJob 唤醒后找不到任务直接跳过）
- **Transaction 类型**：`acme_order`/`acme_cancel`（一对一防重，禁止重复 `transaction_id`；仅传统 `order` 因证书重签增域名场景允许重复）
- **产品标识**：`products.product_type = 'acme'`
- **Source API 层**：`Services/Acme/Api/` 按 `product.source` 路由，仅 `default` 源（和 Order 一致），`AcmeSourceApiInterface` 统一 `new`/`get`/`cancel`/`getProducts` 接口，`default/Sdk` 通过系统设置 `ca.acme_url`/`ca.acme_token`（回落到 `ca.url`/`ca.token`）调用 上游系统 `/api/acme/*` 端点
- **产品导入**：`Order\Action::importProduct()` 同时查询 Order 和 ACME 两端产品，合并后按 `api_id` 去重
- **控制器路由**：
  - API：`/api/acme/` — new, get, cancel, get-products（对下游代理，与 上游系统 对齐）
  - Admin：`/api/admin/acme/` — index, show, batch（聚合详情）, new, pay, commit, sync, commit-cancel, revoke-cancel, remark（管理员备注）
  - User：`/api/user/acme/` — index, show, batch（聚合详情）, new, pay, commit, sync, commit-cancel, revoke-cancel, remark（用户自己的备注，限当前用户）
  - Deploy：`/api/deploy/acme/` — new（一步到位：创建+支付+提交）, get（含 EAB + directory_url）
- **字段暴露策略**（参考传统 Order）：
  - User Web `index` 走 select 白名单，`show`/`batchShow` 走 `makeHidden(['user_id','plus','api_id','refer_id','admin_remark','channel'])`；product 关联 select 含 `ca`（`syncDirectoryUrl` 取 cache key 需要）
  - Deploy/V2 API `get` 走 `makeHidden(['user_id','plus','api_id','admin_remark','channel'])`；**保留 `refer_id`**（客户端关联键，contract 一部分）
  - Admin 全字段返回，不做 makeHidden
- **搜索**：Admin/User 控制器 `index` 支持 quickSearch / id / status / brand / period / **eab_kid 前缀匹配（走索引）** / amount 范围 / product_name / created_at / period_till 范围；Admin 额外 user_id/username
- **产品 API 分离**：`/api/v2/get-products` 排除 ACME 产品，`/api/acme/get-products` 仅返回 ACME 产品；下单页面产品选择器通过 `exclude_product_type=acme` 过滤
- **传统流程完全隔离**：ACME 通过独立控制器、服务和前端模块处理，与传统订单无交集；V2 API `new` 和 `Order\Action::initParams` 拒绝 ACME 产品
- **批量操作**：列表页 7 个批量按钮
  - `GET /api/{admin,user}/acme/batch?ids=1,2,3` — 批量详情聚合（纯读）；URL 可分享，前端 `details.vue` v-for 渲染。返回 `{items: [...]}` 含 directory_url（按 ca 缓存避免重复算）。User 端复用 show 的字段隐藏；Admin 端全字段
  - `POST /api/{admin,user}/acme/batch-pay` — 同步逐条扣费（pay autoCommit=false），成功的 id 批量入队 `commit_acme`；状态限 unpaid，返回 `{success_count, commit_count, errors}`
  - `POST /api/{admin,user}/acme/batch-commit` — 创建 `commit_acme` Task 立即入队，状态限 pending；`checkRepeat` 存在 executing 任务时整体报错
  - `POST /api/{admin,user}/acme/batch-sync` — 创建 `sync_acme` Task 立即入队，状态限 active/cancelling（必须有 api_id），pending/unpaid 无 api_id 无法同步
  - `POST /api/{admin,user}/acme/batch-commit-cancel` — 同步逐条调单体 commitCancel（混合直接退费 + 延时 Task），状态限 unpaid/pending/active；单体 commitCancel 已扩展支持 unpaid（未扣费无需 refund）
  - `POST /api/{admin,user}/acme/batch-revoke-cancel` — 同步逐条调单体 revokeCancel，状态限 cancelling
  - `POST /api/{admin,user}/acme/batch-copy-eab` — 纯读返回 EAB 文本（`directory_url\ncontact_email\neab_kid\neab_hmac`，条目间空行），**Admin 端跨用户拒绝**；User 端 UserScope 自动限制
- **TaskJob 分发**：`commit_acme / sync_acme / cancel_acme` 统一去 `_acme` 后缀调 `Acme\Action::{commit,sync,cancel}`；其余 action 走 `Order\Action`
- **User 端同步**：`POST /api/user/acme/sync/{id}`（与 Admin 对齐）
- **checkRepeat 并发限制**：与 Order 相同，`checkRepeat` 和 `createTasks` 之间无事务锁，并发情况下可能产生双份 executing Task；沿用 Order 设计，属已知限制
- **createTasks 逐条幂等**：`Acme\Action::createTasks` 与 `Order` 的 `createTask` 一致，foreach 内创建前查已存在 executing 同 `order_id+action` task 则 continue（防重复 task）；`cancel_acme` 走独立 `Task::create` 路径、自带同款幂等查询
- **批量上限（`config/batch.php`）**：`max_ids=100`（所有 batch 接口 ids 数量上限，21 个 `GetIdsRequest` + `BaseRequest::messages` 统一校验）、`max_upstream=20`（batchPay/batchCommitCancel 等"逐条调上游"循环的硬上限，防单请求打爆上游）

## 系统架构约定

- **`$order->latestCert` 非空保证**：由系统架构保证 latestCert 关系非空，查询时加 `with('latestCert')` 预加载即可，无需额外空值判断
- **`$this->error()` 方法**：来自 `ApiResponse` trait，调用后抛出异常终止执行，不会继续后续代码
- **取消/吊销不静默成功**：上游接口未返回明确成功时，一律返回失败；不允许跳过上游调用直接标记本地状态
- **sync 终态守卫（防复活）**：`Order\Action::sync`/`Acme\Action::sync` 在锁内用上游状态回写本地前，若本地已是终态（`cancelled`/`revoked`/`renewed`/`reissued`/`failed`）则 `unset($data['status'])`，不让上游旧状态把已取消/已吊销的订单"复活"回 active（与 commitCancel 串行化配合，防资金错乱）。**守卫与写回必须按"写回目标 cert（外层 `$cert`）自身"判定**：上游 get 是事务外慢 IO，期间并发重签会把 `order.latest_cert_id` 切到新 cert B，若用 `lockedOrder->latestCert`(=B) 判定会漏判旧 cert A 已终态 → 复活 A + 误删 B 的延时 commit task。Order sync 锁内按 `$cert->id` 重读写回目标自身状态做守卫/`hasStatusChanged`，写回 `($targetCert ?? $cert)->update()` 确保判定对象===写回对象（即"读=写同一行"）
- **ACME 计费流程**：`Action` 三步流程 `new→pay→commit` 详见"ACME 订阅管理"章节
- **ACME 取消策略**：未提交上游（无 api_id）的 pending 订单直接退费取消；已提交上游的订单通过延时任务调 Api->cancel() 后退费
- **Action 无 userId 构造参数**：`Acme\Action` 和 `Order\Action` 均无 `userId` 构造参数，通过 `app(Action::class)` 获取实例。用户隔离由 UserScope 全局作用域保证（`Authenticate`/`ApiAuthenticate` 中间件注册 Acme、ApiToken、Callback、CnameDelegation、Order、Fund、Transaction、Organization、Contact、OrderDocument），控制器在创建方法的 params 中传入 `user_id`。UserScope `apply()` 无条件执行 `where('user_id', ...)`，不做零值跳过
- **ACME Action 统一封装上游 API 调用**：所有上游 API 调用（new/get/cancel 等）必须通过 `Services/Acme/Action`，不允许控制器直接调 `Api`。操作方法接收 ID（int），创建方法接收参数数组。内部负责模型查询、参数过滤、返回值校正、重复提交防护、状态入库。控制器仅做请求验证 + 一行调用 Action
- **资金/状态变更必须在事务 + 行锁内**：任何涉及 `Transaction::create`/余额变动/状态机变更的路径都要 `DB::transaction` + 目标行 `lock()`/`lockForUpdate()`，且**状态检查放在锁内**（锁外校验会被并发绕过）。Order 走 `Order::with(['latestCert'])->whereHas('latestCert')->lock()` 约定锁 + 所有变更走 `$order->latestCert->update()` 路径；ACME 走 `Acme::lock()` 直接锁 status 所在行。`$this->success()` 必须放在事务闭包**外**（它抛 `ApiResponseException` 会触发回滚）；`$this->error()` 放闭包内正好触发回滚。unpaid 等无资金流水的状态清理路径可免锁（双击第二次自然报错无资金损害）。TaskJob::handle 必须整体包 `DB::transaction`，否则 `lockForUpdate` 在自动提交模式下是"假锁"（SELECT 返回即释放）。**支付路径必须同时锁 user 行**（否则同一用户跨订单并发支付会绕过 credit_limit 校验）：ACME `User::lockForUpdate()`，Order `with(['user' => fn ($q) => $q->lockForUpdate(), 'latestCert'])`。**ACME commit 也需要锁内调用上游**：与 commitCancel 串行化防止"上游已建单 + 本地被改 cancelled 后又被 commit 覆盖回 active"的资金错乱。
- **资金安全四道网（确定性体系）**：分两类 — **物理阻断**（INSERT/UPDATE 之前挡住错账发生）：(1) DB 唯一索引（`funds(pay_method, pay_sn)` / `transactions(type, transaction_id) WHERE type != 'order'`，旧 3 列 `funds_type_pay_method_pay_sn_unique` 已被替换为 2 列）；(2) `Fund::transitionToSuccessful` 完整 5 字段 WHERE（id + amount + type + pay_method + status=0）的 CAS UPDATE 替代 SELECT-then-UPDATE，CAS WHERE 不能简化为 status 单一条件，否则金额/支付方式不匹配的回调也会把本地 fund 标成功；(3) 事务 + 锁 + 锁内二次校验（destroy/batchDestroy 等"删除已入账"路径无法被 CAS 或唯一索引拦截，删除 SQL 本身合法）；(4) `Transaction.php` 防重豁免列表仅 `'order'`（ACME 一对一交付 EAB，不存在重签增域名）。**事后发现**（不阻止发生但保证发现）：(5) `tests/Pest.php` afterEach hook 自动跑 `App\Services\FundAudit\FundInvariants::all()` 4 条 SQL（L1 账目恒等 / L2 事件唯一 / L3 状态-事件配对 / L4 金额配对），动了 funds/transactions/users.balance 的测试自动守门；(6) 每天 03:00 `finance:audit` cron 全量对账，违反走 `NotificationCenter` 邮件告警 + `Log::error` 兜底，可选 `--freeze-on-violation` 自动禁用涉事用户。**关键**：物理阻断不能被替代为事后发现 — 已删除的 fund 即便 invariant 报 orphan transaction，钱已入账、订单已消失，损失已发生。新增资金路径必须保证：状态转换走 CAS 而非 SELECT-then-UPDATE；写 transaction 不依赖应用层 `exists` 防重，靠 DB 唯一索引兜底；修改 user.balance 必须在 `DB::transaction(fn)` 内同事务创建 transaction；CI tearDown invariant 自动校验，新测试无需手写资金断言。详见 `skills/backend-dev.md` 资金确定性体系章节。
- **外部命令调用统一走 BinaryLocator**：所有 `exec("php ...")` / `exec("composer ...")` / `exec("openssl ...")` / `exec("mysqldump ...")` / `exec("curl ...")` 必须通过 `app(\App\Services\Binary\BinaryLocator::class)` 解析二进制路径，拼命令统一用 `escapeshellarg($path).' arg1 arg2'`，不允许变量插值或裸命令（如 `exec('openssl pkcs12 ...')`）—— 多版本 PHP 系统下会走错 CLI / open_basedir 限制下 `is_executable` 会误判（探测必须走 `proc_open` 子进程，不能用 `is_executable`/`file_exists`）。失败抛 `BinaryNotFoundException`，失败处理分两档：**用户显式请求的产物/格式/算法**（type=iis/tomcat、alg=sm2）失败必须硬报错 + `Log::error`，绝不静默给残缺产物；仅 **best-effort 聚合路径**（type=all、DCV 单点）可静默跳过且须 returnCode+file_exists 双判 + Log 留痕、不 `> /dev/null` 丢 stderr；升级流程内 PHP/composer 失败应向上抛阻塞。详见 `skills/backend-dev.md` BinaryLocator 章节。
- **后台升级 binary preflight**：`POST /api/admin/upgrade/execute` 入口（isRunning 短路后）先跑 `UpgradePreflight::check()` 4 项检查 —— ① FPM `disable_functions`、② PHP CLI 可探、③ composer phar 可探、④ CLI `disable_functions`（CLI 和 FPM 用各自独立 ini，必须起子进程读 CLI 的 `ini_get`，不能只查 FPM 进程的 `ini_get('disable_functions')`）。任一阻塞返回 503 + `{blocking: [], items: [], ini: {fpm, cli}}`，fix 文案统一"使用 upgrade.sh 升级"。独立健康检查端点：`GET /api/admin/upgrade/binary-health`（不阻塞，纯展示 8 个工具状态供前端升级页面参考）。**注意：preflight 只负责 binary，完整 PHP 环境校验（PHP 版本 / extensions.required / functions.required）在升级流程内的 `EnvironmentChecker::check()` —— 解包后、applyUpgrade 前跑，不通过抛 `PhpEnvironmentException` 中断升级（代码原样未动），引导用 upgrade.sh 修复**。
- **redis 扩展动态必装**：`php-requirements.json` 中 `redis` 默认在 `recommended`；但当 `backend/.env` 的 `CACHE_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` 任一为 `redis` 时，`EnvironmentChecker` 和 `deploy/upgrade.sh::_redis_required_from_env` 同步把 redis 升级为必装（避免装好后 cache/queue 运行时崩）。两侧解析逻辑必须对称：处理引号、行内注释、CRLF、前后空白；.env 缺失时 fail-safe 返回 false。修改任一侧记得同步另一侧。
- **`TaskJob::dispatch` 一律加 `->afterCommit()`**：`config/queue.php` 所有连接默认 `after_commit=false`，事务内 dispatch 的 Job 会立即入队，worker 可能在事务提交前消费 Job，读不到事务内新建的行/状态导致任务静默丢失。统一"一律加"口径（Laravel 无活动事务时 afterCommit 立即派发，语义等价），免去"是否在事务内"的语义判定，使 `skills/scripts/finish-check-greps.sh` 的硬零断言可机械执行；新增 Job dispatch 点同步跟进。
- **异步 Job 必须显式 `->onQueue(config('queue.names.tasks'))` 或 `notifications`**：生产部署的 supervisor worker 只监听 `tasks,notifications` 两个队列（`deploy/scripts/bt-install.sh` 自动写入 `queue:work --queue tasks,notifications`、`skills/deploy-ops.md` 文档同），**不监听 connection 默认的 `default` 队列**。漏写 `onQueue` 的 Job 会落到 `default` 永远没人消费（`SubmitDocumentJob` 曾踩此坑：文档静默不上传上游）。约定：业务 Job → `tasks`、通知 → `notifications`，新增 Job 的 dispatch 必须 onQueue 到二者之一；**gateway 侧同此约定**（其 worker 同样只监听这俩，镜像的 `SubmitDocumentJob` 也需 onQueue）。
- **升级冻结 release 计入 attempts，幂等 ShouldQueue 不可 `tries=1`**：freeze 期 `SkipWhenUpgradeFrozen` 中间件对 Job 调 `$job->release(60)` 且不执行 handle；Laravel `release()` 下次 pop 由驱动把 attempts +1，`Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts` 在 `fire()` 前判 `attempts > tries` 即 MaxAttemptsExceeded、handle 永不执行。故 `tries=1` 的 Job 被 freeze release **一次**后第二次 pop 即被误杀（freeze 中间件是进程内唯一兜底——UPGRADE.md 文档化的"停 worker"（宝塔 Supervisor 面板停队列进程，程序名为站点域名、非 `manager-queue`）运维步骤当前未接入 `UpgradeService::performUpgradeWithStatus`/`upgrade.sh` 自动升级流程，freeze 也仅由 `/upgrade/freeze` 端点 / `upgrade:freeze` 命令手动激活；激活后 worker 不停则逐次 release），备份/恢复恰最可能在升级窗口附近运行。约定：**handle 幂等的 ShouldQueue 用 `tries=5`（吸收数次 freeze release，覆盖 ~5×60s 短窗；更长的手动 freeze 仍会耗尽 attempts，需配合停 worker 或 `retryUntil()`）+ `maxExceptions=1`（业务异常仍只一次；handle 自吞全部 Throwable 的备份类则作防御性封顶）**，已应用于 `CreateBackupJob`/`RestoreBackupJob`。无显式 `$tries` 的 Job 走 worker `--tries 3`（吸收 2 次 release）。`maxExceptions` 仅 handle 真抛时累加，freeze release 不触发它。复现见 `tests/Feature/Jobs/UpgradeFreezeReleaseAttemptsTest`。
- **统一锁顺序 task→order/acme 防死锁**：`TaskJob::handle` 是 task→order/acme 顺序（先锁 task，action 内再锁业务行）；所有 DELETE/修改 task 的业务路径（Order `revokeCancel`/`commitCancel(active)`/`batchCommitCancel`、ACME `revokeCancel` 等）都必须按同一顺序，先 `Task::where(...)->lockForUpdate()->get()` 拿 task 锁再锁业务行，再做 DELETE。否则 InnoDB 会周期性触发死锁回滚，用户看到随机失败。**统一锁顺序只压制行锁层死锁，二级索引间隙锁 × 并发 INSERT 仍偶发死锁**——靠三层兜底：`tasks(order_id,action,status)` 复合索引降频 + `TaskJob` 并发错误不可吞（重抛交 queue 重试 + `failed()` 兜底标记，**禁止在已被 MySQL 回滚的死事务上继续 `$task->update()`**，否则抛 `no active transaction` 雪崩）+ `sync` 事务 `attempts=3` 自愈（`commit` 因上游下单在事务内**不**重试）。详见 `skills/backend-dev.md` tasks 死锁防护章节。
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
- **签发后禁止上传**：证书 `latestCert.status === 'active'` 后不再接受文档上传——`ActionDocumentTrait::uploadDocument`/`uploadDocumentFromBase64` 单点拦截（覆盖 Admin/User UI + V2 API 三入口，全仓写 `order_documents` 仅此二方法），前端 admin/user 的 process.vue 均在 active 态隐藏上传入口
- **提交上游异步化 + 重试**：`submitDocuments` 不再同步阻塞，改为每个未提交文档派发 `App\Jobs\SubmitDocumentJob`（`tries=3`、`backoff=[60,300]` 指数退避、`->afterCommit()`）。Job 调 `ActionDocumentTrait::submitDocument(int $docId)`：成功标 `submitted`+`submitted_at`、永久失败（文件缺失）记 `submit_error` 不重试、可重试失败（订单未提交/上游错误）抛异常退避重试；`failed()` 兜底记错 + `Log::error`。前端 `documentUpload.vue` 状态列展示 submitted/失败(submit_error)+轮询
- **跨级去重（content_hash）**：`order_documents` 加 `content_hash`(sha256) + 唯一索引 `(order_id, content_hash)`。**纯接收端**实现 —— `uploadDocumentFromBase64` 对解码字节算 hash，同 order 同内容已存在则跳过（重试/重复推送幂等），唯一索引兜底并发竞态（catch `QueryException` errorInfo 1062）。**发送端/线协议不变**，旧版下游推到新接收端也能去重。Gateway 侧 `V2/ApiController::uploadDocument` 镜像同一逻辑（对端对称）。防止 Job 重试在上游产生重复行 → Certum 重复提交
- **上传即自动转发上游**：`uploadDocument`（UI 文件上传）与 `uploadDocumentFromBase64`（V2 接收下游）存档后**都**自动派发 `SubmitDocumentJob` 往上游转（含 dedup-hit 补转），多级链全自动、免手动点提交。前端「提交」按钮降级为兜底（仅 `unsubmittedCount>0` 时显示、全部 submitted 后隐藏；上传后前端轮询刷新状态）。**注意：自动转发仍依赖 queue worker 常驻**——无 worker 则 Job 滞留 `jobs` 表、文档不会真正到达上游
- **新增列**：`submitted_at` / `submit_attempts` / `submit_error` / `content_hash`（迁移 `2026_05_31_100000_*`，幂等 `hasColumn` 守护）

### 对外 API 接口文档

- **源**：`backend/resources/docs/api/{v2,acme,deploy}.yaml`（OpenAPI 3.1，单一来源，随版本发布打包）；编辑时逐端点对照控制器实际校验/返回核对，枚举值对系统字典（如 `validation_method` 对 `validationMethodOptions`）
- **后端端点**：`GET /api/meta/api-doc?surface=v2|acme|deploy`（公开无鉴权，返回 `application/yaml` 原文，供 curl / Scalar 渲染）；surface 白名单，非法 404。spec 描述主系统 v2/acme/deploy 契约、跟随主系统版本，**留主系统未拆进插件**
- **展示**：由 `api-docs` 插件（**纯前端、仅 user 端**）提供 —— 外壳 IIFE（~1KB，external vue）向「系统设置」注入「接口文档」菜单，页面用 **iframe(srcdoc)** 内嵌 Scalar 官方 standalone bundle（`scalar-standalone.js`，自带 Vue）渲染三套。iframe 隔离使 Scalar 的 ~1MB JS / 236KB CSS 仅在打开文档页时加载、不污染主系统、布局为 Scalar 原生。主系统**不再内置**渲染（已拆 `apiDocs.vue`×2 + `unplugin-vue-markdown` + `@apidoc`）。接入要点见 `skills/plugin-dev.md`

### 自动续费/重签

- `orders.auto_renew`: 订单级自动续费开关（null 时回落到用户设置）
- `orders.auto_reissue`: 订单级自动重签开关（null 时回落到用户设置）
- `users.auto_settings`: 用户级默认设置 `{"auto_renew": false, "auto_reissue": false}`
- `AutoRenewCommand` 每天 00:00 执行：证书到期前 14 天触发，订单剩余 ≤15 天续费、>15 天重签；API channel 订单由下游控制，不处理
- **延时提交**：Command 创建续费/重签 + 支付后不立即 commit，通过 Task 表创建延时 commit 任务（随机 0~8 小时），分散上游压力，8 点后人工可检查状态
- **产品条件**：续费要求 `product.status=1 && renew=1`；重签仅要求 `reissue=1`（产品禁用仍可重签）
- **参数继承**：从原订单提取 period/contact/organization/domains；CSR 按 `product.reuse_csr` 决定重用或生成
- **算法继承**（防静默降级）：续费/重签 `reuse_csr=0` 重新生成 CSR 时，`ActionTrait::initParams` 在 `encryption.alg` 缺失时从 `last_cert` 继承 alg/bits/digest（列存大写，`strtolower` 归一），覆盖自动路径（`AutoRenewCommand` 不传 encryption）与 API 省略；前端 `loadOrderInfo` 回填原算法为表单默认（用户仍可改）。**继承值在 `ValidatorUtil::validate` 之后才注入 `$params`**——不让当前产品 `encryption_alg` 菜单校验阻断存量证书续签（显式传入的 encryption 仍照常 validate）；但 SM2 能力 gate `guardSm2Capable` 早触发，国密 openssl 不可用则报错（保持 SM2，绝不静默降级为 RSA）。`CsrUtil::getEncryptionParams` 归一返回小写 alg（修大写算法失配 bug）。前端 ECDSA 密钥长度选项 `512→521` 对齐后端 `secp521r1`。否则原 ECDSA/SM2 证书会在 reuse_csr=0 续签后静默降级为 RSA
- **委托前置条件**：缺失委托记录时自动创建（`_dnsauth` 精确域名、回落前缀按根域）；DNS 验证采用宽松策略（所有 dnsTools + 本地全部尝试，任一匹配即有效），目的是尽可能发起续签

### 工商查询与企业-联系人绑定

- **多对一模型**：`organizations.contact_id` 关联 `contacts.id`（应用层校验，无 DB 外键 — 避免误删 Contact 时连锁清空企业绑定）；删除 Contact 前校验是否被任何 Organization 引用
- **嵌套 upsert**：`POST/PUT /api/{role}/organization` 请求体支持嵌套 `contact_id + contact`，单事务原子；User/Admin OrganizationController 复用 `Concerns\ResolvesContactId` trait
- **update 保留原绑定**：PUT `/api/{role}/organization/{id}` 未传 `contact_id` 也未传 `contact` 时 **保留原 contact_id**（避免部分字段更新意外清空绑定）；显式传 `contact_id: null` 才清空。Admin/User 端一致
- **订单自动反查**：`Order\ActionTrait::initParams` 当传 organization 但缺 contact 时，从 `organization.contact_id` 自动取；contact_id 为空报错"请先为该企业绑定联系人"
- **工商查询服务**：`Services/EnterpriseLookup`（`LookupInterface` + `AliyunDriver` + `LookupManager`），仅对接阿里云市场（AppCode 鉴权，HTTP timeout 固定 10s）；Redis 缓存 24h 成功 / 1h 失败，**cacheKey 含 fieldMap 指纹**（配置变更后旧缓存自动失效，避免"改完 fieldMap 但 24h 缓存仍返回旧 schema"的字段缺失）；`fieldMap` 吸收响应结构差异（dot path），`queryField` 吸收请求参数名差异（极速工商 `name`/`company`、其他接入商 `keyword` 等），切接入商不改代码
- **配置项**（`system_setting.enterprise.*`，setting 顶层 key 采用小驼峰）：`url` / `appCode`（base64 编码存储，非加密；真加密为待评估项）/ `queryField`（默认 `name`）/ `fieldMap`（内部标准 key **对齐 organization/contact 入库字段名**，前端可直接消费无需二次映射：`name` / `registration_number` / `address` / `state` / `city` / `regionname` / `legal_person`）/ `dailyLimit`（全局每日上限，默认 100，0 视为无限制）
- **启用判定**：去掉了独立的 `enabled` 开关，`LookupManager::enabled()` 改为校验 `url` + `appCode` + `queryField` 非空且 `fieldMap` 至少配齐 `name`/`registration_number`/`address` 三个标准字段；任一缺失即视为未启用
- **全局每日上限**：`AliyunDriver::enforceAndIncrementDailyQuota()` 在 cache miss 后、HTTP 请求前 `Cache::add + Cache::increment` 原子计数，key `enterprise:daily:{YYYY-MM-DD}` TTL 至当日 23:59:59；**缓存命中不计数、超限抛 LookupException(429) 不写失败缓存**（否则次日重置后仍命中失败缓存）。30/min IP 节流保留作为前置防刷
- **标准 key 命名约定**：直接对应入库字段（`organizations.name` / `organizations.registration_number` / `organizations.address` / `organizations.state` / `organizations.city`），加 2 个中间值 `regionname`（供邮编查询使用，不入库）和 `legal_person`（拆分后入 `contacts.first_name/last_name`）；移除了未消费的 `status` 字段
- **端点**：`POST /api/{role}/enterprise-lookup`（节流 30/min）+ `GET /api/{role}/enterprise-lookup/status`（前端探活）
- **查询按钮可见性**：User 端 `enabled()=false` → 隐藏；Admin 端 `enabled()=false` → 禁用+tooltip
- **前端组件**：`shared/components/OrganizationEditor` — 单弹窗内两个 select（企业/联系人）+ 工商查询按钮；User/Admin 共用，按 `role` prop 切换按钮可见性策略（http baseURL 已含 /api/admin，URI 不重复前缀）；`countryOptions` 必填 prop 由调用方注入（admin/user 各自 `@/views/system/country` 维护，shared 组件不硬编码项目数据）
- **法人姓名拆分**：`splitChineseName()` 仅在 contact 未选且 last/first 都为空时回填 — 含空格 → 按空格切；含 `·` 中点（少数民族姓名）→ 按 `·` 切；其他 → 第一个字符为姓、其余为名（复姓需手动调整）。回填成功且 `contact.title` 为空时同步填"法定代表人"（用户可改）

### 邮编查询（本地数据 + 县级市识别）

- **数据来源**：基于 [tombcato/china-zipcode-data](https://github.com/tombcato/china-zipcode-data) MIT 全量 2879 条省/市/区/县/县级市邮编。字段裁剪至 `province / city / name / zipcode`；4 个直辖市 city 字段规范化为 province 名（重庆数据源用"重庆城区/重庆郊县"，统一改"重庆市"以对齐阿里云 `regionname` 输出）。文件 `backend/resources/data/china_city_zipcode.json` 约 273 KB（gzip ~30 KB），自托管零外部依赖
- **服务**：`App\Services\ZipcodeLookup\ZipcodeLookup`，进程内 `static` 缓存全量数据 + 预计算"每地级市最小 zipcode 代表"；测试用 `ZipcodeLookup::resetCache()` 重置
- **匹配策略**：`find(regionname, companyName = null)` 三步走 —
  1. **最长 fullPath 匹配**：遍历数据找最长的 `province+city+name` 子串命中 `regionname`（直辖市同时尝试 `city+name` 两段拼接，兼容阿里云"北京市朝阳区"风格）。命中即返回区/县/县级市精度
  2. **公司名兜底县级市**：若 regionname 仅到地级市未命中区/县，扫县级市候选 — 只要 regionname 含 province **或** city 任一（兼容工商响应字段不全的县级市公司，常见只给 city 不给 province），公司名是否包含县级市名（去/不去"市"后缀），命中则用县级市覆盖 `city` 字段
  3. **市级回落**：以上都未命中时，按优先级遍历地级市代表（① province+city 都命中 → ② 仅 city 命中 → ③ 仅 province 命中），返回该地级市最小 zipcode
- **返回 shape**：`{zipcode, province, city, district}` — 命中县级市时 `city` 填县级市名、`district` 留空；命中普通区县时 `district` 填区县名
- **端点**：`POST /api/{role}/zipcode-lookup`（节流 60/min，IP 维度），入参 `{regionname, name?}`，未匹配返回 `code: 0`
- **前端集成**：`OrganizationEditor.onLookup()` 工商查询成功后用 `d.regionname || d.province+d.city` 当 regionname、`d.name`（公司名）当 companyName 调邮编接口；回填 `postcode`（仅在空时），并用返回的 `city` **覆盖**已填 city（zipcode 服务的 city 比工商更精确，如县级市识别）。失败静默
- **fieldMap 联动**：`enterprise.fieldMap` 加入 `regionname` 标准字段（默认 `result.basic.regionname`），让工商响应带出完整行政区划路径供邮编查询使用
- **跨省误判防护**：第 2 步要求县级市的 province/city 必须在 regionname 里出现，避免"重庆某义乌商品城"被误判为浙江义乌

### 通知体系（主系统仅 mail，其他通道由插件注入）

- **业务事件归主系统、通道实现归插件**：主系统在 `Order/Action`/`AutoRenewCommand`/`ExpireCommand`/`TaskJob`/`FundAuditCommand`/`User\AuthController`（改密/重置成功 → `security`）触发 `NotificationCenter::dispatch(NotificationIntent)`，`ChannelManager` 分发到所有已注册可用且通过 `shouldSend()` 的 channel
- **`ChannelManager` 必须绑 singleton**（`AppServiceProvider::register`）：否则容器对未绑定类每次 make 新实例，插件 ServiceProvider 里 `app(ChannelManager::class)->register(...)` 注册的通道随实例丢弃、NotificationCenter/Job 解析到只含 mail 的新实例，插件通道端到端静默失效
- **主系统内置 mail，无 channel 抽象冗余层**：已删 `Guards/` 整目录（4 Guard + Manager）和 `TemplateSelection.channelTemplates`；`notification_templates.channels` 字段已移除（每个 code 对应单一模板，**`code` 唯一**）；`notifications` 表无 channel 字段
- **用户偏好扁平按 code**：`users.notification_settings = {cert_issued: true, cert_expire: true, security: true}`；`User::allowsNotification(code)` 替代旧 `allowsNotificationChannel(channel, type)`；`User::normalizeNotificationSettings` 兼容老的嵌套 `{mail: {x}}` 数据（自动提升 mail 子树）
- **Builder 注册按 code 单维度**：`config/notification.builders = ['cert_issued' => CertIssuedNotificationBuilder::class, ...]`，不再 `code.channel` 复合 key；4 个内置 Builder 已去 `Mail` 后缀（`CertIssuedNotificationBuilder` 等）。Builder 输出 `NotificationPayload($data)`，`data` 对所有 channel 通用，mail-specific 数据放 `data._meta`；敏感字段（如初始密码）走第二参 `NotificationPayload($data, $transient)`——`transient` 由 `NotificationJob` 发送前合入内存供渲染、发送后还原，**绝不入库**
- **插件接入主系统的全部触点**（**主系统对插件的承诺仅此**）：
  1. ServiceProvider 里 `app(ChannelManager::class)->register('feishu', new FeishuChannel)`
  2. 实现 `ChannelInterface`：`send(Notification): array` + `isAvailable(): bool` + `shouldSend(Model $notifiable, string $code): bool`
  3. 用户偏好/UI/模板全部由插件自治：自己加表/字段读偏好，主系统不预留 schema/UI/API 钩子，不加 widget 插槽
- **MailChannel::shouldSend 内联逻辑**：检查 `notifiable->email` 非空 + 调 `allowsNotification($code)`；Admin 等无此方法的 notifiable 默认 true
- **取消/重发等 Admin 操作**：测试通知 `/api/admin/notification/test-send` 和重发 `/api/admin/notification/{id}/resend` 不再传 `channels` 入参（已删 sanitizeChannels）；发送会广播到所有已注册可用 channel
- **携密/附件安全（job 边界）**：① `NotificationJob implements ShouldBeEncrypted`——携密 context（如 user_created 密码经 `NotificationIntent.context` → Job 构造参数序列化）用 APP_KEY 加密整个 job payload，防明文落 `jobs`（执行前窗口）/`failed_jobs`（长期）表；`transient` 只防 `notifications.data`，二者互补。② 多通道临时附件：Builder 按通道各 build 一次，附件类 payload（CertIssued 含私钥证书 ZIP）每通道各生成一份，`NotificationJob::handle` 末尾统一清理 `data._meta.cleanup_paths`（覆盖所有通道 + 发送失败路径、与 MailChannel 内清理幂等），防非 mail 通道（插件注入）临时文件泄漏
- **`DefaultNotificationBuilder` 兜底安全约定**：未配置 builder 的 code（Admin 测试通知 / 插件自定义 code）走 `DefaultNotificationBuilder`，**直通 `$intent->context` 入库**，不做敏感字段过滤。调用方需自律 — context 不传 password/token/secret/api_key/private_key 等字段，否则会明文存入 `notifications.data` 列并随通道转发外部。**例外**：`user_created`（携初始密码）已注册专用 `UserCreatedNotificationBuilder`，把密码走 `NotificationPayload.transient`（仅渲染入邮件、不入库），不回落 Default；新增携密 code 同样必须走专用 Builder + transient，不可依赖 Default。**`security`**（账号改密/重置提醒，`User\AuthController::updatePassword`/`resetPassword` 事务提交后触发）虽不携密，也用专用 `SecurityNotificationBuilder` 白名单 `username`/`event`/`email` 入库（纯文本 `is_html=false`），避免调用方误把敏感字段塞进 context 被 Default 直通；`event` 仅传安全事件可读描述，不含凭据

### 国密 (SM2) 证书

- **能力 gate（探测，非开关）**：下单 `Order\ActionTrait::initParams` 的 `guardSm2Capable` 对 `alg=sm2` 探测 `BinaryLocator::gmOpenssl()`，不可用即事务前拒绝（后端兜底防绕过、统一拦所有 SM2 含 reuse_csr=1、不留半残环境）。已移除 `site.gmEnabled` 业务开关与 `site.gmOpensslPath` 设置项——能否签 SM2 由本机 openssl 能力决定，不靠人工开关
- **国密 openssl（须签 id-ecPublicKey 标准编码，运维下限 OpenSSL 3.0.13）**：PHP openssl 扩展不支持 SM2，CSR 生成走 `BinaryLocator::gmOpenssl()`。**`probeSm2` 探测不只验「能签 SM2」，还实签一张 CSR 校验 SPKI 是 id-ecPublicKey 标准编码**——OpenSSL 3.0.0~3.0.12 能签 SM2 但把公钥 algorithm 写成 SM2 曲线 OID（dual-sm2），被国密 CA（如 Keeptrust）拒为「csr 解析失败」；官方 **3.0.13 / 3.2.1 起 restore** 回 id-ecPublicKey（commit `f2db052` 在 3.0 引入 dual-sm2）。**功能探测而非版本号比较**——3.1.0~3.2.0 版本号高于 3.0.13 但仍 dual-sm2 且已 EOL，数字比较会误放行；`csrUsesStandardEcPublicKey` 校验 CSR DER 含 id-ecPublicKey OID（`2a8648ce3d0201`）。`gmOpenssl()` 与系统 `openssl()` 同源（共用候选 + shell 兜底），不接独立国密二进制；不达标即 fail-closed 拒下单、绝不静默降级 RSA。RSA/ECDSA 走 PHP openssl 扩展不受影响。运维下限 **OpenSSL ≥3.0.13**（Ubuntu 24.04 自带；22.04 升 3.0.14；或 3.2.1+），**已彻底移除独立国密二进制的硬编码候选与镜像编译**（勿再加回）
- **双证书 + 存储**：签名证书（用户密钥对，manager 本地 `CsrUtil::generateSM2` 生成 SM2 CSR，临时文件 finally 强清不留盘）+ 加密证书（CA/KGC 托管下发）。`enc_cert`/`enc_key`/`enc_key2` 存 `certs` 表真实列（**每张证书独立**，不入按 issuer 聚合的 `chains` 表，否则同 CA 多证书互相覆盖加密私钥），跟随 `private_key` 暴露策略
- **多级代理透传**：manager 走 `default` source 调上游 `{ca.url}/get`（=对端 V2 get），CA 对接在 gateway（不在主系统）。上游 `get` 响应须带 `enc_cert`/`enc_key`/`enc_key2`（契约，键名=列名），sync 的 `$data=$result['data']` 透传 + `$cert->update($data)` 靠 fillable 自动写入（**sync 并发零改动**）。**manager 作上游时其 `V2 get` 也须透传 enc**（latestCertFields 加 enc + 非空透传/空 unset，同 `private_key` 策略；Deploy get 亦透传），否则多级 manager 链路下游写不进 enc。**sync 终态守卫**：本地终态时连同 status 一并 unset enc，拒上游滞后 enc 回写已终结证书。**中间证书入 chains**：sync 写回前先 `! empty($data['issuer']) && $cert->issuer = $data['issuer']` 落位，确保 `$cert->update($data)` 触发 `setIntermediateCertAttribute` 时 issuer 已就位、非空 `intermediate_cert` 在签发轮即入 chains（Eloquent fill 顺序不保证 issuer 早于 intermediate_cert；国密因 enc 短路 retrieved 钩子尤需确定性入库，与上游 gateway 对称）
- **证书解析**：`ActionTrait::parseCert` 对 SM2 用 PHP `openssl_x509_parse`（OpenSSL ≥1.1.1 原生识别 `signatureTypeSN=SM2-SM3`、公钥 256 位）+ `isSM2Cert` DER OID 兜底，固定 `encryption_alg=SM2`/`signature_digest_alg=SM3`/`encryption_bits=256`；`isSM2Cert` 对已明确解析出非 SM2 算法（signatureTypeSN 非 UNDEF/空）短路、不对每张 RSA/ECDSA 跑 DER
- **下载**：国密只出 nginx 双证书包（`_sign.crt`/`_sign.key`/`_enc.crt`/`_enc.key`/`_enc_gmt0009.key`/`_enc_gmt0016.key`/`_sign_ca.crt` + 说明.txt），`ActionFileTrait::addCertToZip` 按 `encryption_alg='sm2'` 走 `addSm2CertToZip`（**与前端 isSM2/Deploy gate 同口径、不按 enc_cert**——enc 空的 SM2 也强制国密包，不掉进普通 PKCS12 逻辑丢签名私钥/iis 报错）；**加密文件需 enc_cert + enc_key 成对才出**（三列独立 nullable、无成对到达约束，缺任一即整组降级仅签名 + 提示，杜绝"有证书无私钥/有私钥无证书"残缺包）
- **前端 gate**：`install.vue` 两端国密只显 Nginx + `enc_cert`/`enc_key` 任一空即置灰（`encMissing` 与后端成对守卫对齐）；`process.vue` 两端隐藏自动部署；算法字典 `dictionary.ts` 已含 sm2/sm3（无需改）；申请表单 `action.vue` 两端选产品自动校正加密选项（**不兼容才切**：当前算法不在产品 `encryption_alg` 菜单内才切到首选并复用 `handleAlgChange` 联动 bits，摘要同理校正到 `signature_digest_alg` 菜单内，SM2 强制 256+SM3、`keyBitsOptions` 增 SM2 分支只显 256；**仅 apply/batchApply**，续费/重签由 `loadOrderInfo` 回填原算法、不在此覆盖以防静默降级）
- **Deploy API gate**：`query` 的 `field=certificate|private_key` 拉取拒绝国密（防 certimate 单证书残缺自动部署）；`getOrderData` 国密 active 附 `enc_certificate`/`enc_private_key`/`enc_private_key_gmt0009` + `encryption_alg=sm2` 标记

### cloud-deploy 插件（证书自动推送云平台）

- **用途**：证书签发/续期 `latestCert.status=active` 后，自动把证书推送到各大云平台资源（CDN/负载均衡/WAF/对象存储/函数计算等）。`plugins/cloud-deploy`，独立 PHP 插件，命名空间 `Plugins\CloudDeploy`
- **架构**：certimate 式封装（每个 `(provider, product)` 一个 deployer，`AbstractDeployer` + `makeClient` 注入缝 + schema 驱动前后端校验 + guardSdk 凭证脱敏 + 4 类 uploader/`RemoteCertStore` 去重）；依赖阿里/腾讯官方 SDK（约 80M，**不 scoping**，ClassLoader 挂 SPL 栈尾）。25 端点（阿里 14 + 腾讯 11）
- **vendor 运行时安装（不入库/不打包）**：插件 `backend/vendor/` 约 80M **不入 git 也不进发布 zip**（仅打包 `backend/composer.json` + `backend/composer.lock`）。主系统 `PluginManager` 安装/更新带 `backend/composer.json` 的插件时自动 `composer install --no-dev`（**通用能力**，无 composer.json 的插件如 easy/invoice/notice/api-docs 跳过、零影响）：install 必装、update 仅当 `composer.lock` sha256 较旧版变化才装。复用 `BinaryLocator::composer()` + `UpgradePreflight`（composer/CLI proc_open 探测）+ 阿里云镜像自动切换，逻辑封装在 `App\Services\Plugin\PluginComposerRunner`。**对目标机要求**：composer 可执行 + CLI 未禁 `proc_open`/`exec` + 能访问 packagist（GitHub 不可达时自动切阿里云镜像）；不满足则安装失败并给明确文案（install 清理半装目录、update 回滚备份）。降级：缺 vendor 时 ServiceProvider `loadPluginVendor` is_file 守卫不 fatal、`CloudDeployJob::guardSdk` 把缺 SDK 转 per-target 失败日志，主系统其余零影响
- **主系统足迹**：backend 仅 `PluginManager` 加通用 composer hook + 新增 `PluginComposerRunner`（其余插件不受影响）；插件功能侧复用既有 widget 插槽 2 个（`admin-order-detail-ssl-actions` / `user-order-detail-ssl-actions`，order 详情 SSL 卡片注入「推送到云平台」按钮 + 目标状态）
- **详细开发规范见 `plugins/cloud-deploy/skills/development.md`**（核心架构、「新增部署端点」操作模板、其余 provider 任务目录、已知陷阱清单）

## 测试

- 本地开发环境用容器（`compose.yaml` + `Makefile`，详见 `docker/README.md`）：后端 PHP 8.4 + MySQL 8.4 + Redis 7，`make test` 容器内并行跑（隔离库 `ssl_manager_test`，`--processes` 防 OOM）
- **MySQL 5.7 与 8.x 双版本**：生产二者都有，CI core 跑 `5.7×{8.3,8.4}` + `8.4×{8.4,8.5}` 矩阵、各 plugin 跑 5.7+8.4；本地默认 8.4（ARM 原生），复现 5.7 用内网实例或看 CI
- **collation 按版本自动选择**（三处统一：`bt-install.sh` 的 `_detect_db_collation` / 容器 `entrypoint.sh` / CI `matrix.collation`）：8.x→`utf8mb4_0900_ai_ci`、5.7→`utf8mb4_unicode_520_ci`、MariaDB→`utf8mb4_unicode_ci`；`structure.json` 以 8.4 为基准。新迁移/SQL 避开 8.0+ 保留字（`rank`/`groups`/`system`）与 5.7 不支持的语法
- 详见 `skills/backend-dev.md` 测试章节

### M4 测试覆盖

- **Commands**（55+ 用例）：AutoRenew、Expire、DelegationCheck、DelegationCleanup、Validate、Purge、ResetAdminPassword、ClearAllCache、UserData
- **Models**（14 文件）：Order、User、Cert、Admin、Product、Notification、NotificationTemplate、Contact、Organization、Fund、Transaction、CnameDelegation、ApiToken、Task
- **Middleware**（8 文件）：AdminAuthenticate、UserAuthenticate、ApiAuthenticate、DeployAuthenticate、LogOperation、RateLimiter、LoginRateLimiter、FlushLogs
- **ACME**：Unit/Services/Acme/ActionTest（32 用例）、Feature/Controllers/Admin/AcmeControllerTest（13 用例）、Feature/Controllers/User/AcmeControllerTest（11 用例）、Feature/Controllers/Deploy/AcmeControllerTest（5 用例）
- **Deploy**：Feature/Controllers/Deploy/OrderControllerTest（39 用例）：query（21）、callback（7）、update（8）、认证（2）、数据结构（1）
