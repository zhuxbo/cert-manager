# ACME 订阅接口

供下游接入方申请 ACME 订阅，获取 EAB 凭证（`eab_kid` / `eab_hmac`）与 `directory_url`，用于 ACME 客户端（certbot/acme.sh 等）自动签发。

- **Base URL**：`{站点地址}/api/acme`
- **鉴权**：请求头 `Authorization: Bearer {API Token}`（同 API v2 Token）
- **统一响应**：`{ "code": 1|0, "msg": "", "data": {...} }`

## 端点一览

| 方法 | 路径            | 说明                               |
| ---- | --------------- | ---------------------------------- |
| GET  | `/get-products` | ACME 产品列表                      |
| POST | `/new`          | 创建订阅（一步：创建+支付+提交）   |
| GET  | `/get`          | 订阅详情（含 EAB + directory_url） |
| POST | `/cancel`       | 取消订阅                           |

## 创建 `POST /new`

| 参数            | 必填 | 说明                                         |
| --------------- | ---- | -------------------------------------------- |
| `product_code`  | 是   | ACME 产品编码（见 get-products），`≤50`      |
| `contact_email` | 是   | ACME 账号邮箱（RFC 8555 contact），`≤254`    |
| `period`        | 否   | 周期（月），整数，预留多年期；不传按产品默认 |
| `plus`          | 否   | `0`/`1`，赠送时间（默认 1）                  |
| `refer_id`      | 否   | 端到端幂等键，`≤64`                          |

返回 `data`：订阅记录，含 `eab_kid`、`eab_hmac`、`directory_url`、`contact_email`、`refer_id`、`status` 等。

## 查询 `GET /get?order_id=`

返回订阅详情（含 `eab_hmac` 与 `directory_url`）；查询会先同步上游最新状态。

## 取消 `POST /cancel`

`{ order_id }`，立即取消（下游 API 不走延时任务）。

## 示例

```bash
curl -X POST "{站点地址}/api/acme/new" \
  -H "Authorization: Bearer {API Token}" \
  -H "Accept: application/json" \
  -d "product_code=xxx" -d "contact_email=admin@example.com"
```

EAB 配置（acme.sh 示例）：

```bash
acme.sh --register-account --server {directory_url} \
  --eab-kid {eab_kid} --eab-hmac-key {eab_hmac}
```
