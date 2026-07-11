# 通知体系（主系统仅 mail，其他通道由插件注入）

业务事件归主系统触发，通道实现归插件。主系统内置 mail，插件通过 `ChannelManager` 注册其他通道（如飞书）。

**核心安全**：① `ChannelManager` 必须绑 singleton，否则插件通道端到端静默失效；② `DefaultNotificationBuilder` 直通 context 入库、不过滤敏感字段，携密 code 必须走专用 Builder + `transient`（绝不入库）。

## 触发与分发

- **业务事件归主系统、通道实现归插件**：主系统在 `Order/Action`/`AutoRenewCommand`/`ExpireCommand`/`TaskJob`/`FundAuditCommand`/`User\AuthController`（改密/重置成功 → `security`）触发 `NotificationCenter::dispatch(NotificationIntent)`，`ChannelManager` 分发到所有已注册可用且通过 `shouldSend()` 的 channel
- **`ChannelManager` 必须绑 singleton**（`AppServiceProvider::register`）：否则容器对未绑定类每次 make 新实例，插件 ServiceProvider 里 `app(ChannelManager::class)->register(...)` 注册的通道随实例丢弃、NotificationCenter/Job 解析到只含 mail 的新实例，插件通道端到端静默失效
- **主系统内置 mail，无 channel 抽象冗余层**：已删 `Guards/` 整目录（4 Guard + Manager）和 `TemplateSelection.channelTemplates`；`notification_templates.channels` 字段已移除（每个 code 对应单一模板，**`code` 唯一**）；`notifications` 表无 channel 字段

## 用户偏好与 Builder

- **用户偏好扁平按 code**：`users.notification_settings = {cert_issued: true, cert_expire: true, security: true}`；`User::allowsNotification(code)` 替代旧 `allowsNotificationChannel(channel, type)`；`User::normalizeNotificationSettings` 兼容老的嵌套 `{mail: {x}}` 数据（自动提升 mail 子树）
- **Builder 注册按 code 单维度**：`config/notification.builders = ['cert_issued' => CertIssuedNotificationBuilder::class, ...]`，不再 `code.channel` 复合 key；4 个内置 Builder 已去 `Mail` 后缀（`CertIssuedNotificationBuilder` 等）。Builder 输出 `NotificationPayload($data)`，`data` 对所有 channel 通用，mail-specific 数据放 `data._meta`；敏感字段（如初始密码）走第二参 `NotificationPayload($data, $transient)`——`transient` 由 `NotificationJob` 发送前合入内存供渲染、发送后还原，**绝不入库**
- **MailChannel::shouldSend 内联逻辑**：检查 `notifiable->email` 非空 + 调 `allowsNotification($code)`；Admin 等无此方法的 notifiable 默认 true
- **取消/重发等 Admin 操作**：测试通知 `/api/admin/notification/test-send` 和重发 `/api/admin/notification/{id}/resend` 不再传 `channels` 入参（已删 sanitizeChannels）；发送会广播到所有已注册可用 channel

## 系统告警（SystemAlert，运维/健康 admin 告警共享件）

`App\Services\Notification\SystemAlert`：把「运维告警」标准化为一次经 `NotificationCenter` 的 `system_alert` 通知投递，附 Cache 状态指纹去重防持续异常态刷屏。只做传输层，聚合/阈值判定归调用方；异步加密（ShouldBeEncrypted）、afterCommit、`onQueue('notifications')` 全由 `NotificationJob` 继承。消费方：E1~E6 监控命令、AutoRenew A4 缺价（`missing_price`）、F1-4 部署回调滑窗、F2-1 dnsTools 停摆、F2-4 证书链校验（`Order/Action.php:773/793`）、H1 升级 watchdog、H3 备份失败、E2 产品同步（`ImportProductCommand`）；**P0 批次新增**：M7 上游连通性（`ca_connectivity`/`ca_outage`）、T1 僵尸任务转人工（`task_stale`）、T5 卡单每日快照（`reconcile_maxed`）、T6 ACME 卡单转人工（`acme_reconcile`）、T7 ACME 退款失败（`acme_refund`）。**本体三件套（`SystemAlert` / `SystemAlertNotificationBuilder` / `config/notification.builders` 注册）契约冻结，新消费方只适配、不改造本体。**

