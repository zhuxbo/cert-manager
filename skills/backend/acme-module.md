---
description: ACME 模块 - 封装下单 + 交付 EAB 模式、订阅计费、取消流程。修改 ACME 相关代码时自动加载。
---

# ACME 模块

Manager 作为 ACME 订阅管理平台，通过 REST API 连接 上游系统，向用户交付 EAB 凭据（eab_kid + eab_hmac）。

## 架构

```
用户/Deploy API → Manager (ACME 订阅) → 上游系统 REST API → Certum
```

简化为"封装下单 + 交付 EAB"模式，不再实现 RFC 8555 协议服务端。

## 数据模型

- 单一 `Acme` 模型（`App\Models\Acme`，表 `acmes`），替代旧的 `acme_orders`/`acme_certs`/`acme_authorizations` 多表
- `eab_hmac` 加密存储（`encrypted` cast），默认 hidden
- `eab_kid` 建索引，支持前缀匹配搜索
- `contact_email`（`VARCHAR(254) NULL`）：ACME 账号邮箱（RFC 8555 `contact`）。下单表单必填，user 端默认填当前用户绑定邮箱、admin 端选中用户后自动回填该用户邮箱；用户可改为任意 email。`commit` 时作为 `customer` 传给上游，且以上游回写为权威值
- `plus`：赠送时间开关（0/1），UI 默认 1；仅在产品 brand ∈ `certum/positive/sectigo/ssltrus` 时展示
- `products.product_type = 'acme'`（`Product::TYPE_ACME`）标识 ACME 产品
- Transaction 类型：`acme_order`（下单扣费）/ `acme_cancel`（取消退费）。**一对一防重**：禁止重复 `transaction_id`，由 `Transaction.php` creating 钩子 + DB 部分唯一索引 `transactions(type, transaction_id) WHERE type != 'order'` 双层兜底；只有传统 `order` 因证书重签增域名场景允许重复
- **字段映射**：上游 `/acme/new` 响应 `data.order_id` → 本地 `acmes.api_id` 列（**务必以 `order_id` 键读取**；误用 `api_id` 键会静默写入 null，导致后续 sync/get/cancel 全部失败）
- **ACME 产品不使用的字段**：`encryption_alg`/`signature_digest_alg`/`renew`/`reuse_csr`。EAB 由 ACME 客户端自行签发，这些属性无意义。管理端表单对 ACME 隐藏，后端 `setAcmeDefaults` 强制置空数组/0
- **产品形态限制**：当前仅保留"单域名"（`standard_max=1, wildcard_max=0`）与"单通配符"（`standard_max=0, wildcard_max=1`）两种；多域名 mixed 产品暂不提供
- **额度字段自动推断**：下单接口不再接收 `purchased_standard_count/wildcard_count`，`Action::createOrder` 按产品的 `standard_max/wildcard_max` 推断。前端下单表单不显示这两个字段

## 状态流转

```
unpaid ──[pay]──→ pending ──[commit]──→ active ──[到期]──→ expired
   │                │                      │
   │                │                      └──[commitCancel]──→ cancelling ──[cancel 成功]──→ cancelled + 退费
   │                │                                              │
   │                │                                              ├──[上游返回 revoked]──→ revoked + 退费
   │                │                                              └──[上游失败]──→ 保持 cancelling（Job 重试）
   │                │
   │                └──[commitCancel, 无 api_id]──→ cancelled + 直接退费
   │
   └──[前端删除]──→ 删除记录（未支付无需退费）
```

## 计费流程（Action）

三步流程：

1. **`new`**（创建 unpaid 订单）：计算金额，不扣费
2. **`pay`**（支付 → pending）：扣费 + 创建 `acme_order` 交易记录
3. **`commit`**（提交 上游系统 → active）：调 `Api->new()`，成功后写入 `api_id`/`eab_kid`/`eab_hmac`/`period_from`/`period_till`，状态 → active。**失败保持 pending，不退费**（用户可重试或取消）

**`commitOrder` 对上游发送的参数**（与上游系统 `/acme/new` 接口对齐）：

