# 订单与资金安全

退款入口、状态、金额口径、退款期和 task 清理的总览见 [退款矩阵](refund-matrix.md)。本文件保留订单资金实现、并发与审计细节。

## 产品价格批量初始化

- **启用范围**：只处理 `products.status=1` 的启用产品；禁用产品不进入预览统计、成本告警、状态指纹或价格查询，默认模式不为其补价，强制模式也不得删除或重建其历史价格。正式执行锁住完整产品集合后再筛选启用产品，避免状态在指纹校验与写入之间切换。
- **成本事实来源**：初始化直接读取原始 `cost` JSON，并按产品当前周期和适用 SAN 类型校验必需字段；字段缺失、非普通非负十进制数或适用范围外的多余成本项都会告警并阻断整批执行。人工保存成本先经 `ProductCostNormalizer` 校验并写入完整规范化输出；产品导入不校验成本，数组成本原样保存，未提供、`null` 或非数组成本均不覆盖，避免本地旧产品配置阻断上游信息同步。禁止依赖模型访问器自动补零掩盖缺失成本。
- **零值与 SAN 边界**：纯主价产品的主价必须大于 0；仅当产品存在适用 SAN 类型时主价可明确为 0，但每个适用的标准/通配符 SAN 成本必须存在且大于 0。不适用的 SAN 售价列保存结构性 `0.00`，字段缺失绝不能按明确零值处理。
- **定点换算**：初始化只把 `price`、`alternative_standard_price`、`alternative_wildcard_price` 三个同名成本字段分别乘以级别倍率，使用 BCMath 与 `ROUND_HALF_UP` 按 0/1/2 位精度舍入，禁止浮点计算；正成本舍入为 `0.00` 或单字段超过 `999998.00` 时整批阻断。
- **预览令牌与状态指纹**：预览绝不写库；无告警时签发 10 分钟令牌，绑定管理员、规范化参数及产品原始成本、周期、适用 SAN 类型、选中级别和选中级别现有价格的稳定指纹。正式执行必须在锁和事务内重读并复算，令牌无效、过期或指纹变化时零写入返回 `stale_preview`。
- **写入范围**：默认模式只筛出缺失的“产品 + 级别 + 周期”唯一键并分批普通 `insert`，既有价格整行保留，禁止 `INSERT IGNORE`；强制模式只删除并重建本次选中级别。删除、插入和可选的级别倍率同步必须处于同一事务，并核对 `created + preserved = target` 或 `rebuilt = target`。
- **全局变更锁**：初始化、产品价格新增/修改/删除/批量删除/单产品设置，以及会员级别危险变更，共用数据库命名锁 `ssl-manager:product-price:mutation`。`GET_LOCK(..., 0)` 和 `RELEASE_LOCK(...)` 必须在同一连接严格返回 1；回调成功、业务拒绝或异常都由 `finally` 释放，释放异常须 critical 记录、断开连接并 fail-closed。
- **真实订单计价边界**：初始化不读取或解释 `standard_min/max`、`wildcard_min/max`、SAN 数量，也不改 `OrderUtil` 公式。`OrderUtil::getLatestCertAmount()` 继续从真实 `ProductPrice` 读取三类售价，按 SSL/ACME 各自的已购 SAN 来源和基础配额计算超额，重签只计算增购 SAN；对端测试必须用初始化实际落库的价格验证这些路径。

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
- **redis 黑洞不能"挂"**：`config/database.php` redis `default`+`cache` 两块均设 `timeout`+`read_timeout`（默认 5s，`REDIS_TIMEOUT`/`REDIS_READ_TIMEOUT` 可调）。键名对 phpredis（`read_timeout`→`OPT_READ_TIMEOUT`，**不是** Predis 的 `read_write_timeout`——写错会被静默忽略、fail-open 永不触发）。无此配置时 redis 网络黑洞会让读阻塞至 OS TCP 超时（分钟级），`RedisException` 永不抛 → fail-open 死等。连接级断言见 `RedisTimeoutConfigTest`（断 `OPT_READ_TIMEOUT===5.0`，禁 config-only 假绿）。

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
2. **`Task::lockForMutation` scope 强制复合索引（确定性兜底）+ `runTaskMutationTransaction` 统一重试**：锁查询下沉为 Task 模型 scope `scopeLockForMutation`（`forceIndex('tasks_order_action_status_index')` + where order_id + whereIn action + whereIn status(executing,stopped) + `select('id')` + `lockForUpdate`，常量 `Task::TASK_LOCK_INDEX`），Order/ACME 共用，调用形如 `Task::lockForMutation($orderId, ['commit','sync'])->get()`；即便某库残留孪生索引，`forceIndex` 仍确定性收窄间隙锁。重试助手抽为共享 trait `App\Traits\RunsTaskMutationTransaction`（`DB::transaction(..., 3)`，常量 `TASK_MUTATION_TRANSACTION_ATTEMPTS=3`），`Order\Action` 与 `Acme\Action` 都 use。**覆盖面（不含上游副作用的纯本地 task→order/acme 变更，全部走 attempts=3 重试）**：Order `sync` / `commitCancel(active)` / `batchCommitCancel(active)` / `revokeCancel` / `refundForSyncedCancel` / `cancelPending` + ACME `revokeCancel` / `sync` / `commitCancel` / `batchCommitCancel`。controller 直调时本事务为最外层，重试只重跑锁+本地写回，安全。**`commit` 绝不加重试**——其上游下单 `$this->api->$action()` 在事务内（`Action.php`），重试 = 重复下单/重复扣费；且 commit 只锁 order 行、不执行 `tasks FOR UPDATE`，本就不是死锁受害者。**ACME `commitCancel`/`batchCommitCancel` 已纳入覆盖面**：commitCancel 纯本地（延时任务才调上游 cancel），改为先锁 cancel_acme task 再锁 acme 行，与 sync T7 取消退款分支（先锁 cancel_acme task 间隙锁、再锁 acme 行）统一为 task→acme 锁序；其自身虽不执行 `tasks FOR UPDATE`，但 `Task::create(cancel_acme)` 的插入意向锁会撞 sync 持有的间隙锁而卷入 1213 死锁环，故必须对齐（batchCommitCancel 逐条调 commitCancel 自动继承）。
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

