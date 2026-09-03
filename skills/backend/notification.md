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
- **取消/重发等 Admin 操作**：测试通知 `/api/admin/notification/test-send` 和重发 `/api/admin/notification/resend/{id}` 不再传 `channels` 入参（已删 sanitizeChannels）；发送会广播到所有已注册可用 channel

## 系统告警（SystemAlert，运维/健康 admin 告警共享件）

`App\Services\Notification\SystemAlert`：把「运维告警」标准化为一次经 `NotificationCenter` 的 `system_alert` 通知投递，附 Cache 状态指纹去重防持续异常态刷屏。只做传输层，聚合/阈值判定归调用方；异步加密（ShouldBeEncrypted）、afterCommit、`onQueue('notifications')` 全由 `NotificationJob` 继承。消费方：监控命令群、AutoRenew A4 缺价（`missing_price`）、部署失败完整小时聚合（`deploy_failure`）、F2-4 证书链校验（`Order/Action.php:773/793`）、H1 升级 watchdog、H3 备份失败；**P0 批次新增**：M7 上游连通性（`ca_connectivity`/`ca_outage`）、T1 僵尸任务转人工（`task_stale`）、T5 卡单每日快照（`reconcile_maxed`）、T6 ACME 卡单转人工（`acme_reconcile`）。`ValidateCommand` 的 dnsTools 全挂只保留 order 级 sync 安全网，不发送管理员告警；传统订单与 ACME 退款失败均不使用专属 `SystemAlert`，由事务回滚、任务失败留痕和每日资金对账兜底；产品导入和委托周巡检均不使用 `SystemAlert`。**本体三件套（`SystemAlert` / `SystemAlertNotificationBuilder` / `config/notification.builders` 注册）契约冻结，新消费方只适配、不改造本体。**

