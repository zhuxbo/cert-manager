# 自动部署上报与签发失败记录

本域主体是服务端记录与告警消噪，`auto_deploy_reports` 表结构零改动（不加列、不建新表、不给 orders 加列）。Deploy API 的请求/响应契约见下方「Deploy API 边界契约」。

**核心原则（记录全量、通知消噪）**：每次失败照常逐条入表，记录层零抑制；通知层按订单固定指纹去重，避免续签每天重试对同一问题刷屏。

## 报告表用途（`auto_deploy_reports`）

- 每次自动部署上报独立入一行：`order_id` / `cert_id` / `status`(success|failure) / `deployed_at` / `ip` / `message` / `created_at`（Snowflake ID，无 `updated_at`）。
- 客户端部署回调（`callback`）写行携来源 IP + 客户端 message；服务端自写签发失败行 **ip 留空**，二者天然可辨。
- 管理端/用户端各有「自动部署记录」菜单；`Order::autoDeployReports()` 取最近 20 条。用户删除沿 `UserDataTableRegistry`（indirect/purge/export/snowflake 四处已注册）走订单链清理。

## 签发失败记录行的来源（服务端自写，客户端零参与）

签发/自动重签失败**不由客户端上报**（客户端只上报部署结果），服务端在两个写入点自写 `status=failure` 行，`cert_id` 取订单唯一 `latestCert`（签发失败时它就是正在续签的前驱证书、恒存在），message 以明确原因开头：

1. **local CSR 提交后的服务端处理失败** — `Deploy\ApiController::update()` active 分支携 CSR（`renew_mode=local`）时，服务端续费/重签处理抛 `ApiResponseException` → 自写「本地签发失败：…」行后重抛（客户端仍收到同步错误响应）。非本地路径（服务端生成 CSR）不重复留痕。
2. **pull scheduler 自动重签失败** — `AutoRenewCommand::processOrders` catch（真实 renew/reissue 尝试异常）→ 自写「自动续费/重签失败：…」归一行。跳过类（IP/委托/缺价/余额）保持既有仅用户兜底通知语义、**不入本表**。

写行 + 告警统一经 `App\Services\Order\AutoDeployReportService`（`recordServerFailure` 自写行并触发告警；`notifyFailure` 只告警；`clearFailureAlert` 成功清键；`recordServerRecovery` 重签成功恢复行）。

**恢复侧必须对称（防持续误提醒）**：`AutoRenewCommand` 同订单 reissue 成功后调 `recordServerRecovery`——仅当最后一条在案报告为 failure 时自写一行 `status=success`（ip 留空、`cert_id` 回读切换后的 `latest_cert_id`）并清去重键。否则无客户端回调的 web 订单重签恢复后「最后一条报告」永远停在 failure，`schedule:deploy-failure-reminder` 会按 TTL 持续误提醒直到订单终局。renew 成功建新单、旧订单证书翻 `renewed` 终态、提醒天然停止，不写恢复行。

**恢复来源门（重签成功 ≠ 部署恢复）**：最近一条**客户端上报行**（ip 非空）仍为 failure 时（含最后一条本身即客户端部署失败、以及「客户端失败 → 后叠服务端签发失败」交错形态），`recordServerRecovery` 不写恢复行、不清键——部署侧失败只能由客户端 success 回调解除，触顶（CAPPED）静默客户端正是靠 reminder 持续提醒兜底，恢复行若掩蔽它会在到期前关键窗口丢掉本机制的核心承诺。恢复行仅在纯签发侧失败（服务端自写、无未解除的客户端失败）时写入。延时 commit 尚未执行时恢复行即写：commit 终局失败由 `cert_renew_stalled` + reconcile 体系接手，不归本域。

### scheduler 防御（与客户端过期静默对齐）

- **过期防御**：`getRenewOrders`/`getReissueOrders` 加 `expires_at >= now()` 下界——证书已过期不再自动续费/重签，堵 00:00 auto-renew 早于 09:00 `ExpireCommand`（active→expired）翻转的时序缝，交人工处理。
- **开关防御**：重签选单要求 `auto_reissue` 有效（true 或用户默认回落）——local setup 关闭 `auto_reissue`，故 local 订单天然被 scheduler 排除，不生成服务端私钥。

## 告警消噪规则