## 续费孤儿止血 + 卡单对账扩展（P0-1 包 O / P0-3 包 T）

延续「API 下单韧性」的 pending 卡单对账，本批把**续费/重签**路径补齐原子性（O1~O3）、给孤儿单加自动收尾（O4）、给僵尸任务与卡单加兜底（T1/T5）。到顶/产品缺失判据全部收口到共享 `PendingReconcileQuery`（禁手抄）。cert_renew_stalled 到期止血侧见 `skills/backend/auto-renew.md`。

### PendingReconcileQuery 共享判据（`Services/Order/PendingReconcileQuery.php`，单一真相源）

pending 订单「到顶(maxed-out) / 产品缺失(product-missing)」判据是 reconcile 主扫描（可动作）、转人工扫描（告警）、O4 sweep 收尾（退款）三方共用的分界，**必须共用同一批 SQL 片段常量，禁止任一处手抄 `whereRaw`**——否则「哪些单已到顶」在三处漂移，造成「reconcile 判到顶告警、sweep 判未到顶不收尾」的裂缝。

- **三方法正反同源**（同 `MAXED_COUNT_SUBQUERY` / `PRODUCT_MISSING_EXISTS` 片段）：`actionable()` = 未到顶 AND 无产品缺失（主扫描占 limit）；`maxedOrProductMissing()` = 到顶 OR 产品缺失（转人工告警，与 actionable 严格互补）；`maxedAndNotProductMissing()` = 到顶 AND 非产品缺失（O4 收尾退款，= 转人工集 ∩ 非 T8 产品缺失）。
- **锚点 = latestCert.created_at**（`JOIN certs as lc`）：只统计「当前证书周期」内失败 commit task（`last_execute_at >= lc.created_at`），二次卡单不被上一张证书旧账误判到顶。**INNER JOIN 勿改 LEFT**——NULL 锚点会让 `>= lc.created_at` 恒 NULL、到顶判据静默失效。
- **maxAttempts 参数同源硬约束**：所有调用方（含 sweep-orphan-orders）必须传 `config('reconcile.max_attempts')` 同源值，不得硬编码或读别的键——参数化本身留的漂移口子。
- **PRODUCT_NOT_FOUND_SIGNAL 常量** `'Product not found'`：上游产品下线/删除返回（亲验 gateway V2 措辞），落 `task.result['msg']`；`matchesProductMissing()` PHP 侧用 `stripos`（与 SQL `LIKE` 在 utf8mb4\_\*\_ci 大小写不敏感口径一致）。措辞变更即失明 → 该单回退正常退避 → max_attempts 到顶转人工兜底（只慢不丢）。
- **改动纪律**：动 `PendingReconcileQuery` 任一片段/常量/方法，必须同步核对全部消费方（`ReconcilePendingCommand` 主+转人工两扫描、`SweepOrphanOrdersCommand::sweepPending`）——类 docblock 已钉「包O 消费方契约」。

### 受保护不变式：reconcile 主扫描不得加 channel 过滤

`ReconcilePendingCommand` 主扫描（`actionable`）**绝不能加 `channel` 过滤**。O4 sweep-orphan-orders 限定 `channel=auto` 接手，Deploy/api 渠道的 pending 孤儿全靠本主扫描（无 channel 过滤）接住瞬态自愈；一旦加 channel 过滤 = Deploy/api 安全网静默消失。`ReconcilePendingCommandTest` 有护栏用例守此。

### O1 AutoRenew 续费/重签事务化（`AutoRenewCommand::processOrder`）

