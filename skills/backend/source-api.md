---
description: Source API 接入 - 新增上游来源的开发指南。修改 Order\Api 或 Acme\Api 相关代码时加载。
---

# Source API 接入指南

Manager 通过两套 Source API 分发层与上游交互，均按 `product.source` 字段路由：

| 命名空间        | 职责                                                     | 当前来源  |
| --------------- | -------------------------------------------------------- | --------- |
| `Order\Api\Api` | 传统订单 CRUD（new/renew/reissue/get/cancel/revalidate） | `default` |
| `Acme\Api\Api`  | ACME 流程（创建/验证/签发/吊销），调上游 REST API        | `default` |

两套独立运作，新增来源时两套都需要实现。

## 目录结构

```
backend/app/Services/
├── Order/Api/                       # 传统订单 API
│   ├── OrderSourceApiInterface.php  # 接口定义（8 个方法）
│   ├── Api.php                      # 工厂（getSourceApi → error 终止）
│   └── default/
│       ├── Api.php                  # 业务逻辑 + 参数整理
│       └── Sdk.php                  # HTTP 客户端（上游 /api/v2/*）
│
└── Acme/Api/                        # ACME API
    ├── AcmeSourceApiInterface.php   # 接口定义
    ├── Api.php                      # 工厂（getSourceApi → error 终止）
    └── default/
        ├── Api.php                  # 实现 AcmeSourceApiInterface
        └── Sdk.php                  # HTTP 客户端（上游 /api/v2/acme/*）
```

## 两套 Api.php 的架构差异（设计意图）

|          | `Acme\Api\Api`                   | `Order\Api\Api`                                       |
| -------- | -------------------------------- | ----------------------------------------------------- |
| 定位     | 纯工厂，只返回 source 实例       | 门面（Facade），代理所有业务方法                      |
| 业务逻辑 | 由 `OrderService` 统一编排       | 内置 `findOrder` + `handleResult`                     |
| 原因     | ACME 协议标准化，source 间差异小 | 传统 API 各家差异大，需在 source 内处理后提供统一调用 |

这是有意的设计，不需要统一。

## 工厂模式

两个工厂的路由逻辑一致：

```php
$class = __NAMESPACE__.'\\'.strtolower($source).'\\Api';
```

未找到类 → `$this->error()` 抛异常终止。

**空 source 必须报错，禁止回落 default**：`product.source` 为空说明数据有问题，回落会掩盖配置错误。调用方传 `$product->source ?? ''`，由工厂的 `! $source` 检查报错。此原则适用于 Manager 和上游系统两个项目的 ACME 和传统 API。

## ACME Source API 接口

```php
interface AcmeSourceApiInterface
{
    public function createOrder(string $customer, string $productCode, array $domains, ?string $referId = null): array;
    public function reissueOrder(int $orderId, array $domains, ?string $referId = null): array;
    public function respondToChallenge(int $challengeId): array;
    public function finalizeOrder(int $orderId, string $csr): array;
    public function getCertificate(int $orderId): array;
    public function cancelOrder(int $orderId): array;
    public function revokeCertificate(string $serialNumber, string $reason = 'UNSPECIFIED'): array;
    public function isConfigured(): bool;
}
```

方法对应上游的 `/api/v2/acme/*` REST 端点。

## Order API 接口定义

```php
interface OrderSourceApiInterface
{
    public function getProducts(string $brand = '', string $code = ''): array;
    public function new(array $data): array;
    public function renew(array $data): array;
    public function reissue(array $data): array;
    public function get(string|int $apiId, array $cert = []): array;
    public function cancel(string|int $apiId, array $cert = []): array;
    public function revalidate(string|int $apiId, array $cert = []): array;
    public function updateDCV(string|int $apiId, string $method, array $cert = []): array;
}
```

8 个核心方法通过接口约束，`getOrders` 等可选方法仍用 `checkMethodExists()` 运行时检查。

## Certum 续费的 SAN 继承边界

传统订单的 `renew` 在 Manager 内仍是**新订单**：创建新 `orders`/`certs` 记录，主价格、基础 SAN 配额及超额 SAN 全部按新购口径计费；源证书只用于续费资格、接替关系和状态翻转。这一点不能与上游接口的域名能力混为一谈。