- **退款失败的通用通知**：传统订单与 ACME 的异步取消/同步最终失败统一由 `TaskJob::failed` 发送 `task_failed` 管理员通知；这不是退款专属 `SystemAlert`。资金侧另有事务回滚和每天 03:00 的 `finance:audit` 对账兜底。
- **取消失败才告警**：不再按 `validation_type` 对 `processing/approving` 做独立 7/14/21 天卡单扫描；退款期自动取消由 `schedule:purge` 先执行，进入 `cancel` 任务后仅真实取消/退款未完成才由上述 `task_failed` 告警，已终态或已退款的幂等拒绝不告警。
- **支付证书健康检查**：`schedule:payment-health` 只检查已配置证书的解析结果与到期时间；空证书字段表示该证书能力未启用，不作为配置缺失告警。检查源与支付 SDK 保持一致：已存在 `storage/pay` 落盘证书时以该文件为准，文件尚未生成时才回退设置值；支付宝 RSA2 公钥按 SDK 验签公钥口径检查，根证书按 SDK 的 bundle 口径逐张解析但不做到期预警。渠道当前可充值不等于证书未临近到期，提前 30 天预警仍保留。
- **契约签名**：`send(string $category, string $title, string $message, array $details = [], ?string $dedupeKey = null, int $dedupeTtlHours = 24, ?string $fingerprint = null): bool`（返回 false = 去重跳过 / 无 admin / dispatch 失败）+ `clearDedupe(string $dedupeKey): void`
- **五步时序（勿改序）**：① 去重预检（`system_alert:{dedupeKey}` 键值 === 指纹 → 不发）→ ② 解析 admin（有 `site.adminEmail` 时它始终作为目标邮箱，优先绑定同邮箱 Admin、否则任取一条 Admin 作为通知归属；未配置时跳过空邮箱账号，取首个有效 Admin 邮箱；无归属 Admin/目标邮箱 → Log::warning 返回 false、**不占键**）→ ③ 写端把 `admin_email = $targetEmail` 显式写入 intent context（镜像 `FundAuditCommand` 写端；Admin 专用 Builder 和 MailChannel 必须允许 `site.adminEmail` 运维别名覆盖登录账号邮箱）→ ④ dispatch（失败只 Log::warning、**不占键**——否则整个 TTL 静默丢告警）→ ⑤ `DB::afterCommit` 置键（键值=指纹快照；无事务时立即执行，事务内调用回滚时键随 NotificationJob 一起丢弃、语义对齐）
- **固定指纹 vs 内容指纹**：`fingerprint=null` → 内容指纹 `sha1(json([category,title,message,details]))`——同一故障内容恒定则 TTL 内一封、**新增异常项指纹变则立即再发**（E1 凭证 / E3 支付证书这类集合型用它）；**计数/波动型（卡单数、偏差秒数、失败计数、outage 轮数）必须传固定指纹**，否则每轮计数变化翻新内容指纹击穿去重 → 刷屏（in-tree 范式：AutoRenewCommand `'missing'`、E4 `'clock_skew'`、E5 `'threshold_exceeded'`、M7 `'ca_outage'`、T1 `'swept_exhausted_{id}'`、T6 `'acme_maxed_{id}'`、W 委托巡检 `'patrol_outage'`）
- **每日快照日期指纹（T5，吸收 M5）**：卡单转人工 admin 汇总 `reconcile_maxed` 用 **fingerprint 含当天日期** `'maxed_summary_'.now()->toDateString()`——既非纯固定（否则 SMTP 瞬断丢首封后整期静默）、亦非内容指纹（否则每轮卡单集变化刷屏）。语义：每天首个非空轮发一封含当天全部转人工单，同日不重发、次日指纹变化必发（含存量+新增），新增单最坏延迟 1 天。原「reconcile 去重标记带 TTL 24h（M5）」由本机制单源吸收
- **M7 上游整体连通性（`CaHealthcheckCommand` 连通性维度）**：与凭证维度（`ca_credentials`，主信号 401/403 + 辅助 Unauthorized）分离。连接超时/请求失败/5xx 等 `code=0` 非鉴权失败走连通性——`Cache::forever` 跨 cron 周期**累计连续失败计数**，达 `connectivity_threshold`（默认 3，3×15min=45min 滤上游滚动重启瞬断）才发 SystemAlert，**固定指纹 `ca_outage`**（计数型防 churn 击穿）+ TTL `connectivity_ttl_hours`（默认 6h ≥ 3×45min）。**healthy / 上游可达分支**（code=1 或返回鉴权错误=上游可达）必须重置：清计数 + `clearDedupe(ca_connectivity)`（否则恢复后复发被旧键静默压掉）。**未配置态**（`'Api url or token is not set'`）Log::info 剔出、不占键不动计数（新装/测试实例把「没填」当「失效」是狼来了）
- **TTL 语义**：固定指纹场景 TTL = 同一持续异常的**重复提醒间隔**（不是缓存过期直觉），按场景重要性定；契约要求 ≥3× 巡检周期（防 TTL≈周期时去重形同虚设）。**备份失败（日频 cron）用 24h=每天一封是有意偏离**：备份连续失败不应静默 3 天，主控书面选择
- **恢复清键分两类（选型须知）**：**level/状态监控型**消费方（E1/E3~E5/H3/A4/W 委托巡检，健康态可周期性检测）healthy 分支**必须** `clearDedupe`——否则条件恢复后复发被旧键静默压掉；**窗口聚合或事件驱动告警型**（部署失败小时窗口 / F2-4 chain fail-open / H1 watchdog 自愈）无稳定的「healthy」轮询点，不要求清键：部署聚合靠窗口指纹保证每小时至多一封，chain 恢复即写链使下次 `Chain::exists()` 短路 send 不可达，watchdog 自愈后 status→failed 早返且内容指纹含 pid 新进程必变。
- **Builder 净化管线（消费方适配须知）**：details 经 `filterDetails` 四道机制——敏感键 denylist `/token|secret|password|passwd|key|private|pem|cert|hmac|apiclient|credential|authorization|bearer|sign/i`（键名命中 → 值掩码 `***`、键保留便于定位）→ 非标量 → `[filtered:non-scalar]` 占位 → 值内容 PEM（`-----BEGIN|PRIVATE KEY`）/ JWT（`eyJ` 开头且 ≥40 字）整值掩码 → 单值 >200 字截断、键数 >20 截断加 `_truncated`；title ≤100 / message ≤500，模板一律 Blade `{{ }}` 转义。**写新消费方时**：details 键名避开上述子串（否则正常运维键被掩码丢信息）、嵌套结构拍平为标量串、长样本预压 200 字内、键数 ≤20；测试用 `assertSystemAlertDetailsSafe()`（tests/Pest.php，反射读 Builder 私有常量、与实现同源）做回归护栏
- **与 ChannelManager 的关系**：SystemAlert 构造 intent 后全权交 NotificationCenter/ChannelManager 扇出，依赖「ChannelManager 绑 singleton」红线——非单例时插件通道对运维告警同样端到端静默失效