把「创建续费/重签 + `pay(commit=false)`」两步包进单个 `DB::transaction` 闭包保证原子：pay 段 charge 失败时，renew 已翻转的旧证书（active→renewed）+ 新订单/证书一并回滚，杜绝「旧证书 renewed 终态 + 新单卡 unpaid」的静默孤儿（P0-1 路径 1）。延时 commit 任务留**事务外** `createTask($id,'commit',$delay)`（= V2「commit 移出事务」等价）。

- **ApiResponseException 流控**：`renew()/reissue()` 的 `success()` 抛 `ApiResponseException`（带 `data.order_id`）是**成功信号**——吞掉取 id；业务失败（无 order_id）rethrow `\Exception` 逸出闭包触发回滚（**勿把成功路径当失败回滚**）。pay 段 `code!==1` rethrow 逸出。
- **attempts=1 是必需约束、非从简**：`renew()/reissue()` 入口 `checkDuplicate` 是 `Cache::add`（SETNX，10s TTL）且回滚不清缓存；若事务级重试（attempts>1），重入命中自己首轮残留键 → error → 必自败。故用 `DB::transaction` 默认 attempts=1，勿改大；死锁 → 回滚 → `processOrders` catch 兜底通知 → 次日自愈。
- **锁内无上游 HTTP（非「零上游 HTTP」）**：new/reissue 的 SQL 与 charge 纯本地扣费在源订单行锁内；`initParams` 的 CSR keygen（openssl fork）与**委托 TXT 上游写（`ProxyDNS`，确属上游 HTTP）**都落在**首个源订单行锁之前**（故不违反「锁内不做上游 HTTP」红线，而非闭包内没有上游 HTTP）；含上游的 commit 走事务外（V1/V2 延时任务、Deploy 同步于 `DB::commit()` 后，见 O3-B）。

### 续费/重签锁下沉（防并发双开）

O1 把 new(renew)/reissue 建单 + 扣费包进单事务后，同一源订单的**毫秒级并发双开**（两个 renew、或 renew×reissue 同时读到源证书 `active`）仍会各自建接替单、重复扣费。锁下沉在**同一事务闭包内**加行锁 + 前驱翻转 CAS 守卫串行化：

- **锁源订单行**：`new`（`action=renew`）与 `reissue` 事务闭包首步 `Order::whereHas('latestCert')->lock()->find($order_id)` 锁源订单行；plain new（无源订单）不锁、行为不变。同一源订单的并发变更就此在行锁上串行。
- **前驱翻转 CAS + affected-rows 三态守卫**：翻前驱证书状态用带 `WHERE` 条件的 UPDATE 取影响行数——renew 走 `WHERE status='active' → renewed`、reissue 走 `WHERE id=前驱 AND status IN('active','expired') → reissued`。**命中 0 行 = 前驱已被并发续费/重签/取消抢先翻走**，重读前驱权威状态分三态 `error` 回滚：`renewed`→「订单已续费」、`reissued`→「订单已重签」、`cancelled`→「订单已取消」、其余→「订单状态已变化，请刷新重试」。守卫落在 pay 之前，回滚时扣费从未发生。**禁盲 UPDATE**（无 `WHERE` 状态条件翻状态会让第二个并发者也翻成功、双开成单）。
- **reissue 锁内 re-read 基线比对**：reissue 额外在锁内重读 `order.latest_cert_id`，与锁前 `initParams` 捕获的基线 `params['last_cert_id']` 比对，不等即「订单已重签」回滚——挡「stale 比 stale」（两并发者锁前读到同一旧 `latest_cert_id`，先提交者已推进接替，后者翻转 CAS 若恰好仍能命中需此关兜底）。
- **`certs.last_cert_id` UNIQUE 物理底线**：即便应用层守卫被极窄竞态绕过，reissue 第二个 `INSERT`（`last_cert_id` 撞已占槽位）也会触 `1062` 回滚——双开的确定性兜底。
- **有意不套 `order_mutate` 互斥**：与 commit/cancel 不同，本路径**事务内零上游调用**（上游 commit 由事务外延时任务承载）、且与 commit/cancel **状态互斥**（renew/reissue 要求源证书 active/expired，commit 要求 pending、cancel 要求 cancelling，同订单不可能同时满足）——源订单行锁 + 前驱翻转守卫已足，无需再叠 Cache 互斥（落点表「一条龙 ❌ withMutex」的安全性正由此保证）。锁序 Order → Cert → User 正序，契合全局 Cache < Task < Order < Cert < User 无环。
- **内层事务 `DB::transaction(closure, 1)`（attempts=1）**：本闭包经 AutoRenew O1 / V1V2 一条龙外层事务嵌套为 savepoint，**嵌套死锁不可事务级重试**（同 `commitLocked` 先例——嵌套层 Laravel 直接抛 `DeadlockException` 到最外层、不在内层重试）；且入口 `checkDuplicate`（SETNX，回滚不清键）遇事务级重试必命中自身残留键自败（见 O1）。死锁 → 回滚 → 外层 catch 兜底通知 → 次日自愈。
- **`checkDuplicate` 保留作同参挡板**：锁下沉解决并发不同请求的双开，`checkDuplicate`（入口 `Cache::add` SETNX 10s）继续挡「同参数重复提交」，二者正交、都保留。

