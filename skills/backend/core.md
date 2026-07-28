# 后端开发规范（核心）

## 技术栈

- **框架**: Laravel 11.x
- **PHP**: 8.3+，双引号变量不加大括号（如 `"$var"` 而非 `"{$var}"`）；例外：变量后紧跟中文等非 ASCII 字符时必须加花括号（`"{$var}，中文"` 而非 `"$var，中文"`），因为 PHP 变量名匹配 `\x80-\xff` 字节
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

### 路由风格（新增路由必须遵守；存量以 2026-07 统一为准）

- URL 一律 kebab-case；资源前缀用单数（`order`、`cert`；存量 `logs` 等复数不动）。
- 标准 CRUD 走 `RouteHelper::registerResourceRoutes`，先注册资源路由、再补自定义组；组内声明顺序 `/` → `{id}` → `batch`。
- 单资源动作统一动词在前 `action/{id}`（如 `pay/{id}`、`resend/{id}`）；嵌套资源下的动作（`backups/{backupId}/restore`）和子资源读取（`order/{id}/certs`）例外。
- 有副作用的端点禁止用 GET；批量操作统一 `batch` / `batch-*`，不得挂在集合根路径上。
- 局部更新用 PATCH（子集 upsert 也算局部更新），整份替换用 PUT。
- 导出统一 POST（入参可能超长且不宜被缓存/预取），响应用 blob 下载。
- 路径参数默认 `{id}` 并加 `[0-9]+` 约束（含 `{groupId}` 这类数字外键）；非数字主键或语义参数用 camelCase 语义名（如 `{backupId}`、`{userId}`、`{uuid}`、`{token}`）。
- 对外 API（V1/V2、acme、deploy、callback）契约冻结，风格调整不得波及。

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

### Cert 中间证书缓存与 retrieved 时序

- `Cert::retrieved` 钩子对 `active + issuer` 的证书，从 `Cert::chainMap()`（请求 / Job 级容器缓存的全部中间证书）取 `intermediate_cert`；缺失时把 `status` 改写为 `approving`（中间证书未就绪 → 表现为签发中，**影响 Deploy 部署判断 / V1·V2 cacheTime / 文档上传拦截，有意设计，勿当纯输出移除**）。
- **不能用 `with('chain')` 预加载**：Laravel `retrieved` 事件早于 `with()` eager load 触发，retrieved 内访问预加载关联会触发 lazy load（N+1 重现）。故用 `chainMap()` 一次性全表缓存（Chain 是 CA 中间证书、`common_name` 唯一、数量有限）替代逐条 `Chain::where()`，列表 N+1 → 每请求 / Job 仅 1 次全表查询。
- **缓存用 `app()->scoped` 而非 `instance`**：FPM 每请求新容器天然刷新；`queue:work` 常驻 worker 在每个 job 边界由框架 `resetScope → forgetScopedInstances` 自动清，避免跨 job 读到陈旧中间证书（`instance` 不随 job 清）。`setIntermediateCert` 写新 Chain 后 `forgetInstance('cert.chainMap')` 让同请求 / 同 job 内即时失效。

---

## 健康监控与调度心跳（P0-4 监控最小闭环）

`GET /api/health`（`HealthController`，无鉴权、命名空间无关、不受 `MaintenanceMode` 拦截）+ 调度心跳（M1）+ 队列积压（M2），供管理后台首页展示系统健康度。**运维部署视角（cron 属主 / 可选外部监控）见 `skills/ops/deploy-ops.md`**，此处固化判定机制。

### /api/health 三态判定（error 优先序不可乱）

`aggregate()` 判定序**固化**：所有 error 分支必须全部先于 degraded 分支 return，否则「心跳缺失→degraded 200」会掩盖真 error（如 db 挂时误判 200）。

