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

`DelegationCleanupCommand` 每天 06:00 清理无效的委托 TXT 记录：

- **保留**：`processing` 状态订单使用的委托记录
- **删除**：代理域名下所有其他 TXT 记录
- **清理数据库标记**：移除已删除记录对应的 `auto_txt_written` 标记

### 相关服务

| 服务                     | 文件位置               | 职责                     |
| ------------------------ | ---------------------- | ------------------------ |
| `CnameDelegationService` | `Services/Delegation/` | 委托记录管理、有效性检测 |
| `DelegationDnsService`   | `Services/Delegation/` | DNS TXT 记录操作         |
| `AutoDcvTxtService`      | `Services/Delegation/` | 订单维度的自动 TXT 写入  |

---
