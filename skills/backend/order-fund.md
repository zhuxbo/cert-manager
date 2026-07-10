# 订单与资金安全

## order 级互斥锁（方案 C：根治 3+ 并发 1205）

> **背景**：点 1（Sdk 锁内超时 28/10/10）把单次持锁压到 ≤48s 后，同一订单 **3+ 并发** commit/cancel 仍会在 DB 行锁上**排队累计** >`innodb_lock_wait_timeout`(已固化 session=50，见 `config/database.php` PDO `MYSQL_ATTR_INIT_COMMAND`) → 偶发 `1205 Lock wait timeout`。方案 C 在**进 DB 锁之前**加一把按订单 id 的 Cache 互斥锁，把"DB 锁等待 1205"转成"Cache 抢锁立即失败"。

### 核心原语 `App\Support\MutexLock::withMutex`

```php
withMutex(string $key, Closure $cb, int $ttl = 60): mixed
// 抢到 → 执行 $cb，finally 原子释放（Cache::lock 带 owner，TTL 过期不误删他人锁）
// 抢不到（非阻塞 ->get()）→ 抛 MutationBusyException（不进 DB 锁等待队列）
// Cache 故障 → fail-open 放行（退回 DB 锁串行，由点 1 Sdk 超时兜底，慢但不 1205、不阻塞业务）
```