- ① db ping 失败 → `error`（503）
- ② **cache 后端故障** → `error`（503）：`cacheCheck` 只读 `Cache::get('schedule:heartbeat')` 探连通性（redis 宕机时抛）。**必须先于下方 disk/queue/heartbeat**——它们经 `get_system_setting`→`Cache::remember` 读阈值/心跳，cache 故障时会抛，早 return 规避二次抛异常；`heartbeatAge` 的 `Cache::get` 亦 try/catch 返 null（不误判 degraded，因 cache error 已先 return）
- ③ `disk_free_gb` < `health.disk_free_threshold_gb`（默认 1.0）→ `error`（503）
- ④ freeze=false 时：`queue_lag` 超阈 → error；心跳**存在且过旧**（stale，> `health.heartbeat_stale_seconds` 默认 300）→ `error`（503，死 scheduler）
- ⑤ 心跳**缺失**（null）→ `degraded`（**200**）——排在全部 error 之后（新装机未跑调度 / `cache:clear` 清键，后台显示“需要关注”）
- ⑥ 其他 → `ok`（200）
- **freeze 期**：`queue_lag` 与心跳 stale 均不参与 503（worker/scheduler 已按升级流程停止），避免升级窗误报（双保险：console.php 侧心跳不挂 skip、health 侧 freeze 期不评估 stale）；**cache 后端故障不受 freeze 豁免**（cache 是独立于升级流程的基础设施）

### schedule:heartbeat（M1，第二个有意 freeze 存活者）

`HeartbeatCommand` 每分钟 `Cache::forever('schedule:heartbeat', now()->timestamp)`。调度接线与 `upgrade:watchdog` 同款**有意不对称**：`->everyMinute()->evenInMaintenanceMode()` 且**不挂** `->skip($skipWhenFrozen)`——挂了则 freeze 期心跳停，后台健康度会误报 scheduler 异常。`ScheduleFreezeSkipTest` 对 heartbeat/watchdog 断言 `filtersPass=true`。

- **用 forever 无 TTL 是刻意选型**：死 scheduler 留旧时间戳 → age 超阈 → stale 503（正确检出）；带 TTL 则键到期消失 → 缺失 → degraded 200，把死 scheduler 误判「未装机」。
- **访问时检测边界**（文档化于 deploy-ops.md）：「已死 scheduler + 之后 `cache:clear`」→ 键缺失 → degraded 200，后台健康度显示黄色“需要关注”，不主动发信。

### queueLag 队列语义（M2）

按 `queue.default` 分发（`HealthController::queueLag`），任何失败一律返 0（健康检查不因 queue 探活异常而 503）：

- **redis**：遍历 `config('queue.names')` 全部队列（notifications/tasks/default）求和——就绪深度 `llen queues:{name}` + **已到期**延时 `zcount queues:{name}:delayed -inf now`。**只计已到期**：整包 zcard 会把 auto-renew 夜间 0~8h 延时 commit 批次（score 在未来）当积压 → 00:00-08:00 持续误报。含 default 与 database 全队列扫描语义对称（约定恒空、非空即真积压——漏写 onQueue 的 Job——应报）。
- **阈值按驱动取义**（`queueThreshold`）消除「秒 vs 条数」两义：redis 返回**深度条数**用 `health.queue_depth_threshold`（默认 500 条）；database 返回**积压秒数**用 `health.queue_lag_threshold`（默认 600 秒）。低量 redis 部署误用 600「秒」当深度门槛会堆 600 条才 503、worker 死检测显著延迟。
- `/api/health` 通过 `queue_lag_unit` 明确前端显示单位（database=`seconds`、redis=`jobs`），并通过 `check_statuses` 返回各维度的 `ok/degraded/error`，前端不自行复制可配置阈值。心跳缺失为 `degraded`；freeze 期间超阈队列与过旧心跳也显示 `degraded`，避免把升级窗口的预期暂停标红。

### M6 cron 可见性

- schedule 命令非零退出挂 `->onFailure(...)` 落 `Log::error('[schedule.failed] ...')`。仅挂 validate / auto-renew / reconcile-pending / sweep-stale-tasks / reconcile-acme / sweep-orphan-orders（backup / finance / E 系监控自带告警）。
- 生产仅创建一个 `schedule:run` 宝塔计划任务，不额外重定向输出，由宝塔面板保存任务日志。

---

## 用户数据导出/清理（user:data）