- `source`（Manager 用于路由到对应 source 类，上游会忽略）
- `contact_email`（= `acmes.contact_email`，**所有下单入口都必填**：User/Admin 表单 / API Token / Deploy Token。上游正常返回会覆盖回写，缺失则保持本地值。**HTTP 字段名全链路统一为 `contact_email`**，Certum REST 契约层的 `customer` 只存在 Gateway → Certum SDK 这一跳内部）
- `product_code`（Manager Product.code，上游用该 code 查自身 Product 并映射到 CA 产品代码）
- `plus`（赠送时间 0/1，由本地订单 `acmes.plus` 读出）
- `refer_id`（Manager 端生成的 32 位幂等键，上游按此幂等返回同一订单）
- **不再传** `product_api_id`、`period`、`purchased_*count`、`product_type` 等（上游不关心）

**`commitOrder` 处理上游响应**（字段映射极易踩坑）：

- 上游返回 `data.order_id` / `eab_kid` / `eab_hmac` / `vendor_id` / `directory_url`
- 本地写入：`api_id = data.order_id`（**不是 `data.api_id`**）、`eab_kid`、`eab_hmac`、`vendor_id`、`period_from/till`、`status=active`
- 顺带用 `directory_url` 刷新 Cache `acme_directory_url:{ca}`

## 取消流程

1. **`commitCancel`**（Web 入口，延时流程，保留撤回窗口）：
   - 无 `api_id` 的 pending 订单 → 直接退费 + 标记 cancelled（此时已真实取消，设置 `cancelled_at`）
   - 有 `api_id` 的订单 → 仅标记 cancelling + 创建 Task（action=`cancel_acme`，延迟 120s）+ dispatch `TaskJob`（延迟 123s）——**不写 `cancelled_at`**，实际取消由 `cancel()` 完成
2. **`cancelNow`**（下游 API 入口 `/api/v2/acme/cancel`，立即取消）：
   - 不创建 Task、不 dispatch Job；悲观锁后标记 cancelling（不写 `cancelled_at`）并同步调 `cancel()` 完成上游通信与退费
   - 未提交上游的 pending 订单仍走直接退费分支（同 commitCancel）
   - 上游失败时订单保持 cancelling（不退费）——与延时流程一致，等待人工或重试
3. **`revokeCancel`**（撤回取消）：
   - 仅在 acme.status=cancelling 时允许；悲观锁 acme 行 + 删除 executing/stopped 的 `cancel_acme` Task（已 dispatch 的 TaskJob 唤醒后因任务被删会直接跳过）+ 状态回 active
   - 并发安全：若 TaskJob 已抢先获得任务锁并开始执行 `cancel()`，本调用的 DELETE 会等其提交后匹配不到行，随后 acme 状态已非 cancelling，撤回失败
4. **`cancel`**（由 TaskJob / cancelNow 调用）：
   - 调 `Api->cancel()` → 上游返回 revoked → 状态 revoked + 退费 + 写入 `cancelled_at`
   - 调 `Api->cancel()` → 上游返回其他成功 → 状态 cancelled + 退费 + 写入 `cancelled_at`
   - 上游失败 → 保持 cancelling，不退费（等待下次重试）
5. **`sync`** 检测到上游已 cancelled/revoked 且本地 `cancelled_at` 为空时，用当前时间补记（兜底：上游已取消但 Manager 未经 cancel() 流程的场景）

> **`cancelled_at` 语义**：记录"订单已正式取消"的时间；仅在状态真正变为 cancelled/revoked 时写入，cancelling 阶段保持 null。

## 卡单对账与取消退款闭合（P0-3 T6/T7）

### T6 `schedule:reconcile-acme`（`ReconcileAcmeCommand`，镜像 Order reconcile）

重发卡在 `pending` 且无 `api_id` 的 ACME 订单 commit（每 5 分钟，freeze 期 skip）。

