# ACME 客户端申请 SSL 证书指南

使用 certbot、acme.sh 等标准 ACME 客户端，通过 SSL Manager 的 ACME 订阅服务申请 SSL 证书。

> **架构说明**：Manager 是 **ACME 订阅管理平台**，不实现 RFC 8555 服务端，自身不参与证书签发/吊销。下单后 Manager 调用上游接口拿到 `eab_kid` / `eab_hmac` / `directory_url` 三件套并交付给用户；ACME 客户端拿到这三项后**直连 CA**（如 `https://acme.test.certum.pl/directory/`）完成注册、申请、续签、吊销。EAB 凭据可复用，重复注册不扣费。

## 前置条件

1. 已购买并 commit 到 active 状态的 ACME 订阅（Manager 中 `products.product_type='acme'` 的产品）。订阅可通过下面三种入口创建：
   - 用户端 / 管理端 Web 页面（"ACME 订阅 → 新建"）
   - API Token：`POST /api/acme/new`（一步到位）
   - Deploy Token：`POST /api/deploy/acme/new`（一步到位，自动化部署常用）
2. ACME 客户端（`certbot` 或 `acme.sh` 等任意符合 RFC 8555 的客户端）。
3. 域名 DNS 管理权限（用于 dns-01 验证；通配符必须用 dns-01）。

## 第一步：获取 EAB 凭据

订阅创建并 commit 成功后会得到一组凭据：`eab_kid` / `eab_hmac` / `directory_url`。EAB 可复用，密钥丢失后重新获取即可，无需重新购买。

### 方式一：Web 页面（推荐手工场景）

登录用户端 → ACME 订阅列表 → 详情页 → "ACME 凭据" 标签页。页面展示三项可一键复制，并直接给出适配 certbot / acme.sh 的命令模板。

### 方式二：API Token（标准 ACME 子账户场景）

```bash
# 一步到位创建 + 支付 + 提交
curl -sS -X POST http://your-platform/api/acme/new \
  -H "Authorization: Bearer <api-token>" \
  -H "Content-Type: application/json" \
  -d '{
    "product_code": "cnssl-acme-dv-standard",
    "contact_email": "you@example.com",
    "period": 12,
    "plus": 1,
    "refer_id": "your-idempotency-key"
  }' | jq .
# 返回：{"code":1,"data":{"order_id":...,"eab_kid":"...","eab_hmac":"...","directory_url":"https://acme.test.certum.pl/directory/","status":"active"}}
```

入参字段：

| 字段            | 必填 | 类型          | 默认 | 说明                                                                     |
| --------------- | ---- | ------------- | ---- | ------------------------------------------------------------------------ |
| `product_code`  | 是   | string max:50 | —    | 产品代码（`products.code`，product_type=acme）                           |
| `contact_email` | 是   | email max:254 | —    | ACME 账号邮箱（RFC 8555 contact）                                        |
| `period`        | 否   | integer       | 12   | 订阅时长（月）；预留 Certum 多年期产品，需在 `product.periods` 内        |
| `plus`          | 否   | integer 0\|1  | 1    | 赠送时间（与传统 V2 API 一致）                                           |
| `refer_id`      | 否   | string max:64 | —    | 幂等键，按当前用户范围内去重；同 user 重复返回 `Refer id already exists` |

### 方式三：Deploy Token（推荐自动化部署场景）

```bash
# 一步到位创建 + 支付 + 提交（入参与 /api/acme/new 同构）
curl -sS -X POST http://your-platform/api/deploy/acme/new \
  -H "Authorization: Bearer <deploy-token>" \
  -H "Content-Type: application/json" \
  -d '{
    "product_code": "cnssl-acme-dv-standard",
    "contact_email": "deploy@example.com",
    "period": 12,
    "plus": 1
  }' | jq .

# 已有订阅时获取详情（含 EAB + directory_url）
curl -sS -H "Authorization: Bearer <deploy-token>" \
  http://your-platform/api/deploy/acme/<id> | jq .
```

## 第二步：注册 ACME 账户

> **账户隔离**：`--config-dir` / `--config-home` 用独立目录，避免与其他 ACME 服务（如 Let's Encrypt）配置冲突。
> **server URL 来自 `directory_url`**：直连 CA，**不是** Manager 自身的地址。

### certbot

```bash
certbot register \
  --config-dir ~/acme/certbot \
  --work-dir  ~/acme/certbot/work \
  --logs-dir  ~/acme/certbot/logs \
  --server "<directory_url>" \
  --eab-kid "<eab_kid>" \
  --eab-hmac-key "<eab_hmac>" \
  --email user@example.com \
  --no-eff-email --agree-tos
```

### acme.sh

```bash
acme.sh --register-account \
  --config-home ~/acme/acmesh \
  --server "<directory_url>" \
  --eab-kid "<eab_kid>" \
  --eab-hmac-key "<eab_hmac>"
```

## 第三步：申请证书（dns-01）

### certbot