`php artisan user:data {export|import|purge}`，服务在 `app/Services/UserData/`：`UserDataExporter`（导出 SQL dump，仅核心表，供跨系统迁移）/ `UserDataImporter`（导入 + 冲突检测）/ `UserDataPurger`（彻底清理用户全部数据）/ `UserDataTableRegistry`（表清单与删除顺序的单一来源）。

### tasks.order_id 双归属（删除/统计必须覆盖 orders + acmes）

`tasks` 表**没有 `user_id` 列**，仅靠 `order_id` 间接归属，且 `order_id` 同时承载两类：

- 普通订单任务 → `orders.id`
- ACME 任务（`commit_acme`/`sync_acme`/`cancel_acme`）→ `acmes.id`（`Acme\Action` 多处 `'order_id' => $acme->id`）

`orders`/`acmes` 均为全局唯一雪花 ID，二者 id 集合天然不相交，所以"这个 task 归谁"**完全由 order_id 命中哪张归属表决定，与 action 字符串无关**。`UserDataPurger::deleteTasks` 因此分两遍删：`orders` 子查询 + `acmes` 子查询（各自 `where('user_id', ...)` 限定，不会误删他人任务），不靠 `action LIKE '%_acme'` 过滤——避免 action 命名与真实归属漂移时重新制造孤儿。`getStatistics` 对 `tasks` 同样合并两张子查询计数。

- **删除顺序**：`purgeOrder()` 里 `tasks`（type=`tasks`）必须排在 `orders`/`acmes` 之前，否则归属表行先删、子查询查不到 → 漏删。
- **certs / domain_validation_records 是 Order 独有**（ACME 不产生），所以唯一需要双归属处理的间接表就是 `tasks`。
- **exporter 不导出 tasks**（瞬时队列态，不属迁移范畴；有测试断言 `not->toContain('INSERT INTO \`tasks\`')`），故 `cleanupOrphans` 对 tasks 自然跳过，无需特殊处理。
- 漏删后果：孤儿 task 被 `TaskJob` 唤醒后查不到对应 acme → 报错 / 失败任务噪音。
- 测试：`tests/Feature/Commands/UserDataCommandTest.php` 覆盖"order+acme 任务都删""不误删他人任务""统计含 ACME 任务"。

---

## 关键文件索引

### 委托验证与自动续签

| 文件                                             | 关键方法/位置                                   | 说明                                                            |
| ------------------------------------------------ | ----------------------------------------------- | --------------------------------------------------------------- |
| `Services/Order/Traits/ActionTrait.php`          | `generateDcv()`                                 | delegation→txt 转换，设置 is_delegate                           |
| `Services/Order/Traits/ActionTrait.php`          | `generateValidation()`                          | 委托记录查找/创建                                               |
| `Services/Order/Traits/ActionTrait.php`          | `writeDelegationTxtRecords()`                   | 订单创建时写入 TXT                                              |
| `Services/Order/Traits/ActionTrait.php`          | `mergeDcv()`                                    | API 响应合并保留委托标记                                        |
| `Services/Delegation/CnameDelegationService.php` | `getDelegationPrefixForCa()` / `isExactForCa()` | config 驱动派生 prefix / exact（exact 是 CA 属性）              |
| `Services/Delegation/CnameDelegationService.php` | `resolveZone($domain,$ca)`                      | 创建期 zone：exact 精确域名 / 非 exact 根域                     |
| `Services/Delegation/CnameDelegationService.php` | `findDelegation()` / `findValidDelegation()`    | 按 ca 查找委托（内核 `findByResolution`，exact 驱动是否回落）   |
| `Services/Delegation/CnameDelegationService.php` | `findExact()`                                   | 精确 (zone,prefix) 查找不回落（DCV host 已知 zone+prefix 场景） |
| `Services/Delegation/CnameDelegationService.php` | `checkAndUpdateValidity()`                      | 即时检测 CNAME 并更新有效性                                     |
| `Services/Delegation/DelegationDnsService.php`   | `setTxtByLabel()`                               | 批量写入 TXT 记录                                               |
| `Services/Delegation/AutoDcvTxtService.php`      | `handleOrder()`                                 | 订单级 TXT 处理                                                 |
| `Console/Commands/AutoRenewCommand.php`          | `checkDelegationValidity()`                     | 发起前即时检查委托有效性                                        |
| `Console/Commands/AutoRenewCommand.php`          | `processOrder()`                                | 自动续费/重签处理                                               |
| `Console/Commands/AutoRenewCommand.php`          | `autoPayAndCommit()`                            | 自动支付提交                                                    |
| `Console/Commands/ValidateCommand.php`           | `checkDelegationValidity()`                     | 验证前即时检测                                                  |
| `Console/Commands/DelegationCleanupCommand.php`  | `handle()`                                      | 清理非 processing 状态的 DNS 记录                               |

