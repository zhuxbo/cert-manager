# SSL Manager

[![GitHub Release](https://img.shields.io/github/v/release/zhuxbo/ssl-manager?include_prereleases)](https://github.com/zhuxbo/ssl-manager/releases)
[![CI](https://github.com/zhuxbo/ssl-manager/actions/workflows/ci.yml/badge.svg)](https://github.com/zhuxbo/ssl-manager/actions/workflows/ci.yml)

SSL 证书管理系统，支持多级代理、自动续签、在线升级。MySQL + 宝塔部署。

## 安装

```bash
# 国内服务器
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash

# 海外服务器
curl -fsSL https://release-us.cnssl.com/install.sh | sudo bash
```

完整性校验：install.sh 自动从 releases.json 强校验脚本包 sha256，校验失败立即退出。

详细部署指南见 [DEPLOY.md](DEPLOY.md)，升级回滚演练见 [UPGRADE.md](UPGRADE.md)。

<details>
<summary>更多安装选项</summary>

```bash
# 非交互式安装（必须提供 --site-domain 或 INSTALL_DIR）
curl ... | sudo bash -s -- -y --site-domain manager.example.com
# 或：INSTALL_DIR=/data/manager curl ... | sudo bash -s -- -y

# 指定版本安装
curl ... | sudo bash -s -- --version 0.0.9-beta
```

| 参数                     | 说明                                                        |
| ------------------------ | ----------------------------------------------------------- |
| `-y`                     | 非交互模式，自动确认（需 `--site-domain` 或 `INSTALL_DIR`） |
| `--site-domain <domain>` | 站点域名，自动推导 `INSTALL_DIR=/www/wwwroot/<domain>`      |
| `--version latest`       | 最新稳定版（默认）                                          |
| `--version dev`          | 最新开发版                                                  |
| `--version x.x.x`        | 指定版本号                                                  |

`-y` 模式下数据库默认用户名/数据库名为 `manager`，密码留空。如需自定义传 env：

```bash
DB_USERNAME=manager DB_PASSWORD=xxx \
  curl ... | sudo bash -s -- -y --site-domain manager.example.com
```

</details>

## 升级

### 在线升级（推荐）

登录管理后台 → 系统设置 → 在线升级，可视化操作。

### 脚本升级

```bash
curl -fsSL https://release-cn.cnssl.com/upgrade.sh | sudo bash
```

<details>
<summary>更多升级选项</summary>

```bash
curl ... | bash # 升级到最新版
curl ... | bash -s -- --version 1.0.0 # 升级到指定版本
curl ... | bash -s -- --dir /path/to/app # 指定安装目录
curl ... | bash -s -- rollback # 回滚到上一版本

# artisan 命令（需进入 backend 目录）
php artisan upgrade:check # 检查更新
php artisan upgrade:run # 执行升级
php artisan upgrade:rollback # 回滚

# 用户数据管理
php artisan user:data export {user_id} # 导出用户数据（SQL dump）
php artisan user:data import {user_id} --dry-run # 干跑检测冲突
php artisan user:data import {user_id} # 导入用户数据
php artisan user:data purge {user_id} # 清理用户数据（需先禁用+导出）
```

| 参数              | 说明                               |
| ----------------- | ---------------------------------- |
| `--version x.x.x` | 升级到指定版本                     |
| `--dir PATH`      | 指定安装目录（自动检测失败时使用） |
| `-y, --yes`       | 自动确认，非交互模式               |
| `rollback`        | 回滚到上一版本                     |

</details>

## 卸载

通过宝塔面板删除站点 + 关联的 supervisor / cron 即可。详见 [DEPLOY.md](DEPLOY.md)。

## 架构

```
frontend/ # Vue 3 前端
├── shared/ # 共享组件库
├── admin/ # 管理端
└── user/ # 用户端
backend/ # Laravel 13 后端
build/ # 构建系统（见 build/README.md）
deploy/ # 部署脚本
```

| 组件 | 技术栈                                           |
| ---- | ------------------------------------------------ |
| 后端 | Laravel 13, PHP 8.3/8.4/8.5, MySQL, Redis (可选) |
| 前端 | Vue 3, TypeScript, Element Plus, Vite            |

运行时并发防护：订单/任务状态变更统一遵循 `task→order/acme` 锁顺序；任务锁查询统一经 Task 模型 scope `Task::lockForMutation` 强制走 `tasks(order_id, action, status)` 复合索引（与之同首列的单列 `order_id` 索引已在 schema 层删除，杜绝优化器退回扩大间隙锁），纯本地 task 变更事务（Order 与 ACME 共用重试助手）启用 3 次死锁重试，降低并发取消、同步时 MySQL 1213 对用户请求的影响。CI 同时守住 Task 锁入口收口、tasks 索引最终态和 scope 接线，防止复合索引或 forceIndex 保护回归。

管理端产品价格支持按会员级别倍率预览并批量初始化；每个级别可单独调整倍率，默认只补齐缺失价格，强制模式也只重建本次选中的级别。

续费/重签接替单在已提交上游后被取消时不会恢复前驱证书；普通取消以及启用 `autoRefundOnSync` 后由同步发现上游取消的续费单，都会发送一次性 `cert_renew_cancelled` 提醒，避免前驱证书脱离续期监控后静默过期。

Certum 非 DV 产品在订单进入 processing 后提供验证文档处理入口；入口按产品签发机构 `product.ca` 判断，不受订单品牌字段影响。

支付前的 CAA 签发预检只检查 DNS 域名；IPv4/IPv6 不适用 CAA，系统会在调用验证服务前跳过，避免 IP 被误判为非法域名。

管理端订单详情允许在最新证书为 unpaid 或 pending 时修改订单内已有的企业与联系人申请信息；企业和联系人电话统一接受 5–15 位数字字符串或整数，带前导零时使用字符串；修改只作用于当前订单快照，不会反向改动用户维护的企业/联系人资料库，订单进入 processing 等后续状态后后端会拒绝保存。

仓库 Shell 脚本兼容 Linux Bash 与 macOS Bash 3.2；变量后紧跟中文等非 ASCII 字符时统一使用 `${var}` 明确边界，避免 UTF-8 locale 配合 `set -u` 将后续字节误解析为变量名。

## 自动化部署

### CNAME 委托

将域名验证 CNAME 记录指向平台托管域名，实现自动续签：

管理端和用户端手工添加委托均支持 Unicode 中文域名与 Punycode，系统会统一处理 DNS 查询所需的域名编码。

```
_dnsauth.example.com → *******.your-platform.com
```

配置后，平台自动完成 DNS 验证，无需手动操作。

### 自动部署工具

配合 [sslctl](https://github.com/zhuxbo/sslctl) 工具实现全自动化：

```bash
# 安装部署工具
curl -fsSL https://release.cnssl.com/sslctl/install.sh | sudo bash

# 一键部署（推荐）
sslctl setup --url https://your-platform.com --token <deploy_token> --order <order_id>

# 或手动扫描并部署
sslctl scan
sslctl deploy --cert order-12345
```

### Deploy API

通过 Deploy Token 认证（`Authorization: Bearer <deploy_token>`）：

```http
GET /api/deploy?order=123 # 按订单 ID 查询
GET /api/deploy?order=example.com # 按域名查询
GET /api/deploy?order=1,2,a.com # 批量混合查询
GET /api/deploy # 列出所有 active 订单
POST /api/deploy # 更新/续费证书
POST /api/deploy/callback # 部署结果回调
```

### ACME 订阅管理

Manager 作为 ACME 订阅管理平台（"封装下单 + 交付 EAB"模式），自身不实现 RFC 8555 服务端；directory URL 与 EAB 凭据均由上游系统签发并透传。

```bash
# Deploy API：一步到位创建 + 支付 + 提交，返回 EAB + directory_url
curl -X POST -H "Authorization: Bearer <deploy-token>" \
  -H 'Content-Type: application/json' \
  -d '{"product_id": 57, "period": 12, "plus": 1}' \
  https://your-platform.com/api/deploy/acme/new

# 查询订阅详情（含 EAB + directory_url）
curl -H "Authorization: Bearer <deploy-token>" \
  https://your-platform.com/api/deploy/acme/<id>

# certbot 使用返回的 directory_url 注册
certbot certonly --server <directory_url> \
  --eab-kid <EAB_KID> --eab-hmac-key <EAB_HMAC> \
  -d example.com --preferred-challenges dns-01
```

Web 端支持两步创建：先建立订阅（unpaid → pending），再从详情页提交到上游激活（active）。详情页展示 directory URL / EAB KID / EAB HMAC，每项可一键复制。列表页支持批量查看（聚合详情页 v-for 渲染，URL 可分享）、批量支付、提交、同步、取消、撤回取消、复制 EAB 等批量操作。

## 插件管理

管理端「插件管理」支持异步安装、更新、卸载和检查更新。页面仅展示执行中和失败的插件任务；安装/更新成功后刷新插件列表，不保留成功安装记录。安装/更新失败后，同插件不会继续创建重复安装任务，需在失败记录上选择「重试」重新入队，或选择「卸载」清理失败安装记录后再重新安装；重试复用原任务记录，但会按新的终态重新提示并在成功后刷新插件列表。卸载选择“完全清除”时会重置该插件全部历史迁移并执行 Seeder 清理钩子；任一清理失败都会中止卸载并保留插件文件，避免误报数据已清除。

带 `backend/composer.json` 的插件会在安装/更新时自动运行 `composer install --no-dev` 安装运行时依赖；Composer 子进程使用 `storage/app/plugin-composer` 作为 home/cache，避免队列环境缺少 `HOME` 导致安装失败。

### 云部署插件

cloud-deploy 插件用于把订单证书推送到云平台资源。云凭证在系统设置中维护，不绑定订单；部署目标绑定订单、云凭证、云产品和资源配置。绑定域名或云资源的目标继续跨订单唯一，不能让多个订单覆盖同一资源；只创建或导入证书、不绑定资源的纯上传产品按订单唯一，因此不同订单可共用同一凭证和产品，同一订单仍不能重复创建。续费自动迁移目标时会同步纯上传目标的订单作用域唯一键；如果新订单已配置相同目标，则保留新订单目标并停用旧订单目标，避免重复推送。用户端和管理端的「云部署」都支持在订单详情管理当前订单的部署目标，也可以在系统设置按「部署目标 / 云凭证 / 部署历史」统一查看和管理；管理端订单详情通过订单的 `user_id` 限定用户上下文，样式和操作与用户端订单详情保持一致。新增或改绑部署目标时，订单通过远程下拉按订单号或域名搜索，管理端先选择用户再限定该用户的凭证和订单；管理端部署目标列表使用组合搜索覆盖订单号、域名、用户名和凭证名，并保留状态/启用筛选；双端部署目标表单共用插件前端共享组件保持行为一致，字段标签被表单宽度省略时悬浮显示完整文本。异步云任务的 jobId 成功写入部署目标的 target 数据库记录后，常规清缓存不影响后续续查同一任务。云部署失败模板声明 `product`、`domain`、`access_name`、`error_code` 四个变量；保留数据卸载时模板继续保留，完全清除时随插件迁移一并删除。插件后端维护独立 PHPStan 配置，源码以 0 errors 为准入要求。

## 文档

| 文档                               | 说明                                                                |
| ---------------------------------- | ------------------------------------------------------------------- |
| [DEPLOY.md](DEPLOY.md)             | 部署指南（宝塔，含 sha256 校验、目录权限）                          |
| [UPGRADE.md](UPGRADE.md)           | 升级回滚演练手册（freeze 流程、smoke test、自动回滚链路）           |
| [skills/SKILL.md](skills/SKILL.md) | 开发规范（按领域组织：后端 / 前端 / 部署 / 构建发布 / 插件 / ACME） |
| [build/README.md](build/README.md) | 构建系统、版本发布                                                  |

## License

MIT