- **契约签名**：`send(string $category, string $title, string $message, array $details = [], ?string $dedupeKey = null, int $dedupeTtlHours = 24, ?string $fingerprint = null): bool`（返回 false = 去重跳过 / 无 admin / dispatch 失败）+ `clearDedupe(string $dedupeKey): void`
- **五步时序（勿改序）**：① 去重预检（`system_alert:{dedupeKey}` 键值 === 指纹 → 不发）→ ② 解析 admin（`site.adminEmail` → `Admin::where(email)` → `Admin::first()`；无 admin/邮箱 → Log::warning 返回 false、**不占键**）→ ③ 写端把 `admin_email = $targetEmail` 显式写入 intent context（镜像 `FundAuditCommand` 写端；否则 adminEmail 是运维分发别名而非任何 Admin 登录邮箱时，MailChannel 回落 `Admin::first()->email` 投错地址）→ ④ dispatch（失败只 Log::warning、**不占键**——否则整个 TTL 静默丢告警）→ ⑤ `DB::afterCommit` 置键（键值=指纹快照；无事务时立即执行，事务内调用回滚时键随 NotificationJob 一起丢弃、语义对齐）
- **固定指纹 vs 内容指纹**：`fingerprint=null` → 内容指纹 `sha1(json([category,title,message,details]))`——同一故障内容恒定则 TTL 内一封、**新增异常项指纹变则立即再发**（E1 凭证 / E3 支付证书这类集合型用它）；**计数/波动型（卡单数、偏差秒数、失败计数、outage 轮数）必须传固定指纹**，否则每轮计数变化翻新内容指纹击穿去重 → 刷屏（in-tree 范式：AutoRenewCommand `'missing'`、E4 `'clock_skew'`、E5 `'threshold_exceeded'`、E6 `'stuck'`、F1-4 `'deploy_callback_failure'`、F2-1 `'outage'`、M7 `'ca_outage'`、T1 `'swept_exhausted_{id}'`、T6 `'acme_maxed_{id}'`、T7 `'refund_failed_{id}'`）
- **每日快照日期指纹（T5，吸收 M5）**：卡单转人工 admin 汇总 `reconcile_maxed` 用 **fingerprint 含当天日期** `'maxed_summary_'.now()->toDateString()`——既非纯固定（否则 SMTP 瞬断丢首封后整期静默）、亦非内容指纹（否则每轮卡单集变化刷屏）。语义：每天首个非空轮发一封含当天全部转人工单，同日不重发、次日指纹变化必发（含存量+新增），新增单最坏延迟 1 天。原「reconcile 去重标记带 TTL 24h（M5）」由本机制单源吸收
- **M7 上游整体连通性（`CaHealthcheckCommand` 连通性维度）**：与凭证维度（`ca_credentials`，主信号 401/403 + 辅助 Unauthorized）分离。连接超时/请求失败/5xx 等 `code=0` 非鉴权失败走连通性——`Cache::forever` 跨 cron 周期**累计连续失败计数**，达 `connectivity_threshold`（默认 3，3×15min=45min 滤上游滚动重启瞬断）才发 SystemAlert，**固定指纹 `ca_outage`**（计数型防 churn 击穿）+ TTL `connectivity_ttl_hours`（默认 6h ≥ 3×45min）。**healthy / 上游可达分支**（code=1 或返回鉴权错误=上游可达）必须重置：清计数 + `clearDedupe(ca_connectivity)`（否则恢复后复发被旧键静默压掉）。**未配置态**（`'Api url or token is not set'`）Log::info 剔出、不占键不动计数（新装/测试实例把「没填」当「失效」是狼来了）
- **TTL 语义**：固定指纹场景 TTL = 同一持续异常的**重复提醒间隔**（不是缓存过期直觉），按场景重要性定；契约要求 ≥3× 巡检周期（防 TTL≈周期时去重形同虚设）。**备份失败（日频 cron）用 24h=每天一封是有意偏离**：备份连续失败不应静默 3 天，主控书面选择
- **恢复清键分两类（选型须知）**：**level/状态监控型**消费方（E1~E6/H3/A4/F2-1，健康态可周期性检测）healthy 分支**必须** `clearDedupe`——否则条件恢复后复发被旧键静默压掉；**事件驱动失败告警型**（F1-4 deploy 回调失败 / F2-4 chain fail-open / H1 watchdog 自愈）无稳定的「healthy」轮询点，**固定指纹 + 有界 TTL 封顶即足、不强求清键**——各自自带收敛（deploy 防 flap 抖动靠 TTL 周节奏、chain 恢复即写链使下次 `Chain::exists()` 短路 send 不可达、watchdog 自愈后 status→failed 早返且内容指纹含 pid 新进程必变）
- **Builder 净化管线（消费方适配须知）**：details 经 `filterDetails` 四道机制——敏感键 denylist `/token|secret|password|passwd|key|private|pem|cert|hmac|apiclient|credential|authorization|bearer|sign/i`（键名命中 → 值掩码 `***`、键保留便于定位）→ 非标量 → `[filtered:non-scalar]` 占位 → 值内容 PEM（`-----BEGIN|PRIVATE KEY`）/ JWT（`eyJ` 开头且 ≥40 字）整值掩码 → 单值 >200 字截断、键数 >20 截断加 `_truncated`；title ≤100 / message ≤500，模板一律 Blade `{{ }}` 转义。**写新消费方时**：details 键名避开上述子串（否则正常运维键被掩码丢信息）、嵌套结构拍平为标量串、长样本预压 200 字内（E6 `SAMPLE_LIMIT=5` 即此因）、键数 ≤20；测试用 `assertSystemAlertDetailsSafe()`（tests/Pest.php，反射读 Builder 私有常量、与实现同源）做回归护栏
- **与 ChannelManager 的关系**：SystemAlert 构造 intent 后全权交 NotificationCenter/ChannelManager 扇出，依赖「ChannelManager 绑 singleton」红线——非单例时插件通道对运维告警同样端到端静默失效