## 插件接入主系统的全部触点（主系统对插件的承诺仅此）

1. ServiceProvider 里 `app(ChannelManager::class)->register('feishu', new FeishuChannel)`
2. 实现 `ChannelInterface`：`send(Notification): array` + `isAvailable(): bool` + `shouldSend(Model $notifiable, string $code): bool`
3. 用户偏好/UI/模板全部由插件自治：自己加表/字段读偏好，主系统不预留 schema/UI/API 钩子，不加 widget 插槽

**前置门（引入第二通知通道前必须先解决）**：`notifications` 表当前无 channel 维度，「行复用启发式」按「接收者+模板+时间窗+context 指纹」定位通知行（实体维度已由 context 指纹隔离，见「NotificationJob 失败重试分档」段）——但仍缺 **channel 维度**，引入第二通知通道前**必须先解决**（届时评估「加 channel 列 + 含 channel 的唯一定位索引」vs「插件通道自治映射各自建表」两方案），否则 mail 重试会跨通道复用错行、覆盖插件通道记录。当前 mail-only 基座该局限休眠，不预建列（YAGNI）。

## 携密 / 附件安全（job 边界）

- ① `NotificationJob implements ShouldBeEncrypted`——携密 context（如 user_created 密码经 `NotificationIntent.context` → Job 构造参数序列化）用 APP_KEY 加密整个 job payload，防明文落 `jobs`（执行前窗口）/`failed_jobs`（长期）表；`transient` 只防 `notifications.data`，二者互补。② 多通道临时附件：Builder 按通道各 build 一次，附件类 payload（CertIssued 含私钥证书 ZIP）每通道各生成一份，`NotificationJob::handle` 末尾统一清理 `data._meta.cleanup_paths`（覆盖所有通道 + 发送失败路径、与 MailChannel 内清理幂等），防非 mail 通道（插件注入）临时文件泄漏
- **`DefaultNotificationBuilder` 兜底安全约定**：未配置 builder 的 code（Admin 测试通知 / 插件自定义 code）走 `DefaultNotificationBuilder`，**直通 `$intent->context` 入库**，不做敏感字段过滤。调用方需自律 — context 不传 password/token/secret/api_key/private_key 等字段，否则会明文存入 `notifications.data` 列并随通道转发外部。**例外**：`user_created`（携初始密码）已注册专用 `UserCreatedNotificationBuilder`，把密码走 `NotificationPayload.transient`（仅渲染入邮件、不入库），不回落 Default；新增携密 code 同样必须走专用 Builder + transient，不可依赖 Default。**`security`**（账号改密/重置提醒，`User\AuthController::updatePassword`/`resetPassword` 事务提交后触发）虽不携密，也用专用 `SecurityNotificationBuilder` 白名单 `username`/`event`/`email` 入库（纯文本 `is_html=false`），避免调用方误把敏感字段塞进 context 被 Default 直通；`event` 仅传安全事件可读描述，不含凭据

## 续期停滞孤儿提醒（cert_renew_stalled，P0-1 包 X）

续费/重签把前驱证书终态化（renewed/reissued）后，后续证书长期卡在非 active 停滞态、前驱即将到期。`cert_expire` 对 renewed/reissued 前驱抑制、`AutoRenewCommand` 因 active 前置不再处理 → 本提醒是唯一止血。检测形态源与「证书为轴前驱侧扫描」见 `skills/backend/auto-renew.md`；此处记通知三件套侧。

