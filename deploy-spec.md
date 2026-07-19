# SSL 证书部署工具统一规范

跨平台 SSL 证书自动部署工具的共通行为规范。定义配置文件结构、API 接口、续签状态机、一键部署、部署流程、升级协议、构建发布、安装/卸载、安全规范、共享常量和智能体协作约定。

适用项目：sslctl（Linux Nginx/Apache）、sslctlw（Windows IIS）、sslbt（宝塔面板）及未来新平台实现。

## 定位

- **开发参考**：维护现有项目时保持行为一致
- **新项目蓝图**：新平台实现时的快速指导
- **范围**：仅定义所有项目共有的交集行为，各项目独有特性自行处理

---

## 1. 配置文件结构

公共字段统一定义，平台特有字段放在各层级的扩展区。扩展字段不得与公共字段命名冲突。

### 1.1 顶层结构

```json
{
  "release_url": "",
  "upgrade_channel": "main",
  "schedule": {},
  "certificates": []
}
```

| 字段              | 类型   | 说明                                        |
| ----------------- | ------ | ------------------------------------------- |
| `release_url`     | string | 升级发布地址                                |
| `upgrade_channel` | string | 升级通道：`main`（稳定版）/ `dev`（测试版） |
| `schedule`        | object | 全局调度配置                                |
| `certificates`    | array  | 证书列表                                    |

### 1.2 schedule

| 字段                | 类型   | 默认值 | 说明                            |
| ------------------- | ------ | ------ | ------------------------------- |
| `renew_mode`        | string | `pull` | 全局续签模式：`pull` / `local`  |
| `renew_before_days` | int    | 14     | 提前续签天数，由 API 返回值覆写 |

- `renew_before_days` 初始值 14，每次 API 交互后用服务端返回值更新
- 证书级 `renew_mode` 优先于全局设置

### 1.3 证书配置（certificates[] 元素）

| 字段                | 类型     | 说明                                                   |
| ------------------- | -------- | ------------------------------------------------------ |
| `cert_name`         | string   | 证书名称（如 `example.com-12345`）                     |
| `order_id`          | int      | 订单 ID                                                |
| `enabled`           | bool     | 是否启用                                               |
| `domains`           | string[] | 域名列表                                               |
| `renew_mode`        | string   | 证书级续签模式，空串表示使用全局 `schedule.renew_mode` |
| `validation_method` | string   | 验证方式：`file` / `delegation`                        |
| `api`               | object   | 证书级 API 配置                                        |
| `metadata`          | object   | 证书元数据                                             |

`validation_method` 受域名类型限制（仅 local 模式需要选择）：

- IP 域名不可选 `delegation`（IP 无 DNS 记录，无法完成委托验证）
- 通配符域名不可选 `file`（通配符无法指向具体站点放置验证文件）
- SAN 含 IP 的证书在 setup 与续签时自动派生为 `renew_mode=local` + `validation_method=file`（见 §5.2）

### 1.4 api

| 字段    | 类型   | 说明         |
| ------- | ------ | ------------ |
| `url`   | string | API 端点地址 |
| `token` | string | Bearer Token |

每个证书独立的 API 配置，支持不同证书来自不同 API 源。

### 1.5 metadata

| 字段                | 类型   | 说明                                                           |
| ------------------- | ------ | -------------------------------------------------------------- |
| `last_deploy_at`    | string | 最后部署时间（RFC3339）                                        |
| `cert_expires_at`   | string | 证书过期时间（RFC3339）                                        |
| `cert_serial`       | string | 证书序列号                                                     |
| `csr_submitted_at`     | string | CSR 提交时间（仅 local 模式）                                  |
| `last_csr_hash`        | string | 上次 CSR 的 SHA256 哈希                                        |
| `last_issue_state`     | string | 签发/生命周期状态：`""` / `processing` / `CAPPED`（触顶，记录阶段：签发/部署/legacy）/ `EXPIRED`（已过期静默）/ 其他异常（等待人工处理） |
| `issue_retry_count`    | int    | 签发尝试计数（CSR 提交），`>= 10` 触顶                          |
| `deploy_attempt_count` | int    | 部署尝试计数，`>= 10` 触顶；与签发计数分离，不从旧混合计数推断  |

### 1.6 扩展区约定

顶层、证书级、metadata 级均可存在平台特有字段。规范不定义扩展字段的内容，各实现自行添加。

绑定模型是主要的平台差异点，不纳入公共字段：

| 平台         | 证书→站点 | 站点→证书 | 扩展字段示例                  |
| ------------ | --------- | --------- | ----------------------------- |
| Nginx/Apache | 1:N       | 1:1       | `bindings[]`（按站点）        |
| IIS          | 1:N       | 1:N       | `bind_rules[]`（按域名:端口） |
| 宝塔         | 1:N       | 1:1       | `site_name[]`（站点名列表）   |

### 1.7 配置文件迁移

规范演进可能引入新字段、重命名字段或移除废弃字段。各实现在以下时机检查并校正配置文件：

- **升级后首次运行**：检测配置版本，补充缺失的新字段（使用默认值），清除已废弃字段
- **重新执行 setup**：基于 API 返回数据重新生成配置，保留用户已有的绑定和平台扩展字段

迁移原则：

- 升级是非连续的（可能从任意旧版本直接升级到最新版），不依赖中间版本的过渡字段
- 新增字段填入默认值，不影响现有行为
- 废弃字段静默移除，不报错
- 用户数据（证书配置、绑定关系、API 凭据）永远不丢失
- 如果旧配置缺少当前版本必需的数据且无法推导，提示用户重新执行 `setup` 部署

#### 通用迁移方法

各实现应采用**数据驱动**的通用迁移引擎，将迁移知识与迁移逻辑分离。

**设计原则**：

1. **规则与引擎分离**：所有字段变更以声明式规则表达（规则表/映射表），引擎本身不含任何字段名等业务知识
2. **操作原语统一**：各实现支持的基本操作类型一致（具体语法随语言不同）：
   - `delete` — 移除废弃字段
   - `rename` — 字段重命名（目标已存在则保留新值）
   - `move` — 扁平字段移入子对象（目标已存在则不覆盖）
   - `spread` — 顶层字段分发到数组元素（如全局 API → 各证书，仅补全缺失字段）
   - 平台可按需扩展操作类型，但以上四种为公共基础
