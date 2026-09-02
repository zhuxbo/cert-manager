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

### 多代理域设置与 DNS provider

委托配置使用独立的 `delegation` 设置组，配置项平铺存储：

- `defaultDomain` 是手工新订单及新建委托记录初始指引使用的代理域名。
- 每个代理域有一个 `array` 类型设置，域名先按项目 IDNA 规则转为 ASCII，再转小写、去末尾点并校验 DNS 总长/label，key 由该 ASCII 域将点号替换为下划线后转 camelCase 得到；例如 `proxy.example.com` 对应 `proxyExampleCom`。Unicode 与等价 Punycode 必须归一为同一域和同一 key。value 必须内含与 key 一致的 `domain`，provider 字段和凭据直接平铺在同一数组内。
- Tencent 配置形如 `{domain, provider: "tencent", secretId, secretKey}`；Cloudflare 配置形如 `{domain, provider: "cloudflare", zoneId, apiToken}`；Aliyun 配置形如 `{domain, provider: "aliyun", accessKeyId, accessKeySecret}`。`DelegationDnsProviderFactory` 按 `provider` 路由，三者都通过统一的 TXT upsert、枚举和删除接口工作。Cloudflare 直接调用必要 HTTP 接口；Tencent 只保留一个 TC3 签名器并直接调用 DNSPod 的 `DescribeRecordList`、`CreateTXTRecord`、`DeleteRecord`，请求不携带 DNSPod 不需要的 Region；Aliyun 只保留一个 AliDNS RPC HMAC-SHA1 签名器并调用 `DescribeDomainRecords`、`AddDomainRecord`、`DeleteDomainRecord`，不得引入三家完整 SDK。
- 只有 domain、provider 和该 provider 必填凭据全部有效，且设置 key 与 domain 派生 key 一致的配置才进入运行时。`domain` 为空的数组项视为未启用草稿，既不进入运行配置也不作为畸形配置告警；已经填写 domain 但凭据不完整的项仍排除运行时，并由配置诊断报告错误。

Seeder 首次创建“域名委托”设置组时，按数据库中“证书接口”组的当前权重插入其后；目标权重被占用时才将该位置及其后的组整体后移。后续重跑不修改任何已有设置组权重，保留管理员排序。Seeder 并幂等创建空的 `defaultDomain`；完成旧配置迁移后，再为尚无非空域配置的 provider 补充对应的 `tencentExample`、`cloudflareExample`、`aliyunExample` 空凭据示例。已有 provider 配置即使凭据尚未补全，也不再添加同 provider 示例；已有示例不覆盖、不自动删除。示例是普通可编辑草稿：填写 domain 和凭据时，应同时把 key 改为该域名派生的小驼峰 key，完整后即可进入运行时。升级迁移只处理旧版 `site.delegation` 腾讯云单项设置，将其转换为 Tencent 域配置，并只回填 `proxy_domain` 为空的历史委托；已有的非空绑定不会被覆盖。旧配置凭据不完整时仍按原值迁移为草稿，由运行配置解析统一排除。

### 逻辑委托、订单快照与 provider 切换

`CnameDelegation` 以 `(user_id, zone, prefix)` 标识逻辑委托，`validation.delegation_id` 持久化引用该记录。`proxy_domain` 表示最近一次全局 CNAME 检测实际命中的代理域；`validation.delegation_target` 则是某张证书使用的不可变 TXT 目标快照。两者职责不同：后续全局检测可以校正共享记录，但不得改写旧订单快照。

- 手工 web/admin 新建、续费、重签一律冻结当前完整 `defaultDomain`；`updateDCV(delegation)` 也把现有订单切换到当前默认域。两者都不直接修改共享 `proxy_domain`。
- 手工 revalidate 只清验证状态和 `auto_txt_written` 后，向原 `delegation_target` 幂等重写；不切换目标。后台定时 revalidate 不换目标，也不清写入标记。
- auto/deploy 续费或重签先从源证书 validation 取得精确 `delegation_id`，全局检测该逻辑委托，再以其检测结果生成新订单快照。旧数据缺少有效 ID 时才按 CA 规则回落查找或创建。
- V1/V2 API 不支持 delegation 验证方式，也不接收或下传本地委托字段。

全局检测按“完整默认域优先，其余完整配置按设置顺序”逐一检测。任一目标命中即更新 `valid=true` 和 `proxy_domain=命中域`；得到权威答案但全部未命中时置 `valid=false` 并保留原 `proxy_domain`；全部渠道不可达时只更新检查时间，不改变有效状态或失败计数。CA 返回 `active` 不能替代 CNAME 检测，因为客户可能直接解析 TXT 绕过委托。

委托业务状态只承诺单节点部署，不引入命名锁、状态机、revision 或额外表。同一代理域切换 provider 后，域名身份和订单快照不变，后续 TXT 操作按该域当前完整 provider 配置执行。多个独立部署可以共用同一代理 DNS 域：定时清理以 provider 返回的记录更新时间、精确 RecordId 和删除后复查实现保守且幂等的跨系统清理；业务数据库与委托状态仍不在部署间共享。

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
2. 按 `delegation_id + delegation_target` 分组收集验证 tokens，避免同一逻辑委托的新旧目标串写
3. 已写入的记录直接跳过；写入域优先从 `delegation_target` 解析，旧数据缺失快照时才回落共享 `proxy_domain`
4. 调用 `DelegationDnsService::setTxtByLabel()` 批量幂等写入 TXT 记录；共享记录当前 `valid=false` 不阻止写入冻结目标
5. 更新 validation 中的 `auto_txt_written` 和 `auto_txt_written_at` 标记

**validation 字段说明**：