### O2 / O3-D 余额预检实时化

AutoRenew 续费预检前 `$user->refresh()`（O2）：`getRenewOrders` 一次性 `with('user')` 预载，同 user 多订单共享同一 User 实例、balance 停在查询时刻值；前序单 charge 改的是 DB 另取的行，内存实例不更新 → 不 refresh 则后续同用户单读旧值误放行（07-07 断言 1）。Deploy update（O3-D）`$order->user` 懒加载现取现读，单请求内 balance 新鲜、无需 refresh；口径与 AutoRenew 预检逐字一致（`getLatestCertAmount` + `balance + |credit_limit|` 比对，仅 renew 检查）。

### O3 Deploy update 移植 V2 一条龙范式（`Deploy/ApiController::update`）

active 续费分支**完整移植** V2 范式（非「只包事务」）：

- **O3-A**：`pay(false)` 进 `withMutex(order_mutate_$id)` 事务，与 renew/reissue 同事务原子（charge 纯本地扣费、无上游、无嵌套 mutex → 安全嵌套），charge 失败整体回滚，杜绝「旧证书终态 + 新单卡 unpaid」孤儿（P0-1 路径 2）。
- **O3-A′（锁纪律收口）**：`withMutex` 事务内**不再对订单行显式预锁**（原 `Order::...->whereHas('user')->lock()->find()` + active 守卫已移除）。renew/reissue 的 `initParams`（CSR keygen + 委托 TXT 上游 DNS 写 `ProxyDNS`，逐 token 15s）在其**内部源订单行锁之前**执行；防并发双开的串行主体是 renew(`persistOrder`)/reissue 内部「源订单行锁 + 前驱翻转 affected-rows CAS」（**CAS 是锁定写 current read，不受 initParams 前置一致读建立的 RR view 影响**，与 V1/V2「不套 mutex 靠内部 CAS」同源，无需外层再叠预锁）。旧预锁把 keygen + 委托 DNS HTTP 全罩进订单行锁内——DNSPod 劣化时 ≥4 token 即超 `innodb_lock_wait_timeout=50` → 同订单 sync/renew 抢锁 1205，违反「锁内不做上游 HTTP」红线。移除后 Deploy 与 V2「CSR/委托生成先于行锁」范式一致（并发前驱翻 renewed 由内部 CAS 挡下、报「订单已续费」`code=0`，不双开）。
- **O3-B**：`commit` 移到互斥锁**外**（commit 自取同键 mutex，锁内二次抢必自死锁；且 commit 含上游 HTTP，锁内不做上游调用红线）。
- **O3-C**：`getData('commit')` 段 SDK `code=0` 超时/失败**不冒泡**（`return []`）——订单停 pending、已扣费保留，返 200+pending 展示态（下游轮询容忍，见 deploy.yaml），权威自愈 = ReconcilePendingCommand 主扫描（无 channel 过滤）+ 下游 pull `get` 条件式加速。
- **M-2 知情不对称**：unpaid resume 分支走 `pay(autoCommit=true)`，其 `MutationBusyException` 经 `method='pay'≠'commit'` 仍上抛 503——与 active 分支 commit 段吞不对称；既有行为、有意不改（O3 范围仅 active 分支 + getData commit 段），下游重试即收敛。

### O4 schedule:sweep-orphan-orders 孤儿清理

`SweepOrphanOrdersCommand`（每小时）清理 `channel=auto` 卡死的孤儿续费/重签单：

- **unpaid 分支**（`RECONCILE_ORPHAN_UNPAID_ENABLED` 默认 **true**）：stale > `orphan.unpaid_stale_minutes`（默认 60）→ `Action::delete`（恢复旧证书 active、删新单，无退款无流水；锁内只放行 unpaid，并发变更报错跳过无害）。
- **pending 分支无开关**：消费 `PendingReconcileQuery::maxedAndNotProductMissing`（到顶 ∩ 非产品缺失）+ 镜像 reconcile 排除 executing commit task。此时订单已扣费、`api_id=null`、无执行中 commit，确认未提交上游，必须调用 `Action::cancelPending` 退款并恢复旧证书；锁内二次校验 status=pending，late-commit 推 processing 即早退不误退款。
- **hourly 时序**：保证每 5min 的 T5 转人工先于 pending 收尾接手，防两自动化拆台；每日快照继续作为事后核对。
- 每单独立事务 + 一单失败不断整批。

### T1 schedule:sweep-stale-tasks 僵尸任务重派（`SweepStaleTasksCommand`）