- **卡单成因**：`newAndCommit` / `pay+commit` 在扣费落 pending 后、上游提交完成前中断（响应丢失、进程死）。
- **自愈**：`queueCommit` 重发同 `refer_id` → 上游 **Case A**（已成功建单、行有 EAB）幂等返回 order+EAB → `commitOrder` 回填 `api_id`/EAB/active。**Case B**（上游占位遗留、EAB 空）恒返通用可重试 msg，与瞬时并发不可区分 → **不做 msg 启发式**，只靠 `max_attempts` 有界退避 → 超限转人工（对齐排除项：manager↔上游幂等由 gateway 保证，T6 只兜 max_attempts）。
- **cutoff 15min**：ACME 无 0~8h 延时正常态（commit 秒级），`acme_cutoff_minutes`（默认 15）远大于秒级 commit，不误触。
- **两段式（镜像 T5）**：(a) 主扫描 `MAXED_COUNT_SUBQUERY < ?` 排除到顶（不占 limit，防批量卡单队头阻塞，锚 `acmes.created_at`——ACME 无重签周期，建单时间即锚）；(b) `alertMaxedAcmes` 到顶单逐条 SystemAlert `acme_reconcile`（固定指纹 TTL 24h→每日一封）。前移后主扫描不再触发告警，必须由 (b) 承载。
- **仅 admin 转人工、不发 user**：ACME 无 `channel=auto` 续费语义（到期提醒属 P1-3），也无 O4 自动收尾。被 admin batchStart 重启的到顶单短暂进 (b)、每日一封外观噪音，可接受（对齐 Order alertMaxedOrders 取舍）。

### T7 sync `cancelling`→上游终态补退款（`Acme\Action::sync`，D 评审孪生缺口）

`sync` 的 cancelling 守卫原只堵 `(cancelling, upstream=active)` 半格；`(cancelling, upstream∈{cancelled,revoked,expired})` 三格原会写终态但**不退款**，延时 `cancel_acme` 到点撞「状态不是取消中」断死 → under-refund。T7 补退款闭合。

- **锁序 task→acme（防 acme→task 反序死锁）**：上游响应终态判据用**事务外 HTTP 响应快照**（`$upstreamTerminal`，非本地行状态）决定是否先 `Task::lockForMutation($acmeId, ['cancel_acme'])`，再锁 acme 行。高频 `get` 常态 active 零 task 锁开销（仅上游终态才锁 cancel_acme，与执行条件对齐、无冗余锁）。
- **终态判据前置 + 读写同行**：锁内重取 acme 行自身判 `status===cancelling && $upstreamTerminal`（ACME 无 latestCert 切换，天然满足「读=写同一行」红线）→ 预检 `acme_cancel` 未存在则 `refund` + 清孤儿 cancel_acme 任务（executing/stopped 二态）+ 写终态（`cancelled_at ??= now()`；有意不合并 vendor_id/period 等非状态字段，终态元数据以取消时刻为准）。
- **N1（MUST-FIX）并发错误 rethrow 不告警**：`refund` catch 内，并发错误（`DeadlockException` 1213 / `MutationBusyException` 1205 / `causedByConcurrencyError`）**直接 rethrow、不置告警标记**，由 `runTaskMutationTransaction`（attempts=3）静默重试——**重试期零告警红线**（见 order-fund.md）。仅「终态失败」（非并发）才置 `$refundAlert`，延后到事务外一次性 SystemAlert `acme_refund`（事务已回滚、置键立即可达）。`Acme\Action` 因此 `use DetectsConcurrencyErrors`；`refund` 由 `private` 改 `protected` 供测试 Mockery 注入并发/终态异常。
- **告警覆盖边界**：仅保证「refund 自身终态异常且重试耗尽」即时可见；其余回滚形态（refund 成功后 update 死锁耗尽）无专项告警，由 `finance:audit` daily 全量对账 + TaskJob sync failed 落库兜底。告警自身抛异常不得替换原始 `$e`（否则 TaskJob 收到的异常类型变化、破坏并发错误 release 判定）。

**测试**：`ReconcileAcmeCommandTest`（T6 两段式 + maxed 不占 limit 仍告警）、`Unit/Services/Acme/ActionTest`（T7 并发错误零告警 + 终态失败事务外告警 + SQLSTATE 1213 模拟直击红线）。

## 提交通道 channel

`acmes.channel` 记录订单来源通道，由创建入口决定：

| 入口                                           | 通道          |
| ---------------------------------------------- | ------------- |
| `/api/user/acme/new`（Web 用户下单）           | `web`（默认） |
| `/api/admin/acme/new`（管理员代下单）          | `admin`       |
| `/api/v2/acme/new`（API Token 下单）           | `api`         |
| `/api/deploy/acme/new`（Deploy Token 下单）    | `deploy`      |
| `AutoRenewCommand` 自动续费（如未来支持 ACME） | `auto`        |