3. **默认值自动填充**：递归对比当前数据与默认结构，补齐缺失字段、校正类型不匹配（如 string → list）
4. **幂等性**：同一规则重复执行不产生副作用，迁移后的配置再次迁移结果不变
5. **顺序无关**：支持从任意旧版本直接升级，不依赖中间版本的过渡状态
6. **旧文件合并**：配置文件拆分/合并变更通过声明旧文件名和目标字段表达，写入成功后再删除旧文件

**执行流程**（程序加载配置时完成）：

```
1. 读取当前配置文件
2. 合并旧文件（如有）
3. 遍历规则表，对全局配置和每个证书条目执行字段迁移
4. 递归补齐默认值
5. 如有变化，持久化写回（失败不影响本次加载）
```

**扩展方式**：添加新迁移只需在规则表追加条目；默认值变更只需修改默认结构定义。

### 1.8 工作目录结构

各平台安装目录不同，但内部布局统一：

```
{install_dir}/
├── config.json          # 统一配置文件
├── certs/               # 证书存储（按站点名或证书名组织）
├── pending-keys/        # 待确认私钥（local 模式，按证书名组织）
└── logs/                # 日志文件
```

平台可增加额外目录（如 sslctl 的 `backup/`、`scan-result.json`），不做统一要求。

---

## 2. Deploy API 接口规范

### 2.1 认证

`Authorization: Bearer {token}`

### 2.2 通用响应格式

```json
{
  "code": 1,
  "msg": "success",
  "data": {}
}
```

`code = 1` 表示成功，其他值为错误。

### 2.3 查询证书

```
GET /api/deploy?order={order_id}
GET /api/deploy?order={domain}
GET /api/deploy?order={id1,id2,domain1,...}  （批量，逗号分隔，上限 100）
```

响应 data（分页格式）：

```json
{
  "total": 1,
  "page": 1,
  "page_size": 100,
  "data": [CertData, ...],
  "renew_before_days": 14
}
```

空参数时返回最新 active 订单，支持 `page` 和 `page_size` 参数。

### 2.4 CertData 结构

| 字段             | 类型        | 说明                                       |
| ---------------- | ----------- | ------------------------------------------ |
| `order_id`       | int         | 订单 ID                                    |
| `status`         | string      | 证书状态                                   |
| `domains`        | string      | 域名列表（逗号分隔）                       |
| `certificate`    | string      | 证书 PEM（仅 active）                      |
| `ca_certificate` | string      | 中间证书 PEM（仅 active）                  |
| `private_key`    | string      | 私钥 PEM（仅 active，可选）                |
| `issued_at`      | string      | 签发日期（YYYY-MM-DD）                     |
| `expires_at`     | string      | 过期日期（YYYY-MM-DD）                     |
| `file`           | object/null | 文件验证信息（仅 processing + 文件验证时） |

`certificate`、`ca_certificate`、`private_key`、`issued_at`、`expires_at` 仅在 `status=active` 时返回。

状态语义：提交 CSR 的成功响应只会是 `pending` / `processing`（均表示服务端已收到 CSR）；查询订单在
`processing` 与 `active` 之间可能出现短暂中间态 `approving`。客户端将 `pending` / `processing` /
`approving` 统一归一为 `processing` 继续等待；`active` 之后的状态均为订单终态，客户端持久化后停止
自动动作，等待人工处理。

### 2.5 file 结构

| 字段      | 类型   | 说明             |
| --------- | ------ | ---------------- |
| `path`    | string | 验证文件相对路径 |
| `content` | string | 验证文件内容     |

### 2.6 提交 CSR（local 模式）

```
POST /api/deploy
Content-Type: application/json
```

请求体：

| 字段                | 类型   | 说明                  |
| ------------------- | ------ | --------------------- |
| `order_id`          | int    | 订单 ID               |
| `csr`               | string | CSR PEM               |
| `domains`           | string | 域名（逗号分隔）      |
| `validation_method` | string | `file` / `delegation` |

`validation_method` 限制见 §1.3。

响应 data：单个 CertData + `renew_before_days`。提交 CSR 后服务端先进入验证流程，响应状态为
`processing`（提交暂未完成时可能保持 `pending`），不会在本次请求中同步返回 `active`；客户端应在后续
查询中等待状态变为 `active` 后再部署。

服务端对重复提交的幂等由现有 Order/Action 状态机保证（订单已进入处理则不再创建新证书、不重复扣费）。客户端收到
`pending` 或"已在处理"类响应时，统一归一为 `processing` 查询路径：只 GET 查询，不重复 POST，不增加计数，不重新生成
CSR。

服务端未接收提交（校验失败、订单状态不允许等）时返回错误信息而非状态：客户端按明确业务拒绝处理，清理在途
pending 后停止。因此提交结果不确定（超时/断连/解析失败）后的恢复只需查询订单状态，无需重复 POST。

### 2.7 切换自动重签

```
POST /api/deploy/auto-reissue
Content-Type: application/json
```

请求体：

| 字段           | 类型 | 说明      |
| -------------- | ---- | --------- |
| `order_id`     | int  | 订单 ID   |
| `auto_reissue` | bool | 开启/关闭 |

客户端在首次部署时根据续签模式调用：pull → `true`，local → `false`。

### 2.8 部署回调

```
POST /api/deploy/callback
Content-Type: application/json
```

请求体（四字段，协议零改动）：

| 字段          | 类型   | 说明                  |
| ------------- | ------ | --------------------- |
| `order_id`    | int    | 订单 ID               |
| `status`      | string | `success` / `failure` |
| `deployed_at` | string | 部署时间（RFC3339）   |
| `message`     | string | 可选，仅 `status=failure` 时携带失败原因摘要：客户端截断至 ≤256 字符并经敏感信息脱敏；服务端校验上限 500，超限整条被拒；第 10 次（最后一次）部署失败在 `message` 标注"已达重试上限" |

响应 data 包含 `recorded`（服务端已记录）与 `renew_before_days`。

回调契约：

