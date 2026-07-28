# SSL Manager 部署指南

仅支持 **宝塔面板 + MySQL** 部署。

> **宝塔环境手工运维注意**：本文档命令示例中的 `php` 在宝塔多版本系统下需替换为绝对路径（避免 root PATH 找到错误 PHP 版本）：
>
> - PHP 8.3：`/www/server/php/83/bin/php`
> - PHP 8.4：`/www/server/php/84/bin/php`
> - composer：`/www/server/php/<ver>/bin/php /usr/local/bin/composer`（绕过 phar shebang 强制版本一致）
>
> `bt-install.sh` / `upgrade.sh` 已自动使用绝对路径，本提示仅针对手工运维场景。

---

## 系统要求

| 项       | 要求                                       |
| -------- | ------------------------------------------ |
| OS       | CentOS / Debian / Ubuntu（x86_64 / arm64） |
| 配置     | 1 核 2G 起                                 |
| 宝塔面板 | 11.5+                                      |
| PHP      | 8.3 或 8.4                                 |
| 数据库   | MySQL 5.7+ / MariaDB 10.5+                 |
| Redis    | 可选（推荐启用 cache / queue）             |

---

## 一键安装

```bash
# 国内服务器
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash

# 海外服务器
curl -fsSL https://release-us.cnssl.com/install.sh | sudo bash

# 非交互模式（必须提供 --site-domain 或 INSTALL_DIR）
curl -fsSL ... | sudo bash -s -- -y --site-domain manager.example.com
# 自定义安装目录：
INSTALL_DIR=/data/manager \
  curl -fsSL ... | sudo bash -s -- -y
```

`-y` 模式数据库默认 `DB_USERNAME=manager` / `DB_DATABASE=manager` / `DB_PASSWORD=空`。如需自定义传 env：

```bash
DB_USERNAME=manager DB_PASSWORD='xxxx' \
  curl -fsSL ... | sudo bash -s -- -y --site-domain manager.example.com
```

### 完整性校验

`install.sh` 自动从 release 站根目录的 `releases.json` 读取目标版本 `assets[].sha256` 强校验脚本包，失败立即退出。bt-install.sh 在下载完整包 / 升级包后按相同逻辑再次校验。`deploy/upgrade.sh` 升级链路同样以 `releases.json` 为唯一真相源。

---

## 宝塔自动安装

```bash
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash
```

`bt-install.sh` 流程：

1. 检测宝塔环境（`/www/server/panel`，版本 ≥ 11.5）+ PHP 8.3/8.4
2. **自动解禁** PHP 函数（`putenv` / `proc_*` / `exec` / `pcntl_*` 等）
3. **自动安装**缺失扩展（`fileinfo` / `intl` / `mbstring` / `calendar` / `pdo_mysql`，宝塔 11.x API 优先 + 老版 install.sh / php.ini 直写双 fallback；失败回退手工提示）
4. 检测 / 安装 Composer 2.8+
5. 下载 `ssl-manager-full-{version}.zip` + sha256 强校验
6. 解压 + 选择安装目录(默认 `/www/wwwroot/ssl-manager`)
7. composer 镜像源（中国大陆自动切阿里云，全局 `-g` 配置）
8. 收集 MySQL 连接信息并生成 `.env`（DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD）
9. 运行 `composer install`、`php artisan migrate --force`、`php artisan db:seed --force`
10. admin 密码安全收集并通过 `admin:reset-password` 直接设置（详见下文）
11. 尝试宝塔 API 自动建站、注入 vhost、添加 supervisor 与 cron；失败时打印手工配置命令

安装器只创建一个每分钟执行的宝塔计划任务：`<PHP绝对路径> <安装目录>/backend/artisan schedule:run`。命令不额外重定向输出，由宝塔面板保存任务日志；计划任务必须以 `www` 用户运行。

### admin 密码安全

**禁止** `--admin-password=xxx`（命令行明文进 shell history）。允许 4 种来源（严格优先级）：

```bash
# 1. 临时文件（推荐自动化）
echo 'StrongPass123' > /tmp/admin.pwd && chmod 600 /tmp/admin.pwd
sudo bash bt-install.sh --admin-password-file=/tmp/admin.pwd
# 脚本读取后立即 rm 销毁原文件

# 2. 环境变量（脚本读后立即 unset）
ADMIN_PASSWORD='StrongPass123' sudo bash bt-install.sh

# 3. 交互输入（明文回显，安装是一次性私有操作）
sudo bash bt-install.sh

# 4. 自动生成（-y 模式且无密码来源 → 16 位强密码 + 终端打印一次）
sudo bash bt-install.sh -y
```

bt-install 不会落盘保存 admin 密码：脚本在 seed 后直接调用 `php artisan admin:reset-password` 设置密码。建议首次登录后立即修改密码。