前端 `Admin/acme/details.vue` 显示"来源"一列（`cancelled_at/api_id/vendor_id` 为空时自动隐藏）。

## API 层架构

`Services/Acme/Api/Api.php` — 路由器，按 `product.source` 分发：

- `default/Api.php` → `default/Sdk.php`（HTTP 调用 上游系统 ACME 端点）

统一接口 `AcmeSourceApiInterface`：`new`/`get`/`cancel`/`getProducts`

上游系统 端点（RPC 风格，通过 `order_id` 传参）：

- `POST /api/v2/acme/new` — 创建订单
  - 字段集（manager 视角完整 schema）：`contact_email` / `product_code` / `period`(int) / `plus`(int 0/1) / `refer_id`
  - `period` 当前 gateway 暂不接收（由 `product.periods[0]` 决定），manager 稳定外发等其升级多年期产品后自然贯通
  - `source` 是 manager 内部 Api 路由参数，作为 `Api::new($data, $source)` 第二个独立参数，不混入 data
- `GET /api/v2/acme/get?order_id=` — 查询订单（响应含 directory_url）
- `POST /api/v2/acme/cancel` — 取消订单
- `GET /api/v2/acme/get-products` — 获取 ACME 产品列表

## ACME directory URL

每个签发 CA 对应一个固定的 ACME directory URL（ACME 客户端 `--server` 参数）。

**数据模型**：

- **权威源**：上游系统（CA 维护方）
- **本地长期缓存**：Laravel Cache，key `acme_directory_url:{ca}`（小写 CA 名，如 `certum`、`letsencrypt`），`Cache::put` + 30 天 TTL 写入（`Acme\Action::DIRECTORY_URL_CACHE_TTL_DAYS` 常量；D2 已弃 `Cache::forever`——过期自动纳入 `syncDirectoryUrl` 既有「缓存 miss 回源」分支，CA 极偶发换端点时错值最终被刷新）；**不使用 system_setting**（不需要后台配置项）
- **按 `Product.ca`（签发机构）聚合**，而非 source（source 是 Manager→上游的路由属性，多 source 可共用同一 CA）

**同步流程**（`Services/Acme/Action`）：

- `commit` / `newAndCommit`：调上游 `/acme/new` 成功后，将响应 `directory_url` 写入/刷新 Cache；响应 payload 通过 `syncDirectoryUrl($acme)` 返回，此时缓存已命中
- `sync`：调上游 `/acme/get`，顺便用响应中的 `directory_url` 刷新 Cache
- `show` / `get` / `batchShow`（Admin/User/Deploy）：调 `syncDirectoryUrl($acme)` —— 先读 Cache 命中直接返回；Cache 空且有 `api_id` 时回源上游 `get` 拉取并写 Cache（**一次性回填**，后续命中）；上游暂不可达静默降级为 null
- Cache 被清理不影响正确性，下次访问多一次上游同步即可恢复

> ⚠ `syncDirectoryUrl` 内部读 `$acme->product->ca` 算 cache key —— 凡在 controller 用 `with(['product' => fn($q) => $q->select([...])])` 收窄 product 字段的地方，**select 必须包含 `ca`**，否则 cache key 退化为空串、`directory_url` 永远 null。User 端 `index` / `show` / `batchShow` 已显式 select `ca`

**前端**：详情页"ACME 凭据" tab 显示端点（directory_url）、EAB KID、EAB HMAC 三项，每项带复制按钮

## 控制器端点

### Admin（`/api/admin/acme/`）

| 方法 | 端点                       | 功能                                                |
| ---- | -------------------------- | --------------------------------------------------- |
| GET  | `/acme`                    | 列表（见"搜索"章节，UserScope 以外无隔离）          |
| GET  | `/acme/{id}`               | 详情（含 EAB + directory_url）                      |
| POST | `/acme/new`                | 创建订单（`plus` 可选；`contact_email` 必填）       |
| POST | `/acme/pay/{id}`           | 支付                                                |
| POST | `/acme/commit/{id}`        | 提交上游系统                                        |
| POST | `/acme/sync/{id}`          | 同步状态（status 白名单校验，顺带刷 directory_url） |
| POST | `/acme/commit-cancel/{id}` | 取消                                                |
| POST | `/acme/revoke-cancel/{id}` | 撤回取消（仅 cancelling 状态，清理延时任务）        |
| POST | `/acme/remark/{id}`        | 管理员备注（写 `admin_remark`）                     |