- 客户端只上报**部署结果**：每次部署成功与每次明确部署失败各尽力上报一次，不做次数筛选、不做节流。签发失败不上报（服务端自行记录），客户端仅记本地日志与本地计数。
- 上报为非关键路径：传输失败仅由既有传输层重试（3 次退避）兜底，之后只记日志，不持久排队、不补发；无 outbox、无幂等 ID，允许缺行、偶发重复行、迟到行。
- 回调所有权收敛到编排层：自动续签调用链的底层部署函数不得自行发送回调，只返回结构化结果，由编排层在部署结果原子落盘后统一发送一次；手动 `deploy` / `setup` 回调语义不变。
- 触顶（计数 `>= 10`）、过期、policy 阻断路径不发送任何回调。

### 2.9 renew_before_days 的传递

服务端在所有接口的响应 data 中返回 `renew_before_days`。客户端每次收到后更新本地 `schedule.renew_before_days`。

客户端对返回值做上限校验：`renew_before_days` 上限为 **30**——无论续费还是重签，续签动作都应发生在到期前 30 天以内；超过 30 视为服务端异常值，拒绝更新并保留本地现值，防止异常大值把全部证书拉入需续签状态、触发每日全量续签。

---

## 3. 续签状态机

### 3.1 定时触发

- 每天执行一次续签检查
- 多证书间随机延迟，分散 API 压力（见常量表）
- 单次最多处理 100 个证书

### 3.2 前置过滤

遍历证书列表，按以下条件跳过：

| 条件                                            | 处理                                           |
| ----------------------------------------------- | ---------------------------------------------- |
| `enabled = false`                               | 跳过                                           |
| 已过期（剩余时间 ≤ 0）                          | 静默终止并转 `EXPIRED`，仅留本地日志与人工入口 |
| 剩余有效期 < 安全余量（默认 24 小时）           | 跳过，不启动新动作                             |
| `last_issue_state = CAPPED`（已触顶）           | 跳过，等待人工处理                             |
| `issue_retry_count >= 10`（签发触顶，local 模式）| 进入 `CAPPED`，跳过                            |
| `deploy_attempt_count >= 10`（部署触顶）        | 进入 `CAPPED`，跳过                            |
| 无 API 配置                                     | 跳过                                           |

**核心原则：证书到期后不再自动发起任何操作，等待人工处理。触顶（任一计数 `>= 10`）与过期均静默终止，不启动新动作、不发送任何回调。**

**计数与终止：**

- 签发尝试（`issue_retry_count`，即 CSR 提交）与部署尝试（`deploy_attempt_count`）分别计数、各自上限 10 次；所有停止判断统一为 `count >= 10`，绝不出现第 11 次尝试。
- 计数在"持久化一个新的逻辑尝试意图"时递增；同一尝试的崩溃恢复重放、传输层重试、GET 轮询、policy 阻断均不增加计数。
- 任一阶段触顶后进入客户端本地 `CAPPED` 状态并记录触顶阶段（签发 / 部署 / legacy）：不再启动新动作、不发送任何回调，等待人工处理；证书到期后转 `EXPIRED`。
- 证书绝对到期时间是自动动作的准入截止点：剩余有效期小于统一安全余量（默认 24 小时）时不再启动新动作；已过期证书静默终止。触顶与过期都不产生任何回调事件。

### 3.3 续签模式确定

```
effective_mode = cert.renew_mode || schedule.renew_mode
```

证书级优先，回退到全局设置。

### 3.4 Pull 模式

```
查询订单 (GET /api/deploy?order={order_id})
  ├─ status=active 且有证书内容
  │   ├─ 剩余天数 ≤ renew_before_days → 部署证书
  │   └─ 剩余天数 > renew_before_days → 跳过
  ├─ status=processing → 跳过，等待下次检查
  └─ 其他状态 → 跳过
```

### 3.5 Local 模式

```
检查 metadata.last_issue_state：

== ""（初始/已完成）：
  ├─ 剩余有效期 ≤ renew_before_days 且 ≥ 安全余量 → 提交新 CSR
  │   ├─ 检查签发计数（issue_retry_count >= 10 → 进入 CAPPED，停止，等待人工处理）
  │   ├─ 生成 CSR（仅 CN，不含 SAN）
  │   ├─ 网络请求前：原子持久化 pending key 与 CSR 哈希，并递增 issue_retry_count（计数 = 持久化一个新的逻辑尝试意图）
  │   ├─ POST /api/deploy 提交 CSR
  │   │   └─ 超时 / 断连 / 响应解析失败属"不确定结果"：保留 pending key 作为在途标记，下轮查询订单状态恢复，不重复 POST、不重新生成 CSR
  │   └─ 响应 status=processing 或 pending → 归一为 processing，放置验证文件，标记 processing
  └─ 剩余有效期 > renew_before_days → 跳过

== "processing"（等待签发）：
  ├─ 证书已过期或剩余不足安全余量 → 停止（EXPIRED / 等待人工处理），不再动作
  └─ 查询订单状态（只 GET，不重复 POST，不增加计数）
      ├─ status=active → 读取 pending key，部署，清理
      ├─ status=processing / pending / approving → 归一为 processing，更新验证文件（如有新的），继续等待
      └─ 其他状态（订单终态）→ 持久化实际状态到 `last_issue_state`，停止，等待人工处理；后续轮次仍可查询自愈，但状态未变化时不重复记录/落盘
```

### 3.6 文件验证流程（local 模式）

当 API 返回 `status=processing` 且包含 `file` 字段时：

```
1. 验证 file.path 不含 ".."，确保在 .well-known/ 下
2. 收集所有启用绑定的 webroot 目录（去重）
3. 在每个 webroot 下创建验证文件：
   {webroot}/{file.path}
4. 记录已放置的文件路径到 metadata
5. 部署成功后自动清理所有验证文件
```

### 3.7 并发执行保护

防止多个进程同时执行续签（cron 重叠、手动与自动并发）：

- 续签检查开始时获取进程级锁（文件锁或 PID 文件）
- 已有进程在运行时，后来者直接退出
- 进程结束后释放锁

### 3.8 部署成功后

