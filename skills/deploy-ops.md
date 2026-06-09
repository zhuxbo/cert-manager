# 部署运维规范

> 项目仅支持 MySQL + 宝塔面板部署。

## 一键安装

```bash
# 中国大陆机器
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash

# 海外服务器
curl -fsSL https://release-us.cnssl.com/install.sh | sudo bash
```

`install.sh` 自动从 `releases.json` 强校验脚本包 sha256（完整性校验链），失败立即退出。

---

## 宝塔面板部署

### 一键安装

```bash
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash
```

### 安装流程

`bt-install.sh` 单脚本完成环境准备和应用初始化：

1. 检测宝塔面板（版本 ≥ 11.5）与 PHP 8.3/8.4
2. 配置 MySQL 数据库连接（host/port/database/username/password）
3. 解禁必要 PHP 函数并按驱动安装缺失扩展
4. 下载 `ssl-manager-full-{version}.zip` 并按 `releases.json` 校验 sha256
5. 安装 Composer 依赖、生成 `.env`、执行 `migrate` + `db:seed`
6. 通过 `admin:reset-password` 设置 admin 初始密码
7. 尝试宝塔 API 自动建站、注入 vhost、添加 supervisor 和 cron；失败时打印手工配置命令

### 系统要求

- 宝塔面板 11.5+
- PHP 8.3 或 8.4（`bt-install.sh` 仅检测这两个版本）
- MySQL 5.7+ 或 MariaDB
- Redis 可选（默认 file cache + database queue）
- Composer **2.8+**（低版本可能出现依赖安装错误）

### PHP 扩展

宝塔默认 PHP 已包含大部分必需扩展。通常需要额外确认的是 `pdo_mysql`、`fileinfo`、`calendar`、`intl`，按需启用 `redis`。`bt-deps.sh` 会尽量自动安装缺失扩展；失败时提示到宝塔面板 → 软件商店 → PHP 8.x → 设置 → 安装扩展手工处理。

### 国密 (SM2) 支持（可选，仅签发国密证书时需要）

SM2 证书的 CSR 生成走命令行系统 openssl（PHP openssl 扩展不支持 SM2），不影响 RSA/ECDSA。**OpenSSL 3.0+ 的 default provider 原生支持 SM2**，主流发行版（Debian/Ubuntu/RHEL 系）系统 openssl 即满足，**无需任何额外安装或配置**——系统已统一依赖系统 OpenSSL 3，不接独立国密二进制：

1. 确认系统 openssl 支持 SM2（绝大多数 OpenSSL 3.0+ 满足；LibreSSL / 编译 `no-sm2` / FIPS-only 除外）：

   ```bash
   openssl version                          # ≥ 3.0
   openssl ecparam -name SM2 -genkey -noout # exit 0 即支持
   ```

2. 无需配置：`BinaryLocator::gmOpenssl()` 自动探测系统 openssl 是否真支持 SM2（与系统 `openssl()` 共用候选 + shell PATH 兜底，功能探测真验 SM2，不支持的会被正确跳过、不签错证书）。

3. 能否签 SM2 由 `gmOpenssl()` 探测决定，无业务开关 —— 探测到支持即可下单 SM2，都不支持则国密 fail-closed（下单前拒绝），不影响非国密证书。

### PHP 禁用函数

宝塔默认禁用 `putenv`、`proc_open`、`exec`、`pcntl_*` 等函数。`bt-deps.sh` 会自动解除以下函数（同时处理 `php.ini` 和 `php-cli.ini`，自动备份）：

```
putenv, proc_open, proc_close, proc_get_status, proc_terminate,
exec, shell_exec, pcntl_signal, pcntl_alarm, pcntl_async_signals
```

最低必需：`exec`、`putenv`、`pcntl_signal`、`pcntl_alarm`；`proc_open` 强烈建议启用，避免 Composer 解压异常。

### 脚本自动处理

- **权限**：`chown -R www:www $INSTALL_DIR`（宝塔 Web 用户为 `www`，非 `www-data`），`chmod -R 775 storage bootstrap/cache backups`
- **Nginx 占位符**：替换 `$INSTALL_DIR/nginx/*.conf` 和 `frontend/web/*.conf` 中的 `__PROJECT_ROOT__`
- **version.json**：注入 `release_url` 和 `network` 字段

### 手工配置步骤（仅自动化失败时）

1. **Nginx 站点**：宝塔面板创建网站（目录 = INSTALL_DIR，PHP 8.3/8.4），配置文件 → root 站点路径下添加：
   ```
   include /www/wwwroot/ssl-manager/nginx/manager.conf;
   ```
