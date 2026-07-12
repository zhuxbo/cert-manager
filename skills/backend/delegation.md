# 委托验证与 S/MIME 字段

## S/MIME 验证字段要求（Certum，防回归）

按 Certum API User Guide 5.18 + **实测**，S/MIME 四种细分字段要求 —— **关键：除 `mailbox` 外都需要联系人（contact → requestorInfo），`organization` 也不例外**：

| 类型（产品 code 含） | email | 联系人 contact | 企业 organization | 证书 CN       |
| -------------------- | ----- | -------------- | ----------------- | ------------- |
| `mailbox`            | 必填  | —              | —                 | email（自动） |
| `individual`         | 必填  | 必填           | —                 | 联系人姓名    |
| `sponsor`            | 必填  | 必填           | 必填              | 联系人姓名    |
| `organization`       | 必填  | 必填           | 必填              | 组织名        |

**`requestorInfo`（谁发起申请）≠ 证书主体（subject）**：§3.2.4「organization 仅验证 the organization、不验证 subscriber」说的是**证书主体**——org 证书 CN=组织名、主体里不放个人 givenName/surname；但请求 payload 里的 `requestorInfo`（firstName/lastName/email）是「申请人」信息，Certum 对所有非 mailbox 类型**一律强制**，与证书主体是两码事。

**踩坑（勿重蹈）**：曾误把 §3.2.4「不验证 subscriber」当成「不需要联系人」，去掉 organization 的 contact 收集 → 实测提交被 Certum 拒单（`requestorInfo/email|firstName|lastName` 缺失，错误码 1053/1054/1055），已回退。**organization 必须收联系人**，校验/组装/前端与 sponsor 一致。

落点（非 mailbox 四类一致，都要 contact）：

- 校验：`ValidatorUtil::validateSMIMEParams` 的 `case 'organization'` 校验 contact + organization（与 sponsor 同）
- 组装：`ActionTrait::getApplyInformation` 的 `$needContact` 含 `['individual','sponsor','organization']`
- 前端：`OrganizationEditor` 联系人区块对所有 SMIME（非 mailbox）/ OV·EV SSL / codesign / docsign 均显示且必填
- 上游 gateway 对端：certum `getNewParams` 对所有非 mailbox SMIME `needExtendedParams=true`，从 `contact` 构造 `requestorInfo`（firstName/lastName/email），为空则 Certum 1053/1054/1055 拒单
- 文档签名（docsign）：Certum 仅 OV 一种（无 individual/sponsor 细分），CN 可个人名或组织名，但组织验证始终必须

---

## 委托验证

### 验证方法转换

用户选择 `delegation` 验证方法时：

1. `ActionTrait::generateDcv()` 将 method 转换为 `txt`
2. 设置 `dcv['is_delegate'] = true` 和 `dcv['ca']` 标记
3. `generateValidation()` 查找用户的 CnameDelegation 记录
4. validation 数组包含 `delegation_id`、`delegation_target`、`delegation_valid`、`delegation_zone`

### 委托前缀与 exact（config 驱动）

`backend/config/delegation.php` 的 `ca_map` 按 CA 映射 `{prefix, exact}`，未知 CA 走 `default`。**`exact` 是 CA 属性而非 prefix 属性**——同一 prefix（如 `_dnsauth`）在不同 CA 下可要求不同：

| CA                                              | prefix            | exact 默认 |
| ----------------------------------------------- | ----------------- | ---------- |
| Sectigo                                         | `_pki-validation` | false      |
| Certum                                          | `_certum`         | false      |
| DigiCert/GlobalSign/TrustAsia/Sheca/CFCA/Wotrus | `_dnsauth`        | false      |
| 未知 CA（default）                              | `_dnsauth`        | false      |