Certum 是需要保留续费 SAN 限制的特殊来源。Certum `renewCertificate` 接口只提交 `customer`、续费产品码、CSR、原证书序列号及验证方式等字段，**不提交域名列表或 `SANEntries`**；续费域名等订阅者数据从原证书继承。`SANEntries` 只用于 `reissueCertificate` 的新增域名。因此：

- `add_san=0` 时，续费不得提交超过原证书标准/通配符数量的本地 SAN 集合；否则 Manager 会记录上游不会兑现的新增域名。
- `replace_san=0` 时，续费必须保留并合并原证书 SAN；否则 Manager 会记录上游不会兑现的删除或替换。
- 合并仅发生在 `replace_san=0`；合并、去重后必须对最终完整域名集合重新计算 SAN 数量，并应用 `gift_root_domain`，避免赠送根域名跨新旧集合时重复计数。
- 这两个限制表达的是**产品/上游操作能力**，不是“续费复用原订单”或“续费按重签增量计费”。不得仅因续费创建新订单，就从 `ActionTrait::getCert()` 删除 `renew` 的 `add_san` / `replace_san` 约束。
- 非 Certum 来源若续费接口支持完整替换 SAN，应通过准确的产品能力值表达；不要在 Manager 中按 CA 名称硬编码分支。

相关测试应同时固定两条轴：续费金额与新增同口径；`add_san=0` / `replace_san=0` 时，本地续费证书的最终 SAN 集合与上游继承能力一致。

## 返回值约定

- 成功：`['code' => 1, 'data' => [...]]`
- 失败：`['code' => 0, 'msg' => '...']`

## ACME 调用方分布

OrderService 集中封装上游调用方法（供其他 Service 复用）：

- `submitNewOrder()` / `submitReissue()` — 接受可选 `$sourceApi` 参数避免重复查找
- `revokeCertificateUpstream(Cert)` — 吊销 + 更新本地状态
- `cancelOrderUpstream(Cert)` — best-effort 取消
- `getCertificateFromUpstream(Cert)` — 获取证书数据

Action 用 `app(OrderService::class)` 调用（非构造器注入，避免循环依赖）。

Source 获取统一模式：

```php
$source = $cert->order?->product?->source ?? 'default';
$sourceApi = app(Api\Api::class)->getSourceApi($source);
```

## 新增来源步骤

### 1. 传统订单 API

创建 `backend/app/Services/Order/Api/{sourcename}/`：

- `Api.php` — 实现 `OrderSourceApiInterface`
- `Sdk.php` — HTTP 客户端

### 2. ACME API

创建 `backend/app/Services/Acme/Api/{sourcename}/`：

- `Api.php` — 实现 `AcmeSourceApiInterface`
- `Sdk.php` — HTTP 客户端

### 3. 产品配置

`products` 表对应产品的 `source` 字段设为 `{sourcename}`。

### 4. 系统设置

如需独立配置（API 地址、Token），在 `system_settings` 表 `ca` 组添加对应键。

### 回调入口与 ID 字段约定

- `callback` 设置组的每个键名就是一个接入商回调入口：`/callback/{endpoint}` 读取 `callback.{endpoint}`；非 `default` 入口未配置时才回落 `callback.default`。
- 一个键名对应一家接入商，该接入商回调的订单 ID 参数名是统一契约，故保持单值 `id_field`；不扩展为逗号分隔的 `id_fields` 多字段尝试。
- 若另一家接入商使用不同 ID 参数名，应新增独立回调键名并配置其 `id_field`，不在同一入口内猜测多个字段。

### 5. 测试

```php
$mockFactory = Mockery::mock(\App\Services\Acme\Api\Api::class);
$mockFactory->shouldReceive('getSourceApi')->andReturn($mockSourceApi);
app()->instance(\App\Services\Acme\Api\Api::class, $mockFactory);
```

## Sdk 超时约定（防 1205 锁等待超时）