- **三件套**：`config/notification.builders['cert_renew_stalled' => CertRenewStalledNotificationBuilder]` + seeder 模板（`variables: [email]`，仅保留测试发送可覆盖的收件邮箱；`username/certificates/site_url/site_name` 由 Builder 查询或注入）+ 专用 Builder。每个 `certificates[]` 从前驱 `order.product` 注入归一化后的 `product_type` / `product_type_label`；`ExpireCommand` 派发侧经 `StalledRenewalQuery::forDispatch()` 取 distinct `order.user_id` 逐 user `dispatch('cert_renew_stalled')`（additive 分支，既有 active 到期查询一字不改、零回归）。
- **强制发（不入 `user_default_preferences`）**：涉及服务中断风险，穿透用户可能已关的常规到期偏好。机制是**隐式**——`User::allowsNotification($code)` 对 notification_settings 里**缺席**的 code 返回默认 `true`，故不把该 code 铺进用户偏好 UI = 永远不写入 settings = 恒发（同 `balance_forecast`/`auto_renew_failed` 范式）。
- **双侧同源（防「派发了 user、Builder 重查为空 → 静默漏发」）**：派发侧 `forDispatch`（前驱 expires_at 离散节点窗口 14/7/3/1）与重查侧 `forUser`（连续 14 天超集窗口，防 NotificationJob 异步延迟跨窗漏发）共用 `StalledRenewalQuery` 单一形态；`SUCCESSOR_STALLED_STATUSES` 5 态常量 `public`，Builder 重查后对预载 `nextCert` 再判一次停滞态白名单（复用同一真相源、禁手写第二份清单，兜「主查询通过后 nextCert 预载前」毫秒级 race）。
- **5 态可行动文案**（`actionHint`，模板只渲染不做逻辑）：`unpaid` 中性化（未扣费、不硬承诺去支付，避免与 O4 自动清理冲突）；`pending`/`processing`/`approving` 已扣费（勿重复支付）；`failed` 指「重新购买」（failed/renewed/reissued 三态均进不了 renew/reissue gate、唯一动作是另开新单）。携密不入库（仅域名/日期/停滞标签/文案）。

## 续签订单取消一次性提醒（cert_renew_cancelled）

续费/重签订单在已提交上游（processing/approving，含已签发 active）状态被取消后，前驱证书（renewed/reissued 终态）就此脱离 `cert_expire` / `AutoRenew` / `cert_renew_stalled` 三重监控——原证书物理上仍在有效期服役、却不再收到任何到期/续期提醒。本一次性通知是唯一止血：告知用户续签订单已取消、原证书不再受续期监控，如需继续使用请手动续期。触发形态源（`cancelLocked` 非恢复分支，或启用 `autoRefundOnSync` 后 `refundForSyncedCancel` 终结续费单；均要求有前驱）见 `skills/backend/order-fund.md`；此处记通知三件套侧。

- **三件套**：`config/notification.builders['cert_renew_cancelled' => CertRenewCancelledNotificationBuilder]` + seeder 模板（`variables: [email,common_name,expires_at,order_id,product_type]`；`username/product_type_label/site_url/site_name` 由 Builder 注入）+ 专用 Builder。
- **一次性事件驱动（≠ cert_renew_stalled 周期重查）**：数据在取消发生时即确定、前驱处终态不再变动，派发点从前驱 `order.product` 取得 `product_type`，Builder 只读包含该字段的 context 白名单标量、不重查 DB（cert_renew_stalled 走 `StalledRenewalQuery` 周期重查，是因停滞态随时间演进）。
- **触发点完整**：`Action::cancelLocked` 非恢复分支对有前驱的 renew + reissue **对称派发**；启用 `autoRefundOnSync` 后，`refundForSyncedCancel` 对有前驱的 renew 同样派发。两条路径均由 `NotificationCenter` afterCommit 投递；plain new（`last_cert_id=null`、无前驱）不派。
- **强制发（不入 `user_default_preferences`）**：涉及服务连续性风险，穿透用户可能已关的常规到期偏好；机制同 cert_renew_stalled——code 不铺进用户偏好 UI = 永不写入 settings = `User::allowsNotification` 对缺席 code 恒返回默认 `true` 恒发（与 cert_renew_stalled 成对）。
- **携密白名单**：派发点提供前驱标识 `common_name` / 到期日 `expires_at` / 订单号 `order_id` / 前驱产品类型 `product_type`；专用 Builder 只逐键取用这些业务标量及可选 `email`，**绝不整包直通 `$intent->context`**。config 注册专用 Builder、不回落 `DefaultNotificationBuilder`（Default 直通红线见「携密 / 附件安全」段）。

## 证书吊销一次性提醒（cert_revoked）

Order sync 发现上游把证书同步为 `revoked` 终态时触发。被吊销证书立即失去 CA 信任：SSL 可能导致浏览器拦截 HTTPS，其他类型可能影响身份验证、签名或加密业务，用户须知悉并按需重新申请。原 sync 通用写回落 revoked 仅触发 callback + deleteTask、**零通知**（可见性洞）；本通知补齐。触发形态源（`Order/Action::sync` 通用写回分支，锁内终态守卫之后、与 `cert_renew_cancelled` 分支并列）见 `skills/backend/order-fund.md`；此处记通知三件套侧。

