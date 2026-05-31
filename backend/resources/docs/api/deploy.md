# 部署接口（Deploy）

供自动化部署工具（certimate 等）拉取证书、触发续费/重签、回报部署结果。

- **Base URL**：`{站点地址}/api/deploy`
- **鉴权**：请求头 `Authorization: Bearer {部署 Token}`（在「设置 → 部署」获取；亦支持 `?token=`）
- **统一响应**：`{ "code": 1|0, "msg": "", "data": {...} }`（`field` 取 PEM 时直接返回纯文本）

## 端点一览

| 方法 | 路径            | 说明                    |
| ---- | --------------- | ----------------------- |
| GET  | `/`             | 查询订单 / 拉取 PEM     |
| POST | `/`             | 更新（自动续费或重签）  |
| POST | `/callback`     | 回报部署结果            |
| POST | `/auto-reissue` | 开关订单自动重签        |
| POST | `/acme/new`     | 创建 ACME 订阅          |
| GET  | `/acme/{id}`    | ACME 订阅详情（含 EAB） |

## 查询 `GET /`

| 参数                 | 说明                                                                                                              |
| -------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `order`              | 订单 ID、域名，或逗号分隔的批量（混合）。不传则返回最近 100 条 active                                             |
| `field`              | `certificate` / `private_key`：`order` 为单个 ID 或域名时**直接返回纯 PEM 文本**（适配 URL 拉取，续费后地址不变） |
| `page` / `page_size` | 分页（≤100）                                                                                                      |

订单 `data`：`order_id`、`domains`、`status`；active 时附 `certificate`、`private_key`、`ca_certificate`、`issued_at`、`expires_at`；文件验证时附 `file{path,content}`。响应含 `renew_before_days`。

## 更新 `POST /`

按订单当前状态推进：`unpaid`→支付、`pending`→提交、`active`→到期 ≤15 天续费、否则重签。

| 参数                | 必填 | 说明                                                       |
| ------------------- | ---- | ---------------------------------------------------------- |
| `order_id`          | 是   | 订单 ID                                                    |
| `csr`               | 否   | 不传则系统生成                                             |
| `domains`           | 否   | 逗号分隔；不传沿用当前证书域名                             |
| `validation_method` | 否   | `delegation`（默认）/ `file`（按产品回落 file→https→http） |

> 续费需订单开启自动续费，否则返回错误。

## 回调 `POST /callback`

`{ order_id, status: success|failure, deployed_at? }`，成功才记录部署时间。

## 自动重签 `POST /auto-reissue`

`{ order_id, auto_reissue: true|false }`。

## ACME

- `POST /acme/new`：`product_code`（必）、`contact_email`（必）、`period`、`plus`、`refer_id` → 返回含 `eab_kid`/`eab_hmac`/`directory_url`
- `GET /acme/{id}`：订阅详情（含 EAB + directory_url）

## 示例

```bash
# 拉取证书 PEM
curl "{站点地址}/api/deploy/?order=example.com&field=certificate" \
  -H "Authorization: Bearer {部署 Token}"

# 触发更新
curl -X POST "{站点地址}/api/deploy/" \
  -H "Authorization: Bearer {部署 Token}" \
  -d "order_id=123"
```