2. **队列守护进程**（宝塔 → 计划任务 → 守护进程，以 www 用户运行）：
   ```
   /www/server/php/83/bin/php /www/wwwroot/ssl-manager/backend/artisan queue:work --queue tasks,notifications --sleep=3 --tries=3 --max-time 3600
   ```
3. **定时任务**（宝塔 → 计划任务 → 每分钟，以 www 用户运行）：
   ```
   /www/server/php/83/bin/php /www/wwwroot/ssl-manager/backend/artisan schedule:run
   ```

### 目录结构

```
/www/wwwroot/ssl-manager/
├── version.json              # 版本配置（release_url/network/channel）
├── backend/                  # Laravel 后端（含 .env）
├── frontend/admin,user,web/  # 前端
├── plugins/                  # 插件目录
├── nginx/manager.conf        # 被网站配置 include
└── backups/                  # 备份和升级包
```

---

## Composer 依赖安装

发行包不包含 `backend/vendor`。`bt-install.sh::run_composer_install` 在宿主机执行 `composer install --no-dev --optimize-autoloader`。

### PHP / Composer 路径约定

**所有 PHP / Composer 调用必须用绝对路径**，避免宝塔多版本系统下 root PATH 命中错误版本：

- artisan：`"$PHP_CMD" artisan ...`（`PHP_CMD=/www/server/php/<ver>/bin/php`）
- composer：`"$PHP_CMD" "$COMPOSER_BIN" install ...`（**显式 `$PHP_CMD` 驱动 phar，绕过 shebang `#!/usr/bin/env php`**，否则 phar 内部仍会用 PATH 中找到的 PHP）

`bt-install.sh` 安装时由 `select_php_version` 选定 `PHP_CMD`，写入 BT vhost / supervisor / cron 配置。
`upgrade.sh` 通过 `detect_php_cmd` 探测：env `PHP_CMD` 优先 → BT vhost 反查（`root` 等于 `INSTALL_DIR` 的站点的 `enable-php-XX.conf`）→ 系统单版本兜底；多版本反查失败时报错要求 `export PHP_CMD=...`。

### 网络环境（国内镜像）

`install.sh` 启动时确定网络环境：

1. env `FORCE_CHINA_MIRROR=1/0` 优先
2. `-y` 非交互模式默认中国大陆
3. 交互模式让用户选（默认 1=中国大陆）

选中"中国大陆"时：

- `bt-install.sh::check_composer` 用 `-g` 全局配置阿里云源（`mirrors.aliyun.com/composer/`）
- 同时写入 `version.json` 的 `network: "china"`，供 `upgrade.sh` 后续使用

---

## 升级

### 升级模式

| 入口                     | 触发方式                   | sha256 校验          | 失败回滚             |
| ------------------------ | -------------------------- | -------------------- | -------------------- |
| 管理后台一键升级         | 后台 → 系统设置 → 在线升级 | releases.json 强校验 | 解压 backup zip 还原 |
| `deploy/upgrade.sh`(SSH) | 直接运行                   | releases.json 强校验 | 解压 backup zip      |

freeze 文件锁路径：`storage/framework/upgrade.lock`。

### 命令

```bash
php artisan upgrade:check     # 检查更新
php artisan upgrade:run       # 执行升级
php artisan upgrade:rollback  # 回滚
```

### 安装目录检测

升级脚本通过 `backend/.ssl-manager` 标记文件检测：

1. 预设目录：/opt/ssl-manager、/www/wwwroot/ssl-manager
2. 系统搜索：/opt、/www/wwwroot、/home（深度 4 层）

### releases.json + sha256 校验链

唯一真相源：release 站根目录 `releases.json`（GitHub Release API 风格 + `assets[].sha256` 字段）。

```json
{
  "releases": [
    {
      "tag_name": "v0.4.23-beta",
      "prerelease": true,
      "assets": [
        {
          "name": "ssl-manager-full-0.4.23-beta.zip",
          "sha256": "...",
          "size": 3500000
        },
        {
          "name": "ssl-manager-upgrade-0.4.23-beta.zip",
          "sha256": "...",
          "size": 2800000
        },
        {
          "name": "ssl-manager-script-0.4.23-beta.zip",
          "sha256": "...",
          "size": 120000
        }
      ]
    }
  ]
}
```

校验时序（所有入口对齐）：