## 插件接入主系统的全部触点（主系统对插件的承诺仅此）

1. ServiceProvider 里 `app(ChannelManager::class)->register('feishu', new FeishuChannel)`
2. 实现 `ChannelInterface`：`send(Notification): array` + `isAvailable(): bool` + `shouldSend(Model $notifiable, string $code): bool`
3. 用户偏好/UI/模板全部由插件自治：自己加表/字段读偏好，主系统不预留 schema/UI/API 钩子，不加 widget 插槽

## 携密 / 附件安全（job 边界）

- ① `NotificationJob implements ShouldBeEncrypted`——携密 context（如 user_created 密码经 `NotificationIntent.context` → Job 构造参数序列化）用 APP_KEY 加密整个 job payload，防明文落 `jobs`（执行前窗口）/`failed_jobs`（长期）表；`transient` 只防 `notifications.data`，二者互补。② 多通道临时附件：Builder 按通道各 build 一次，附件类 payload（CertIssued 含私钥证书 ZIP）每通道各生成一份，`NotificationJob::handle` 末尾统一清理 `data._meta.cleanup_paths`（覆盖所有通道 + 发送失败路径、与 MailChannel 内清理幂等），防非 mail 通道（插件注入）临时文件泄漏
- **`DefaultNotificationBuilder` 兜底安全约定**：未配置 builder 的 code（Admin 测试通知 / 插件自定义 code）走 `DefaultNotificationBuilder`，**直通 `$intent->context` 入库**，不做敏感字段过滤。调用方需自律 — context 不传 password/token/secret/api_key/private_key 等字段，否则会明文存入 `notifications.data` 列并随通道转发外部。**例外**：`user_created`（携初始密码）已注册专用 `UserCreatedNotificationBuilder`，把密码走 `NotificationPayload.transient`（仅渲染入邮件、不入库），不回落 Default；新增携密 code 同样必须走专用 Builder + transient，不可依赖 Default。**`security`**（账号改密/重置提醒，`User\AuthController::updatePassword`/`resetPassword` 事务提交后触发）虽不携密，也用专用 `SecurityNotificationBuilder` 白名单 `username`/`event`/`email` 入库（纯文本 `is_html=false`），避免调用方误把敏感字段塞进 context 被 Default 直通；`event` 仅传安全事件可读描述，不含凭据

## 续期停滞孤儿提醒（cert_renew_stalled，P0-1 包 X）

续费/重签把前驱证书终态化（renewed/reissued）后，接替证书长期卡在非 active 停滞态、前驱即将到期。`cert_expire` 对 renewed/reissued 前驱抑制、`AutoRenewCommand` 因 active 前置不再处理 → 本提醒是唯一止血。检测形态源与「证书为轴前驱侧扫描」见 `skills/backend/auto-renew.md`；此处记通知三件套侧。