### 调度配置

| 命令                           | 调度       | 说明                                                          |
| ------------------------------ | ---------- | ------------------------------------------------------------- |
| `schedule:validate`            | 每分钟     | 证书验证任务                                                  |
| `schedule:heartbeat`           | 每分钟     | 调度心跳（写 Cache 供 /api/health 判活；freeze 存活者，见下） |
| `schedule:auto-renew`          | 每天 00:00 | 自动续费/重签（延时 commit 0~8h）                             |
| `schedule:reconcile-pending`   | 每 5 分钟  | pending 卡单对账重发 commit（见 order-fund.md）               |
| `schedule:sweep-stale-tasks`   | 每 5 分钟  | 重派僵尸 executing 任务（T1，见 order-fund.md）               |
| `schedule:reconcile-acme`      | 每 5 分钟  | ACME 卡单对账（T6，见 acme-module.md）                        |
| `schedule:sweep-orphan-orders` | 每小时     | 清理 channel=auto 孤儿续费单（O4，见 order-fund.md）          |
| `schedule:ca-healthcheck`      | 每 15 分钟 | 上游 CA 凭证 + 连通性告警（M7，见 notification.md）           |
| `delegation:check`             | 每天 05:30 | CNAME 委托健康检查                                            |
| `delegation:cleanup`           | 每天 06:00 | 委托 DNS 清理                                                 |

---

## 测试

### 运行测试

```bash
php artisan test --parallel                           # 全部测试（需 MySQL，推荐加 --parallel 与 CI 一致）
php artisan test --parallel --exclude-group=database  # 纯单元测试（无需数据库）
php artisan test --coverage --min=80                  # 覆盖率报告
```

> **测试库隔离（双重兜底，勿移除）**：① `phpunit.xml` 的 `<env name="DB_DATABASE" value="ssl_manager_test" force="true"/>` 覆盖 `.env`/`.env.testing` **文件值**——但 `force` **不覆盖 OS 环境变量**（`docker -e DB_DATABASE=...` / shell `export`，实测带 `-e DB_DATABASE=ssl_manager` 仍会连开发库）；② 故 `TestCase::createApplication()` 加运行期物理断言：测试库名不含 `_test` 即 `fwrite(STDERR) + exit(1)`，在 `RefreshDatabase` 清库**之前**硬阻断，杜绝任何跑法（含 `-e` 误传 OS env）清空开发库 `ssl_manager`。`.env.testing`（gitignore、仅本地）DB 应为 `ssl_manager_test`；`make test` 显式 `-e ssl_manager_test`、CI 用 `.env`+sed 同名。注意 `make migrate`（=`migrate:fresh --seed`）走开发库、会清库重建，与测试无关。

> **CI 经验**：本地务必用 `--parallel` 跑测试，与 CI 保持一致。`paratest`（并行测试）对 PHP Warning 的处理比 `phpunit` 更严格——例如无命名空间文件中的 `use Mockery;`、`use ZipArchive;` 等全局类 use 语句，`phpunit` 仅输出 Warning 继续运行，而 `paratest` 会直接 fatal exit 导致 CI 失败。