任一 `status=failure` 行（不分部署/签发来源）触发一封 `SystemAlert`（category `deploy_failure`）：

- **per-order 固定指纹去重**：dedupeKey `deploy_failure_{order_id}` + 固定指纹 `deploy_failure`（防内容/来源 churn 击穿），TTL `config('deploy.failure_alert.dedupe_ttl_hours')` 默认 168h（7 天）。TTL 内同一订单重复失败只入表不再通知。
- **成功清键**：部署成功回调 `clearFailureAlert` 清去重键，复发时立即再告警（healthy 分支清键，防旧键把复发静默压掉）。
- **持续未解决提醒**：`schedule:deploy-failure-reminder`（每天 08:00）扫描「订单最新一行（MAX(id)）仍为 failure」的订单，复用同一去重键 + 固定指纹调 `notifyFailure`——由 TTL 裁决「TTL 内一封、到期仍未解决再一封」，**基于状态而非新失败行**，覆盖客户端触顶后不再上报的静默期；证书已过期或订单终态后停止提醒。
- 一个问题完整生命周期至多数封：初次失败一封、此后每 TTL 周期一封，直到解决或订单终局。SystemAlert 本体契约见 `notification.md`。

## 报告清理（挂入现有 purge 机制）

`PurgeCommand::purgeTerminalOrderReports`（`schedule:purge` 每天 02:00）：仅清「订单已终态」（`latestCert` 落 `AutoDeployReportService::ORDER_TERMINAL_CERT_STATUSES`）且超保留期（`config('purge.retention.auto_deploy_reports')` 默认 90 天）的历史行 + 孤儿行；仍 active/在途的订单显式排除，保住审计视图。分批范式复用 `deletePurgeInChunks`。

## Deploy API 边界契约

跨仓权威副本是 sslctl 仓库的 `deploy-spec.md`（本仓不留副本）；改这三条必须同步该文件。共同前提：**所有拉取与回调都必须有边界**——下游是无人值守长驻进程，任何"由服务端自报数据决定何时停"的循环都是无界循环。

### query 只按订单 ID，不分页（`ApiController::query`）

不带 `field` 的 JSON 查询：`order` **必填**，且只接受 `/^\d+(,\d+)*$/`（单个订单 ID，或英文逗号分隔的多个，上限 `MAX_BATCH_ITEMS`=100）。不满足即 `code=0` + `error_code=invalid_order`（空串同样落这里）。

**响应无分页字段**——`queryResult()` 只返 `data` + `renew_before_days`。单 ID 恒 1 条，批量受 `MAX_BATCH_ITEMS` 约束且每个 ID 至多对应一个订单，返回条数恒 ≤100，`total` / `page` / `page_size` 没有信息量。批量路径**不报** `order_not_found`（部分命中是正常形态），只返回命中的那些。

**已取消：空参数列全量、按域名查询**（含批量里的 ID+域名混合）。理由：自动部署链路的发起方永远持有准确订单号——`deployCommands()` 入口强制 `preg_match('/^\d+(,\d+)*$/')`、生成的三条模板全部带上订单号（sslctl / sslctlw 是 `--order`，sslbt 是 URL 的 `?order=`），下游 daemon 续签也是逐张证书按 `order_id` 调 QueryOrder。两种形态只有手工场景、前端从未展示，且域名走的是 `alternative_names LIKE %domain%` **子串匹配**：查 `example.com` 会把 `notexample.com` / `example.com.cn` / `myexample.common.org` 这类跨域证书一并命中（实测 8 张证书全中，其中 3 张无关），返回条数也无上限。砍掉比修更划算。

> **`field` 分支的域名形态保留**（`?order=<域名>&field=certificate|private_key`）：走的是另一段 `Cert::where('common_name', ...)` **精确匹配**取最新 active 证书，与上面的 LIKE 无关。它只服务 certimate 等 URL 拉取——URL 填死在第三方配置里而订单号会变：续费换新订单靠 `resolveRenewedOrder` 追 `last_cert_id` 链能续上，但**到期后重新下单**不产生该关联、订单号 URL 直接失效，域名 URL 则始终有效。前端订单详情 → SSL → 部署 → URL tab 的「域名 / ID」切换器默认就是域名（`deployCommands()` 的 `cert_url_domain` / `key_url_domain`）。本家客户端不使用此形态。