- **三件套**：`config/notification.builders['cert_renew_stalled' => CertRenewStalledNotificationBuilder]` + seeder 模板（`variables: [username,email,certificates]`，`site_url/site_name` 由 Builder 从系统设置注入）+ 专用 Builder。`ExpireCommand` 派发侧经 `StalledRenewalQuery::forDispatch()` 取 distinct `order.user_id` 逐 user `dispatch('cert_renew_stalled')`（additive 分支，既有 active 到期查询一字不改、零回归）。
- **强制发（不入 `user_default_preferences`）**：涉及服务中断风险，穿透用户可能已关的常规到期偏好。机制是**隐式**——`User::allowsNotification($code)` 对 notification_settings 里**缺席**的 code 返回默认 `true`，故不把该 code 铺进用户偏好 UI = 永远不写入 settings = 恒发（同 `balance_forecast`/`auto_renew_failed` 范式）。
- **双侧同源（防「派发了 user、Builder 重查为空 → 静默漏发」）**：派发侧 `forDispatch`（前驱 expires_at 离散节点窗口 14/7/3/1）与重查侧 `forUser`（连续 14 天超集窗口，防 NotificationJob 异步延迟跨窗漏发）共用 `StalledRenewalQuery` 单一形态；`SUCCESSOR_STALLED_STATUSES` 5 态常量 `public`，Builder 重查后对预载 `nextCert` 再判一次停滞态白名单（复用同一真相源、禁手写第二份清单，兜「主查询通过后 nextCert 预载前」毫秒级 race）。
- **5 态可行动文案**（`actionHint`，模板只渲染不做逻辑）：`unpaid` 中性化（未扣费、不硬承诺去支付，避免与 O4 自动清理冲突）；`pending`/`processing`/`approving` 已扣费（勿重复支付）；`failed` 指「重新购买」（failed/renewed/reissued 三态均进不了 renew/reissue gate、唯一动作是另开新单）。携密不入库（仅域名/日期/停滞标签/文案）。

## NotificationJob 失败重试分档（M4）

`NotificationJob`（`implements ShouldQueue`）对发送失败按**瞬态可重试 / 永久不重试**分档，改动点在 Job + MailChannel 返回值，**不动 MailChannel 发信主体**。

- **`tries=5` + `maxExceptions=1`**（幂等 ShouldQueue 约定，见 CLAUDE.md）：tries=5 给 `SkipWhenUpgradeFrozen` 的 `release(60)`（freeze 每分钟烧 1 个）留余量；瞬态重试走 `retryDelay()` backoff `[60,300,300,300]`。maxExceptions=1 只对「真·未捕获异常」（如建行时 DB 挂）快失败——本设计瞬态路径 catch 后 release/fail 均不抛，正是意图。
- **retryable 契约**：`ChannelInterface::send` 返回 `array{code,msg?,retryable?}`；MailChannel 判档——**永久（retryable=false）**：空邮箱 / 未配置 / 附件问题（防新装机 failed_jobs 风暴 + CertIssued 每轮重生成含私钥 ZIP）；**瞬态（retryable=true）**：SMTP send 失败 / 发送异常（下轮 build 可自愈）。缺省不含该键（成功 code=1 / 插件通道）→ Job 视作 false（不重试）安全。
- **分档收敛**：成功 / 永久失败 → 落 FAILED 行 + 清 build 产物 + `return`（不 release 不 throw）；瞬态失败 → 先清 build 产物（**cleanup-before-release**：防含私钥 ZIP 逐轮泄漏，下轮 handle 重跑 build 重生成）→ 未到上限 `release(retryDelay())`、末轮交 `fail()` 标终态 + `Log::error` 前置。
- **行复用启发式（零迁移）+ 局限**：仅重试轮（`attempts()>1`）复用同接收者+模板+近 1h 的 sending/failed 行（按 `getMorphClass()` FQCN 定位，走 morphs+template_id+status 索引），避免「重试 N 次 = N 行」。**局限（观察项登记）**：`notifications` 表无 channel 列，多通道并存时可能跨通道复用行（mail 重试复用插件通道行）；当前基座 mail-only 该局限休眠，引入插件通道时升级为 `idempotency_key`（含 channel）+ 唯一索引。
- **测试注入缝**：`MailChannel::makeMail()`（子类覆盖注入 mock，避免真实 SMTP）。`NotificationJobTest` 断言瞬态 release / 永久 FAILED / 末轮 fail() / build 产物每轮清理。