- 用 `Cache::lock(...)->get()`（**非阻塞**，不用 `->block()`），抢不到立即返回，绝不在 Cache 层排队。
- `MutationBusyException`（`App\Exceptions\`，**不继承 `ApiResponseException`**——否则被 TaskJob 内层 `catch(ApiResponseException)` 当业务结果标 failed）。

### Cache driver 兼容性（生产默认 file 可行，不绑定 Redis）

`withMutex` 用 `Cache::lock`（默认 store），各 driver 的锁支持（Laravel 13）：

- **file（`config/cache.php` 默认）**：`FileStore implements LockProvider`，`FileLock::acquire` 走 `FileStore::add` 的 `flock(LOCK_EX)` 临界区——**单机多 PHP-FPM worker 间原子互斥**（宝塔单机部署典型场景，storage 在本地盘 flock 可靠）。
- **redis / database / array / memcached**：均支持 `Cache::lock`。
- **多机部署注意**：file lock 基于本地文件系统、**不跨机**；多 app 服务器共享负载时，跨机并发同一订单各自抢到本地锁 → 退回 DB 锁串行（点 1 超时兜底，不 1205、不资金错乱，但失去"立即失败"优化）。多机要跨机互斥又不上 Redis，用 `database` driver（走 cache 表、跨机有效）。
- **任何不支持 lock 的 driver / Cache 故障** → fail-open 放行退回点 1 兜底，不阻塞业务。

测试 `MutexLockTest` 同时覆盖 array（测试默认）+ file（生产默认）两 driver 实证真互斥。

### 落点表（Order 与 ACME 不对称，源于 pay 的内部调用链）

| 公共方法                                         | 包 withMutex          | 依据                                                                                         |
| ------------------------------------------------ | --------------------- | -------------------------------------------------------------------------------------------- |
| `Order\Action::commit`/`cancel`                  | ✅ `order_mutate_$id` | 多入口操作已存在订单；commit/cancel 共用 key 串行                                            |
| `Order\Action::pay`                              | ❌                    | 单 id 分支调**公共** `commit`（自带锁，再包同 key 自死锁）；多 id 分支走 `createTask` 异步化 |
| `Acme\Action::commit`/`pay`/`cancel`/`cancelNow` | ✅ `acme_mutate_$id`  | ACME `pay` 走 private `commitOrder`（不带锁）→ **必须自包**，与 Order pay 不对称             |
| `newAndCommit` / Order 一条龙                    | ❌                    | 新订单 id 事务内生成、无并发同 id；id 事务内才有、不便在事务外抢锁                           |

实现模式：原方法体下移为 `private *Locked()`，public 方法 `$this->withMutex("..._$id", fn () => $this->xxxLocked($id))`，**锁内逻辑零改动**。

### 两路分流（同步 vs 异步）

- **同步入口**（API/网页，经 `ApiExceptions`）：`MutationBusyException` → 503 + "该订单正在处理中，请稍后重试"，且加入 `$dontLogExceptions` 免高频刷 error_logs。
- **异步 `TaskJob::handle`**：内层 + 外层 catch 把 `MutationBusyException` 与 `DeadlockException‖causedByConcurrencyError` 同等对待 → rethrow + `attempts<tries` 时 `release` 错峰、**不标 failed**（`causedByConcurrencyError` 只匹配 DB SQLSTATE，绝不识别自定义异常，故必须显式纳入）。

### 与点 1 超时的关系（不可删）

互斥锁 = 消掉"3+ 并发抢同一行"**高频主因**（应用层、依赖 Cache）；点 1 超时 = 兜住**残余 + 降级**（DB 层、确定性）。三条互斥盖不到、必须靠点 1：① 互斥只盖 commit×cancel，盖不住 commitCancel/sync 写回/markRenewed 撞 commit 持锁行；② Cache 故障 fail-open 退回 DB 锁串行，靠点 1 保证每个 ≤48s 不 1205；③ 应用层互斥替代不了存储层持锁硬上界。**删点 1 会破坏方案 C 降级安全**。

### 孤儿单（C vs 曾否决的 B）

保持锁内 → 上游建单 `$this->api->$action()` 与本地 `save()` 在同一事务原子，孤儿窗口仅"上游已返回成功、save() 提交前遇死锁回滚"的**毫秒级既有窗口**（`Order/Action.php` commit 注释承认，传统 Order 既有，方案 C 不新增）。曾否决的 B（commit 锁外 + CAS）把窗口放大到整个锁外调上游期（28–48s）且常规并发 cancel 即触发 → C 显著更优，但非"零孤儿单"。

### 测试

`tests/Unit/Support/MutexLockTest`（原语 6 behavior）+ `tests/Unit/Bootstrap/ApiExceptionsMutationBusyTest`（503+免日志）+ `tests/Feature/Services/Concurrency/CommitCancelMutexTest`（占锁→busy，证明 withMutex 在最外层、抢锁早于查 DB）+ `tests/Unit/Jobs/TaskJobMutexBusyTest`（异步 release/达上限冒泡）。

---

## tasks 死锁防护与并发错误处理

**线上现象（2026-06）**：同一订单被 V2 `get`（内联 sync）+ `POST /api/order/sync` + queue worker 多入口高频并发，都抢 `tasks` 表 `WHERE order_id=X AND action IN (commit,sync,revalidate) AND status IN (executing,stopped) FOR UPDATE`，触发 InnoDB 死锁（1213）；TaskJob 的 `catch (Throwable)` 又把死锁异常当普通业务异常吞掉后继续 `$task->update()`，外层 `DB::transaction` 提交时抛 `PDOException: There is no active transaction`，job 失败被 queue 无脑重试 → 雪崩刷屏。

**死锁防护分两组**：运行时防护层负责实际降频和自愈（schema 删除孪生索引、`Task::lockForMutation` 强制复合索引、`runTaskMutationTransaction` 重试）；finish-check 守卫层负责防回归（代码入口收口、schema 最终态、scope 接线）。

1. **schema 删除孪生单列索引 `tasks_order_id_index`（根治退回目标）**：该单列索引与复合索引 `tasks(order_id, action, status)` 同首列、体积更小，是 MySQL 优化器退回、把 next-key lock 扩大到"整个 order_id 区间" → 1213 的现实目标；复合索引左前缀完全覆盖它，删除后全部按 order_id 的查询（Order/Acme Action、ReconcilePendingCommand、PurgeCommand、UserDataPurger 等）走复合索引。这是**唯一能覆盖 `deleteTask` 的 DELETE 路径**的手段——MySQL 单表 DELETE 不支持 `FORCE INDEX`，只能靠 schema 消灭退回目标。迁移 `2026_07_08_000001_drop_tasks_order_id_index`（幂等 `SHOW INDEX` 守卫、复合索引就位后才删，兼容 5.7/8.x/MariaDB）+ `create_tasks_table` 去掉 order_id 列的单列 `->index()`（保留复合索引）。**陷阱：删索引必须同步重导 `backend/database/structure.json`**——升级流程 migrate 后跑结构自修复（`DatabaseStructureService`）**只对 missing_indexes 生成 ADD、不删 extra**，structure.json 不同步会在升级时把孪生索引原样加回、白删。
2. **`Task::lockForMutation` scope 强制复合索引（确定性兜底）+ `runTaskMutationTransaction` 统一重试**：锁查询下沉为 Task 模型 scope `scopeLockForMutation`（`forceIndex('tasks_order_action_status_index')` + where order_id + whereIn action + whereIn status(executing,stopped) + `select('id')` + `lockForUpdate`，常量 `Task::TASK_LOCK_INDEX`），Order/ACME 共用，调用形如 `Task::lockForMutation($orderId, ['commit','sync'])->get()`；即便某库残留孪生索引，`forceIndex` 仍确定性收窄间隙锁。重试助手抽为共享 trait `App\Traits\RunsTaskMutationTransaction`（`DB::transaction(..., 3)`，常量 `TASK_MUTATION_TRANSACTION_ATTEMPTS=3`），`Order\Action` 与 `Acme\Action` 都 use。**覆盖面（不含上游副作用的纯本地 task→order/acme 变更，全部走 attempts=3 重试）**：Order `sync` / `commitCancel(active)` / `batchCommitCancel(active)` / `revokeCancel` / `refundForSyncedCancel` / `cancelPending` + ACME `revokeCancel` / `sync`。controller 直调时本事务为最外层，重试只重跑锁+本地写回，安全。**`commit` 绝不加重试**——其上游下单 `$this->api->$action()` 在事务内（`Action.php`），重试 = 重复下单/重复扣费；且 commit 只锁 order 行、不执行 `tasks FOR UPDATE`，本就不是死锁受害者。**ACME `commitCancel` 同理不重试**（无 task `FOR UPDATE`，不在本机制覆盖内，改动超出范围）。
3. **CI 硬零守卫（`finish-check-greps.sh` Z12/Z13/Z14 + DB 最终态测试）**：Z12 要求 `backend/app` 内对 Task 模型的 `lockForUpdate` 只允许出现在 `app/Models/Task.php` 的 scope 定义与 `app/Jobs/TaskJob.php` 的主键锁两处，其余一律走 `Task::lockForMutation` scope；检查以 `Task::` 起头的语句聚合到分号，链中出现 `lockForUpdate` 即 FAIL（其他模型 Order/User/Acme 的 lockForUpdate 不以 `Task::` 起头 → 零误报；`Task::lockForMutation(...)` 调用点不含 `lockForUpdate` 字面量 → 不误命中）。Z13 用 `structure.json` 断言 tasks 表只允许 `tasks_order_action_status_index(order_id, action, status)` 这一条 `order_id` 首列索引，真实 DB 测试用 `SHOW INDEX FROM tasks` 覆盖迁移后最终态；Z14 校验 `scopeLockForMutation` 的 `TASK_LOCK_INDEX`、`forceIndex`、where/action/status、`select('id')`、`lockForUpdate` 接线完整。脚本已在 `ci.yml` 强制执行（fail-closed）。

**`forceIndex` 保留理由（孪生索引已 schema 删除后为何仍保留 hint）**：孪生 `tasks_order_id_index` 既已 schema 级删除、优化器不再有更小的退回目标，`forceIndex` 的唯一不可替代价值是 **MySQL 版本矩阵（5.7 / 8.x / MariaDB）下的执行计划稳定性**——DB 大版本升级时优化器代价模型会变（同一 SQL 可能改选索引 / 改扫描方式）。这条轴不能只靠代码形态守卫（Z12）或 schema 形态守卫（Z13）覆盖，必须由查询 hint 本身提供确定性，Z14 负责防 hint 接线断开。保留成本极低：hint 收口在 `Task::lockForMutation` 单处一行，索引名被迁移（`create_tasks_table` 复合索引）+ `structure.json` 双处钉死，一旦索引被改名 / 删除 `forceIndex` 立即 `1176 Key ... doesn't exist` 响亮失败（相关测试断言先红），绝不静默退化。辅助事实：孪生索引若漂移回来（旧备份恢复 / 人工加回），升级结构校验会以 `extra_indexes` 点名（`manual_actions`「删除多余索引」，只报告不自动删），且全路径 `attempts=3` 重试使漂移窗口内的死锁危害有限——即 `forceIndex` 是一层**廉价的确定性保险**而非必要层，删它须先接受"版本升级执行计划漂移"这一确定性风险。

