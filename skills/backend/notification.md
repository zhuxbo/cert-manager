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

## 插件接入主系统的全部触点（主系统对插件的承诺仅此）

1. ServiceProvider 里 `app(ChannelManager::class)->register('feishu', new FeishuChannel)`
2. 实现 `ChannelInterface`：`send(Notification): array` + `isAvailable(): bool` + `shouldSend(Model $notifiable, string $code): bool`
3. 用户偏好/UI/模板全部由插件自治：自己加表/字段读偏好，主系统不预留 schema/UI/API 钩子，不加 widget 插槽

## 携密 / 附件安全（job 边界）

- ① `NotificationJob implements ShouldBeEncrypted`——携密 context（如 user_created 密码经 `NotificationIntent.context` → Job 构造参数序列化）用 APP_KEY 加密整个 job payload，防明文落 `jobs`（执行前窗口）/`failed_jobs`（长期）表；`transient` 只防 `notifications.data`，二者互补。② 多通道临时附件：Builder 按通道各 build 一次，附件类 payload（CertIssued 含私钥证书 ZIP）每通道各生成一份，`NotificationJob::handle` 末尾统一清理 `data._meta.cleanup_paths`（覆盖所有通道 + 发送失败路径、与 MailChannel 内清理幂等），防非 mail 通道（插件注入）临时文件泄漏
- **`DefaultNotificationBuilder` 兜底安全约定**：未配置 builder 的 code（Admin 测试通知 / 插件自定义 code）走 `DefaultNotificationBuilder`，**直通 `$intent->context` 入库**，不做敏感字段过滤。调用方需自律 — context 不传 password/token/secret/api_key/private_key 等字段，否则会明文存入 `notifications.data` 列并随通道转发外部。**例外**：`user_created`（携初始密码）已注册专用 `UserCreatedNotificationBuilder`，把密码走 `NotificationPayload.transient`（仅渲染入邮件、不入库），不回落 Default；新增携密 code 同样必须走专用 Builder + transient，不可依赖 Default。**`security`**（账号改密/重置提醒，`User\AuthController::updatePassword`/`resetPassword` 事务提交后触发）虽不携密，也用专用 `SecurityNotificationBuilder` 白名单 `username`/`event`/`email` 入库（纯文本 `is_html=false`），避免调用方误把敏感字段塞进 context 被 Default 直通；`event` 仅传安全事件可读描述，不含凭据