僵尸 executing 任务（dispatch 后 worker 未消费、永久卡 executing 且从未执行）会 ① 压制 reconcile（`whereNotExists(executing)` 整单排除，卡单永不重发）② 静默失效（取消类任务永不退款）。本命令是二者权威兜底（每 5min）。

- **扫描**：`status=executing AND started_at < now-stale_minutes`（默认 30）且 `last_execute_at 空或滞后`（未来延时任务 started_at 在未来对 threshold 恒 false → 天然排除）。
- **收敛**：每 task 独立事务 + 单行锁（不碰 order/acme，无跨行锁序）+ CAS 复检（TaskJob 已接管则落空跳过）；观察期 `min_interval_minutes`（默认 30）内不猛派；`TaskJob::dispatch(...)->afterCommit()->onQueue(tasks)` 重派（幂等：handle 守卫 status=executing AND started_at<=now）。
- **零迁移**：result JSON 承载 `swept_count`/`last_swept_at`。超 `max_redispatch`（默认 3）→ 置 **stopped**（非 executing 解压 reconcile、非 failed 不污染 C4 max_attempts 计数）+ swept_count 清零（admin batchStart 拉起获全新预算）+ SystemAlert `task_stale` 转人工。sweeper 不扫 stopped，无自拉起循环。
- **T3 配套**：`Admin/TaskController::batchStart` 恢复 cancel/cancel_acme 任务必须补 `->delay($task->started_at + 3s)`——否则 job 立即消费、handle 守卫 `started_at<=now` 落空 no-op → task 永久 executing（用户取消意图静默失效）。delay 从 `$task->started_at` 取真值（跟随 120s 恢复延时），消除与硬编码双点漂移。

### T5 reconcile 队头前移 + 每日快照转人工（`ReconcilePendingCommand`，与 T8 同一原子改动）

- **队头前移**：到顶/产品缺失单经 `actionable()` 在主扫描 SQL 内排除，**不占 limit 名额**——否则批量产品下线/持续失败挤满窗口、饿死真正可动作卡单（队头阻塞）。
- **两段式原子**：前移后主扫描 (a) 内不再触发到顶告警，必须由 handle 末尾转人工扫描 (b) `alertMaxedOrders` 承载（同一 handle 原子落地，否则「前移已落、告警丢失」）。(b) 消费 `maxedOrProductMissing`、不占 limit（候选=卡单终局态总量、有界）。
- **user 逐单 + admin 每日快照**：user 复刻 C2（channel=auto+email gate、单周期一封、`task.result.reconcile_user_alerted_at` 去重、dispatch 成功后落键），文案中性化——产品缺失走「请重新选购」可行动文案、其余走「正在处理；长时间未完成将自动取消并退款」不承诺重试（因 O4 会自动取消退款）；admin 每日快照 SystemAlert `reconcile_maxed` **fingerprint 含当天日期**（吸收 M5，见 `skills/backend/notification.md`）。

**测试**：`AutoRenewAtomicityTest`（O1 回滚）、`Deploy/UpdateAtomicityTest`（O3）、`SweepOrphanOrdersCommandTest`（O4 双开关 + GUARD PROBE 反向变异）、`SweepStaleTasksCommandTest`（T1 DELETE jobs 注入范式）、`ReconcilePendingCommandTest`（T5 前移 + 护栏 channel 不变式）、`Admin/TaskControllerTest`（T3 delay）。守门三独立文件登记 `tests/Support/FundAuditGuard.php`。

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

`schedule:purge` 每天 02:00 执行，扫描 `created_at` 在 `refund_period - 2 ~ refund_period` 天之间的处理中订单，经 `commitCancel` 置 cancelling + 建 cancel task，由后续 cancel TaskJob 调 `Order\Action::cancel` 取消并退款。

### 限定条件

- `cert.status = 'processing'`
- `cert.action IN ('new', 'renew', 'reissue')`（**B3/审计 P1-12**：reissue 原被排除致退款期到期卡 processing、无兜底取消；现纳入，退款由 `cancelLocked` reissue 分支处理，processing+ 已提交上游不再恢复前驱、置 cancelled 终结，见下节）
- `products.refund_period >= 5`（退款期 < 5 天的产品跳过）

### 实现位置

`backend/app/Console/Commands/PurgeCommand.php` 两段 query 的 `whereHas('latestCert', ...)` 闭包同时加 `whereIn('action', ['new','renew','reissue'])`：

- 预同步 query（refund_period - 4 ~ refund_period - 2 天的订单创建 sync 预热任务）
- 取消 query（refund_period - 2 ~ refund_period 天的订单走 sync + cancel）

### 取消路径锁序对齐（P3 包R）