```bash
certbot certonly \
  --config-dir ~/acme/certbot \
  --work-dir  ~/acme/certbot/work \
  --logs-dir  ~/acme/certbot/logs \
  --server "<directory_url>" \
  --manual --preferred-challenges dns \
  --key-type rsa --rsa-key-size 2048 \
  -d "example.com" \
  -d "*.example.com"
```

### acme.sh

```bash
acme.sh --issue \
  --config-home ~/acme/acmesh \
  --server "<directory_url>" \
  --dns \
  -d "example.com" \
  -d "*.example.com" \
  --yes-I-know-dns-manual-mode-enough-go-ahead-please
```

> Certum 测试环境不支持 ECDSA，必须 `--key-type rsa --rsa-key-size 2048`。

## 第四步：完成 DNS 验证

如果域名已在 Manager 中配置 CNAME 委托（`_acme-challenge.example.com → *.your-platform.com`），TXT 记录由 Manager 后台自动写入，等待 DNS 传播即可（通常 1-2 分钟）。

未配置委托时，certbot/acme.sh 会提示手动添加 TXT 记录：

1. 登录域名 DNS 控制台
2. 添加 TXT：`_acme-challenge`（主机记录），值 = 客户端给出的 token
3. 等待 DNS 生效，确认：
   ```bash
   dig TXT _acme-challenge.example.com +short
   ```
4. 在客户端按回车继续

## 第五步：签发证书

DNS 验证通过后客户端自动完成签发，证书产物落在客户端的 config 目录：

```
# certbot
~/acme/certbot/live/example.com/
├── fullchain.pem   # 证书 + 中间证书
├── privkey.pem     # 私钥
├── cert.pem        # 证书
└── chain.pem       # 中间证书

# acme.sh
~/acme/acmesh/example.com_ecc/
├── fullchain.cer
├── example.com.key
├── example.com.cer
└── ca.cer
```

## 证书续签

续签复用已注册的 ACME 账户，无需再次注册。续签前订阅必须仍处于 active 状态。

```bash
# certbot
certbot renew \
  --config-dir ~/acme/certbot \
  --work-dir  ~/acme/certbot/work \
  --logs-dir  ~/acme/certbot/logs

# acme.sh
acme.sh --renew \
  --config-home ~/acme/acmesh \
  -d "example.com"
```

### 客户端密钥丢失恢复

EAB 可复用：重新获取 `eab_kid` / `eab_hmac` / `directory_url`，重新跑一次 `register` + `certonly` 即可，订阅本身无需重建。

## 证书吊销

```bash
# certbot
certbot revoke \
  --config-dir ~/acme/certbot \
  --server "<directory_url>" \
  --cert-path ~/acme/certbot/live/example.com/cert.pem

# acme.sh
acme.sh --revoke \
  --config-home ~/acme/acmesh \
  -d "example.com" \
  --server "<directory_url>"
```

吊销动作直接发到 CA，不经过 Manager（Manager 不参与证书生命周期）。

## 取消订阅 / 退费

```bash
# 用户端（JWT 鉴权）
curl -X POST http://your-platform/api/user/acme/commit-cancel/<id> \
  -H "Authorization: Bearer <user-jwt>"

# 管理端（JWT 鉴权）
curl -X POST http://your-platform/api/admin/acme/commit-cancel/<id> \
  -H "Authorization: Bearer <admin-jwt>"

# 通过下游 API 立即取消（API Token）
curl -X POST http://your-platform/api/acme/cancel \
  -H "Authorization: Bearer <api-token>" \
  -d '{"order_id": <api_id>}'
```

Web 入口走延时取消（标记 cancelling → 120s 后由 Task 调上游取消并退费），保留撤回窗口。120s 内可调 `revoke-cancel` 撤回。下游 API 走立即取消，无延时。

## 注意事项

- **订阅生命周期与证书无关**：Manager 只管订阅 `unpaid → pending → active → cancelled/revoked`；证书签发 / 吊销由 ACME 客户端 ↔ CA 直接完成。
- **EAB 可复用**：同一组 EAB 可重复跑 `register`，多端共享，不重复扣费。
- **CNAME 委托**：在 Manager 配置后，`_acme-challenge` TXT 由系统自动写入，省去手工配置。
- **通配符域名**：必须 dns-01 验证（`--preferred-challenges dns`）。
- **密钥类型**：Certum 测试环境强制 RSA 2048（`--key-type rsa --rsa-key-size 2048`）。
- **directory_url 的来源**：由上游 CA 返回，Manager 仅做按 CA 缓存（`acme_directory_url:{ca}`）。下次访问详情页若 cache 缺失，Manager 会回源上游一次性回填。

## 字段映射速查

| Manager `acmes` 列              | 上游 `/acme/new` 响应字段                 | ACME 客户端命令参数 |
| ------------------------------- | ----------------------------------------- | ------------------- |
| `api_id`                        | `data.order_id`（**不是 `data.api_id`**） | —                   |
| `eab_kid`                       | `data.eab_kid`                            | `--eab-kid`         |
| `eab_hmac`                      | `data.eab_hmac`                           | `--eab-hmac-key`    |
| `vendor_id`                     | `data.vendor_id`                          | —                   |
| Cache `acme_directory_url:{ca}` | `data.directory_url`                      | `--server`          |