1. 更新 metadata：`last_deploy_at`、`cert_expires_at`、`cert_serial`
2. 清零签发与部署状态：`csr_submitted_at`、`last_csr_hash`、`issue_retry_count`、`deploy_attempt_count`、`last_issue_state`
3. 清理 pending key 和验证文件
4. 由编排层在结果原子落盘后统一发送 `success` 回调 `POST /api/deploy/callback`（非关键路径，底层部署函数不自行发送）

---

## 4. Setup 一键部署流程

所有平台的主入口，接收最少参数完成完整部署。

### 4.1 参数

| 参数            | 说明                                        |
| --------------- | ------------------------------------------- |
| `url`（必需）   | API 端点地址                                |
| `token`（必需） | Bearer Token                                |
| `order`（可选） | 订单 ID 或逗号分隔的多个 ID，不传时查询全部 |

### 4.2 流程

```
1. 查询证书信息（GET /api/deploy?order=...）
2. 检测/匹配站点（平台特定扩展点）
3. 部署证书到匹配的站点
4. 按证书派生续签模式并设置 `auto_reissue`（SAN 含 IP 的证书强制 local/file，见 §5.2；调用 toggleAutoReissue）
5. 写入配置文件（api、domains、metadata 等）
6. 注册守护服务/计划任务（平台特定扩展点）
```

未指定 order 时查询该 Token 下所有 active 订单，自动匹配站点部署。

---

## 5. 部署流程

### 5.1 通用步骤

```
1. 验证证书和私钥匹配
2. 验证中间证书存在（API 部署时必需）
3. 构建完整证书链（cert + ca_certificate）
4. 平台特定部署（扩展点）
5. 更新 metadata
6. 发送部署回调
```

自动续签路径：部署尝试在持久化新的部署意图时递增 `deploy_attempt_count`（`>= 10` 触顶进入 `CAPPED`，崩溃恢复重放同一意图不增计数）；底层部署函数只返回结构化结果，由编排层在结果原子落盘后统一发送回调。手动 `deploy` / `setup` 的回调语义不变。

### 5.2 续签模式与 API 侧行为

| 模式    | API 自动重签                 | 续签发起方             |
| ------- | ---------------------------- | ---------------------- |
| `pull`  | 开启（`auto_reissue=true`）  | 服务端签发，客户端拉取 |
| `local` | 关闭（`auto_reissue=false`） | 客户端生成 CSR 提交    |

首次部署时调用 `toggleAutoReissue` 接口按模式设置 `auto_reissue`：local 关闭（防服务端 scheduler 抢跑生成服务端私钥），pull 开启；不自动开启付费的 `auto_renew`。

**IP 证书自动 local/file**：SAN 含 IP 的证书在 setup 与续签时自动进入 `renew_mode=local`、`validation_method=file` 并提交 CSR；`pull` 模式不携带 CSR。批量 setup 逐证书派生模式，DNS 证书不受混合批次影响；IP 与通配符不能同时提交的限制沿用服务端现有校验。三平台（Linux / Windows / 宝塔）均支持 IP local/file。

旧的非法 IP 配置（IP + `pull` 或 IP + `delegation`）进入 `policy_blocked_needs_setup`：不自动改配置、不计数、不回调，等待重新 setup。

### 5.3 私钥来源

按优先级依次尝试：

1. API 返回的 `private_key`
2. 调用参数指定的私钥路径
3. 本地已有的私钥（绑定站点的已部署私钥）
4. `pending-keys/` 待确认私钥（本地私钥与目标证书不配对时，配对校验通过才用；部署成功后转正，与 3.8 一致）
5. 交互提示用户提供私钥

所有来源均需验证与证书匹配后才使用。

批量部署时不逐个交互，部署结束后汇总三类结果：成功、失败、需要私钥。需要私钥的证书列出名称，由用户逐个手动部署触发交互提示。

### 5.4 订单号变更处理

续费时服务端可能返回新的 `order_id`（旧订单状态变为 `renewed`，创建新订单）。客户端在以下场景检测并更新：

- `query` 响应中 `order_id` 与本地不同时
- `update`（CSR 提交）响应中返回新 `order_id` 时

检测到变更后立即更新本地配置中的 `order_id`，并同步迁移证书配置与 `pending-keys/` 目录（按证书名/订单键改名，无 pending 私钥时为空操作），保持证书关联与在途 CSR 私钥不断。

### 5.5 部署后域名提取

部署成功后从证书 PEM 中解析 CN 和 SAN，更新配置中的 `domains` 列表。

优先级：

1. 从已部署的证书 PEM 提取（权威来源）
2. 回退到 API 返回的 `domains` 字段（逗号分隔字符串）

确保 `domains` 始终反映实际证书内容，而非仅依赖 API 数据。

### 5.6 证书链构建

拼接顺序：服务器证书在前，中间证书在后。

```
fullchain = certificate + "\n" + ca_certificate
```

部署时根据平台需求使用 fullchain 或分别提供 cert 和 intermediate。

### 5.7 域名匹配规则

setup 自动匹配站点时使用的域名匹配逻辑：

| 类型             | 规则                     | 示例                                     |
| ---------------- | ------------------------ | ---------------------------------------- |
| 精确匹配         | 证书域名 = 站点域名      | `example.com` 匹配 `example.com`         |
| 通配符           | `*.x.com` 匹配单级子域   | `*.example.com` 匹配 `www.example.com`   |
| 通配符不匹配裸域 | `*.x.com` ≠ `x.com`      | `*.example.com` 不匹配 `example.com`     |
| 通配符不匹配多级 | `*.x.com` ≠ `a.b.x.com`  | `*.example.com` 不匹配 `a.b.example.com` |
| IP 证书          | 精确匹配，不走通配符     | `192.168.1.1` 仅匹配 `192.168.1.1`       |
| IDN 域名         | 自动转换 Punycode 后匹配 | `中文.com` → `xn--fiq228c.com`           |

### 5.8 CSR 生成规范

- 仅需 Common Name（CN），不含 SAN
- IP 证书的 CN 也写 IP 地址
- 默认密钥类型：RSA 2048
- CSR 生成后计算 SHA256 哈希用于去重

---

## 6. 升级协议

### 6.1 版本信息获取

```
GET {release_url}/releases.json
```

单文件，通道名做顶层 key：

