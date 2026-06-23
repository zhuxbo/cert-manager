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

### 5. 测试

```php
$mockFactory = Mockery::mock(\App\Services\Acme\Api\Api::class);
$mockFactory->shouldReceive('getSourceApi')->andReturn($mockSourceApi);
app()->instance(\App\Services\Acme\Api\Api::class, $mockFactory);
```

## Sdk 超时约定（防 1205 锁等待超时）

`Order\Api\default\Sdk::call()` 与 `Acme\Api\default\Sdk` 的上游 HTTP 调用**必须有 timeout 上限**，且**锁内调用的 timeout 必须 < `innodb_lock_wait_timeout`（默认 50s）**。

**为什么**：`commit()`（下单 new/renew/reissue）和 `cancel()` 在 `orders`/`acmes` 行锁内同步调上游（资金安全要求，见主 `CLAUDE.md`「资金/状态变更必须在事务 + 行锁内」）。Guzzle `new Client` 默认 `timeout=0`（无限等待），上游慢/挂时持锁事务无限阻塞，超过 50s 后任何并发访问同一订单行的 `for update`（另一个 commit/cancel/sync 写回/commitCancel/revokeCancel/markRenewed）都会报 `SQLSTATE[HY000] 1205 Lock wait timeout`。

**Order default Sdk**：`call()` 带可选第四参 `?int $timeout`，经 `makeClient()` 注入缝传给 Guzzle client config（`connect_timeout = min(10, $timeout)` + `timeout`）。
- 锁内：`new` / `renew` / `reissue` / `cancel` → 28s
- refer_id 反查（在 commit 锁内栈内触发，`getOrderIdByReferId` + 反查的 `get($id, 10)`）→ 10s，锁内最坏 `28+10+10=48 < 50`（留 2s 裕度）；反查与 new 超时互斥但 `t_new` 可逼近 28s 故仍受此约束
- 锁外（`$timeout=null`，不限时，避免掐断耗时操作）：`uploadDocument`（Certum 文档 base64 上传，可能数 MB）、`sync` 的 `get`、`getProducts`/`getOrders`/`revalidate`/`updateDCV`

**ACME default Sdk**：无文档上传、无 refer_id 反查，`request()` 全局 `Http::timeout(30)` 即可（30 < 50）。

**新增 source 的 Sdk 必须遵守**：锁内上游调用设 timeout < 50s（多次串联调用时确保总和 < 50s）；含耗时上传的调用单独留无超时通道。`makeClient()` 注入缝便于测试断言 timeout（参考 `tests/Unit/Services/Order/Api/DefaultSdkTimeoutTest.php`：用 array driver 注入 `setting:group_name:ca` 缓存绕过 DB，子类覆盖 `makeClient` 捕获 config + MockHandler 短路 HTTP）。

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