`Order\Api\default\Sdk::call()` 与 `Acme\Api\default\Sdk` 的上游 HTTP 调用**必须有 timeout 上限**，且**锁内调用的 timeout 必须 < `innodb_lock_wait_timeout`（已通过 `config/database.php` 的 PDO `MYSQL_ATTR_INIT_COMMAND` 固化为 session=50、覆盖 global 漂移，见 `InnodbLockWaitTimeoutTest`）**。

**为什么**：`commit()`（下单 new/renew/reissue）和 `cancel()` 在 `orders`/`acmes` 行锁内同步调上游（资金安全要求见 `skills/backend/order-fund.md`）。Guzzle `new Client` 默认 `timeout=0`（无限等待），上游慢/挂时持锁事务无限阻塞，超过 50s 后任何并发访问同一订单行的 `for update`（另一个 commit/cancel/sync 写回/commitCancel/revokeCancel/markRenewed）都会报 `SQLSTATE[HY000] 1205 Lock wait timeout`。

**Order default Sdk**：`call()` 带可选第四参 `?int $timeout`，经 `makeClient()` 注入缝传给 Guzzle client config（`connect_timeout = min(10, $timeout)` + `timeout`；Guzzle `timeout` 含 connect，单次墙钟上限 = `timeout`）。connect_timeout 取 10s（早期 3s）：manager 是多级代理，上游可能是任意深度的另一个 manager，网络路径/DNS/地域全不可控，按"不可控上游"处理，对齐 callback 的 `connectTimeout(10)`；10 < 50 不破坏 1205 防护（总 timeout 45s 封顶不变，connect 不叠加），黑洞上游失败慢一点换多级链路的连接宽容，是有意取舍。

- 锁内：`new` / `renew` / `reissue` / `cancel` → **45s**。commit 锁内**只有一个**上游调用（下单或 cancel），`45 < 50`（留 5s 裕度 + 锁内 `save()` 开销）。**default 源只对接同构 V2 的上游，上游对已存在 refer_id 一律幂等返回 order_id（code=1），绝不返回含 "Refer id" 的 code=0，故本地不做 refer_id 反查、锁内不会串第二个调用**（对接其它 CA 走独立 source 另行处理，不共用本预算约定）
- 锁外也设超时上限**防 FPM worker 被上游挂死永久占用**（`max_execution_time` 不计 socket 阻塞、只有 FPM `request_terminate_timeout` 能兜且部署默认未设，不可靠）：
  - `uploadDocument`（唯一调用方 `SubmitDocumentJob`(queue)，被 worker `--timeout 60` 的 SIGALRM 硬杀；单文件 ≤5MB，base64 ~6.7MB 正常网络 <10s）→ **55s**（< worker 60，让 Guzzle 自己先干净断，交给 Job 的 backoff 重试，而非被 SIGALRM 硬杀进程）
  - 其他 `sync` 的 `get`（默认 30s）/`getProducts`/`getOrders`/`revalidate`/`updateDCV` → **30s**（非耗时，仅防 hang）

**ACME default Sdk**：无文档上传、无 refer_id 反查，`request()` 全局 `Http::timeout(30)->connectTimeout(10)`（对齐 Order Sdk min(10,timeout)，多级代理上游按不可控处理）即可（30 < 50）。

**新增 source 的 Sdk 必须遵守**：锁内上游调用设 timeout < 50s（多次串联调用时确保总和 < 50s）；**锁外调用（尤其有 FPM 同步入口的）也须设上限防 worker 被上游 hang 拖死**（有 queue worker 消费的调用应 < worker `--timeout`，让 Guzzle 先于 SIGALRM 干净断；普通查询/操作 30s）。`makeClient()` 注入缝便于测试断言 timeout（参考 `tests/Unit/Services/Order/Api/DefaultSdkTimeoutTest.php`：用 array driver 注入 `setting:group_name:ca` 缓存绕过 DB，子类覆盖 `makeClient` 捕获 config + MockHandler 短路 HTTP）。

## Sdk catch 脱敏（异常原文不外泄内部地址）

`Order\Api\default\Sdk::call()` 的 catch 分支**绝不能把 Guzzle 异常原文（含上游内部地址）拼进对外 `msg`**——原文经 `Api → ApiResponseException(status=200) → ApiExceptions` 第一分支会绕过脱敏 match，以 HTTP 200 泄露给下游客户端（2026-06-30 事故：`'Request failed: '.$e->getMessage()` 把 `cURL error 28 ... https://<上游内部地址>/…` 直接返给下游）。约定：