**并发错误处理 · TaskJob 死锁不可吞 + 自愈重试不刷日志（治本 + 降噪）**：`TaskJob::handle` 对并发错误（`Illuminate\Database\DeadlockException` 或 `DetectsConcurrencyErrors::causedByConcurrencyError`：1213/1205/序列化失败）分两层。内层 `catch (Throwable)` **重新抛出**（绝不在已被 MySQL 回滚的事务里继续 `$task->update()` 或让闭包正常返回触发 commit）→ 逸出闭包触发外层 `DB::transaction` 回滚。**handle() 外层 catch** 再判 `$this->attempts() < $this->tries`（显式 `public int $tries = 3`，与 worker `--tries 3` 一致、重试次数不变）：未达上限时由本 Job 自己 `$this->release(random_int(3,8))` 错峰重试并 `return`——**关键：不抛出**，worker 不进 `Worker::runJob` 的 `$this->exceptions->report()` 路径，避免每次"会自愈的偶发死锁"被刷进 `error_logs`（reportable 回调）+ `laravel.log`（默认 channel fall-through），tries=3 一次死锁最多 6 条噪音 → 降为 0；达上限才冒出 → worker `report()` 记一次最终失败 + `failJob` → `failed()` 钩子兜底标记 `task=failed`（守卫 `status==='executing'`，否则普通异常路径重复 update）——不标记会永久卡 executing 被 `checkRepeat` 当"处理中"阻塞该订单后续 commit/sync。`release` 不碰 task（保持 executing 等下次拾取），与 `failed()` 兜底构成闭环。**freeze release（`SkipWhenUpgradeFrozen`）与死锁 release 共享 `$tries` 预算**，沿用 tries=3 现状不恶化。

