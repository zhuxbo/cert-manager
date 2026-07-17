# 企业信息查询（工商查询 + 邮编查询）

企业-联系人绑定模型，工商查询服务（阿里云市场）与本地邮编查询（含县级市识别），前端由共享组件 `OrganizationEditor` 串联。

## 工商查询与企业-联系人绑定

- **多对一模型**：`organizations.contact_id` 关联 `contacts.id`（应用层校验，无 DB 外键 — 避免误删 Contact 时连锁清空企业绑定）；删除 Contact 前校验是否被任何 Organization 引用
- **嵌套 upsert**：`POST/PUT /api/{role}/organization` 请求体支持嵌套 `contact_id + contact`，单事务原子；User/Admin OrganizationController 复用 `Concerns\ResolvesContactId` trait
- **update 保留原绑定**：PUT `/api/{role}/organization/{id}` 未传 `contact_id` 也未传 `contact` 时 **保留原 contact_id**（避免部分字段更新意外清空绑定）；显式传 `contact_id: null` 才清空。Admin/User 端一致
- **订单自动反查**：`Order\ActionTrait::initParams` 当传 organization 但缺 contact 时，从 `organization.contact_id` 自动取；contact_id 为空报错"请先为该企业绑定联系人"
- **订单快照修改边界**：Admin 订单详情仅在 `latest_cert.status` 属于 `unpaid/pending` 且对应 `orders.organization/contact` JSON 快照已存在时提供修改入口；`PATCH /api/admin/order/applicant/{id}` 在事务内锁订单并二次校验状态，只更新当前订单快照，绝不反向修改 `organizations/contacts` 资料库，也不借接口补建原本不存在的快照；企业与联系人电话统一接受 5–15 位数字字符串或整数（带前导零时使用字符串），其他申请字段仍按原字符串规则校验；共享 `ValidatorUtil` 禁止数组/对象借长度规则绕过
- **工商查询服务**：`Services/EnterpriseLookup`（`LookupInterface` + `AliyunDriver` + `LookupManager`），仅对接阿里云市场（AppCode 鉴权，HTTP timeout 固定 10s）；Redis 缓存 24h 成功 / 1h 失败，**cacheKey 含 fieldMap 指纹**（配置变更后旧缓存自动失效，避免"改完 fieldMap 但 24h 缓存仍返回旧 schema"的字段缺失）；`fieldMap` 吸收响应结构差异（dot path），`queryField` 吸收请求参数名差异（极速工商 `name`/`company`、其他接入商 `keyword` 等），切接入商不改代码
- **配置项**（`system_setting.enterprise.*`，setting 顶层 key 采用小驼峰）：`url` / `appCode`（base64 编码存储，非加密；真加密为待评估项）/ `queryField`（默认 `name`）/ `fieldMap`（内部标准 key **对齐 organization/contact 入库字段名**，前端可直接消费无需二次映射：`name` / `registration_number` / `address` / `state` / `city` / `regionname` / `legal_person`）/ `dailyLimit`（全局每日上限，默认 100，0 视为无限制）
- **启用判定**：去掉了独立的 `enabled` 开关，`LookupManager::enabled()` 改为校验 `url` + `appCode` + `queryField` 非空且 `fieldMap` 至少配齐 `name`/`registration_number`/`address` 三个标准字段；任一缺失即视为未启用
- **全局每日上限**：`AliyunDriver::enforceAndIncrementDailyQuota()` 在 cache miss 后、HTTP 请求前 `Cache::add + Cache::increment` 原子计数，key `enterprise:daily:{YYYY-MM-DD}` TTL 至当日 23:59:59；**缓存命中不计数、超限抛 LookupException(429) 不写失败缓存**（否则次日重置后仍命中失败缓存）。30/min IP 节流保留作为前置防刷
- **标准 key 命名约定**：直接对应入库字段（`organizations.name` / `organizations.registration_number` / `organizations.address` / `organizations.state` / `organizations.city`），加 2 个中间值 `regionname`（供邮编查询使用，不入库）和 `legal_person`（拆分后入 `contacts.first_name/last_name`）；移除了未消费的 `status` 字段
- **端点**：`POST /api/{role}/enterprise-lookup`（节流 30/min）+ `GET /api/{role}/enterprise-lookup/status`（前端探活）
- **查询按钮可见性**：User 端 `enabled()=false` → 隐藏；Admin 端 `enabled()=false` → 禁用+tooltip
- **前端组件**：`shared/components/OrganizationEditor` — 单弹窗内两个 select（企业/联系人）+ 工商查询按钮；User/Admin 共用，按 `role` prop 切换按钮可见性策略（http baseURL 已含 /api/admin，URI 不重复前缀）；`countryOptions` 必填 prop 由调用方注入（admin/user 各自 `@/views/system/country` 维护，shared 组件不硬编码项目数据）
- **法人姓名拆分**：`splitChineseName()` 仅在 contact 未选且 last/first 都为空时回填 — 含空格 → 按空格切；含 `·` 中点（少数民族姓名）→ 按 `·` 切；其他 → 第一个字符为姓、其余为名（复姓需手动调整）。回填成功且 `contact.title` 为空时同步填"法定代表人"（用户可改）

