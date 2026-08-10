# 自动部署上报与签发失败记录

本域主体是服务端记录与小时聚合告警，`auto_deploy_reports` 表结构零改动（不加列、不建新表、不给 orders 加列）。Deploy API 的请求/响应契约见下方「Deploy API 边界契约」。

**核心原则（记录全量、通知聚合）**：每次失败照常逐条入表，记录层零抑制；失败请求不即时发信，由小时命令跨订单聚合上一个完整小时，至多发送一封管理员告警。

## 报告表用途（`auto_deploy_reports`）

- 每次自动部署上报独立入一行：`order_id` / `cert_id` / `status`(success|failure) / `deployed_at` / `ip` / `message` / `created_at`（Snowflake ID，无 `updated_at`）。
- 客户端部署回调（`callback`）写行携来源 IP + 客户端 message；服务端自写签发失败行 **ip 留空**，二者天然可辨。
- 管理端/用户端各有「自动部署记录」菜单；`Order::autoDeployReports()` 取最近 20 条。用户删除沿 `UserDataTableRegistry`（indirect/purge/export/snowflake 四处已注册）走订单链清理。

## 签发失败记录行的来源（服务端自写，客户端零参与）

签发/自动重签失败**不由客户端上报**（客户端只上报部署结果），服务端在两个写入点自写 `status=failure` 行，`cert_id` 取订单唯一 `latestCert`（签发失败时它就是正在续签的前驱证书、恒存在），message 以明确原因开头：

1. **local CSR 提交后的服务端处理失败** — `Deploy\ApiController::update()` active 分支携 CSR（`renew_mode=local`）时，服务端续费/重签处理抛 `ApiResponseException` → 自写「本地签发失败：…」行后重抛（客户端仍收到同步错误响应）。非本地路径（服务端生成 CSR）不重复留痕。
2. **pull scheduler 自动重签失败** — `AutoRenewCommand::processOrders` catch（真实 renew/reissue 尝试异常）→ 自写「自动续费/重签失败：…」归一行。跳过类（IP/委托/缺价/余额）保持既有仅用户兜底通知语义、**不入本表**。

服务端写行统一经 `App\Services\Order\AutoDeployReportService`（`recordServerFailure` 写失败行；`recordServerRecovery` 在纯签发失败恢复时写成功行）。客户端回调直接逐条写 `auto_deploy_reports`。告警只由 `DeployFailureReminderCommand` 的完整小时聚合产生。

**恢复侧状态链**：`AutoRenewCommand` 同订单 reissue 成功后调 `recordServerRecovery`——仅当最后一条在案报告为 failure 时自写一行 `status=success`（ip 留空、`cert_id` 回读切换后的 `latest_cert_id`）。它用于保持报告视图的失败/恢复状态完整，不参与小时窗口内既有失败事件的撤销。renew 成功建新单、旧订单证书翻 `renewed` 终态时不写恢复行。

**恢复来源门（重签成功 ≠ 部署恢复）**：最近一条**客户端上报行**（ip 非空）仍为 failure 时（含最后一条本身即客户端部署失败、以及「客户端失败 → 后叠服务端签发失败」交错形态），`recordServerRecovery` 不写恢复行——部署侧失败只能由客户端 success 回调解除。恢复行仅在纯签发侧失败（服务端自写、无未解除的客户端失败）时写入。延时 commit 尚未执行时恢复行即写：commit 终局失败由 `cert_renew_stalled` + reconcile 体系接手，不归本域。

### scheduler 防御（与客户端过期静默对齐）

- **过期防御**：`getRenewOrders`/`getReissueOrders` 加 `expires_at >= now()` 下界——证书已过期不再自动续费/重签，堵 00:00 auto-renew 早于 09:00 `ExpireCommand`（active→expired）翻转的时序缝，交人工处理。
- **开关防御**：重签选单要求 `auto_reissue` 有效（true 或用户默认回落）——local setup 关闭 `auto_reissue`，故 local 订单天然被 scheduler 排除，不生成服务端私钥。

## 小时聚合告警规则

`schedule:deploy-failure-reminder` 每个整点执行，查询上一个封闭小时窗口 `[startOfHour-1h, startOfHour)` 内所有 `status=failure` 行（不分部署/签发来源）：