**自愈不刷日志 · 三层通用原则（会自愈的重试期不产生任何错误日志，最终耗尽才记录）**：把 TaskJob 既有的"自愈重试不刷日志"上升为全部三条并发自愈路径共享的降噪纪律——

1. **web 入口 `DB::transaction(..., 3)`**（`runTaskMutationTransaction`）：重试期间由 Laravel `ManagesTransactions::handleTransactionException` 静默 `rollBack` + `continue`（`causedByConcurrencyError && currentAttempt < maxAttempts` 分支，vendor 源码确认无 report、无任何 `Log::`），框架**零日志**；只有第 3 次仍失败才 `throw $e` 冒到 `ApiExceptions` → 记一次 `error_logs`（503）。
2. **`TaskJob` 并发错误**（1213/1205/序列化失败）：`attempts() < tries` 时自己 `release(3~8s)` 错峰并 `return`、**不抛出** → worker 不进 `report()` 路径 → 零日志；达 `tries` 才冒出 → worker `report()` 记一次 + `failed()` 兜底标 task。
3. **`MutationBusyException`**（order 级互斥抢锁忙，方案 C）：同步入口在 `ApiExceptions::$dontLogExceptions` 白名单免记（503 友好文案）；`TaskJob` 内 `attempts() < tries` 时 `release(50~70s)` 不抛、达上限才冒泡。

**边界（真实故障不是噪音）**：这三条只静默"会自愈的重试期"；**最终耗尽（web 3 次全败的 `DeadlockException` / `TaskJob` 达 `tries`）必须照常记录**——`DeadlockException` 与 `causedByConcurrencyError` **均不**加入 `dontLogExceptions`（web 耗尽 503 照常写 `error_logs`；TaskJob 耗尽 worker `report()` 记一次），仅 `MutationBusyException`（高频"忙"信号、天然可重试）进 `dontLogExceptions`。`MutexLock` 的 Cache 故障 fail-open 分支 `logException` 是**基础设施故障**（非并发自愈）应保留、且每次故障只记一次不随重试放大。**新增任何并发自愈路径必须遵守同一原则**：重试期零日志、最终失败记一次。

**关键认识**：死锁是 InnoDB 行锁并发写的正常现象，**无法根除，只能降频 + 重试自愈**（MySQL 官方亦要求应用层重试）；把"偶发死锁"放大成"持续雪崩"的是错误的死锁后处理（吞异常 + 死事务上继续写 + 无脑重试），那才是真正的炸点。嵌套事务（TaskJob 包 sync/commit）里 Laravel 对并发错误直接抛 `DeadlockException` 到最外层、不在内层重试（`ManagesTransactions::handleTransactionException` 的 `transactions > 1` 分支）——故 `attempts` 只在 controller 直调（最外层）时生效，TaskJob 路径统一由 job 级重试兜底。**减少多入口并发（如 V2 get 去内联 sync）不是根治方向**：并发不可消除、且会动对外 API 契约。

## API 下单韧性（commit 超时不回滚扣费 + pending 卡单对账）

**背景**：一条龙下单 API（`V1`/`V2` 控制器的 `new`/`renew`/`reissue`）原把「建单 + 扣费 + commit(调上游)」裹进单个外层事务，上游变慢 commit 超时（SDK 压成 `code=0`）冒泡触发整笔 rollback，连**已扣费**一起回滚 →「上游有单、manager 零记录」。修复分层如下（**只改控制器 + `getData`，`Action::new/pay/commit` 本体不动**）：