```json
{
  "main": {
    "latest": "1.2.0",
    "versions": [
      {
        "version": "1.2.0",
        "released_at": "2026-03-20",
        "checksums": {
          "sslctl-linux-amd64.gz": "sha256:a1b2c3...",
          "sslctl-linux-arm64.gz": "sha256:d4e5f6..."
        }
      }
    ]
  },
  "dev": {
    "latest": "1.3.0-rc2",
    "versions": [
      {
        "version": "1.3.0-rc2",
        "released_at": "2026-03-28",
        "checksums": {
          "sslctl-linux-amd64.gz": "sha256:m4n5o6..."
        }
      }
    ]
  }
}
```

| 字段                   | 说明                                                   |
| ---------------------- | ------------------------------------------------------ |
| `{channel}`            | 顶层 key 为通道名（`main` / `dev`）                   |
| `{channel}.latest`     | 该通道最新版本号（不带 v 前缀）                        |
| `{channel}.versions`   | 该通道版本列表，按发布时间倒序，每通道最多保留 5 条    |
| `version`              | 版本号（不带 v 前缀），目录名加 v 前缀（`v1.2.0`）    |
| `released_at`          | 发布日期（YYYY-MM-DD）                                 |
| `checksums`            | 按文件名索引的 SHA256 哈希，支持多平台产物              |
| `source_commit`        | 可选；产物来源 Git commit，dev 发布必须记录             |
| `dirty`                | 可选；产物是否包含未提交改动，dev 发布必须记录           |

平台可在版本条目中增加扩展字段（如 sslctl 的 `signature`），与 `checksums` 同级。

客户端根据自身平台拼出文件名，在 `checksums` 中查找对应哈希。未找到 = 该版本不支持当前平台。

### 6.2 通道

| 通道   | 说明                   |
| ------ | ---------------------- |
| `main` | 正式版，仅含稳定版本   |
| `dev`  | 测试版，含 pre-release |

客户端根据 `upgrade_channel` 配置读取对应通道。`latest` 就是该通道最新版，无需额外过滤。

### 6.3 升级流程

```
1. 获取 {release_url}/releases.json
2. 读取 [upgrade_channel] 通道，比较 latest 与当前版本
3. 无更新则退出；有更新则在 versions 中找到 latest 对应条目
4. 拼出文件名，从 checksums 获取哈希
5. 下载：GET {release_url}/{channel}/v{version}/{filename}
6. SHA256 校验
7. 平台特定安装（扩展点）
```

### 6.4 安全要求

- 下载必须 HTTPS
- SHA256 校验必过
- 各平台可增加额外验证（如 Ed25519 签名、Authenticode 签名）

---

## 7. 安装脚本规范

### 7.1 参数

| 参数                    | 说明                                   |
| ----------------------- | -------------------------------------- |
| `releaseDomain`（必需） | 发布服务器域名，脚本自动拼接为完整 URL |
| `--version <ver>`       | 安装指定版本                           |
| `--dev`                 | 安装测试通道版本                       |
| `--force`               | 强制重新安装（即使已安装相同版本）     |

`releaseDomain` 省略时使用内置默认域名。参数可带子路径段（如 `cdn.example.com/mirror`），在该路径上继续探测。

### 7.2 发布目录探测

按以下顺序探测产品发布目录，首个返回有效 `releases.json` 的候选作为 `release_url` 基础 URL，后续下载、升级均沿用：

1. `https://{releaseDomain}/{product}/releases.json`（根目录布局）
2. `https://{releaseDomain}/release/{product}/releases.json`（`/release/` 回落布局）

判定标准：HTTP 2xx 且响应可解析为包含通道 key 的 JSON。两种布局均不可达时按网络错误退出，不静默使用默认值。

若 `releaseDomain` 已含路径段，相对路径在该段之上追加（`{host}/{path}/{product}/…` 与 `{host}/{path}/release/{product}/…`）。

### 7.3 安装流程

```
1. 解析参数，确定通道（main/dev）和版本
2. 探测发布目录（§7.2），确定 release_url
3. 获取 {release_url}/releases.json，从对应通道确定目标版本
4. 下载安装包
5. SHA256 校验
6. 解压安装到目标目录
7. 写入 release_url、upgrade_channel 到配置文件（已有配置和证书数据不覆盖）
8. 注册守护服务/计划任务（平台特定扩展点）
```

### 7.4 幂等性

- 重复执行 = 升级
- 已有配置文件（`config.json`）和证书数据目录不覆盖
- 服务/计划任务已存在时更新而非重复创建

---

## 8. 构建与发布

### 8.1 构建

各平台构建方式不同（Go binary / Python zip），但遵守统一约定：

- **版本号注入**：构建时注入版本号（语义化版本 x.y.z），运行时可通过 `--version` 查看
- **产物命名**：由各仓发布 skill 按平台安装与升级契约定义；同一平台和版本内必须稳定，版本统一在目录路径 `{channel}/v{version}/` 中体现，`checksums` 必须以实际公开文件名为 key
- **完整性信息**：每个发布产物的 SHA256 必须写入 `releases.json` 对应版本条目的 `checksums`；不要求生成或上传独立 `.sha256` 文件，平台可按需额外提供

### 8.2 发布目录结构

发布服务器上的目录布局：

```
{releaseDomain}/{product}/
├── releases.json                    # 版本索引（单文件，含所有通道）
├── main/
│   ├── v1.2.0/
│   │   ├── sslctl-linux-amd64.gz
│   │   └── sslctl-linux-arm64.gz
│   └── v1.1.0/
│       └── ...
└── dev/
    └── v1.3.0-rc2/
        └── ...
```

### 8.3 发布流程

```
1. 构建产物，注入版本号
2. 计算所有产物的 SHA256；平台可增加额外签名步骤（Ed25519、Authenticode 等）
3. 上传同一批产物到所有发布节点的对应通道目录
4. 核对各节点产物数量、SHA256 和平台签名（如适用）
5. 所有节点验证通过后更新 releases.json（追加或更新版本、更新 latest 字段）
```