- **跨订单单封**：同一窗口不按订单拆信，邮件包含失败报告数、订单去重数、客户端/服务端来源分档和最多 20 个订单 ID 样本。
- **边界稳定**：更早历史和当前未结束小时不进入本轮；整点之后写入的失败自然归下一窗口。
- **重跑去重**：dedupeKey 固定为 `deploy_failure_hourly`，fingerprint 使用窗口起点 `deploy_failure_YYYYMMDDHH`，同一窗口命令重跑不重复发；TTL 48h 仅保存窗口指纹，下一小时指纹变化立即放行。
- **请求链零发信**：客户端 callback 和服务端自写失败只入表，不解析管理员、不调用 `SystemAlert`，避免并发失败按订单刷屏。
- **空窗口不发**：没有新失败报告时只输出命令日志。当前设计不对无新增报告的历史未解决问题周期重发，人工排查以自动部署记录为准。SystemAlert 本体契约见 `notification.md`。

## 报告清理（挂入现有 purge 机制）

`PurgeCommand::purgeTerminalOrderReports`（`schedule:purge` 每天 02:00）：仅清「订单已终态」（`latestCert` 落 `AutoDeployReportService::ORDER_TERMINAL_CERT_STATUSES`）且超保留期（`config('purge.retention.auto_deploy_reports')` 默认 90 天）的历史行 + 孤儿行；仍 active/在途的订单显式排除，保住审计视图。分批范式复用 `deletePurgeInChunks`。

## Deploy API 边界契约

客户端契约规范 `deploy-spec.md` 由客户端仓自行维护：**本仓是服务端，不保存该文件、也不承担它的同步**。契约改动在本仓只落到代码、`deploy.yaml` 与本文，客户端侧的跟进交给客户端仓的会话。**本文与代码注释都不引用该文件的小节号**——跨仓章节号各自漂移、无法校验，需要说明客户端行为时直接把事实写清楚。共同前提：**所有拉取与回调都必须有边界**——下游是无人值守长驻进程，任何"由服务端自报数据决定何时停"的循环都是无界循环。

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

### 非 active 的 CSR/域名守卫（`ApiController::update`）

`csr` / `domains` 只有 active 分支会消费，故**任何非 active 状态携带非空 `csr`/`domains` 都显式报错**，不是只拦 `unpaid`/`pending`：`processing`/`approving`/`cancelling` 与各终态既不会改 CSR 也走不到任何动作，静默落到末尾 `success()` 返回 `code=1` 会让客户端认为新 CSR 已被接受，此后按 CSR 比对私钥永远失配、永远等不到匹配私钥的证书。仅传 `order_id` 的推进（`unpaid` 付款 / `pending` commit 自愈，以及 `processing` 轮询）不带二者，不触发守卫。

按「会不会自行回到 active」分两个 error_code：`unpaid`/`pending`/`processing`/`approving` → `order_in_progress`（过渡态，客户端归一 `processing` 后只 GET）；`cancelling` 与各终态 → `order_not_active`（不会自行恢复，每轮重现直到人工介入）。分开是为了不让客户端对一张永不回到 active 的订单一直轮询到无进展时限。

> 边界：`Cert::retrieved` 在 active 但缺中间证书时把内存里的 status 读成 `approving`（等下次同步补链），这类订单的 local 提交会落 `order_in_progress`。相比旧行为（静默返回 approving 数据）只是把「本次不签发」这个事实显式化。

### local 重签冷却（`ApiController::update` active 分支）

携 CSR 的 local **重签**受同订单 15 天冷却约束（`LOCAL_REISSUE_COOLDOWN_DAYS`）：当前 active 证书 `private_key` 为空且 `issued_at` 未满 15 天时直接 `error()`，错误文本给出可再次提交的时间。判定在 `$isRenew` 之后，且**做两次**：互斥锁之前那次是主判定（与产品校验 / auto_renew 校验同纪律——早失败不进临界区），`reissue` 分支在互斥锁 + 事务内、订单行锁与前驱 CAS 之前用当前证书再复判一次。