- **M1 — 拆事务**：外层 `DB::transaction` 只包 `new + pay(commit=false)`（扣费 `charge` 作嵌套 savepoint 落 `pending`，随外层原子提交）。`commit`（调上游）移到 `DB::commit()` **之后**独立调用。commit 超时/失败/抢锁忙不回滚、不报错，订单停 `pending`（`api_id=NULL`）、扣费保留，返回下游既有 `processing` 展示态（`get` 出口的内存转换，DB 仍 pending）。**扣费必须仍嵌套在 new+pay 外层事务内**——不可改独立顶层 tx/独立 connection，否则「扣费独立提交但 new 回滚」→ 孤儿 order transaction。
- **`getData($action, $params)` 分流**：仅 `$action === 'commit'` 时吞 `code=0`（`return []`）与 `MutationBusyException`（不外抛 503）；`new/renew/reissue/pay` 段保持原样冒泡（建单/扣费失败照常报错）。`get` 出口对 `pending` 的内联 `commit()` 同步 `catch (ApiResponseException|MutationBusyException)`，抢锁忙不冒 503。reissue 的跨用户所有权校验**保留在事务内** `throw`，触发整笔回滚（reissue 建的证书一起撤销）。
- **M4 — 卡单对账**：`ReconcilePendingCommand`（`schedule:reconcile-pending`，`routes/console.php` 每 5 分钟 `withoutOverlapping`+`skip($skipWhenFrozen)`）扫「`status=pending` 且 `api_id=NULL` 且 `created_at` 超 `reconcile.pending_stale_minutes`」的卡单，`createTask(id,'commit')` 重发（`createTask` 内置 executing 幂等）。带重试上限（`reconcile.max_attempts`，按**失败 commit task 的行数 = 失败对账周期数**判定，**不 sum 单个 task 的 worker 级 `attempts`**——否则单任务被 worker 重试到 attempts≥max 就在约一个周期后误判到顶、过早转人工）+ 退避（`retry_delay_minutes`，倍率随对账周期数增长）+ 超限走 `NotificationCenter` 的 `task_failed` admin 告警（`task.result.reconcile_alerted_at` 去重、不重复告警）。配置 `config/reconcile.php`（env `RECONCILE_*`）。**manager 卡单态是 `pending`（上游是 `processing`）——任何对账/幂等判定严禁照抄上游的 processing。**
- **M5 — `resolveReferId`（原 `checkReferId`）**：同 `refer_id` 命中订单时不再硬拒，改幂等推进——仅当 `! api_id && status === 'pending'`（manager 卡单态）重提 `commit`，否则直接幂等返回既有 oid（守卫天然排除 cancelled/revoked 等终态、不复活）。并发同 refer_id 由 `certs.refer_id` 唯一索引兜底。**ACME 侧仍走 `Refer id already exists` 硬拒（DB unique 翻译），与 Order 的分歧是有意的**。
- **`bootstrap/resilient.php`（同批附带）**：`bootstrap/cache` 的 `services.php`/`packages.php` 在多进程并发首次编译 / 升级 optimize 窗口 / VirtioFS 非原子 rename 下偶发 TOCTOU「Failed to open stream」。该错误在框架 bootstrap 极早期（异常处理器未注册），故用兜底重试器包裹启动：仅识别这两个清单的读失败 → 清半态缓存 + 退避重试（≤3 次），其余异常原样抛。落点 `public/index.php` / `artisan` / `tests/TestCase::createApplication`。

**测试**：`tests/Feature/Http/Controllers/{V1,V2}/ApiControllerCommitResilienceTest`（超时保 pending+扣费/崩溃模拟/refer_id 幂等/跨用户回滚/getData 分流单测）、`tests/Feature/Commands/ReconcilePendingCommandTest`、`tests/Unit/Bootstrap/ResilientBootstrapTest`、`tests/Unit/Services/Order/Api/DefaultSdkCatchScrubTest`。SDK 层脱敏见 `skills/backend/source-api.md` 的「Sdk catch 脱敏」章节。

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

| 索引                                                                | 含义                                                                                                                                                                                          |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `funds(pay_method, pay_sn)` 唯一                                    | 防"不同 fund 同一支付编号"。MySQL BTREE 索引中 NULL 互不相等，处理中订单 (pay_sn=NULL) 多行合法；落地后的 (pay_method, pay_sn) 才进入唯一性判定                                               |
| `transactions(type, transaction_id) WHERE type != 'order'` 部分唯一 | 防"同一事件被重复入账"。MySQL generated VIRTUAL 列 + 完全唯一索引模拟（`CASE WHEN type='order' THEN NULL ELSE CONCAT(type,':',transaction_id) END`，NULL 不参与唯一约束，效果等价于部分索引） |

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

