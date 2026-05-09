# ACME 端到端测试

本地 Docker 环境下 ACME 全链路端到端测试方案（**封装下单 + 交付 EAB** 模式）。

## 架构

```
客户端（Deploy Token / Admin / User）
    ↓ POST /api/deploy/acme/new
Manager backend (:5300)
    ↓ POST /api/acme/new
上游系统 backend (:6300)
    ↓ REST API
Certum CA
    → 返回 { eab_kid, eab_hmac, directory_url }

certbot
    ↓ 直连 CA (--server <directory_url>)
Certum ACME Server
```

**关键事实**：

- Manager 不再实现 RFC 8555 服务端，不提供 `/acme/directory` 端点
- `directory_url` 由上游返回（如 `https://acme.test.certum.pl/directory/`）
- certbot 拿到 EAB + directory_url 后**直接与 CA 通信**，Manager 不参与证书签发 / 吊销过程
- Manager 只管订阅生命周期：创建 → 扣费 → 提交上游 → 返回 EAB；取消 → 通知上游 → 退费

## 正式运行前配置检查

### 上游系统配置

`system_settings` 表 `group='ca'`：

| key              | 说明                 |
| ---------------- | -------------------- |
| `certumRestUrl`  | Certum REST API 地址 |
| `certumOauthUrl` | Certum OAuth 地址    |
| `certumClientId` | OAuth 客户端 ID      |
| `certumUsername` | OAuth 用户名         |
| `certumPassword` | OAuth 密码           |

`users` 表需有 API 用户，其 `api_token` 供 Manager 调用。
`products` 表需有 `product_type='acme'` 且 `status=1` 的 ACME 产品。

### Manager 配置

`system_settings` 表 `group='ca'`：

| key          | 说明                                                                                                |
| ------------ | --------------------------------------------------------------------------------------------------- |
| `acme_url`   | 上游系统 ACME API 地址（如 `http://upstream-backend:8000/api/acme`），可回落 `url` 字段自动替换路径 |
| `acme_token` | 上游系统 API Token（回落 `token` 字段）                                                             |

Manager 还需要：

- `products` 表有 `product_type='acme'` 且 `status=1` 的 ACME 产品
- 用户有足够余额
- 有一个 Deploy Token（`deploy_tokens` 表），用于调用 `/api/deploy/acme/*`

### 凭据：Deploy Token

```sql
-- 查已有 Deploy Token
SELECT id, user_id, name, token FROM deploy_tokens WHERE user_id = <USER_ID>;

-- 或通过 Admin API 创建
-- POST /api/admin/deploy-token ...
```

## 自动化脚本

### check-backend.sh — 环境检查

```bash
bash manager/skills/acme-e2e-test/check-backend.sh
# 自定义 URL
MANAGER_URL=http://localhost:5301 UPSTREAM_URL=http://localhost:6301 \
  bash manager/skills/acme-e2e-test/check-backend.sh
```

检查项：

1. Docker 可用
2. Manager 可达（任意路由 HTTP 状态码非 000）
3. 上游系统可达

### run-e2e.sh — 完整 E2E 流程

**参数**：

| 参数             | 必填 | 说明                                                      |
| ---------------- | ---- | --------------------------------------------------------- |
| `--deploy-token` | 是   | Manager Deploy Token                                      |
| `--product-id`   | 是   | ACME 产品 ID（`products.id` where `product_type='acme'`） |
| `--domain`       | 是   | 测试域名（如 `test.example.com` 或 `*.test.example.com`） |
| `--period`       | 否   | 订阅时长（月），默认 12                                   |
| `--plus`         | 否   | 赠送时间 0/1，默认 1                                      |
| `--email`        | 否   | certbot 注册邮箱，默认 test@example.com                   |
| `--manager`      | 否   | Manager URL，默认 http://localhost:5300                   |
| `--clean`        | 否   | 清理 certbot volumes 后退出                               |

**使用**：

```bash
# 完整流程
bash manager/skills/acme-e2e-test/run-e2e.sh \
  --deploy-token "dpl_xxxxxxxx" \
  --product-id 57 \
  --domain "test.example.com"

# 清理 certbot volumes
bash manager/skills/acme-e2e-test/run-e2e.sh --clean
```

**自动化步骤**：

1. 环境检查（调 check-backend.sh）
2. 调 `POST /api/deploy/acme/new` 一步到位创建订阅，拿到 `{order_id, eab_kid, eab_hmac, directory_url}`
3. `certbot register` — 用 `directory_url` + EAB 直接向 CA 注册账号（Manager 不经手）
4. `certbot certonly` — 用户自行配置 DNS-01 TXT 记录并回车
5. `certbot certificates` — 验证签发
6. `certbot revoke` — 吊销证书
7. 打印取消订阅指引（人工通过 Admin/User API 取消）

每个关键步骤后打印数据库验证 SQL，供人工在数据库中执行检查。

## 手动测试流程

### 步骤 1：创建订阅 + 获取 EAB

**Deploy API**（推荐，单用户自动化场景）：

```bash
curl -sS -X POST http://localhost:5300/api/deploy/acme/new \
  -H "Authorization: Bearer <deploy-token>" \
  -H "Content-Type: application/json" \
  -d '{"product_id":57,"period":12,"plus":1}' | jq .
```

返回：

```json
{
  "code": 1,
  "data": {
    "order_id": 75518...,
    "eab_kid": "08Y7ey...",
    "eab_hmac": "MGY0YW...",
    "status": "active",
    "directory_url": "https://acme.test.certum.pl/directory/"
  }
}
```