- `exact=true`：精确匹配完整 FQDN，查找**拒绝回落根域**、创建用精确域名（不归一 www）。
- `exact=false`：www 归一 + 子域优先 + **回落根域**，创建用根域（一条委托覆盖所有子域）。
- **默认全 false（含 `_dnsauth` 系，为用户定稿决策）**；每家及 default 可由 `DELEGATION_<CA>_EXACT` env 覆盖为 true。
- 一律经 `CnameDelegationService::getDelegationPrefixForCa($ca)` / `isExactForCa($ca)` / `resolveZone($domain,$ca)` 派生，**禁止 `prefix === '_dnsauth'` 推断**。手动创建委托（`DelegationController` store/batchStore）入参按 CA、内部派生 prefix+zone；委托记录仍按 `(user_id, zone, prefix)` 存储（无 ca 列，列表按 prefix 筛选）。`AutoDcvTxtService` 从 DCV host 解析 zone 后用 ca 驱动 `findDelegation`（带回落），与 `ActionTrait::generateValidation` 同口径。
- **ca 取值源统一 `dcv['ca']`（创建期冻结快照）**：`AutoDcvTxtService::collectTxtRecords` 派生 prefix 的 ca 优先取 `cert.dcv['ca']`（回落 `product->ca` 兜 legacy 订单）——委托本就按创建期 `dcv['ca']` 派生的 prefix 建，若订单创建后 `product.ca` 被改指别家 CA，用实时 `product->ca` 会以新 prefix 查不到旧委托 → 静默 miss、TXT 不写。未命中一律 `Log::warning`（含 order_id/zone/domain/ca）surface 静默 miss。

> ACME 通道证书由客户端自行验证，不走委托体系，不使用 `_acme-challenge` 前缀。

### TXT 记录自动写入

**触发时机**：订单创建时，`ActionTrait::generateCsr()` 调用 `writeDelegationTxtRecords()`

**处理流程**：

1. 检查 `dcv['is_delegate'] = true`
2. 按 `delegation_id` 分组收集验证 tokens
3. 跳过无效委托（`delegation_valid = false`）或已写入的记录
4. 调用 `DelegationDnsService::setTxtByLabel()` 批量写入 TXT 记录
5. 更新 validation 中的 `auto_txt_written` 和 `auto_txt_written_at` 标记

**validation 字段说明**：

| 字段                  | 说明            |
| --------------------- | --------------- |
| `delegation_id`       | 委托记录 ID     |
| `delegation_target`   | CNAME 目标 FQDN |
| `delegation_valid`    | 委托是否有效    |
| `delegation_zone`     | 委托的根域名    |
| `auto_txt_written`    | TXT 是否已写入  |
| `auto_txt_written_at` | 写入时间        |

### 即时检测

`ValidateCommand::checkDelegationValidity()` 在验证前即时检测委托记录状态。

### Sectigo 本地 DCV 计算开关

`ActionTrait::generateDcv()` 中针对 Sectigo + cname/http/https 的本地哈希计算（CSR → DER → MD5/SHA256，拼出 `_<md5>` host 和 `<sha1>.<sha2>.<uv>.sectigo.com` value）由系统设置 `site.sectigoDcv` 控制，**默认关闭**（不入 seeder，缺失视为 false）。

- 关闭时：走 `$dcv = ['method' => $method]` 降级，dns/file 字段由上游 `/api/v2/new` 响应回填，再经 `mergeDcv()` 合并写入 cert。这是与 DigiCert/Certum 等其他 CA 一致的行为
- 开启时：本地直接算出 dns.value / file.content，订单创建即可向用户展示验证值（不必等上游回包）
- 手工开启方式：在 `settings` 表 site 组新增一行 `key=sectigoDcv, type=boolean, value=true`（无管理界面入口，按需 SQL 配置）

### DCV 数据合并

从上游 API 更新 dcv 时必须保留委托标记，使用 `ActionTrait::mergeDcv()` 方法：

```php
// 保留 is_delegate 和 ca 标记
$cert->dcv = $this->mergeDcv($result['data']['dcv'] ?? null, $cert->dcv);
```

涉及位置（`Action.php`）：

- 提交订单后更新 dcv
- 同步订单时更新 dcv
- 修改验证方法时（processing 状态）