命令调 `FundInvariants::all()` 全量对账，违反 → 走 `NotificationCenter`（`code=finance_audit`）发邮件给 `site.adminEmail` + `Log::error` 兜底。可选 `--freeze-on-violation` 自动把涉事 user.status=0 禁用。

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

## 微信支付公钥验签切换（yansongda）

> 支付走 `yansongda/pay` v3.7.x（`Pay::wechat()`，薄封装 `App\Services\Payment\PaymentGateway`）；验签逻辑全在 SDK，项目不自处理 `Wechatpay-Serial`。

### 背景：平台证书 → 微信支付公钥

微信 v3 验签正从「平台证书」灰度切到「微信支付公钥」，商户后台两条进度：

- **回调**（微信 → 商户）：微信平台控制灰度（约 7 天完成）
- **应答**（商户调 API 的响应）：**商户请求参数控制** —— 请求头 `Wechatpay-Serial` 带公钥 ID（`PUB_KEY_ID_xxx`），微信才用公钥签应答；「应答使用公钥比例」= 近 7 天带公钥头请求数 / v3 总请求数

### 坑：yansongda 默认不发头，应答比例恒 0%

`AddRadarPlugin`（`vendor/yansongda/pay/src/Plugin/Wechat/AddRadarPlugin.php`）只在 body 有敏感信息加密内容（`_serial_no` 由加密插件设）时才发 `Wechatpay-Serial`。普通充值下单 `scan` / 查单 `query` 不带 → 微信收不到公钥请求 → 应答比例卡 0%、切换无法完成。

### 解法：所有微信 v3 商户请求注入 `_serial_no`

`PaymentConfigTrait::wechatSerial()` 返回 `['_serial_no' => publicKeyId]`，控制器 `array_merge($order, $this->wechatSerial())`：

- yansongda 据 payload 的 `_serial_no` 设 `Wechatpay-Serial` 请求头
- artful `filter_params` 过滤所有 `_` 前缀 key → `_serial_no` **不进发给微信的 body**，不污染业务参数
- 调用点：`User/TopUpController`（下单 `scan` + 查单 `wechatQuery`）、`{Admin,User}/FundController::check`；FundController 统一走 `app(PaymentGateway::class)` 包装（便于测试替换，**勿用 `Pay::` 静态**）

### 坑 2：查单 `query` 管线二次抹掉 `_serial_no`，须走 `PaymentGateway::wechatQuery`

**只在调用点合入 `_serial_no` 不够**——下单 `scan`（`Native\PayPlugin` 用 `mergePayload`）能保住，但查单 `query` 的 `Jsapi\QueryPlugin` 用 `setPayload([...])` **整体重建 payload、只留 `_method/_url/_service_url`**，把 `StartPlugin` 合入的 `_serial_no` 抹掉 → `AddRadarPlugin` 读 payload 读不到 → **查单请求不发头**。而 `query` 在每次前端轮询 + 后台 check 都调，量远大于 `scan`，故应答比例被稀释卡在极低值（现网实测 ~1.5%，≈ 下单请求占比）。v3.7.20（`~3.7.0` 下最新稳定）与 v3.8.0-beta.2 均未修，**升级无解**。

修法：`PaymentGateway::wechatQuery(array $order)` 不走 `->wechat()->query()` 快捷方式，改取 `QueryShortcut::getPlugins()` 的原始插件列表（跟随 vendor 升级漂移），在 `AddRadarPlugin` **前**插入 `App\Services\Payment\Plugin\InjectWechatSerialPlugin`（从全程存活的 `params` 把 `_serial_no` 回灌 payload），再走 `Pay::wechat()->pay($plugins, $order)`。所有查单调用点改 `->wechatQuery(...)`；`scan` 不变。

**测试须在 HTTP 层断言**（`tests/Feature/Services/Payment/WechatSerialPipelineTest`）：绑定假 PSR-18 client（`Yansongda\Artful\Contract\HttpClientInterface`）捕获出站 Request、断言 `Wechatpay-Serial` 头。**不能只 mock 整个 `PaymentGateway` 验「参数到达 wrapper」**——原 7 个测试正因此假绿：参数确实到了 wrapper，却被 vendor 管线抹掉、头从未发出，测试全绿而现网比例不动。`mockPayCapture` 的查单捕获仅作调用点契约（断言按 gate 合入 `_serial_no`），头真的发出由 HTTP 层管线测试保证。