**锁内复判的定位是纵深防御，别高估它**：`withMutex` 是非阻塞抢锁（`Cache::lock()->get()`，抢不到直接抛 `MutationBusyException`），锁外快速失败到抢到锁之间只隔着 `$isRenew` / 产品校验这几步纯本地运算、不含任何上游调用，窗口是毫秒级；要在其中把 `latest_cert` 换成一张同时满足 `active` + `private_key` 空 + `issued_at` 在窗口内的新证书，另一路请求得跑完 reissue→pay→commit→上游签发→解析 notBefore 落 `issued_at` 的完整链路，现实不可达。窗口内真正可能出现的并发形态是新证书停在 `pending`/`processing`（`issued_at` 为 `null`），那种形态冷却按设计 fail-open 放行，实际由 `initParams` 的订单状态校验拒掉。**真正需要这次复判的是两条锁失效路径**：① Cache 故障时 `withMutex` fail-open 直接执行 callback（此时根本没有互斥，两路请求可同时进临界区）；② 未来新增的非 Deploy 写入方不持同一把 `order_mutate` 锁。维护时不要因为这段而删掉 `initParams` 的状态校验——那才是 `issued_at=null` 形态的实际兜底。

**一致性论证以 MySQL 默认 REPEATABLE READ 为前提**（仓内未显式声明隔离级别，`config/database.php` 对 isolation 零命中，完全依赖 MySQL 默认）：锁内那次重读同时确立本事务的一致读视图，`initParams` 随后捕获的基线 `last_cert_id` 与它读到的是同一张证书，而前驱 CAS 是锁定写（current read）、被抢先即 affected=0 回滚，判定对象与实际被重签对象由此对齐。生产实例若被调成 `READ-COMMITTED`（客户自管 MySQL 的常见调优项），`initParams` 那次独立非锁定读会看到更新的已提交数据，复判退化为窄窗口检查——不产生资金或状态损坏，兜底仍是基线比对 + 前驱 CAS。**此处不加 `FOR UPDATE` 预锁**：会把 `initParams` 的 keygen + 委托 DNS 罩进订单行锁，违反锁内不做上游 HTTP 的红线。锁内复判的拒绝**不写「本地签发失败」记录**（签发根本没开始），与锁外那次一致。

**只约束重签，不拦续费**：判定放在 `$isRenew` 之后。临期证书恰好是冷却期内客户端签发的（续签链断裂后重试等形态）并不罕见，拦下续费不会让证书少过期一天（`AutoRenewCommand` 照常兜底），只会让客户端在必然失败的路径上每轮空烧 `issue_retry_count`，10 天触顶进 `CAPPED` 要人工解。续费另有 order 互斥 + 前驱 CAS + `auto_renew` 三道闸，且续费成功后新单 `issued_at` 全新、冷却对新单照常生效。

**判据为什么是 `private_key` 为空**：`CsrUtil::auto` 的 `csr_generate=0` 分支（客户端提交 CSR）不写 `private_key`，故它精确等价于「这张证书的私钥只在某台客户端机器上」，也正是冷却要保护的状态。据此两条边界天然成立：首次 setup 时初始证书由 web/admin 下单、服务端持私钥 → 不冷却；pull 模式只 GET、不 POST 推进 → 不进该分支。

**覆盖范围只到「多台 local 客户端」**：窗口锚在**当前证书行**的 `private_key`/`issued_at`，不追溯历史证书行。中途任何一次服务端持钥签发（会员中心/管理端手工重签、pull setup 打开 `auto_reissue` 后 scheduler 抢跑，以及本端点任何一次不带 CSR 的提交）都会把 `private_key` 变非空，从而重置窗口——不必有人工介入。这是有意的：那些形态里服务端本来就持有私钥、客户端可直接取用，不构成互抢；防 scheduler 抢跑的闸门是 local setup 关 `auto_reissue`，不是本冷却。

**客户端提交内容的判定口径是 trim 后非空**，不用 `empty()`：`empty('0')` 恒为 true，会把 `csr='0'` 这类畸形值当「未携带 CSR」静默改走服务端生成 CSR 重签，客户端以为完成了 local 重签、实际拿到另一把私钥的证书（此后客户端按下发的 CSR 比对本机私钥永远失配）；`domains='0'` 同理会静默回落旧域名后返回成功。`csr` 与 `domains` 各只判定一次，非 active 守卫、冷却判定、`csr_generate` 与 domains 分支、签发失败留痕都复用，避免漂移。显式空值（`null` / 空串 / 纯空白，后两者经 TrimStrings + ConvertEmptyStringsToNull 归零）仍按「未携带」处理，语义与既有下游一致。