1. `install.sh` 解析 `latest`/`dev` 占位符（`_resolve_version` 按 `prerelease` 字段定位）→ 拿具体版本号 → 下脚本包 → 从 releases.json 读 `assets[].sha256` 强校验
2. `bt-install.sh` 下载完整包后读对应 asset.sha256 强校验
3. `upgrade.sh` 同 1，**latest/dev 不再跳过校验**（先解析版本再下包）
4. 任一失败立即 `exit 1`，不降级

`_resolve_version` 是单函数共享逻辑（每个发布入口脚本各自内联，因 install.sh / upgrade.sh 是 release 站发布的单文件，不能 source）。

---

## 宝塔 API 集成

`bt-install.sh` 默认询问 `BT_KEY`（在面板"面板设置 → API 接口"获取）以自动化网站配置：

- 自动创建网站
- 写 nginx 自定义配置
- 添加 supervisor 守护进程（manager-queue）
- 添加 cron（schedule:run）

降级路径（用户拒绝 / 未提供 `BT_KEY`）：打印手工配置步骤，体验等同现状（用户面板手工配）。

### admin 密码安全

bt-install.sh 严格 4 种来源（**禁止** `--admin-password=xxx` 命令行明文，会进 shell history）：

1. 临时文件 `--admin-password-file=PATH`（chmod 600，脚本读取后 rm 销毁）
2. 环境变量 `ADMIN_PASSWORD=xxx`（读后 unset）
3. 交互输入（明文回显，`< /dev/tty` 防 stdin 重定向；安装一次性私有操作，避免视障/远程终端用户输入看不见出错）
4. 自动生成 16 位 `openssl rand -base64 12 | tr -d '+/=' | cut -c1-16`（终端打印一次，首次登录后建议立即修改）

bt-install 不落盘保存 admin 密码，seed 后直接调用 `admin:reset-password`。

### 备份产物格式

`MysqlBackupHandler` 用 `mysqldump` 输出 SQL 文本，`gzip` 压缩后落 `storage/databak/{prefix}_{Ymd_His}.sql.gz`，**不做应用层加密**。

理由：备份文件与 `.env`、数据库本身住在同一台机器，应用层加密对"获取文件读取权限"的攻击者无效；密钥保管反而是新的失败模式。防护交给文件系统层（`storage/` chmod、`.env` 600）。异地保存（S3 / 邮件 / U 盘）请在**传输前**自行 `gpg --encrypt` 或 `age` 加密。

恢复方式：

```bash
gunzip -c backup_20260101_120000.sql.gz | mysql -u<user> -p <db>
```

## 升级注意事项

### Laravel 13 升级 release 部署

部署含 Laravel 13 升级的 release（首次为升级 PR 合并后的 release）到生产时：

- **所有在线用户会被登出**：Laravel 13 默认 session cookie 名格式由 `{app_name}_session` 改为 `{app_name}-session`（下划线→连字符），cookie 名不匹配 → 全部 session 失效
- **旧 Redis cache key 失效**：Laravel 13 默认 cache prefix 由 `{app_name}_cache_` 改为 `{app_name}-cache-`，旧 key 不再被读到，会自然过期、无业务影响
- **建议部署窗口**：业务低峰期发布；可在公告或登录页提前告知用户会被登出
- **不要通过 .env 显式保留旧 prefix**——本系统已决策接受用户重登，避免长期维护两套 prefix

## 常见问题

### 500 服务器错误

- 检查 `storage/logs` 日志
- 确保 PHP 扩展已安装

### Redis 连接失败

- 检查 Redis 服务
- 验证 .env 配置

### 权限问题

- 检查 storage、bootstrap/cache、backups 可写
- 检查 web 用户（宝塔 `www`）所有权

### PHP 函数被禁用

- 宝塔安装脚本会预检并尝试自动解除常见禁用函数
- 宝塔：重新运行 `bt-deps.sh` 自动解除
- 手工：宝塔面板 PHP 管理 → 禁用函数

### Composer 版本过低

- 低于 2.8 可能出现依赖安装错误
- 升级：`composer self-update`

### 回调端点返回"回调未配置鉴权"

- 出厂 `callback.default` 的 token 与 allowed_ips 均为空，回调端点默认**拒绝**（防裸奔被刷 sync / 探测 api_id 存在性）
- 接入上游 webhook 前，须在管理端「系统设置 → 回调设置」为对应 endpoint 配置 token 或 IP 白名单**至少其一**
- 配任一即放行（允许仅 IP 白名单或仅 token），两者皆空才拒绝