| 字段                  | 说明                           |
| --------------------- | ------------------------------ |
| `delegation_id`       | 委托记录 ID                    |
| `delegation_target`   | 本订单应配置的 CNAME 目标 FQDN |
| `delegation_valid`    | 本订单目标是否有效             |
| `delegation_zone`     | 委托的根域名                   |
| `auto_txt_written`    | TXT 是否已写入                 |
| `auto_txt_written_at` | 写入时间                       |

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
手工创建/续费/重签（validation_method=delegation）
    ↓
使用当前 defaultDomain 生成 validation.delegation_target 快照
    → 立即向该目标写 TXT
    → 不修改共享 proxy_domain
    ↓
任一路径发起全局委托检测
    → 默认域优先，回落其他完整配置
    → 命中后校正共享 proxy_domain
    ↓
自动续费/重签或 deploy
    → 从源 validation.delegation_id 加载同一逻辑委托
    → 先全局检测，再按检测结果生成新订单快照
    ↓
CA active 只更新证书状态，不切换共享委托
```

### 委托 DNS 清理

`DelegationCleanupCommand` 每天 06:00 清理无用的委托 TXT 记录：

- **按域隔离**：枚举每个完整代理域配置，通过该域自己的 provider 拉取和删除记录。`processing`/`approving` 订单优先按 `validation.delegation_target` 建立 keepLabels；旧数据缺少目标快照时才回落共享 `proxy_domain`。同名 label 不会跨域误保留，单域失败不阻止其他域继续处理。
- **委托格式白名单（数据破坏防线）**：删除判据在 keepLabels 白名单之上前置「委托格式收敛」——只删 label 形如 **32 或 64 位 hex**（`preg_match('/^([0-9a-f]{32}|[0-9a-f]{64})$/i', ...)`，大小写不敏感）的记录。当代 `generateLabel` 恒产 32-hex，64-hex 兼容历史存量。护住代理域下用户自放的 SPF/DKIM/`_dmarc`/apex `@`/站点验证等**非委托 TXT**（含点、下划线或非 hex 长度的名字永不进删除集）。
- **保留**：`processing`/`approving` 状态订单引用的委托 label，在订单冻结目标所属代理域内保留。
- **记录时间**：统一 provider 记录结构包含 `changed_at` Unix 秒时间戳；Tencent 取 `UpdatedOn`，Cloudflare 取 `modified_on`（缺失时回落 `created_on`），Aliyun 取 `CreateTimestamp`/`UpdateTimestamp` 中较晚者。时间缺失、格式无效或非正数时失败关闭，不删除该记录。
- **删除**：只删除符合委托格式、不在 keepLabels 且 `changed_at` 严格早于当前时间 30 天的记录。按初始 DNS inventory 的精确 RecordId 删除，不按 label 重新枚举，避免同一 label 在清理期间新增的 token 被误删。
- **跨系统幂等**：每个 RecordId 独立删除；provider 返回删除异常时重新枚举 TXT。如果该 ID 已不存在，视为另一套系统已经完成删除并继续；ID 仍存在或复查失败时保留原异常并使本域清理失败。若本轮成功枚举时某个超过 30 天的本地标记对应 label 已不存在，也视为其他系统先完成清理并清除本地标记；枚举失败的域不据此推断。
- **删除计数**：成功日志的 `deleted_count` 按初始 DNS inventory 中、所属 label 的过期记录已完整删除或确认不存在的实际 TXT 记录条数累计；同一 label 多条过期 TXT 按多条计，后续 label 失败不计入。
- **清理数据库标记**：只有 `auto_txt_written_at` 同样超过 30 天，才按订单快照域与成功处理的 label 精确匹配后移除 `delegation_id`、`auto_txt_written` 和 `auto_txt_written_at`。委托行不存在时只清超过 30 天的本地失效字段，不猜测远端归属；缺少或无法解析写入时间的标记保留。

### 删除代理域设置

删除操作沿用通用设置界面，不增加“下岗”按钮。`defaultDomain` 设置本身和当前默认域配置不可删除；其他域只执行一次索引计数：`CnameDelegation::where('proxy_domain', $domain)->count()`。计数大于零时提示“仍有 N 条委托记录使用该委托域”，等周巡检清理无引用记录后再删除。删除路径不扫描证书 validation JSON、不清远端 TXT、不迁移委托记录。批量删除先检查全部目标，避免部分删除。

`delegation` 核心设置组不可改名或删除；空 domain 示例可直接删除，畸形域配置失败关闭。已启用域不能原地改名，但可以在保持域名身份的前提下切换 provider 和凭据。

### 委托健康周巡检（`delegation:check`）

`DelegationCheckCommand` 每周一 07:00 逐条处理：

- 先查询同一用户是否还有 `active/unpaid/pending/processing/approving/cancelling` 证书覆盖该 zone；查询同时匹配 Unicode/Punycode 以及子域和通配符形式。
- 没有引用时立即删除委托记录，不查询 DNS，不参考 `valid` 或 `fail_count`；`--dry-run` 只报告。
- 有引用时才执行全局多域检测，并用原始 `last_checked_at` 做单字段 CAS 落库；CAS 未命中说明期间已有更新，本轮结果作废。
- 无效或不可达的有引用记录继续保留。周巡检不发送用户通知；自动续签真正受阻时才复用 `auto_renew_failed` 通知。

### 相关服务

| 服务                     | 文件位置               | 职责                     |
| ------------------------ | ---------------------- | ------------------------ |
| `CnameDelegationService` | `Services/Delegation/` | 委托记录管理、有效性检测 |
| `DelegationDnsService`   | `Services/Delegation/` | DNS TXT 记录操作         |
| `AutoDcvTxtService`      | `Services/Delegation/` | 订单维度的自动 TXT 写入  |

---