取消分支已由「cert 置 cancelling → `deleteTask` → `createTask('cancel')`」三步裸调（order→task 反序、无事务无锁）**改为 `commitCancel($order->id)`**——锁序合规（`Task::lockForMutation` 先锁 sync/revalidate task 再锁 order，task→order）+ 锁内二次校验 status/refund_period。锁外仍保留 `syncImmediately`（含上游 HTTP 不进锁）+ `refresh` + `status==='processing'` 预检，锁内复检双保险。

- **必须捕获 `ApiResponseException` 判 code**：`commitCancel` 成功末尾 `success()` 抛 `code=1`（DB 副作用已在 `runTaskMutationTransaction` 提交后才抛）。**绝不裸调**——裸调会落进外层 `catch (Throwable)` 把成功当失败打印、`canceledCount` 恒 0（假绿：DB 效果正确但取消健康度不可观测）。`code===1` 计成功、`code===0` 走 info 跳过不中断整批。回归用例断计数 + `doesntExpectOutputToContain('Failed to process')`（PurgeCommandTest）。
- **知情差异（无害）**：`commitCancel` 删 `sync,revalidate`（不含 `commit`），残留 commit task 被 worker 拾取时 `commitLocked` 锁内首查 `status!='pending'` 即业务失败——不调上游、不重复下单/扣费，仅一条无害 failed commit task 噪音（随 90d 终态清理消除）。延时零变化：两路径都 `createTask($id,'cancel')` 无第三参，统一走 `ActionTrait` 内部 `max(120,…)`。

### 三表保留期清理（P3 包R，终态行不冲突）

`schedule:purge` 在自动取消前先清 `tasks`/`notifications` 超保留期的**终态历史行**（`config/purge.php`：`retention.tasks`/`notifications` 默认 90d、`chunk` 默认 1000）：

- **清理集与业务锁定集不相交**：tasks 清 `{successful,failed}`、notifications 清 `{sent,failed}`；`Task::scopeLockForMutation`/`deleteTask` 的锁定/删除集恒为 `{executing,stopped}` → 清理 DELETE 不与任何持 task 锁的业务路径争同一行、**无锁序义务、不触发 Z12**（行级不相交；InnoDB gap 级与 `createTask` insert-intent 存在 enum 边界窄死锁角，由 commitCancel 事务级重试自愈、purge 不触资金表，接受观察）。`failed` 可被 admin `batchStart` 复活（`failed→executing`），但 DELETE 与该 UPDATE 由 InnoDB 行锁串行、90d 窗口远大于人工重试窗口，近乎不可达。锚点用 `created_at`：终态化极晚于创建的行（长期重试后 failed）可能刚终态即超期被清——其排障价值已由过程中的告警/日志承接、admin 即时反馈不依赖历史行，时间窗只是次要护栏。
- **排除 pending 卡单的终态 task（防到顶计数复活）**：`purgeTerminalTasks` 追加 `whereDoesntHave('order', whereHas('latestCert', status=pending))`——关联订单仍是 pending 卡单（未收尾）的 failed commit task 是对账「到顶」判据（`PendingReconcileQuery::MAXED_COUNT_SUBQUERY`，锚 `latestCert.created_at`、**下界无上界**）的计数集。若按 90d 清掉 → 计数归零 → 订单重回 `actionable` → reconcile 重打上游 ≤3 次 + 重发 `auto_renew_failed`（去重键 `reconcile_user_alerted_at` 存 task.result JSON、随行删丢失）→ auto 渠道用户每约 90d 重收一封。订单**收尾**（cancelled/active/renewed 等非 pending）后其历史 task 才进入清理、不永久堆积；孤儿 task（order 不存在）`whereDoesntHave` 返真、照常清理。`config/purge.php` 的「90d ≫ 对账依赖窗口」仅对**已收尾**订单成立。
- **清理抛错不中止主流程**：两清理调用外包 try/catch（与 `_logs` 动态清理对称）——清理是次要职责，异常仅 warn，不得跳过后续退款期自动取消。
- **binlog 前提**：单批 `DELETE ... LIMIT` 无 `ORDER BY`，语句级复制不确定,依赖 `binlog_format=ROW`（MySQL 5.7.7+/8.x 默认；自建主从改过 STATEMENT 的部署需确认）。
- **分批范式**：复用 `UserDataPurger::deleteInChunks` 范式（do-while + 每批独立 `DB::transaction` + `gc_collect_cycles` + maxIterations 护栏），单批 `LIMIT chunk` 避免长事务锁等待/撑爆 binlog。
- **索引路径（EXPLAIN 实测，8.4 造 10 万行 95% 终态）**：`status IN(...) AND created_at<cutoff LIMIT 1000` 走 `status` 索引 `type=range`（**非全表扫**）+ LIMIT 收敛每批锁定上界；**不加 `ORDER BY id`**——会在 DELETE 路径引入 filesort（`type=range; Using filesort`）反而更差。零迁移不加 `created_at`/复合索引，量级证明需要时列后续批次观察项。
- failed_jobs 不在此：走 Laravel 原生 `queue:prune-failed`（14d，M 包 E5 已落地）。