**Admin/User 三步 API**（拆分 new / pay / commit）：

```bash
# 1. 创建 unpaid 订阅
POST /api/user/acme/new  {"product_id":57,"period":12,"plus":1}

# 2. 支付（余额扣费）
POST /api/user/acme/pay/{id}

# 3. 提交上游（拿 EAB + directory_url）
POST /api/user/acme/commit/{id}

# 查看详情
GET /api/user/acme/{id}
```

### 步骤 2：certbot 注册

```bash
docker run --rm \
  -v certbot-e2e-etc:/etc/letsencrypt \
  -v certbot-e2e-var:/var/lib/letsencrypt \
  certbot/certbot register \
  --server "<directory_url>" \
  --eab-kid "<eab_kid>" \
  --eab-hmac-key "<eab_hmac>" \
  --email test@example.com \
  --no-eff-email --agree-tos
```

### 步骤 3：申请证书

```bash
docker run --rm -it \
  -v certbot-e2e-etc:/etc/letsencrypt \
  -v certbot-e2e-var:/var/lib/letsencrypt \
  certbot/certbot certonly \
  --server "<directory_url>" \
  --manual --preferred-challenges dns \
  --key-type rsa --rsa-key-size 2048 \
  -d test.example.com
```

certbot 会提示添加 DNS TXT 记录 `_acme-challenge.test.example.com`，手动配置后回车继续。

> Certum 测试环境不支持 ECDSA，必须 `--key-type rsa --rsa-key-size 2048`。

### 步骤 4：验证

```bash
docker run --rm -v certbot-e2e-etc:/etc/letsencrypt \
  certbot/certbot certificates
```

### 步骤 5：吊销

```bash
docker run --rm \
  -v certbot-e2e-etc:/etc/letsencrypt \
  -v certbot-e2e-var:/var/lib/letsencrypt \
  certbot/certbot revoke \
  --server "<directory_url>" \
  --cert-path /etc/letsencrypt/live/test.example.com/cert.pem \
  --non-interactive
```

### 步骤 6：取消订阅

```bash
# User API
curl -X POST http://localhost:5300/api/user/acme/commit-cancel/<order_id> \
  -H "Authorization: Bearer <user-jwt>"

# 或 Admin API
curl -X POST http://localhost:5300/api/admin/acme/commit-cancel/<order_id> \
  -H "Authorization: Bearer <admin-jwt>"
```

取消流程：

- 无 `api_id` 的 pending → 直接退费 + cancelled
- 有 `api_id` → 标记 cancelling + 创建 Task（延迟 120s）+ TaskJob（延迟 123s）
- TaskJob 调 `Api->cancel()` 通知上游，成功后退费（type=acme_cancel）

## 数据库验证 SQL

```sql
-- 订阅记录
SELECT id, user_id, brand, period, plus, api_id, eab_kid, status, amount, created_at, cancelled_at
FROM acmes ORDER BY id DESC LIMIT 5;

-- 扣费 / 退费（type=acme_order / acme_cancel）
SELECT id, order_id, type, amount, balance_before, balance_after, created_at
FROM transactions
WHERE order_id = <ORDER_ID>
ORDER BY id;

-- 用户余额
SELECT id, username, balance FROM users WHERE id = <USER_ID>;

-- 取消延迟任务
SELECT id, action, order_id, scheduled_at, processed_at
FROM tasks WHERE action = 'cancel_acme' ORDER BY id DESC LIMIT 5;
```

## 清理

```bash
# 清理 certbot volumes
bash manager/skills/acme-e2e-test/run-e2e.sh --clean
# 或
docker volume rm certbot-e2e-etc certbot-e2e-var
```

## 常见问题

| 问题                        | 排查                                                                                                                   |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| 订阅创建返回 `CA 认证失败`  | 上游 Certum OAuth 凭据错误，清 Redis 缓存后重试                                                                        |
| 订阅创建返回 `用户邮箱缺失` | 订阅用户的 `users.email` 为空，ACME 需要 email 注册账号                                                                |
| 订阅创建后 `api_id=null`    | **关键 bug**：上游响应是 `data.order_id`（不是 `data.api_id`），`commitOrder` 已修正映射，出现该问题请检查代码是否回退 |
| certbot 连不上 CA           | `directory_url` 指向公网 CA，需要 Docker 容器能访问公网；测试环境可能需要 VPN                                          |
| `Unsupported key algorithm` | Certum 测试不支持 ECDSA，加 `--key-type rsa --rsa-key-size 2048`                                                       |
| `directory_url` 响应为 null | 上游 `/acme/new` 响应未返回，检查上游是否已适配；本地缓存被清理会触发一次回源刷新                                      |
| 取消订阅不退费              | 仅当上游 cancel 成功返回时才退费；失败保持 cancelling，等待下次重试                                                    |

## 端口映射

| 服务             | 端口 |
| ---------------- | ---- |
| Manager backend  | 5300 |
| 上游系统 backend | 6300 |

## 字段映射速查

| Manager `acmes`                   | 上游 `/acme/new` 响应                    |
| --------------------------------- | ---------------------------------------- |
| `api_id`                          | `data.order_id` ← **不是 `data.api_id`** |
| `vendor_id`                       | `data.vendor_id`                         |
| `eab_kid`                         | `data.eab_kid`                           |
| `eab_hmac`                        | `data.eab_hmac`                          |
| (Cache) `acme_directory_url:{ca}` | `data.directory_url`                     |