### gate 与本地公钥就绪条件对称（防误配）

`wechatSerial()` 发头 gate = `publicKeyId` + `publicKey` **俱全**，与 `getPayConfig` 注册本地公钥到 `wechat_public_cert_path` 的条件逐字对称。**只填 ID 未填公钥内容时不发头** —— 否则微信用公钥签应答，本地却无公钥、回退下载 `v3/certificates` 只返平台证书（永不含 `PUB_KEY_ID`）→ 验签失败。漏配时不发头 = 应答留平台证书、本地可验、保持可用。

### 验签兼容（平台证书签 + 公钥签都能验）

回调 / 应答验签同源 SDK `verify_wechat_sign`：读响应头 `Wechatpay-Serial` → 在 `wechat_public_cert_path` 找 → 命中公钥直接验 / 找不到回退 `GET v3/certificates` 下载平台证书验。故切换期两种签名都能验，满足微信「兼容验签」要求。

### 坑 3：微信每日 ~20 点补投重放已成功通知，serial 与签名不一致 → 验签必失败（非本地配置问题）

微信通知是 at-least-once：某补投链路每天 20:01~20:05 换 IP（121.51.58.17x 段）重放当天**已应答成功**的支付通知（通知 `id`/`create_time`/resource `nonce` 与首投完全相同），且重放的 `Wechatpay-Serial` 标平台证书序列号、实际签名却与该证书对不上（微信侧元数据不一致），本地公钥/现场下载的平台证书都验不过 → 每天固定一条 `InvalidSignException` 噪音 + 微信重试放大。诊断特征：错误集中在每日 20 点后数分钟；yansongda 日志出现 `v3/certificates` 现场下载 = reload 被触发 = 回调 serial 非 `PUB_KEY_ID_`（若 serial 连下载列表都不中会抛「配置异常」，抛「验证微信签名失败」说明 serial 命中了证书但签名对不上）。

消噪（`TopUpController::wechatNotify` → `isCallbackForSettledWechatFund`）：**验签前**先 `decrypt_wechat_resource` 解密取 `out_trade_no`，查 fund `whereIn(type,[addfunds,refunds]) + pay_method=wechat + whereIn(status,[1,2])`（与 `ensureCallbackAccounted` 口径一致），已终态直接应答微信成功（`{"code":"SUCCESS"}`）让其停止重试。安全边界不变：解密依赖 APIv3 密钥的 AEAD 认证加密（无密钥伪造不出合法密文）、命中分支零状态变更、解密失败/查无终态单一律回落完整验签（预检异常 `Log::info` 留痕，区分「消噪失效」与「新故障」）；处理中订单不受影响仍走全量验签。命中时交叉校验报文 `amount.total`/`transaction_id` 与本地 fund（`reportSettledCallbackMismatch`），矛盾记后台错误日志（`ApiExceptions::logException`，ACK 行为不变）——补齐旧路径 `ensureCallbackAccounted` 金额交叉校验在此分支的可观测性。

### 配置与运维流程

- 配置项：`system_setting` 的 `wechat.publicKeyId` + `wechat.publicKey`（base64），`PaymentConfigTrait` 注入 `wechat_public_cert_path[publicKeyId]=公钥文件`
- 切换流程：① 后台发起灰度 → ② 等回调进度 100%（约 7 天）→ ③ 部署带公钥头改动 → ④ 应答进度上升（近 7 天窗口，需几天到 100%）→ ⑤ 后台「确认切换」、停用平台证书
- 保存 `wechat`/`alipay` 设置时 `Setting::clearGroupCache` 自动同步清 `pay_config_*` 应用缓存（避免缓存里旧公钥/证书与 live 设置不一致——公钥轮换后"发新 serial 头但本地仍注册旧公钥"致回调验签失败），无需手动干预；如需手动清，用 `optimize:clear`/`cache:clear`（**非** `config:clear`——后者只清 `bootstrap/cache/config.php` 编译配置，不碰 `cache()` 落的 `pay_config_*` 应用缓存）

## PurgeCommand 自动取消（临近退款期处理中订单）

`schedule:purge` 每天 02:00 执行，扫描 `created_at` 在 `refund_period - 2 ~ refund_period` 天之间的处理中订单，调 `Order\Action::cancel` 取消并退款。

### 限定条件