### 前端判断逻辑

`validation.vue` 的 `getDisplayMethod()` 根据 `dcv.is_delegate` 返回验证方法：

```javascript
const getDisplayMethod = dcv => {
  if (dcv?.is_delegate) return "delegation";
  return dcv?.method;
};
```

### 委托验证自动续签数据流

```
用户创建订单（validation_method=delegation）
    ↓
ActionTrait::generateDcv()
    → method 转换为 txt
    → 设置 is_delegate=true, ca=xxx
    ↓
ActionTrait::generateValidation()
    → CnameDelegationService::findValidDelegation() 查找委托
    → 找不到则 createOrGet() 创建
    → 填充 delegation_id, delegation_target, delegation_valid
    ↓
ActionTrait::writeDelegationTxtRecords()
    → 按 delegation_id 分组
    → DelegationDnsService::setTxtByLabel() 批量写入
    → 标记 auto_txt_written=true
    ↓
订单提交到 CA（dcv.method=txt）
    ↓
ValidateCommand 定时验证
    → checkDelegationValidity() 即时检测
    → 触发 CA 验证
    ↓
证书签发完成
    ↓
[到期前 15 天] AutoRenewCommand（排除 acme 通道）
    → checkDelegationValidity() 即时检查委托有效性
    → 无有效委托 → 跳过，不发起续费/重签
    → 有有效委托 → 强制使用 delegation 验证方法
    → 重新走上述流程
```

### 委托 DNS 清理

`DelegationCleanupCommand` 每天 06:00 清理无用的委托 TXT 记录：

- **委托格式白名单（数据破坏防线）**：删除判据在 keepLabels 白名单之上前置「委托格式收敛」——只删 label 形如 **32 或 64 位 hex**（`preg_match('/^([0-9a-f]{32}|[0-9a-f]{64})$/i', ...)`，大小写不敏感）的记录。当代 `generateLabel` 恒产 32-hex，64-hex 兼容前身仓历史存量 + 迁移列注释。护住 proxyZone 下用户自放的 SPF/DKIM/`_dmarc`/apex `@`/站点验证等**非委托 TXT**（含点/下划线/非 hex 长度的名字永不进删除集），防误删破坏邮件收发/域名验证。
- **保留**：`processing`/`approving` 状态订单使用的委托记录（在途 label 恒在 keepLabels、`! contains` 结构性堵死误删方向）。
- **删除**：代理域名下**委托格式**且不在 keepLabels 的记录（孤儿委托 label 仍为 hex、表内已删 → 仍被清理，格式过滤两全不漏清）。
- **清理数据库标记**：移除已删除记录对应的 `auto_txt_written` 标记（`cleanDatabaseMarks` 全扫，含 delegation_id 指向已删委托的孤儿标记）。

### 委托健康周巡检（`delegation:check`）

`DelegationCheckCommand` 每周一 07:00 巡检全部委托，**两阶段 + 全局熔断**防 dnsTools 系统性停摆误报/误删：