**不用「最后部署时间」做冷却起点**：`auto_deploy_reports` 的部署回调是客户端侧的**非关键路径**（无 outbox、无幂等 ID、允许缺行，且触顶/过期/停更/policy 阻断路径根本不发回调），拿它做判定会被丢包直接旁路。`certs.issued_at` 由服务端解析已签发证书的 notBefore 落库（`ActionTrait` 的 `validFrom_time_t`），是 CA 侧时间而非服务端时钟，但**零依赖客户端上报**——这正是选它的理由。两个衍生行为：`issued_at` 为 `null` 时 `?->` 短路 → 冷却 fail-open（不拦）；CA 若签出未来 notBefore，窗口整体后移且无自助解除接口（真实 CA 一般倒签，窗口只会略短于 15 天）。证书解析失败不会落 0——`parseCert` 直接 `error('证书解析失败')` 中断签发。

**要解决的问题**：同一订单被多台 local 客户端纳入自动续签管理时，私钥只在最后一次提交 CSR 的那台机器上，其余机器每轮都因私钥失配而重新建立 CSR 尝试，把彼此的证书翻成 `reissued`，形成无限互相重签。冷却使非持有私钥的客户端在签发计数触顶后停摆等待人工。**不提供解除冷却的接口**：换机部署走客户端重新 setup 并提供原私钥。

> 冷却**不带 error_code**，走未分类确定性失败：客户端据此清理本轮 pending 与 CSR metadata、保留签发计数、停止本轮（客户端侧行为，权威定义在客户端仓）。

### query 响应下发当前动作的 CSR（`ApiController::getOrderData`）

`getOrderData()` 在**所有状态**下发 `csr`（取 `latestCert->csr`，缺失为空串），不限 active。local 客户端靠它比对 pending 私钥公钥，判断在途签发是不是本机提交的——`processing` 阶段正是要靠它决定跟随还是重提，只在 active 返回等于让该机制失效。`latestCert` 恒为当前签发动作，天然不会下发无关的历史 CSR。

> 这条是客户端「按 CSR 判断在途签发归属」的服务端实现。缺它会让所有持有 pending 的 local 客户端永远无法确认提交是否被接受 → 永远保留 pending、永远停止本轮 → 撑到无进展时限（14 天）集体进 `CAPPED`，即 local 自动续签全线停摆。

### 错误响应的机器可读标识（`App\Support\ApiErrorCode`）

错误响应固定 HTTP 200 + `code=0`（`ApiResponseException` 全站统一契约，前端与所有下游都基于它），客户端无法靠状态码区分「限流 / token 被禁用 / 订单不存在」与真网络错误，只能一律当网络错误无限每日重试。分类标识经 `error($msg, $errors)` 的 errors 数组下发，取值集中定义在 `App\Support\ApiErrorCode`，**一旦发布不得改动**（下游按字符串判定），只允许新增：

- `RateLimiter::checkLimit`（v1/v2/acme/deploy 唯一出口）→ `rate_limited` + `retry_after`（`$window * 2 - $elapsed`，睡满即可重试的保守秒数，取值理由见下）
- `DeployAuthenticate` → `token_missing` / `token_invalid` / `token_disabled` / `account_disabled` / `ip_not_allowed`
- `Deploy\ApiController` query 侧 → `invalid_order`（order 缺失、形态非法、或超 `MAX_BATCH_ITEMS`）、`order_not_found`（单 ID 未命中）
- `Deploy\ApiController` 写侧 → `order_not_found`（update / callback / toggleAutoReissue）、`cert_not_found`（callback）、`order_in_progress`（在途订单拒改 CSR/域名）、`order_not_active`（cancelling 与终态拒改 CSR/域名）、`validation_method_unsupported`（产品不支持委托/文件验证）、`auto_renew_disabled`（续费窗口内未开自动续费）、`insufficient_balance`（续费余额预检不足）