- `catch (ConnectException $e)`（连接失败/超时，含 cURL 28）→ 返 `['code' => 0, 'msg' => '上游连接超时，请稍后重试']`
- `catch (GuzzleException $e)`（其余）→ 返 `['code' => 0, 'msg' => '上游请求失败，请稍后重试']`
- **catch 顺序子类先于父类**（`ConnectException` implements `GuzzleException`，写反则超时掉进"请求失败"分支）
- 两分支都先 `app(ApiExceptions::class)->logException($e)` 把原文（含内部 URL）落 `error_logs` 供排障

契约测试 `tests/Unit/Services/Order/Api/DefaultSdkCatchScrubTest.php` 用 `MockHandler` 注入抛异常客户端，断言对外 msg 不含 host/http/上游内部地址、原文仍被 `logException` 记录。ACME Sdk 走 Laravel `Http` facade，异常文案同理不得回传原文。

## Sdk ca_logs 请求耗时（全路径记录 duration）

`Order\Api\default\Sdk::call()` 全路径（成功 / 超时 / 失败）统一经私有 `logCall()` 写一条 `ca_logs`，含 `duration = round(now - startTime, 3)` 秒（`startTime` 在 `makeClient` 后、发请求前捕获），与 `Acme\Api\default\Sdk` 对齐。要点：

- **duration 必记**：`ca_logs.duration` 列早已存在（`decimal` 默认 0，注释「耗时(秒)」）、`CaLog` fillable/casts 也含 `duration`，但历史上 Order Sdk 从不测量/写入 → Order 侧（占绝大多数）ca_logs 耗时**恒为 0**（ACME Sdk 一直正确，二者不对称）。新增 source 的 Sdk 也须记 duration。
- **超时/失败也写 ca_logs**：catch 分支不再「提前 return、完全不写 ca_logs」，而是 `logCall(..., status_code=0)` 记一条（`status=0`）—— 上游变慢/挂起正是耗时最该被看见的场景（`error_logs` 存异常原文供排障、`ca_logs` 存请求记录 + 真实耗时，各司其职）。
- **response 仍脱敏**：ca_logs 的 `response` 走 `LogScrubber::scrubResponse` 存通用文案，**绝不写 `$e->getMessage()`**（含内部 URL）。
- **不进锁 / 不加持锁时长**：`LogBuffer::add` 仅追加内存缓冲（请求末尾 flush），锁内 commit 的 ca_log 写入不产生 DB IO。
- 契约测试 `tests/Unit/Services/Order/Api/DefaultSdkDurationLogTest.php`：完成请求 duration>0、超时也写一条 ca_logs 且脱敏。

## ACME Sdk 配置回落规则

`Acme\Api\default\Sdk` 构造函数中，`acmeToken` / `acmeUrl` 仅当值为 `null`（未配置）时回落到 `token` / `url`，空字符串不回落。设计意图：允许管理员显式置空以禁用 ACME 功能。

回落 `acmeUrl` 时按 `ca.url`（形如 `.../api/v2`）追加 `/acme` 得 `.../api/v2/acme`（一个 api v2 Token 同时覆盖 v2 与 acme，无需单独配 `acme_url`/`acme_token`）。

## 与上游的关系

Manager 是多级代理系统，上游可以是另一个 Manager 或其他 API 服务。两套 Api 通过 HTTP 客户端（Sdk）调上游 REST API，在上游侧完成实际的 CA 对接。

```
Manager Order\Api  → 上游 /api/v1/*   → ... → CA
Manager Acme\Api   → 上游 /api/v2/acme/* → ... → CA（ACME 协议）
```

新增来源时，Manager 和上游两侧都需要实现对应的 Source API。

**部署顺序依赖（上游优先）**：对外 ACME API 路径与 Sdk 外发均为 `/api/v2/acme/*`。当上游尚未迁到该路径时，default source 的 `get`/`cancel`/`getProducts` 会 404。升级多级链路时必须**自上而下先迁上游、再迁本级**，避免外发 404 窗口。