### 续费链追踪必须有边界（`ApiController::resolveRenewedOrder`）

沿 `last_cert_id` 链追踪 renewed 订单的 while 循环跑在 HTTP 请求线程里、每跳一次 DB 查询，而 Linux 下 `max_execution_time` 不计 I/O 等待——无界循环会长时间占住 PHP-FPM 进程。两道边界：

1. **visited 集合精确判环**：每跳的下一站完全由「当前 order → latestCert」决定，故 order id 重复即必然死循环，第一次重复就退出。只比对 `$nextCert->order_id === $order->id` 仅能防**直接自环**，A→B→C→A 这类三跳以上的环每步都不相等、永远退不出。
2. **`RENEW_CHAIN_MAX_HOPS`（60）硬上限**兜底链虽不成环但异常长的脏数据（链长 = 历史续费次数，一年一次即 60 年）。

触顶/判环都**返回当前 order（status 仍是 renewed）并记 `Log::error('[deploy.renew_chain] ...')`（带 order_id + 走过的链路），不返回 error**：客户端收到 renewed 走终态分支停止等人工，正是数据成环这类事故需要的语义；若返 `code=0`，客户端会当网络错误每天重试，把需人工介入的数据事故降级成静默重试。

### 错误响应的机器可读标识（`App\Support\ApiErrorCode`）

错误响应固定 HTTP 200 + `code=0`（`ApiResponseException` 全站统一契约，前端与所有下游都基于它），客户端无法靠状态码区分「限流 / token 被禁用 / 订单不存在」与真网络错误，只能一律当网络错误无限每日重试。分类标识经 `error($msg, $errors)` 的 errors 数组下发，取值集中定义在 `App\Support\ApiErrorCode`，**一旦发布不得改动**（下游按字符串判定），只允许新增：

- `RateLimiter::checkLimit`（v1/v2/acme/deploy 唯一出口）→ `rate_limited` + `retry_after`（`$window * 2 - $elapsed`，睡满即可重试的保守秒数，取值理由见下）
- `DeployAuthenticate` → `token_missing` / `token_invalid` / `token_disabled` / `account_disabled` / `ip_not_allowed`
- `Deploy\ApiController` query 侧 → `invalid_order`（order 缺失、形态非法、或超 `MAX_BATCH_ITEMS`）、`order_not_found`（单 ID 未命中）
- `Deploy\ApiController` 写侧 → `order_not_found`（update / callback / toggleAutoReissue）、`cert_not_found`（callback）、`order_in_progress`（在途订单拒改 CSR/域名）、`validation_method_unsupported`（产品不支持委托/文件验证）、`auto_renew_disabled`（续费窗口内未开自动续费）、`insufficient_balance`（续费余额预检不足）

> 写侧（POST `/api/deploy`）是 daemon 每日续签的主路径，其中 `validation_method_unsupported` / `auto_renew_disabled` / `insufficient_balance` 是"改配置 / 充值前每天必然重现"的永久性失败（`order_in_progress` 相反，是 unpaid/pending 过渡态，签发完成即自行消失，下游应停止本轮而非永久停止）——**永久性失败漏挂 error_code 的代价最大**：下游按未分类沿用重试策略，等于把需人工介入的事故变成静默每日重试。新增出口时同步本清单、`backend/resources/docs/api/deploy.yaml` 的 enum、以及跨仓 spec（见下方"同步面"），`tests/Feature/Http/Controllers/Deploy/OrderControllerTest.php` 有逐码用例锁定。
>
> **覆盖边界（勿当成"全部错误都有码"）**：`errors` 有三种形态，只有第一种参与分类——① `{error_code, retry_after?}`；② Laravel 参数校验失败袋 `{字段名:[消息]}`（有 `errors` 键但**无码**）；③ 无 `errors` 键，即 `update()` 经 `getData()` 从 `Order\Api\Action` 透传的业务错误（如 `产品配置错误`）沿用 `$result['errors'] ?? null` 恒为空。那是整个订单服务层的错误面，逐条分类是独立课题，未纳入本次收敛。**下游判定必须看 `errors.error_code` 非空，不能看"有没有 errors 键"**；②③ 一律按未分类沿用既有重试策略。这条边界必须在客户端 spec 里如实写明，不要写成"确定性失败一律有码"。
>
> **同步面（4 份对称副本，目前只靠本条约束，无机器校验）**：`ApiErrorCode` 常量 ↔ `deploy.yaml` 的 enum ↔ 本清单 ↔ 跨仓 `deploy-spec.md`（权威副本在 sslctl 仓，sslctlw / sslbt 保持 byte 相同，`md5` 三份一致即同步到位）。另因 `RateLimiter` 是 v1/v2/acme/deploy 共用中间件，`rate_limited` 同时出现在 `acme.yaml` / `v2.yaml` 的 `ApiResponse.errors`——改限流出口时这两份也要跟。