同一次发布中，各发布节点和 GitHub Release 上属于“规范正式资产集合”的产物必须来自同一次构建且字节一致，不得为不同目标分别重建；`releases.json.checksums` 必须与该资产集合的实际文件名和 SHA256 一致。平台如需 GitHub-only 附加资产，必须在本仓发布 skill 中明确列出，且不得替代规范正式资产。

### 8.4 releases.json 维护

格式定义见 6.1 节。发布脚本负责：
- 根据版本类型写入对应通道（正式版 → `main`，pre-release → `dev`）
- 追加或更新版本条目（含 checksums、released_at）到对应通道 `versions` 首位，更新 `latest`
- 每通道保留最近 5 个版本条目，清理超出的旧条目及 `{channel}/v{version}/` 产物目录
- 版本条目和 `latest` 均不带 `v` 前缀；发布目录、版本 Git tag 使用 `v{version}`
- 通过同目录临时文件写入并原子替换 `releases.json`，避免中断产生半写文件

`dev` 通道允许同一版本重复发布并覆盖原条目；`main` 通道版本不可覆盖，规则见 8.6 和 8.7。

### 8.5 dev 测试版发布

`dev` 用于真机验证和快速反馈，优先保证发布灵活性：

- 版本号必须是带预发布段的 SemVer（如 `1.2.0-beta.1`、`1.2.0-rc.2`）
- 可直接发布当前工作区快照，允许存在未提交改动
- 不要求等待本地或 GitHub CI，不因当前 HEAD 已推送而增加 CI 门禁
- 不提交、不推送、不合并、不切换分支，不改变 `main` / `dev` 引用
- 不创建或移动任何 Git tag，不创建 GitHub Release
- 允许同一版本重复发布；重新发布时覆盖该版本产物和索引条目，并刷新 `released_at`、`checksums` 和 `latest`
- 版本条目必须写入 `source_commit` 和 `dirty`；工作区不干净时 `dirty` 必须为 `true`，明确该产物不完全对应 Git commit
- 构建、签名（平台要求时）、上传、远端哈希或索引更新任一步失败，均不得报告发布成功

dev 发布完成至少验证所有发布节点的版本目录可读、产物数量正确，且 `releases.json.dev.latest` 与实际产物 SHA256 一致。

### 8.6 main 正式版发布

`main` 用于可复现、可审计、不可变的正式发布。一个正式版本必须唯一对应一个 Git commit、一批确定的产物字节、一组 SHA256 和一个 GitHub Release。

正式发布按以下顺序执行：

1. 校验稳定 SemVer（不得含预发布段），且版本高于当前 `main.latest`
2. 确认工作区干净，本地 `dev` 与 `origin/dev` 指向同一 commit
3. 通过 `dev → main` Pull Request 发布；等待该仓发布 skill 明确列出且适用于该 PR commit 的 required checks 和 release gates 全部成功后合并
4. 同步本地 `main`，确认本地 `main` 与 `origin/main` 指向合并后的同一 commit，且工作区仍干净
5. 等待该仓发布 skill 明确列出且适用于该精确 `main` commit 的 required checks 和 release gates 全部成功
6. 从该 commit 只构建一次正式产物，完成签名，生成绑定版本、commit、规范资产集合和 SHA256 的发布 manifest
7. 将发布 manifest 和完整产物 bundle 持久保存到发布恢复期间不会被清理或重建的位置；将同一 bundle 暂存到所有发布节点并完成哈希与签名验证
8. 创建并推送不可变版本 tag `v{version}`；tag 必须指向该 `main` commit
9. 创建指向该 tag 和 commit 的 draft GitHub Release，从已保存 bundle 上传规范正式资产及平台声明的附加资产，并完成验收
10. 使用已暂存内容原子更新各节点的 `releases.json.main`，发布 GitHub Release；完成全节点对账后，将唯一可移动的 `latest` Git tag 更新到 `v{version}`
11. 将 `main` 以 fast-forward 方式同步回 `dev` 并推送，等待该仓发布 skill 明确列出且适用于该精确 `dev` commit 的 required checks 和 release gates 全部成功
12. 执行 8.9 的最终验收；验收完成前不得清理发布 bundle，全部通过后才可宣布发布完成

从 PR 合并开始到 `main` 同步回 `dev` 完成为正式发布窗口。该窗口内不得向 `dev` 添加新提交；如果 `dev` 已前进导致无法 fast-forward，必须停止收尾并处理分支差异，禁止通过 force-push 对齐。

### 8.7 正式版本与 Git 引用不可变性

- 初次发布前，`v{version}`、对应 GitHub Release 和 `main/v{version}/` 正式产物必须不存在
- `v{version}` 一旦推送不得删除、移动或覆盖；正式产物和已发布 GitHub Release 资产同样不得替换
- 发布脚本不得自动重建或移动已存在的版本 tag；发现 tag 指向其他 commit 必须立即失败
- `latest` 是唯一允许移动的 Git tag，且只能指向已完成产物验收的稳定版本 tag
- 正式 GitHub Release 必须非 draft、非 prerelease，标记为最新正式版，并包含该平台约定的全部正式发布资产；不要求附带独立 `.sha256` 文件
- `releases.json.main.latest`、版本 tag 和 `latest` Git tag 表达不同层次的引用，但最终必须指向同一正式版本
- 正式版本不得重复发布为不同 commit 或不同产物；需要修复时发布更高的新版本

### 8.8 中断恢复与多节点一致性

- 版本 tag 创建前失败：修复问题后可重新执行正式发布
- 版本 tag 创建后失败：只允许读取并校验已持久保存的发布 manifest 和 bundle，从失败点以 upload-only/resume 方式恢复；禁止移动 tag、调用构建流程或用重建产物覆盖
- 上传阶段先完成所有节点和 draft GitHub Release 资产暂存与哈希验证，再推进公开的 GitHub Release、版本索引和 `latest` 引用
- 任一节点失败时不得宣布成功，也不得只让部分节点长期保留新的 `latest`；修复失败节点后重新执行全节点验收
- GitHub Release、服务器索引或分支同步任一步失败时，保留已有不可变对象和发布 bundle，从失败点按相同 commit 和产物继续
- 已完成的正式版本不得通过删除 tag、覆盖资产或回写同版本修复；回滚或修复使用更高版本

### 8.9 正式版完成验收