### cancelLocked reissue 分支（订单终结与全额退款）

`Action::cancelLocked` 是 Purge、手动 `commitCancel`、`batchCommitCancel` 经 cancel task 汇入的唯一原语。reissue 是否恢复前驱以是否已提交上游为边界：`cancelPending` 恢复前驱并只退当次增量；`cancelLocked` 处理 processing/approving/active 等已提交上游状态，终结整个订单并按订单口径退款。

1. **退款口径**：已提交上游的 new/renew/reissue 统一调用 `OrderUtil::getCancelTransaction()`。以 `order.amount` 为基准，汇总该订单全部 `order/cancel` 流水净额核对；不一致时记录错误并以流水净额为准。cancel 流水 counts 使用订单累计 `purchased_*`，不再按 reissue 最后一笔增量递减订单累计值。只有 `cancelPending` 的 reissue 继续通过 `preparePendingReissueRefund` + `applyPendingReissueIncrementRefund` 只退当次增量，因为该路径会回切 `latest_cert_id`、恢复前驱并删除当前 cert。
2. **订单终结（不按 `issued_at` 分恢复）**：`cancelLocked` 处理的 reissue 恒已提交上游（processing/approving，含已签发 active）——上游各家取消政策不一，恢复前驱 `active` 存在「被上游 supersede 后本地状态与实际不符」风险（旧证书可能已被上游作废、cloud-deploy 已推送 reissue cert），故**不区分是否签发、一律**置 `cert.status='cancelled'` + 全额退款 + `order.cancelled_at`，前驱**不恢复/不回切/不删**（保持 `reissued` 终态）。
   - **`last_cert_id` 保留占槽 inert**：不置 null——订单经 `latestCert=cancelled` 终结后（重签/续费/取消三门前置校验齐闭、无任何路径能再指向前驱），该 UNIQUE 槽位对前驱惰性无害，保留以维持「cancelled 接替 → reissued 前驱」取证链。
   - **恢复窗口仅剩 unpaid / pending**：`delete`（unpaid，恒未签发）手写恢复分支 + `cancelPending`（pending，恒未签发、经 `restoreReissuedCert`）恢复旧证书 `active`——二者都恒在上游签发前，恢复安全；cancelLocked（processing+，已提交上游）不属恢复窗口。

**F1 fail-safe（前置于 `api->cancel`）**：reissue 主动取消在调用上游前先检查同订单是否已有 cancel 流水，命中即转人工；否则可能在上游取消成功后才撞唯一索引并回滚本地状态。`cancelPending` 的 `preparePendingReissueRefund` 同样先做该预检，再校验最后一笔增量交易金额。唯一索引 `(type,transaction_id) WHERE type!='order'` 仍是防双退物理底线。

---

## 同步取消退款开关（site.autoRefundOnSync）

多级代理场景下，上级 Manager 可能先取消订单（如其自身的 PurgeCommand 触发）；下级 Manager 的 `Order\Action::sync` 同步上游状态时，默认仅更新本地 `cert.status='cancelled'`，**不退款**。是否退款给末端用户由各级 Manager 管理员自决。

### 开关

- 可选设置，不进入 Seeder；缺失时默认 `false`
- 如需开启，管理员手工新增：分组 `site`，key `autoRefundOnSync`，type `boolean`，value `true`；新增后会在普通系统设置页面显示并可编辑
- 后端仅在 `get_system_setting('site', 'autoRefundOnSync') === true` 时开启

### 触发条件（四个必须全部成立）

1. 上游返回 `data.status === 'cancelled'`
2. `cert.status ∈ {processing, approving, cancelling}`（过渡态；排除 active 已签发 / 终态）
3. `cert.action ∈ {new, renew, reissue}`（三者均按订单口径退款）
4. 开关 `site.autoRefundOnSync === true`

### 资金路径

私有 helper `Order\Action::refundForSyncedCancel(Order, array $certData)`：

1. `DB::transaction` 闭包
2. `Order::with('latestCert')->whereHas('latestCert')->lock()->find($order->id)` 加 order 行锁
3. 锁内二次校验触发条件 2/3/4（data.status 已外层校验）
4. new/renew/reissue 统一做防重 `exists` 预检，再调用 `OrderUtil::getCancelTransaction` 按订单金额与全部交易净额核对，最后 `Transaction::create`（`Transaction::creating` 钩子内自带 user.lockForUpdate + balance 增加）
5. `$cert->update($certData + [status='cancelled', cancelled_at=now()])`
6. `$order->cancelled_at = now(); $order->save();`
7. 有前驱（`last_cert_id`）时 `dispatchRenewCancelledNotification` 派发 `cert_renew_cancelled`（前驱脱监控止血）
8. callback task / deleteTask（createTask 内部已 `->afterCommit()`）

### sync 集成（退款分支 + 通用写回通知收口）