> **storage 隔离（并行 + 单进程都隔离）**：paratest 各 worker 共享同一 `storage/` 真实磁盘但各自独立 DB（RefreshDatabase）。一测试造真实磁盘文件（`storage_path('app/verification/...')`）、另一测试触发扫/删目录的命令（如 `PurgeCommand` 扫 `verification/` 根按本 worker DB 判“孤立”删除）→ 并行时跨 worker 误删对方文件 → `file_exists` 偶发 false。`TestCase::isolateWorkerStorage()` 把运行时 `storage_path()` + Storage 门面（local/public disk）重定向到 `storage/framework/testing/worker-{token}`，token 取 paratest 的 `TEST_TOKEN`、**单进程回落固定 `single`**（不用 pid：每跑一个新目录会让目录无界堆积，且目录内跨运行缓存如 `domain-rules/public_suffix_list.dat` 每跑缺失 → 每跑实网重抓公共后缀表、断网即红；固定 token 让单进程与 worker 一样首跑落缓存、后续复用），故 `php artisan test <文件>` / `composer test:snapshot` 这类定向跑法同样隔离，**新写“造真实磁盘文件”的测试自动隔离、无需额外处理**（隔离只覆盖运行时 `storage_path()`/门面，不动 framework cache/log/session — 后者用 bootstrap config 路径）。曾踩：单进程不隔离时，支付设置类测试经 `Setting::clearGroupCache → PayConfigCache::forget` 删掉开发环境 `storage/pay` 的真实支付证书并留下测试假证书，而 `getPayConfig` 只在文件缺失时才按设置重写 → 该环境此后一直用假证书签名、静默不可用。普通 `artisan test --parallel` 无 coverage、窗口小常测不出，**变异门禁 `XDEBUG_MODE=coverage` 放大并发窗口才稳定复现**（曾致 `DocumentSubmit/PreviewTest` 偶发挂）。
>
> **公共后缀表（PSL）夹具**：固定 token 只保证“后续复用”，**首跑仍是空目录**（全新克隆 / 干净 CI / 新增 paratest worker）→ 必须联网，且缓存过期重抓会把测试结果绑到上游当时的表。故 `isolateWorkerStorage()` 建好目录后由 `Tests\Support\PublicSuffixListFixture::seed()` 把仓内快照 `tests/Fixtures/public_suffix_list.dat` 灌进 `domain-rules/public_suffix_list.dat`（`xxh128` 内容比对而非只比体积——同尺寸的旧版本残留/写坏缓存会让 DomainUtil 读到别的表；写同目录 `.<pid>.tmp` 再 `rename` 保证不被读到半截；命中快路径也 `touch` 一次，让 mtime 恒为当下）——**测试离线确定性，生产 `DomainUtil` 一行不改、线上照旧抓最新表**。夹具刷新：`curl -fsSL -o backend/tests/Fixtures/public_suffix_list.dat https://publicsuffix.org/list/public_suffix_list.dat`，跑 `DomainUtilTest` 绿了再提交；按需刷新即可（PSL 只增量改后缀，陈旧不影响既有断言）。夹具被截断/换掉时 `seed()` 直接抛 `RuntimeException` 报出原因与刷新命令，不让 `DomainUtil` 静默回落到**无任何多级后缀**的内置表（那会让 `example.com.cn` / `sub.example.co.uk` 解析整体变形，`DomainUtilTest` 只报“两字符串不相等”）。`PublicSuffixListFixtureTest` 做常驻守卫：夹具行数下限 + `com.cn`/`co.uk` 等多级后缀存在、缓存内容与夹具一致且 mtime 恒为当下，以及**探针用例**——往缓存的 ICANN 段首插一条现实中不存在的后缀再断言 `DomainUtil::getRootDomain()` 随之变化。**探针不可省**：缓存路径是 `DomainUtil::loadRules()` 的手抄副本，抄错时联网 CI 下 DomainUtil 会自己把真表抓回来、一切照常全绿，整套离线机制静默失效。