---

## Channel 路由开关

`.env` 4 个开关控制 API 路由族注册：

```bash
CHANNELS_ADMIN=true # 关闭则 /api/admin/* 不注册
CHANNELS_USER=true # 关闭则 /api/user/* 不注册
CHANNELS_API=true # 关闭则 /api/v1/* /api/v2/* /api/acme/* 不注册
CHANNELS_DEPLOY=true # 关闭则 /api/deploy/* 不注册
```

修改后需重载 PHP-FPM 或重启 supervisor 使路由表重新注册。前端启动期调 `/api/meta` 检测，channel 关闭时显示降级页。

---

## 安全基线

| 项          | 落地方式                                                                         |
| ----------- | -------------------------------------------------------------------------------- |
| `.env` 权限 | install 期 `chmod 600`                                                           |
| APP_KEY     | install 期 `php artisan key:generate`；启动校验拒绝默认值 / 空值                 |
| admin 密码  | 4 种来源（不入 shell history），首次登录后建议立即修改                           |
| HTTPS       | 宝塔站点 SSL 配置（自动 Let's Encrypt 或上传证书）                               |
| 备份产物    | `mysqldump` + `gzip`，明文 `.sql.gz` 落 `storage/databak/`（仅本机进程可访问）   |
| 日志脱敏    | `App\Utils\LogScrubber` 集中脱敏密码 / token / CSR / 银行卡号 / 身份证号等 14 类 |

### 备份产物保护

备份不做应用层加密 — 备份文件与 `.env`、数据库本身位于同一台机器，应用层加密无法对抗"获得文件读取权限"的攻击者，反而带来密钥管理负担。防护重点放在**文件系统层**：

- `storage/` 目录由 install.sh 设定 chmod，仅 www 用户可读
- `.env` 单独 `chmod 600`，确保 DB 凭据不外泄
- 异地保存备份时（S3 / 邮件 / U 盘）请在传输前自行 `gpg --encrypt` 或 `age` 加密

---

## 验证部署

```bash
# 健康检查（公开端点，不需要 token）
curl https://your-domain.com/api/health
# 返回 {"status":"ok","freeze":false,"checks":{"db":{"ok":true,...},...}}

# meta 检查（公开端点）
curl https://your-domain.com/api/meta
# 返回 {"data":{"channels":{...},"plugins":[...],"version":"..."}}

# admin metrics（需 admin 鉴权）
curl -H "Authorization: Bearer <admin_token>" https://your-domain.com/api/admin/metrics
```

---

## 常用命令

```bash
cd /www/wwwroot/ssl-manager/backend

# 在线升级（管理后台 → 系统 → 升级）或命令行
sudo /www/wwwroot/ssl-manager/upgrade.sh

# artisan 常用
sudo -u www php artisan migrate --status # 迁移状态
sudo -u www php artisan schedule:backup # 手动触发备份
sudo -u www php artisan upgrade:check # 检查更新

# Supervisor 队列
bt 14 # 进入宝塔面板 → 软件商店 → Supervisor → 重启队列进程（程序名为站点域名）
```

---

## 常见问题

### 1. APP_KEY 校验失败

修复：

```bash
cd /www/wwwroot/ssl-manager/backend
sudo -u www php artisan key:generate
# 重启 PHP-FPM（宝塔面板 → 软件商店 → PHP → 重启）
```

### 2. `/api/health` 503（freeze 残留）

升级失败可能留下 lock 文件：

```bash
ls /www/wwwroot/ssl-manager/backend/storage/framework/upgrade.lock
# 如果存在但升级已完成，删除即可
rm /www/wwwroot/ssl-manager/backend/storage/framework/upgrade.lock
```

### 3. 完整性校验失败

```
SHA256 校验失败 — 包可能被篡改或下载损坏
  期望: abc123...
  实际: def456...
```

可能原因：

- CDN 缓存了旧版本（重试或换镜像源）
- 中间人攻击（验证 install.sh 自身的 sha256）
- 包真的被篡改（联系运维核实 release 渠道）

### 4. PHP 函数被禁用

宝塔默认禁用 `proc_open` / `pcntl_*`，备份/恢复 / 升级会失败。`bt-install.sh` 自动解禁；手工修：宝塔面板 → 软件商店 → PHP 设置 → 禁用函数 → 移除 `proc_open` `proc_close` `proc_get_status` `proc_terminate` `pcntl_signal` `pcntl_async_signals` `exec` `putenv`。

---

## 详细文档

- [skills/ops/deploy-ops.md](skills/ops/deploy-ops.md) — 部署运维规范
- [skills/backend/](skills/backend/) — 后端开发规范（core/order-fund/auth/upgrade/database/delegation）
- [UPGRADE.md](UPGRADE.md) — 升级回滚演练手册