正式发布只有同时满足以下条件才算完成：

- `dev → main` PR 已合并；本仓发布 skill 声明的适用 required checks 和 release gates 在 PR、合并后 `main`、回同步后 `dev` 三个阶段均成功，且均核对到对应的精确 commit
- 本地与远端 `main`、本地与远端 `dev`、`v{version}`、`latest`、GitHub Release target 全部指向同一 commit
- 工作区干净
- 所有发布节点及统一公网入口的 `main.latest` 均等于本次版本（不带 `v` 前缀）
- 所有发布节点和 GitHub Release 的规范正式资产集合完整且字节一致，SHA256 与 `releases.json.checksums` 一致；平台声明的 GitHub-only 附加资产也完整
- GitHub Release 已公开，非 draft、非 prerelease，并标记为最新正式版
- 产物内注入的版本号正确，平台要求的签名验证通过
- 至少通过公网入口实际下载一个代表产物并完成 SHA256 校验

---

## 9. 卸载流程

### 9.1 卸载步骤

```
1. 停止并移除守护服务/计划任务
2. 删除程序文件
3. 可选：清理配置和证书数据（需用户确认）
```

### 9.2 卸载原则

- 默认保留配置和证书数据，防止误删
- 提供 `--purge` 或交互确认选项，允许完全清理
- 卸载不影响已部署到 Web 服务器的证书（证书已复制到站点目录）

---

## 10. 安全规范

各平台实现必须遵守的共通安全要求。

### 10.1 网络安全

- **HTTPS 强制**：API 请求必须使用 HTTPS，仅 localhost/127.0.0.1 允许 HTTP
- **TLS 版本**：最低 TLS 1.2
- **SSRF 防护**：阻止访问内网 IP（10.0.0.0/8、172.16.0.0/12、192.168.0.0/16）、未指定地址（0.0.0.0、::）和云元数据地址（169.254.169.254）
- **DNS Rebinding 防护**：TCP 连接时二次校验目标 IP

### 10.2 文件系统安全

- **符号链接防护**：读写文件前检查路径是否为符号链接，拒绝操作符号链接目标
- **路径遍历防护**：拼接路径后验证结果仍在预期目录内，拒绝包含 `..` 的输入
- **原子写入**：配置文件和证书写入使用临时文件 + rename，防止写入中断导致数据损坏
- **文件权限**：私钥文件 0600，配置文件 0600，目录 0700
- **配置文件锁**：并发操作时使用文件锁保护，防止多进程同时写入

### 10.3 证书与密钥

- **证书验证**：部署前验证证书格式、有效性、证书与私钥匹配
- **中间证书必需**：API 部署时必须包含中间证书，缺失则拒绝部署
- **私钥保护**：私钥写入使用原子操作，local 模式下新私钥先存 pending-keys/，证书私钥配对校验通过且部署成功后再移到正式位置（与 3.8 一致；配对校验失败时保留 pending key，线上私钥不受影响）。POST 超时 / 断连 / 响应解析失败等不确定结果保留 pending key 作为在途标记，下轮查询订单状态恢复（不重复 POST）；仅在明确业务拒绝且确认未创建新证书、或签发部署完成后才清理 pending key
- **大小限制**：私钥 ≤ 16 KB，证书链 ≤ 64 KB，超过则拒绝

### 10.4 日志与敏感信息

- **日志脱敏**：自动过滤私钥内容、Bearer Token、API Token、密码等敏感信息
- **路径脱敏**：错误消息中使用相对路径，避免泄露服务器目录结构

### 10.5 升级安全

- **HTTPS 下载**：升级包必须通过 HTTPS 下载
- **完整性校验**：SHA256 校验必过，校验失败拒绝安装
- **通道白名单**：仅允许 `main`/`dev` 通道，防止路径遍历
- **各平台可增加额外验证**（如 Ed25519 签名、Authenticode 签名）

---

## 11. 共享常量

### 续签相关

| 常量             | 值  | 说明                                                            |
| ---------------- | --- | --------------------------------------------------------------- |
| 默认提前续签天数 | 14  | `schedule.renew_before_days` 初始值，后续由 API 返回值覆写      |
| 提前续签天数上限 | 30  | 无论续费或重签都应在到期前 30 天内，超限拒绝并保留本地现值（见 2.9） |
| 签发/部署尝试上限 | 10  | 签发（CSR 提交，`issue_retry_count`）与部署（`deploy_attempt_count`）分别计数，各自 `>= 10` 触顶后进入 `CAPPED` 停止，等待人工处理 |
| 自动动作安全余量 | 24 小时 | 证书剩余有效期小于该值时不再启动新的签发/部署动作 |
| 单次续签批量上限 | 100 | 单次续签检查最多处理的证书数量，防止长时间阻塞                  |

### 分散延迟

| 常量       | 值     | 说明                                                  |
| ---------- | ------ | ----------------------------------------------------- |
| 延迟最小值 | 5 秒   | 证书间延迟下限，保证即使证书很多也有基本间隔          |
| 延迟最大值 | 120 秒 | 证书间延迟上限，防止证书少时等待过久                  |
| 延迟总预算 | 600 秒 | 所有证书间延迟总上限，per-cert = clamp(600/N, 5, 120) |

### 文件大小限制

| 常量       | 值    | 说明                                        |
| ---------- | ----- | ------------------------------------------- |
| 私钥最大   | 16 KB | 私钥 PEM 文件大小上限，超过则拒绝           |
| 证书链最大 | 64 KB | 完整证书链（cert + intermediate）总大小上限 |

### API 超时与重试

| 常量          | 值    | 说明                                                |
| ------------- | ----- | --------------------------------------------------- |
| 查询超时      | 30 秒 | GET 请求的超时时间                                  |
| 提交/回调超时 | 60 秒 | POST 请求的超时时间                                 |
| 最大重试次数  | 3     | HTTP 5xx/网络错误时的重试次数，指数退避（1s→2s→4s） |

### 升级通道

| 常量       | 值             | 说明                           |
| ---------- | -------------- | ------------------------------ |
| 通道白名单 | `main` / `dev` | 允许的升级通道值，防止路径遍历 |

---

## 12. 智能体配置与 Skill 组织