> **API 快照对照（compat-snapshot）+ tearDown 吞 rollback 陷阱**：`compat-snapshot` job 仅 push main / tag 触发（dev PR 不跑），改了 API schema 或新增 Controller 测试后**必须** `composer test:snapshot:capture` 重新生成 fixtures 并提交，否则合 main 首跑即大面积 diff。更隐蔽的是 `TestCase::tearDown` 把 `SnapshotListener::finalizeTest()`（compare 模式命中 diff 会 `Assert::fail()` 抛异常）放在 `parent::tearDown()` 之前——**任何在 `parent::tearDown()` 之前、可能抛异常的清理逻辑都必须 `try/finally` 兜住 `parent::tearDown()`**，否则异常跳过 RefreshDatabase 的事务 rollback → 连接持锁泄漏 + 事务层级逐测试漂移 → 串行跑全套时后续测试 setUp/seed 撞锁，雪崩成 `Lock wait timeout`（单次 50s × N，job 直接卡满超时）。**只有串行全套暴露**：`--parallel` 各 worker 独立库/连接把泄漏掩盖，单文件也因同连接层级漂移不自锁而看不出。排查时 job 日志会被 MySQL service 容器 health-check 的 `Access denied ... using password: NO` 噪音淹没，真正错因在 `Run snapshot compare` step 的 `php artisan test` 输出尾部。

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

| 时机                             | 触发者                    | 操作                                                                                                                                                              |
| -------------------------------- | ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A) 补了测试**                  | 改资金代码的 PR 作者      | 本机 `composer test:mutate` 看 MSI；如果升高 ≥ 2%，单独 commit 调高 `min_msi`（最多 = `floor(实测 - 2)`，留 2% 缓冲）                                             |
| **B) release 前发现自然升高**    | 跑 `/remote-release` 的人 | 跑完看分数高于 baseline ≥ 3%，先 commit 调高 baseline 再走发布流程                                                                                                |
| **C) 范围扩展**                  | 决策加新核心 class 的人   | 在 `backend/scripts/test-mutate.sh` 加新 `--class=...`，重跑出新 baseline，整体调整 `min_msi`                                                                     |
| **D) 退步（MSI 跌破 baseline）** | 发现退步的人              | **禁止下调 baseline**——必须先补测试让 MSI 回升；除非该 untested mutation 已评估无害（如不可达分支），此时应在源码加 `// pest-mutate-ignore` 标记，而非动 baseline |

**长期阶段路线**：

| 阶段               | min_msi 目标 | 实测  | 重点                                                                                   |
| ------------------ | ------------ | ----- | -------------------------------------------------------------------------------------- |
| 第一阶段（已完成） | 77           | 80.12 | 6 class baseline 落地                                                                  |
| 第二阶段（已完成） | 88           | 90.96 | FundInvariants 加 message 弱断言 + 加入 AutoRenewService                               |
| 第三阶段           | 93+          | —     | 消化剩余 15 个 untested（多为 ConcatSwitchSides 等价突变，性价比低）或扩范围到退费明细 |

**首次跑出 baseline**：

> 容器内 pcov 默认 `enabled=0`；直接跑下面的 `./vendor/bin/pest --mutate` 前需临时开 pcov（`printf 'pcov.enabled=1\npcov.directory=%s\n' "$(pwd)" >"$PHP_INI_DIR/conf.d/zzz-pcov-mutate.ini"`，跑完删掉），宿主机用 xdebug 则 `XDEBUG_MODE=coverage` 即生效。日常重算基线直接 `composer test:mutate`（脚本已自动处理 pcov）。

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

**依赖（dev 容器已内置，开箱即跑）**：

- 开发容器（`docker/php/Dockerfile`）已装 **jq + pcov**，`composer test:mutate` 容器内直接跑、无需手动安装。pcov 默认 `pcov.enabled=0`（不拖慢普通 `make test`）；`backend/scripts/test-mutate.sh` 跑变异时临时写 `conf.d/zzz-pcov-mutate.ini` 开启（含 paratest 各 worker——worker 是独立进程，env/`-d` 不生效，只能走 conf.d），结束 `trap` 复原。
- 容器内必锁测试库：`docker compose exec -T -e DB_DATABASE=ssl_manager_test app composer test:mutate`（否则 RefreshDatabase 清开发库）。
- **宿主机直跑**才需自备 coverage driver（xdebug/pcov）+ jq；本机无 php/composer 时一律走容器。