> 写侧（POST `/api/deploy`）是 daemon 每日续签的主路径，其中 `validation_method_unsupported` / `auto_renew_disabled` / `insufficient_balance` 是"改配置 / 充值前每天必然重现"的永久性失败，`order_not_active`（cancelling 与终态）同属此类（`order_in_progress` 相反，是 unpaid/pending/processing/approving 过渡态，签发完成即自行消失，下游应停止本轮而非永久停止）——**永久性失败漏挂 error_code 的代价最大**：下游按未分类沿用重试策略，等于把需人工介入的事故变成静默每日重试。新增出口时同步本清单与 `backend/resources/docs/api/deploy.yaml` 的 enum（见下方"同步面"），`tests/Feature/Http/Controllers/Deploy/OrderControllerTest.php` 有逐码用例锁定。
>
> **覆盖边界（勿当成"全部错误都有码"）**：`errors` 有三种形态，只有第一种参与分类——① `{error_code, retry_after?}`；② Laravel 参数校验失败袋 `{字段名:[消息]}`（有 `errors` 键但**无码**）；③ 无 `errors` 键，即 `update()` 经 `getData()` 从 `Order\Api\Action` 透传的业务错误（如 `产品配置错误`）沿用 `$result['errors'] ?? null` 恒为空。那是整个订单服务层的错误面，逐条分类是独立课题，未纳入本次收敛。**下游判定必须看 `errors.error_code` 非空，不能看"有没有 errors 键"**；②③ 一律按未分类沿用既有重试策略。这条边界必须在客户端 spec 里如实写明，不要写成"确定性失败一律有码"。
>
> **同步面（3 份对称副本，由 `finish-check-greps.sh` 的 Z15 硬零断言机器校验）**：`ApiErrorCode` 常量 ↔ `deploy.yaml` 的 enum ↔ 本清单。三份都在本仓内（客户端 `deploy-spec.md` **不在同步面内**，见「Deploy API 边界契约」开头，新增取值只需知会客户端仓），故可机器比对：Z15 对常量集与 enum 做双向集合相等、对本清单的**出口行**（`- <出口> → <码>`）做双向比对，任一方向缺漏或多出即 FAIL。**新增取值必须同时改三处**，只改常量会被门禁当场拦下。出口行的判据是「以 `` - ` `` 开头**且**含 `→`」，其首个 `→` 之后**只允许出现取值本身**，非取值的 backtick token 仅有 `retry_after`（`rate_limited` 的伴随字段）在脚本白名单里，往括注里写别的 backtick token 会让门禁误红——这是有意的严格，补充说明写成普通段落或不以 `` - ` `` 起头的列表项即可。改 Z15 的提取规则时注意这些已踩过的坑：小节边界只停在 `###` 会把后续 `##` 小节卷进来；用整节文本做 containment 会让「清单漏登记新码」蒙混过关（说明段落提到码名即算命中）；边界正则**不能用 `{1,3}` 区间量词**（mawk 不支持，CI 的 Debian/Ubuntu runner 默认 awk 正是 mawk，会让干净树恒红）；切「首个 `→` 之后」**不能用 `sed 's/^[^→]*→//'`**（`→` 是 3 字节 `E2 86 92`，C/POSIX locale 下 sed 按字节解释，含 `0x86` 的汉字如「写」会让整条替换不匹配，收窄静默失效且判定随 locale 变化），要用 `awk -F'→'`；常量提取正则必须覆盖 `final` / PHP 8.3 类型化类常量 / 双引号取值 / 同行属性，并由「含 const 的声明行数 == 字面取值数」断言兜底，**该断言的分母必须与取值正则解耦**（共用前缀的两条正则互为分母时，前缀的共同盲区会让分子分母同步减一、断言静默失效），成因也不止漏采一种（同值别名 / 非取值常量 / 跨行声明同样触发，故文案中性并列出未取到取值的整行）；yaml 抽取命中闭合 `]` 时必须把 `error_code` 定位标志一并复位，否则该文件后续任何 block 风格 `enum:` 都会被重新拉起、当成漏同步的错误码误红；yaml 侧是**逐块比对而非取并集**（多块取并集会让「某一块漏码」被别的块掩盖，判定是每块都与常量集全等——将来若确有 schema 只需列子集，显式加白名单，不要退回并集），并由「`error_code:` 键数 == 抽出的 enum 块数」这条独立断言兜住"某块根本没被抽取器认出"（如写成单行 flow 风格 `enum: [a, b]`）。另因 `RateLimiter` 是 v1/v2/acme/deploy 共用中间件，`rate_limited` 同时出现在 `acme.yaml` / `v2.yaml` 的 `ApiResponse.errors`——改限流出口时这两份也要跟。

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
- 客户端首次 setup 无配对私钥时进「需要私钥」、不自动提交新 CSR ↔ 服务端 local 重签冷却，两侧共同保证一个订单的 local 私钥只归一台机器；冷却是后者的兜底，拦的是两台都已完成 setup 的残留形态。
- 客户端用服务端 CSR 判断签发归属（不比 PEM 原文，验签后比公钥） ↔ 服务端 query 所有状态下发 `latestCert->csr`。