## 邮编查询（本地数据 + 县级市识别）

- **数据来源**：基于 [tombcato/china-zipcode-data](https://github.com/tombcato/china-zipcode-data) MIT 全量 2879 条省/市/区/县/县级市邮编。字段裁剪至 `province / city / name / zipcode`；4 个直辖市 city 字段规范化为 province 名（重庆数据源用"重庆城区/重庆郊县"，统一改"重庆市"以对齐阿里云 `regionname` 输出）。文件 `backend/resources/data/china_city_zipcode.json` 约 273 KB（gzip ~30 KB），自托管零外部依赖
- **服务**：`App\Services\ZipcodeLookup\ZipcodeLookup`，进程内 `static` 缓存全量数据 + 预计算"每地级市最小 zipcode 代表"；测试用 `ZipcodeLookup::resetCache()` 重置
- **匹配策略**：`find(regionname, companyName = null)` 三步走 —
  1. **最长 fullPath 匹配**：遍历数据找最长的 `province+city+name` 子串命中 `regionname`（直辖市同时尝试 `city+name` 两段拼接，兼容阿里云"北京市朝阳区"风格）。命中即返回区/县/县级市精度
  2. **公司名兜底县级市**：若 regionname 仅到地级市未命中区/县，扫县级市候选 — 只要 regionname 含 province **或** city 任一（兼容工商响应字段不全的县级市公司，常见只给 city 不给 province），公司名是否包含县级市名（去/不去"市"后缀），命中则用县级市覆盖 `city` 字段
  3. **市级回落**：以上都未命中时，按优先级遍历地级市代表（① province+city 都命中 → ② 仅 city 命中 → ③ 仅 province 命中），返回该地级市最小 zipcode
- **返回 shape**：`{zipcode, province, city, district}` — 命中县级市时 `city` 填县级市名、`district` 留空；命中普通区县时 `district` 填区县名
- **端点**：`POST /api/{role}/zipcode-lookup`（节流 60/min，IP 维度），入参 `{regionname, name?}`，未匹配返回 `code: 0`
- **前端集成**：`OrganizationEditor.onLookup()` 工商查询成功后用 `d.regionname || d.province+d.city` 当 regionname、`d.name`（公司名）当 companyName 调邮编接口；回填 `postcode`（仅在空时），并用返回的 `city` **覆盖**已填 city（zipcode 服务的 city 比工商更精确，如县级市识别）。失败静默
- **fieldMap 联动**：`enterprise.fieldMap` 加入 `regionname` 标准字段（默认 `result.basic.regionname`），让工商响应带出完整行政区划路径供邮编查询使用
- **跨省误判防护**：第 2 步要求县级市的 province/city 必须在 regionname 里出现，避免"重庆某义乌商品城"被误判为浙江义乌