**已知 flake（并行 + 覆盖率放大 TOCTOU）**：

- 变异跑 `--parallel` 且开覆盖率时，清缓存类命令测试（`ClearAllCacheCommandTest` / `BackupCommandTest` 等测 `cache:clear-all`/备份）清掉**所有 worker 共享**的 `bootstrap/cache/{packages,services}.php`，pcov/xdebug 放大窗口 → baseline 测试轮偶发 `require(...packages|services.php): Failed to open stream`（1 failed、中断不出 MSI，命中率约 50%+）。测试隔离 flake、**非资金代码 bug**：`bootstrap/cache` 在框架 bootstrap 阶段加载、早于 `TestCase::setUp`，无法像 `isolateWorkerStorage()` 按 worker 隔离，性价比低。**`test-mutate.sh` 已对该签名自动重试（最多 5 次，每轮 `package:discover` 重建 cache），只重试该 TOCTOU、不掩盖真实失败 / MSI 不达标**；极端连挂 5 次再人工重跑即可。

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

## 后端架构约定（杂项）

- **`$order->latestCert` 非空保证**：由系统架构保证 latestCert 关系非空，查询时加 `with('latestCert')` 预加载即可，无需额外空值判断
- **`$this->error()` 方法**：来自 `ApiResponse` trait，调用后抛出异常终止执行，不会继续后续代码
- **Action 无 userId 构造参数**：`Acme\Action` 和 `Order\Action` 均无 `userId` 构造参数，通过 `app(Action::class)` 获取实例。用户隔离由 UserScope 全局作用域保证（`Authenticate`/`ApiAuthenticate` 中间件注册 Acme、ApiToken、Callback、CnameDelegation、Order、Fund、Transaction、Organization、Contact、OrderDocument），控制器在创建方法的 params 中传入 `user_id`。UserScope `apply()` 无条件执行 `where('user_id', ...)`，不做零值跳过
- **无验证信息订单的定时同步**：`schedule:validate`（每分钟，`ValidateCommand`）只纳入 dcv **且** validation 都非空的 processing/approving 订单；dcv 或 validation 为 **NULL** 的订单（codesign/docsign/smime 等无 DCV 产品，验证靠 CA 人工审核/邮件）被其查询排除、无法自动同步，由独立的 `schedule:sync`（每天 9/15/21 点 `0 9,15,21 * * *`，`SyncCommand`）兜底——查 dcv 或 validation 为 NULL 的 processing/approving 订单并 `createTask(id,'sync')`。两查询条件互为补集、同一订单只被其一处理；频率低因无 DCV 产品订单量小（空数组 `[]` 非 NULL、仍归 validate）

## 队列与 Job 约定

- **`TaskJob::dispatch` 一律加 `->afterCommit()`**：`config/queue.php` 所有连接默认 `after_commit=false`，事务内 dispatch 的 Job 会立即入队，worker 可能在事务提交前消费 Job，读不到事务内新建的行/状态导致任务静默丢失。统一"一律加"口径（Laravel 无活动事务时 afterCommit 立即派发，语义等价），免去"是否在事务内"的语义判定，使 `skills/scripts/finish-check-greps.sh` 的硬零断言可机械执行；新增 Job dispatch 点同步跟进。
- **异步 Job 必须显式 `->onQueue(config('queue.names.tasks'))` 或 `notifications`**：生产部署的 supervisor worker 只监听 `tasks,notifications` 两个队列（`deploy/scripts/bt-install.sh` 自动写入 `queue:work --queue tasks,notifications`、`skills/ops/deploy-ops.md` 文档同），**不监听 connection 默认的 `default` 队列**。漏写 `onQueue` 的 Job 会落到 `default` 永远没人消费（`SubmitDocumentJob` 曾踩此坑：文档静默不上传上游）。约定：业务 Job → `tasks`、通知 → `notifications`，新增 Job 的 dispatch 必须 onQueue 到二者之一；**gateway 侧同此约定**（其 worker 同样只监听这俩，镜像的 `SubmitDocumentJob` 也需 onQueue）。