- **退款分支**：`Order\Action::sync` 在 `$hasStatusChanged` 计算之后插入四条件 if：命中后调 `refundForSyncedCancel($order, $data, $suppressCallback)` 并 `$force || $this->success()` 提前结束 sync（force 模式不抛 success，避免打断 get）。
- **通用写回通知收口**：**未命中退款四条件**的 cancelled 落终态路径（`autoRefundOnSync=false` 的 new/renew/reissue、active→cancelled 等）走 sync 锁内**通用写回**——补一条 `hasStatusChanged && data.status==='cancelled' && $cert->last_cert_id → dispatchRenewCancelledNotification`。与退款分支**互斥**（退款分支命中即 early-return、不走通用写回），防重由 `hasStatusChanged` 保证（二次 sync 终态守卫 unset status → 不再派发）。**无退款是开关语义（可接受），无通知才是洞**——本收口只补通知、不改退款开关语义。revoked 落终态同样让前驱脱监控，但复用 `cert_renew_cancelled`（"取消"文案）语义不贴切，宜另立 `cert_revoked` code（未实现，属 notification 域后续）。

### 设计决策

- **processing/approving/cancelling 同步退款不检查 refund_period**：以上游已取消为权威，与主动 `cancel()` 路径不同；上游已退回的资金继续传递给末端用户。
- **active 同步终态不自动退款**：不同 CA 对同一类终态可能返回 `cancelled` 或 `revoked`，无法可靠区分可退款取消与吊销；active 极少出现此情形，统一只写终态，异常由人工处理。
- **cancelling 状态进入 helper**：先按 task→order 锁序锁定 `cancel/commit/sync/revalidate` task，再锁 order；退款、置 cancelled 与删除这四类 task 在同一事务完成，不遗留延时 cancel task。
- **资金确定性体系契合**：事务+锁、应用层防重 + DB 唯一索引 `transactions_type_transaction_id_unique` 兜底、afterEach FundInvariants 守门（测试登记在 `tests/Support/FundAuditGuard.php::fundAuditGuardedTestPaths()`）。

### 测试覆盖

- `tests/Feature/Services/Order/SyncedCancelRefundTest.php`：20 个用例覆盖开关开/关 / status / action（含 **reissue 订单全额退款 + 通知**）/ 0 元订单 / 并发幂等 / 已退款防重 / revoked 不触发 / **通用写回通知收口**（开关关 renew·reissue 有前驱发通知不退款 + 防重）
- `tests/Feature/Commands/PurgeCommandTest.php`：编排断言 reissue 取消（置 cancelling + cancel task）/ new 仍取消（不跑资金）
- `tests/Unit/Services/Order/ActionTest.php`：cancelLocked reissue 分支资金断言（订单/证书/交易总额 120 时退 120 / reissue 增量为 0 仍退订单全额 / 订单终结门 / last_cert_id 保留 / F1 二次预检 / 上游失败回滚）以及 cancelPending reissue 增量退款恢复路径，落 `fundAuditGuardedTestPaths()` 守门

### TaskJob 取消告警幂等判据（②，与 sync 收口互补）

cancel/cancel_acme task 业务失败（code=0）时，`TaskJob::cancellationTargetTerminal` 判「退款已发生的幂等 no-op」vs「真·CA 失败」决定是否发 `task_failed` admin 告警。order 侧判据收窄（同根因「终态⇒退款已发生」假设为假）：

- **豁免集剔除 `failed`**：failed 全系统无任何退款路径（commitCancel/V2 cancel 均拒 failed），「failed 但退款已发生」不存在合法形态；force sync 可把上游 failed 写过 cancelling → cancel task 撞「订单状态不是取消中」读到 failed = 真失败必须告警。**与 sync 终态守卫的差异**：那里 failed 属【防复活】集（防上游旧 active 覆盖终态），语义不同于此处【退款幂等】判定，两集合不可混用。
- **cancelled 辅以流水判定**：不无条件豁免（sync 开关关可直写 cancelled 而未退款）——有 cancel 流水（已退款）或 `order.amount=0` 才豁免；订单应退金额>0 却无 cancel 流水 = 退款未发生的真失败、告警。已提交上游的 reissue 即使 `cert.amount=0` 也不能豁免，因为该路径应退整个订单。
- revoked/renewed/reissued（吊销/被接替）保留豁免；acme 侧（cancelled/revoked/expired，无 failed）不在收窄范围、保持原样。
- 判据必须在 `handle()` 事务内、持 task+order 行锁时调用（Transaction 查询同事务快照）。测试 `tests/Unit/Jobs/TaskJobTest.php` Imp-1 节（failed 告警 / cancelled 未退款告警 / 0 元订单豁免 / reissue 零增量但订单非零仍告警）。

### 部署注意

- 升级后需跑一次 `php artisan db:seed --class=Database\\Seeders\\SettingSeeder` 让设置项落库
- 默认 `false` 即维持现状行为，无回滚风险，可作为开关式金丝雀

---
