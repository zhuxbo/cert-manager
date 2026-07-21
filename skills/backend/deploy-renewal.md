# 自动部署上报与签发失败记录

Deploy API 三接口（`GET/POST /api/deploy`、`POST /api/deploy/callback`）请求/响应零改动；本域只做服务端记录与告警消噪的小修，`auto_deploy_reports` 表结构零改动（不加列、不建新表、不给 orders 加列）。

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

## 与客户端行为契约的对应关系

服务端 Deploy API 与续签契约由 `backend/app/Http/Controllers/Deploy/ApiController.php`、`backend/app/Console/Commands/AutoRenewCommand.php` 及对应 Feature 测试锁定；与三个客户端的关键对应：

- 客户端「每次部署成败尽力上报一次、签发失败不上报」↔ 服务端「回调失败逐条入表 + 服务端自写签发失败行」。
- 客户端触顶（`CAPPED`）后静默不再回调 ↔ 服务端「持续未解决提醒」基于最后一条报告状态判定，不依赖新失败行。
- 客户端最后一次失败 message 标注「已达重试上限」（自由文本、零协议变化）↔ 服务端原样 strip_tags + 截断入表，告警/记录可读出「已停止自动重试、需人工介入」。
- 客户端 IP 证书 setup 关 `auto_reissue`（local）↔ 服务端 scheduler 因开关无效天然排除。
