# SSL Manager

[![GitHub Release](https://img.shields.io/github/v/release/zhuxbo/ssl-manager?include_prereleases)](https://github.com/zhuxbo/ssl-manager/releases)
[![CI](https://github.com/zhuxbo/ssl-manager/actions/workflows/ci.yml/badge.svg)](https://github.com/zhuxbo/ssl-manager/actions/workflows/ci.yml)

SSL 证书管理系统，支持多级代理、自动续签、在线升级。

## 安装

```bash
# 国内服务器
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash

# 海外服务器
curl -fsSL https://release-us.cnssl.com/install.sh | sudo bash
```

<details>
<summary>更多安装选项</summary>

```bash
# 指定部署方式
curl ... | sudo bash -s -- docker   # Docker 部署（推荐）
curl ... | sudo bash -s -- bt       # 宝塔面板部署

# 非交互式安装
curl ... | sudo bash -s -- docker -y

# 指定版本安装
curl ... | sudo bash -s -- --version 0.0.9-beta
```

| 参数 | 说明 |
|------|------|
| `docker` | Docker 部署（推荐） |
| `bt` | 宝塔面板部署 |
| `-y` | 非交互模式，自动确认 |
| `--version latest` | 最新稳定版（默认） |
| `--version dev` | 最新开发版 |
| `--version x.x.x` | 指定版本号 |

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
curl ... | bash                         # 升级到最新版
curl ... | bash -s -- --version 1.0.0   # 升级到指定版本
curl ... | bash -s -- --dir /path/to/app  # 指定安装目录
curl ... | bash -s -- rollback          # 回滚到上一版本

# artisan 命令（需进入 backend 目录）
php artisan upgrade:check       # 检查更新
php artisan upgrade:run         # 执行升级
php artisan upgrade:rollback    # 回滚

# 用户数据管理
php artisan user:data export {user_id}              # 导出用户数据（SQL dump）
php artisan user:data import {user_id} --dry-run    # 干跑检测冲突
php artisan user:data import {user_id}              # 导入用户数据
php artisan user:data purge {user_id}               # 清理用户数据（需先禁用+导出）
```

| 参数 | 说明 |
|------|------|
| `--version x.x.x` | 升级到指定版本 |
| `--dir PATH` | 指定安装目录（自动检测失败时使用） |
| `-y, --yes` | 自动确认，非交互模式 |
| `rollback` | 回滚到上一版本 |

</details>

## 卸载

Docker 部署卸载：

```bash
cd /opt/ssl-manager  # 进入安装目录
docker-compose down -v  # 停止并删除容器和数据卷
```

## 架构

```
frontend/           # Vue 3 前端
├── shared/         # 共享组件库
├── admin/          # 管理端
└── user/           # 用户端
backend/            # Laravel 11 后端
build/              # 构建系统（见 build/README.md）
deploy/             # 部署脚本
```

| 组件 | 技术栈 |
|------|--------|
| 后端 | Laravel 11, PHP 8.3+, MySQL, Redis (可选) |
| 前端 | Vue 3, TypeScript, Element Plus, Vite |

## 自动化部署

### CNAME 委托

将域名验证 CNAME 记录指向平台托管域名，实现自动续签：

```
_dnsauth.example.com  →  *******.your-platform.com
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
GET  /api/deploy?order=123           # 按订单 ID 查询
GET  /api/deploy?order=example.com   # 按域名查询
GET  /api/deploy?order=1,2,a.com     # 批量混合查询
GET  /api/deploy                     # 列出所有 active 订单
POST /api/deploy                     # 更新/续费证书
POST /api/deploy/callback            # 部署结果回调
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

Web 端支持两步创建：先建立订阅（unpaid → pending），再从详情页提交到上游激活（active）。详情页展示 directory URL / EAB KID / EAB HMAC，每项可一键复制。列表页支持批量支付、提交、同步、取消、撤回取消、复制 EAB 等批量操作。

## 文档

| 文档 | 说明 |
|------|------|
| [build/README.md](build/README.md) | 构建系统、版本发布 |
| [deploy/docker/README.md](deploy/docker/README.md) | Docker 部署详细说明 |

## License

MIT