- `cert.status = 'processing'`
- `cert.action IN ('new', 'renew')`（**重签订单 reissue 不取消**，避免连带把原订单 latestCert 改 cancelled）
- `products.refund_period >= 5`（退款期 < 5 天的产品跳过）

### 实现位置

`backend/app/Console/Commands/PurgeCommand.php` 两段 query 的 `whereHas('latestCert', ...)` 闭包同时加 `whereIn('action', ['new','renew'])`：

- L121-133：预同步 query（refund_period - 4 ~ refund_period - 2 天的订单创建 sync 预热任务）
- L147-154：取消 query（refund_period - 2 ~ refund_period 天的订单走 sync + cancel）

---

## 同步取消退款开关（site.autoRefundOnSync）

多级代理场景下，上级 Manager 可能先取消订单（如其自身的 PurgeCommand 触发）；下级 Manager 的 `Order\Action::sync` 同步上游状态时，默认仅更新本地 `cert.status='cancelled'`，**不退款**。是否退款给末端用户由各级 Manager 管理员自决。

### 开关

- 分组：`site`，key：`autoRefundOnSync`，type：`boolean`，默认 `false`
- 后端读取：`get_system_setting('site', 'autoRefundOnSync')`
- 前端：admin 站点设置页面自动按 SettingGroup 渲染 boolean toggle

### 触发条件（四个必须全部成立）

1. 上游返回 `data.status === 'cancelled'`
2. `cert.status ∈ {processing, approving, cancelling}`（过渡态；排除 active 已签发 / 终态）
3. `cert.action ∈ {new, renew}`（排除 reissue 重签）
4. 开关 `site.autoRefundOnSync === true`

### 资金路径

私有 helper `Order\Action::refundForSyncedCancel(Order, array $certData)`：

1. `DB::transaction` 闭包
2. `Order::with('latestCert')->whereHas('latestCert')->lock()->find($order->id)` 加 order 行锁
3. 锁内二次校验触发条件 2/3/4（data.status 已外层校验）
4. 防重检查 `Transaction::where(['type'=>'cancel','transaction_id'=>$order->id])->exists()`
5. 若不重复且 amount > 0：调 `OrderUtil::getCancelTransaction($order->toArray())` + `Transaction::create($tx)`（`Transaction::creating` 钩子内自带 user.lockForUpdate + balance 增加）
6. `$cert->update($certData + [status='cancelled', cancelled_at=now()])`
7. `$order->cancelled_at = now(); $order->save();`
8. callback task / deleteTask（复用 sync L532-538 副作用，createTask 内部已 `->afterCommit()`）

### sync 集成

`Order\Action::sync` 在 `$hasStatusChanged` 计算之后、邮件通知/callback 之前插入四条件 if：命中后调 `refundForSyncedCancel($order, $data, $suppressCallback)` 并 `$this->success()` 提前结束 sync。Helper 内已接管 cert.update / order.save / callback / deleteTask 所有副作用（callback 受 `$suppressCallback` 透传 gate，下游 pull 入口抑制，见"sync 回调抑制"小节）。

### 设计决策

- **不检查 refund_period**：以上游状态为权威，与主动 `cancel()` 路径行为不同。上游已取消意味着资金已从上游退回，本系统应该传递给末端用户，不受退款期限制。
- **cancelling 状态进入 helper**：若已有 `commitCancel` / `PurgeCommand` 创建的 cancel task 存在，helper 完成后 cert.status='cancelled'，残留 cancel task 被 TaskJob 调度时 `Action::cancel` 锁内首先检查 status === 'cancelled' 立即报错回滚，不会重复调上游 api、不会重复退款。helper 内**不主动删 cancel task**，避免 order→task 与项目惯例 task→order 锁顺序倒置引发死锁。
- **资金确定性体系契合**：事务+锁、应用层防重 + DB 唯一索引 `transactions_type_transaction_id_unique` 兜底、afterEach FundInvariants 守门（测试登记在 `tests/Support/FundAuditGuard.php::fundAuditGuardedTestPaths()`）。

### 测试覆盖

- `tests/Feature/Services/Order/SyncedCancelRefundTest.php`：12 个用例覆盖开关开/关 / status / action / 0 元订单 / 并发幂等 / 已退款防重 / revoked 不触发
- `tests/Feature/Commands/PurgeCommandTest.php`：补 2 个用例验证 reissue 不取消 / new 仍取消

### 部署注意

- 升级后需跑一次 `php artisan db:seed --class=Database\\Seeders\\SettingSeeder` 让设置项落库
- 默认 `false` 即维持现状行为，无回滚风险，可作为开关式金丝雀

---