### 12.1 项目级智能体配置

- `AGENTS.md` 是项目级智能体规则的唯一入口和路由文件，适用于 Claude Code、Codex 及其他智能体；具体跨仓规范和领域工作流仍分别以 `deploy-spec.md` 和对应 skill 为权威来源
- `CLAUDE.md` 是固定的 Claude 兼容入口，只引用 `AGENTS.md`，不得复制或维护独立规则
- 两个文件均作为普通文件提交，不使用仓库内符号链接，避免 Windows checkout 兼容问题
- `AGENTS.md` 保持精简，只包含项目定位、不可违反的项目规则、权威资料入口、核心构建/测试命令和平台边界
- 目录结构、详细实现、发布步骤、检查清单、API 字段说明等内容写入对应 skill、专题文档或脚本，不堆积到 `AGENTS.md`
- 工具私有的权限、hooks、MCP、插件和 UI 配置保留在各工具自己的配置文件中，不写入共享项目规则

每个仓库的 `AGENTS.md` 必须用精简表述在自身包含以下“更新原则”：

- 只记录长期有效、项目级、会影响智能体行为的规则；临时决策、调试记录、单一模块实现细节不得写入
- 新增内容前先判断职责归属：跨仓公共行为写入 `deploy-spec.md`，领域知识和工作流写入对应叶子资源，`AGENTS.md` 只提供入口和不可违反的项目约束，不复制两者正文
- 只直接维护 `AGENTS.md`；`CLAUDE.md` 始终保持固定薄入口，不在其中追加项目规则
- 新增、删除或重命名 skill 时同步更新 `skills/SKILL.md` 及受影响的引用入口
- 修改后删除失效或重复内容，并检查 `CLAUDE.md` 固定模板、skill 路由、引用路径和确定性防漂移门禁；未经明确需求不得新增全局约束

`CLAUDE.md` 使用以下固定模板：

```markdown
# 项目智能体规则

@AGENTS.md

本文件仅为 Claude 兼容入口。禁止在此追加项目规则；需要调整时修改 `AGENTS.md` 或其引用的权威资料。
```

如果工具修改了 `CLAUDE.md`，不得直接将其内容覆盖到 `AGENTS.md`。应先审查改动：核心规则迁移到 `AGENTS.md`，详细规则迁移到对应 skill，工具私有内容迁移到工具配置，最后恢复固定模板。

### 12.2 skills 目录

`skills/` 使用扁平结构：

```text
skills/
├── SKILL.md
├── remote-release.md
├── finish-check.md
├── build-release.md
└── ...
```

- `skills/SKILL.md` 是唯一可发现的 skill 入口和路由器；文件名使用标准大写形式，并包含工具要求的入口元数据
- `SKILL.md` 只维护触发场景、路由规则和叶子文件路径，不承载领域实现细节；`AGENTS.md` 要求智能体在匹配任务中读取该入口及其选中的叶子资源
- 其他文件是由根入口引用的叶子资源，直接存放在 `skills/` 根目录，不建立领域子目录；不宣称其可被 Claude、Codex 或其他工具原生独立发现
- 叶子资源文件名统一使用 kebab-case（如 `build-release.md`、`iis-ops.md`），不得再使用 `<name>/SKILL.md` 结构
- 每个叶子资源只维护一个清晰领域的知识或工作流；公共规则通过引用权威文档复用，不在多个资源中复制
- `remote-release.md` 是本仓发布流程的唯一权威实现，必须遵守第 8 节，并可引用 `build-release.md` 中的平台构建、签名和产物细节
- `finish-check.md` 维护本仓完成检查；工具命令不得复制其检查清单

### 12.3 工具自定义指令

- Claude、Codex 及其他工具的自定义指令只作为薄入口：引用一个对应 skill，并将用户参数原样转交
- 参数校验、执行步骤、命令示例、安全门禁、失败恢复和验收规则全部维护在 skill 中，不得复制到工具指令
- 同一语义的不同工具入口必须引用同一个 skill；工具仅可因参数占位符或入口格式不同保留最小适配内容
- 工具不支持自定义指令时，直接通过 `skills/SKILL.md` 路由到对应 skill，不另建重复流程

示例（具体参数占位符按工具语法调整）：

```markdown
读取并严格遵循 `skills/remote-release.md`。

将用户参数原样作为版本参数传入该流程。
```

### 12.4 文档职责与优先级

```text
deploy-spec.md          跨仓统一行为规范
AGENTS.md               项目核心规则与权威入口
skills/SKILL.md         skill 路由索引
skills/*.md             由根 Skill 路由的领域知识和可执行工作流资源
工具自定义指令          skill 调用与参数适配
```

- 跨仓公共行为以 `deploy-spec.md` 为准，skill 不得静默改变其语义
- 平台差异由各仓叶子资源实现；确需偏离公共规范时，必须先在 `deploy-spec.md` 的对应规则或平台豁免中明确记录，叶子资源只引用该豁免及原因
- `AGENTS.md` 不复制 `deploy-spec.md` 或 skill 正文，只声明权威入口和项目级硬约束
- 工具自定义指令不拥有业务规则，和 skill 冲突时以 skill 为准

### 12.5 防漂移检查

各仓 CI 必须执行可确定判定的本仓结构检查，`finish-check` 可在本地重复执行；跨仓一致性由统一的多仓同步或审计流程检查：

- `CLAUDE.md` 与 12.1 的固定模板一致
- `skills/` 下不存在二级 skill 目录，叶子文件名符合 kebab-case
- `skills/SKILL.md` 中列出的叶子文件全部存在
- 项目文档不再引用旧的 `skills/<name>/SKILL.md` 路径
- 固定模板的工具自定义指令与预期模板哈希一致，并引用存在的对应叶子资源
- 统一多仓流程检查四仓（`ssl-manager`、`sslctl`、`sslctlw`、`sslbt`）`deploy-spec.md` 字节一致；单仓 CI 不拉取其他仓库的移动分支进行比较

第 12 节描述四仓完成智能体配置同步后的目标状态。规范可先行同步；在单仓完成结构迁移前，不启用引用尚不存在文件的结构门禁。某仓完成迁移后，本节即成为该仓必须持续满足的现行约束。