### User（`/api/user/acme/`）

与 Admin 类似，限当前用户。`POST /acme/remark/{id}` 写用户自己的 `remark`（不是 admin_remark）。

### Deploy（`/api/deploy/acme/`）

- `POST /acme/new` — 一步到位：创建 + 支付 + 提交（`plus` 可选；`contact_email` 必填）
- `GET /acme/{id}` — 获取详情（含 EAB + directory_url）

### API Token（`/api/v2/acme/`，api.v2 guard）

- `POST /acme/new` — 一步到位：创建 + 支付 + 提交（`contact_email` 必填）
- `GET /acme/get?order_id=` / `POST /acme/cancel` / `GET /acme/get-products`

## 搜索

Admin/User `index()` 复用同套过滤器，对齐传统订单搜索：

- **quickSearch**：`id` / `eab_kid` 前缀匹配（走 `acmes_eab_kid_index`）/ `remark` / 产品名；Admin 额外含 `admin_remark`、用户名
- 独立字段：`id` / `status` / `brand` / `period` / `eab_kid`（前缀）/ `amount`（范围）/ `product_name` / `created_at`（范围）/ `period_till`（范围）
- Admin 额外：`user_id` / `username`（精确匹配用户名 → user_id）

**eab_kid 必须左匹配**（`where('eab_kid','like',"$kw%")`），带 `%` 前缀会导致索引失效，ACME 订单量上去后会扫全表。

## 批量操作

列表页提供 7 个批量接口：1 个 GET（聚合详情查询）+ 6 个 POST（写操作），路径格式 `/api/{admin,user}/acme/batch*`。

### 状态过滤规则

| 接口                  | 方法 | 允许状态                        | 备注                                                                                                                                     |
| --------------------- | ---- | ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `batch`               | GET  | 任意（纯读）                    | 详情聚合，`?ids=1,2,3` 形式，返回 `{items:[...]}` 含 `directory_url`（按 ca 缓存）。User 端复用 show 的 `makeHidden`；Admin 端全字段     |
| `batch-pay`           | POST | `unpaid`                        | 同步逐条执行，返回 `{success_count, errors}`                                                                                             |
| `batch-commit`        | POST | `pending`                       | 创建 `commit_acme` Task 立即入队；`checkRepeat` 存在 executing 任务时整体报错                                                            |
| `batch-sync`          | POST | `active` / `cancelling`         | 必须有 `api_id`；pending/unpaid 无 api_id 无法同步                                                                                       |
| `batch-commit-cancel` | POST | `unpaid` / `pending` / `active` | 同步逐条调单体 `commitCancel`；unpaid 无需 refund，pending 无 api_id 直接退费，active 走延时 Task                                        |
| `batch-revoke-cancel` | POST | `cancelling`                    | 同步逐条调单体 `revokeCancel`                                                                                                            |
| `batch-copy-eab`      | POST | 任意（纯读）                    | 返回 EAB 文本（`directory_url\ncontact_email\neab_kid\neab_hmac`，条目间空行）；**Admin 端跨用户请求拒绝**，User 端由 UserScope 自动限制 |

### checkRepeat 语义

`batch-commit` / `batch-sync` 在批量创建 Task 前调用 `checkRepeat`：若该 acme 已存在 executing 状态的同类 Task，则整体返回错误，不创建新 Task。`checkRepeat` 与 `createTasks` 之间无事务锁，并发场景下可能产生双份 executing Task，属已知限制（与 Order 批量设计保持一致）。

### TaskJob 分发

`commit_acme` / `sync_acme` / `cancel_acme` 三种 action 由 `TaskJob` 统一处理：去掉 `_acme` 后缀后映射到 `Acme\Action::{commit,sync,cancel}`；其余 action 走 `Order\Action`。

## Action 统一封装上游 API 调用

- 所有上游 API 调用（new/get/cancel 等）必须通过 `Services/Acme/Action`，不允许控制器直接调 `Api`。操作方法接收 ID（int），创建方法接收参数数组。内部负责模型查询、参数过滤、返回值校正、重复提交防护、状态入库。控制器仅做请求验证 + 一行调用 Action