- **三态探测（分档核心）**：`CnameDelegationService::probeValidity` 经 `VerifyUtil::verifyCnameDelegationDetailed` 返 `valid|invalid|unreachable`。detailed 在既有「宽松匹配」上额外暴露 `authoritative`（本轮是否拿到任一权威 DNS 答案）——任一 dnsTools 节点 `code=1`（records 为空亦算权威「无记录」）或本地 `DnsResolver::cnameRecords` 返数组（含空数组）→ authoritative；全渠道失败且本地返 null（不可达）→ 非 authoritative。**本地渠道钉死三态 `cnameRecords`（`null`=不可达 / `[]`=权威无记录 / 非空=记录列表），绝不复用把二者塌缩的 `checkCnameRecordLocal`（已删）/ `DnsResolver::cname`（保留供 F2-1 命中即用场景）**——误接即 authoritative 恒真 → 熔断/冻结整体虚设。
- **落库分档**：`applyProbeOutcome` 三态落库——`valid`→valid=true+归零；`invalid`→valid=false+`fail_count++`（硬截断 100）+固定 last_error；`unreachable`→**冻结计数**（不写 valid/fail_count/last_error，仅更新 last_checked_at 留痕）。`checkAndUpdateValidity` = probe+apply 组合、签名不变，既有消费方（AutoRenewService/DelegationController/ValidateCommand）零改动且同获「unreachable 不误计数」修复。
- **两阶段 + 熔断**：阶段①逐条探测（不落库）跨 chunk 累积标量 outcome + `last_checked_at` 快照；阶段②轮末先判熔断——`unreachable 占比 ≥ 0.5 且样本 ≥ 5`（类常量 `CIRCUIT_BREAKER_RATIO`/`CIRCUIT_BREAKER_MIN_SAMPLE`）判系统性停摆，**本轮零落库/零删除/零通知** + `SystemAlert`（category `delegation_patrol`、固定指纹 `patrol_outage`、dedupeKey `delegation_patrol_outage`、TTL **504h=3×周巡检周期**）；未熔断轮 `clearDedupe` 复位。探测与落库分离使熔断在写库前拦截。
- **阶段② TOCTOU CAS 落库（防陈旧覆盖）**：两阶段间隔可达数十分钟，窗口内 ValidateCommand（每分钟）/双端手动检查/AutoRenew 可能已写入更新鲜结论——巡检落库不走 `applyProbeOutcome`，走 `applyProbeOutcomeIfUnchanged`：单条原子 `UPDATE ... WHERE id = ? AND last_checked_at <=> 阶段①快照`（NULL-safe，MySQL 5.7/8.x 均支持），affected=0 即本条陈旧结论作废、跳过删除/通知 gate（统计「陈旧跳过」）；invalid 的 `fail_count` 用 DB 侧 `LEAST(fail_count+1,100)` 原子自增（顺带消多写者 lost update）；删除加 `valid=false` 条件（落库到删除的极窄窗被并发恢复则不删）。CAS 基准依赖不变式「`last_checked_at` 前移的全局唯一写点是 `applyProbeOutcome`（三态均写 now()）」——新增探测落库路径必须写结论同步写 `last_checked_at`，否则 CAS 误判。
- **抖动 gate（post-apply 口径）**：无效委托的删除/通知统一 `fail_count ≥ 2`（类常量 `NOTIFY_FAIL_THRESHOLD`），gate 读**落库后**值（算术 `累积现值 + invalid?1:0`）——有 active 证书（active/unpaid/pending/processing/approving）→ 保留 + 达阈发用户通知；无 active 证书 → 达阈才删除（未达阈保留、等下轮确认）。`fail_count` 是多写者计数器（ValidateCommand 每分钟/双端手动/AutoRenew 每日均写），「≈2 周确认」是唯一写者情形的下界。
- **失效通知（`delegation_invalid`）**：按 user 聚合（跨 chunk 累积 → 轮末 `groupBy(user_id)` per-user 一封），dispatch 显式传 `delegation_ids`；`DelegationInvalidNotificationBuilder` 按 ids 重载 + 过滤 `valid=false`（读持久列、不查 DNS、null-guard 已删行），全恢复/全删返 null 不发；payload 仅 zone/prefix/target_fqdn/fail_count + **固定用户友好文案（绝不含 last_error/原始异常，防 SQLSTATE 回显嵌套 SQL 入用户邮件）**；**强制发**（不入 `user_default_preferences`，穿透用户已关的到期偏好——委托失效→自动续期静默失败→静默过期）。

### 相关服务

| 服务                     | 文件位置               | 职责                     |
| ------------------------ | ---------------------- | ------------------------ |
| `CnameDelegationService` | `Services/Delegation/` | 委托记录管理、有效性检测 |
| `DelegationDnsService`   | `Services/Delegation/` | DNS TXT 记录操作         |
| `AutoDcvTxtService`      | `Services/Delegation/` | 订单维度的自动 TXT 写入  |

---