- **三件套**：`config/notification.builders['cert_revoked' => CertRevokedNotificationBuilder]` + seeder 模板（`variables: [email,common_name,expires_at,order_id,is_successor,product_type]`；`username/product_type_label/site_url/site_name` 由 Builder 注入）+ 专用 Builder。派发经 `Action::dispatchRevokedNotification`。
- **吊销事件本位（宽口径 P2）**：对**所有** revoked（含 plain new）派发——吊销是独立于续费的重大服务中断事件，plain new 证书被吊销时用户同样须知悉。主体为**被吊销证书自身**（`common_name`/`expires_at` 取写回目标 `$cert`，`product_type` 取该证书当前订单产品，≠ cert_renew_cancelled 取前驱）；`is_successor`（`(bool) $cert->last_cert_id`）标记被吊销的是否续费/重签后续证书，为 true 时前驱亦脱离 `cert_expire`/`AutoRenew`/`cert_renew_stalled` 三重监控 → 模板 `@if($is_successor)` 附带「原证书亦不再受监控、请手动续期」文案（只影响文案、不影响是否发）。
- **一次性事件驱动**：数据在吊销发生时即确定、终态不再变动，Builder 直接读派发点 context 白名单标量、不重查 DB（同 cert_renew_cancelled 范式）。
- **强制发（不入 `user_default_preferences`）**：吊销属服务中断类事件，穿透用户可能已关的常规到期偏好；机制同 cert_renew_cancelled/cert_renew_stalled——code 不铺进用户偏好 = `User::allowsNotification` 对缺席 code 恒返回默认 `true` 恒发。
- **防重靠 hasStatusChanged**：分支必须落在 `runTaskMutationTransaction` 闭包内、终态守卫之后——二次 force-sync 已 revoked 的订单，终态守卫 `unset($data['status'])` → `hasStatusChanged=false` → 不再派发（与 cert_renew_cancelled 同一防重路径，放守卫外会重复发）。与 cancelled 分支按 status 值天然互斥、不双发。revoked 从不进 `refundForSyncedCancel`（后者强制 cancelled），故通用写回单点覆盖全部 revoked。
- **携密白名单**：仅 `common_name`/`expires_at`/`order_id`/`is_successor`（bool 结构标志）/`product_type`；专用 Builder 逐键取用、config 注册**不回落 `DefaultNotificationBuilder`**（Default 直通红线）。
- **Acme 侧不适用**：ACME 无续签前驱链、自身不直发证书（certbot 发），`Acme\Action` 落 revoked 不需本通知。**仅 Order 侧**。

## cert_issued 产品范围与 S/MIME 附件

- **派发白名单**：系统 `cert_issued` 只允许 SSL、S/MIME。`Action::sync` 在锁内按订单产品类型决定是否派发；`send-active` 后端接口同样强制白名单。CodeSign/DocSign 不自动派发、不允许手工发送，Builder 对历史在途 Job 返回 `null` 安全跳过。
- **产品类型展示**：`cert_issued` 单封标题展示 SSL 或 S/MIME；`cert_expire`、`cert_renew_stalled` 汇总逐行展示类型与证书标识；`cert_renew_cancelled`、`cert_revoked` 单封标题展示类型。支持 `ssl`、`smime`、`codesign`、`docsign`，历史 `null`、未知值和缺少新增模板变量的旧队列载荷均回落为 SSL。Seeder 一律 `firstOrCreate`，不覆盖管理员自定义模板。
- **S/MIME 交付附件**：S/MIME 签发邮件与默认 `all` 下载共用打包层，ZIP 只包含 PEM（证书链 + 匹配私钥）和 PFX（3DES/SHA1 兼容策略）及 `password.txt`。PFX 密码为六位安全随机字母数字，排除易混字符并保证同时含字母、数字。
- **PFX 密码仅随 ZIP 交付**：Builder 把同一密码写入 PFX 与 ZIP 内的 `password.txt`，邮件正文、主题及 `NotificationPayload::transient` 均不得包含该密码。OpenSSL 必须用参数数组 + `-passout stdin` 从管道取密码，禁放 shell、argv、环境变量，stderr 记录前须净化；密码也不得进入 `notifications.data` 或日志。
- **错误分档与清理**：证书、链、私钥缺失或不匹配、OpenSSL 缺失/算法或输入确定性失败属于永久构建失败；mkdir、临时文件短写/刷新/关闭、OpenSSL 输出 ENOSPC、ZIP open/close 等 IO 失败抛 `TransientBuildException`。临时文件必须循环写满并校验完整字节数；每张证书使用独占 0700 工作目录，私钥文件 0600，所有路径由外层在成功发送、永久失败或重试前统一清理，异常路径先关闭 ZIP 再删除目录。
- **默认模板仅首次创建**：签发模板正文使用产品无关文案；Seeder 不迁移或覆盖任何存量模板，管理员需要时可自行采用新默认内容。