**限流刻意不改 HTTP 429**：客户端 isRetryable 认 429 → 4 次尝试 + 指数退避（1s→2s→4s），7 秒内全部落在同一 60s 窗口注定失败；且 `checkLimit` 的 `Cache::increment` 在阈值判断**之前**，每次重试都继续推高计数器、把恢复时间往后拖。返回 200 让客户端不重试反而是对的。改状态码还会同时改掉所有 API channel 的错误模型（`RateLimiter` 是共用中间件类）。

**`retry_after` 取 `$window * 2 - $elapsed` 而非 `$window - $elapsed`**：`checkLimit` 判定用滑动窗口加权 `estimated = currentCount + prevCount × (1 - elapsed/window)`。若只给"当前窗口剩余秒数"，客户端睡满后恰好落在下一窗口起点，此时 `elapsed=0` → `prevWeight=1` → 刚刚超限的那个计数**全额**计入，`estimated` 必然仍超限；且 `Cache::increment` 在判定之前，这次徒劳重试还会把再下一窗口的 `prevCount` 垫高，**把恢复时间往后推**。多跨一个整窗口后 prev 指向中间那个窗口（客户端不再请求即为 0），`estimated` 归零，"睡够即可重试"才成立，可按 HTTP `Retry-After` 语义使用（前提：这段时间内不再发请求）。

> 该语义由 `OrderControllerTest` 的「限流 retry_after 睡满后确实放行，睡到下一窗口起点则仍被拒」用例守门——它**两步实证**：先断言只睡到下一窗口起点仍得 `rate_limited`（即旧取值不够），再断言睡满 `retry_after` 后放行。变异探针实测：把实现改回 `$window - $elapsed` 后该用例在第二步 `invalid_order` 断言处变红，即它真正守的是语义而非常量。**该用例里刻意不放 `retry_after` 的常量断言**（常量由上一个用例锁）——常量断言会抢在两步实证之前红，让语义守卫永远拿不到执行机会。

> `field` 拉取分支的 `abort(400/404, ...)` 靠 **HTTP 状态码**可辨，不挂 error_code。注意响应体仍是 `{"code":0,"msg":"..."}`（异常处理器统一包装），只是状态码非 200——只按 `body.code` 判定的第三方拉取方会看成"无码的 code=0"而落进重试；本家客户端不用 field 形态，故未改。

## 与客户端行为契约的对应关系

服务端 Deploy API 与续签契约由 `backend/app/Http/Controllers/Deploy/ApiController.php`、`backend/app/Console/Commands/AutoRenewCommand.php` 及对应 Feature 测试锁定；与三个客户端的关键对应：

- 客户端「每次部署成败尽力上报一次、签发失败不上报」↔ 服务端「回调失败逐条入表 + 服务端自写签发失败行」。
- 客户端触顶（`CAPPED`）后静默不再回调 ↔ 服务端「持续未解决提醒」基于最后一条报告状态判定，不依赖新失败行。
- 客户端最后一次失败 message 标注「已达重试上限」（自由文本、零协议变化）↔ 服务端原样 strip_tags + 截断入表，告警/记录可读出「已停止自动重试、需人工介入」。
- 客户端 IP 证书 setup 关 `auto_reissue`（local）↔ 服务端 scheduler 因开关无效天然排除。
- 客户端「单次请求取完即止、不翻页」↔ 服务端 query 不分页、响应无 `total` / `page` / `page_size`，条数由 `MAX_BATCH_ITEMS` 封顶。
- 客户端按 `errors.error_code` 分类停止而非重试 ↔ 服务端**三个中间件/控制器自身出口**的确定性失败带 error_code（覆盖边界见上节，不是"全部错误"）。