## NotificationJob 失败重试分档（M4）

`NotificationJob`（`implements ShouldQueue`）对发送失败按**瞬态可重试 / 永久不重试**分档，改动点在 Job + MailChannel 返回值，**不动 MailChannel 发信主体**。

- **`tries=5` + `maxExceptions=1`**（幂等 ShouldQueue 约定，升级冻结机理见 `skills/backend/upgrade.md`）：tries=5 给 `SkipWhenUpgradeFrozen` 的 `release(60)`（freeze 每分钟烧 1 个）留余量；瞬态重试走 `retryDelay()` backoff `[60,300,300,300]`。maxExceptions=1 只对「真·未捕获异常」（如建行时 DB 挂）快失败——本设计瞬态路径 catch 后 release/fail 均不抛，正是意图。
- **retryable 契约**：`ChannelInterface::send` 返回 `array{code,msg?,retryable?}`；MailChannel 判档——**永久（retryable=false）**：空邮箱 / 未配置 / 附件问题（防新装机 failed_jobs 风暴 + CertIssued 每轮重生成含私钥 ZIP）；**瞬态（retryable=true）**：SMTP send 失败 / 发送异常（下轮 build 可自愈）。缺省不含该键（成功 code=1 / 插件通道）→ Job 视作 false（不重试）安全。
- **分档收敛**：成功 / 永久失败 → 落 FAILED 行 + 清 build 产物 + `return`（不 release 不 throw）；瞬态失败 → 先清 build 产物（**cleanup-before-release**：防含私钥 ZIP 逐轮泄漏，下轮 handle 重跑 build 重生成）→ 未到上限 `release(retryDelay())`、末轮交 `fail()` 标终态 + `Log::error` 前置。
- **行复用启发式（零迁移）+ context 指纹隔离**：仅重试轮（`attempts()>1`）复用同接收者+模板+近 1h+**同 context 指纹**的 sending/failed 行（按 `getMorphClass()` FQCN 定位走 morphs+template_id+status 索引，指纹在 PHP 侧比对），避免「重试 N 次 = N 行」。**context 指纹（notify ④，零迁移）**：`contextFingerprint()` 剔除携密键（`password`/`token`/… denylist，护「携密不入库」不把凭据喂进可离线爆破的 md5）后取 `md5(json(context))`、落 `data._meta.context_fingerprint`，把复用严格限定为「同一 job 自身前序行」——否则同接收者近 1h 内另一实体（如另一张证书的 `cert_issued`）的 sending/failed 行会被重试轮误认领、整包覆写并按本 job 结果标记（抹除对方失败可见性、admin resend 重发错实体；`failed()` 亦误清兄弟在途行的 `cleanup_paths` 含私钥 ZIP）。**剩余局限（观察项）**：`notifications` 表无 channel 列，多通道并存时可能跨通道复用行（与实体维度正交）；当前基座 mail-only 休眠，引入插件通道时升级为 `idempotency_key`（含 channel）+ 唯一索引（引入前置门见「插件接入主系统的全部触点」段）。
- **测试注入缝**：`MailChannel::makeMail()`（子类覆盖注入 mock，避免真实 SMTP）。`NotificationJobTest` 断言瞬态 release / 永久 FAILED / 末轮 fail() / build 产物每轮清理。

## NotificationJob build 阶段失败可见性（包V，M4 姊妹）

M4 分档在 **send 阶段**，管不到 **build 阶段**（Builder 产出 payload，`cert_issued` 含证书 ZIP 生成）。原 build catch 直接 `return`——通知记录根本不建、不重试，签发成功但交付邮件静默缺失时 `notifications` 表无行、admin 列表看不到（可见性洞）。包V 把 build 失败也纳入分档 + 落 FAILED 记录。

- **`TransientBuildException`**（`App\Services\Notification\Exceptions\`，`extends RuntimeException` 标记类）：build catch 用 `instanceof` 判档（类型安全，不做异常消息字符串匹配）。**瞬态**（IO/磁盘满，恢复后重跑 build 自愈，唯一命中 `CertIssuedNotificationBuilder`）：未达 `tries` → `release(retryDelay())` 沿用 M4 backoff、**不落记录**（前几轮不留行）；末轮 → 落 FAILED 记录 + `fail()`。**永久**（数据/校验错，其余 11 个 Builder 的 build 失败）→ 落 FAILED 记录 + warning、不重试。attempts 预算与 send 阶段、`SkipWhenUpgradeFrozen` 的 `release(60)` 共享 `tries=5`（freeze 期压缩瞬态重试窗，最坏况仍可见 FAILED 行）。
- **ZipArchive 静默 false 检测（磁盘满头号形态）**：`ZipArchive::open()/close()` 返 bool/错误码、**不抛异常**；`addFromString` 仅缓存在内存、`close()` 才压缩刷盘——真·磁盘满最常在 `close()` 写盘期静默返回 false，不检查返回值会静默产空/残 ZIP 标 SENT。`CertIssuedNotificationBuilder` 对 `open()`/`close()` 显式 `!== true` 检查、非成功即抛 `TransientBuildException`（落既有 try 的 catch 统一自清 tempDir 后重抛）。**测试注入缝** `makeZip()`（同 `MailChannel::makeMail()` 范式，子类覆写返回 close 恒 false 的桩）。不改 `ActionFileTrait::addCertToZip` 内 `addFromString` 返回值（下载路径共用、其 false 不涉写盘，磁盘满由 `close()` 检查兜底）。
- **落 FAILED 记录不携密（`persistBuildFailure`）**：① `reason` **一律固定常量**（`BUILD_FAILED_REASON`/`BUILD_FAILED_TRANSIENT_REASON`），**绝不落异常 `getMessage()`**——build catch 是通用路径（覆盖全部 Builder 及框架底层异常），消息内容无法逐一审计（异常消息可能回显携密 context 值），常量是唯一「不做假设」的方案，对齐 send 阶段常量范式；原始 `getMessage()` 仅进 error_logs（`logException`）。② `_meta.order_id` **严格键白名单 + `is_scalar` 守卫**摘录（非敏感整型、供 admin 定位缺失邮件）；除此一键外 payload **绝不含 `$this->context` 任何字段**（`user_created` context 携密码明文），禁 `array_merge($meta, $this->context)`，新增摘录键须逐键过携密评审。③ 单写 FAILED（`createNotification` 传 `status=FAILED`，不走 PENDING→markAsFailed 两步，消除 stuck-pending 窗口）；直接新建、**不走 findReusableRow**（前几轮瞬态失败不落行、无前序行可复用，杜绝误复用同接收者近 1h 其他证书行）。④ DB 写全程 try/catch，失败降级双日志（error_logs + `Log::error`）、**不上抛**（避免 maxExceptions=1 误杀）。
- **前向约定（纵深防线）**：任何生成附件/临时文件的 Builder，其 IO 失败一律抛 `TransientBuildException`（含 ZipArchive 返回值检查），且**异常消息不得携密/PII**——`persistBuildFailure` reason 固定常量为主防线，Builder 侧不携密为纵深；且须在 build 抛异常前自清临时目录（`CertIssued` 已 `File::deleteDirectory($tempDir)`），故 build 失败无 `cleanup_paths` 可泄漏。
- **测试**：`NotificationJobTest` 断言永久/瞬态分档（release 60/300、末轮 fail、FAILED 行、无 pending 残留）、getMessage 不落库（测试 Builder 把 context 敏感值嵌异常消息、断 json 无明文——固定消息测法断言恒过=伪绿，必须敏感值入 message 才真验）、白名单摘录三形态、DB 写异常降级；`CertIssuedNotificationBuilderTest` 经 `makeZip()` 桩验 close 返 false 抛瞬态 + 子类覆写 `addCertToZip` 验 IO 异常自清 tempDir。
- **观察项**：build-failed 记录经 `persistBuildFailure` 直建、不落 `context_fingerprint`，故 send 重试轮 `findReusableRow` 的指纹过滤（notify ④）恒不命中 → **不再被误复用翻 SENT**（原「可见性记录被抹除」观察项已随实体指纹关闭）；build-failed 记录不支持专属手动重发（顶层无 order_id → resend 走永久失败 append-only 无害，补齐需存 context 破携密红线或建 idempotency 破零迁移）。
