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

SM2 证书的 CSR 生成走命令行系统 openssl（PHP openssl 扩展不支持 SM2），不影响 RSA/ECDSA。**需 OpenSSL ≥3.0.13**：OpenSSL 3.0.0~3.0.12 能签 SM2 但公钥 SubjectPublicKeyInfo 编码非标准（dual-sm2，algorithm 填 SM2 曲线 OID），会被国密 CA（如 Keeptrust）拒为「csr 解析失败」；官方 3.0.13（Ubuntu 24.04 自带；22.04 可升 3.0.14）/ 3.2.1 起 restore 回 id-ecPublicKey 标准编码。`gmOpenssl()` 用**功能探测**（实签一张 CSR 验 SPKI 是 id-ecPublicKey）自动判定，**不靠版本号比较**（3.1.0~3.2.0 版本号高但仍 dual-sm2），不达标即 fail-closed 拒单：

1. 确认系统 openssl 能签 id-ecPublicKey 标准编码（不是只看版本号）：

   ```bash
   openssl version  # 参考下限 ≥ 3.0.13（或 3.2.1+）
   openssl ecparam -genkey -name SM2 -out /tmp/k.pem 2>/dev/null \
     && openssl req -new -key /tmp/k.pem -sm3 -subj /CN=t -out /tmp/c.csr 2>/dev/null \
     && (openssl asn1parse -in /tmp/c.csr | grep -q id-ecPublicKey \
         && echo '✓ id-ecPublicKey 标准编码（可用）' || echo '✗ dual-sm2（需升级 openssl）')
   rm -f /tmp/k.pem /tmp/c.csr
   ```

2. 系统 openssl 过低（如 Ubuntu 22.04 自带 3.0.2）→ `apt install --only-upgrade openssl libssl3` 升到 3.0.13+，或编译新版装独立目录后让 `gmOpenssl()` 候选指向它。

3. 能否签 SM2 由 `gmOpenssl()` 功能探测决定，无业务开关 —— 探测到能签标准编码即可下单，否则 fail-closed（下单前拒绝），不影响非国密证书。

### PHP 禁用函数

宝塔默认禁用 `putenv`、`proc_open`、`exec`、`pcntl_*` 等函数。`bt-deps.sh` 会自动解除以下函数（同时处理 `php.ini` 和 `php-cli.ini`，自动备份）：

```
putenv, proc_open, proc_close, proc_get_status, proc_terminate,
exec, shell_exec, pcntl_signal, pcntl_alarm, pcntl_async_signals
```

最低必需：`exec`、`putenv`、`pcntl_signal`、`pcntl_alarm`；`proc_open` 强烈建议启用，避免 Composer 解压异常。

### 脚本自动处理

- **运行目录与权限**：安装器在 Composer 前主动创建 `bootstrap/cache`、`storage/{logs,framework/cache/data,framework/runtime-cache/data,framework/sessions,framework/views,app/public,app/private}`、`backups/upgrades`，再执行 `chown -R www:www $INSTALL_DIR`（宝塔 Web 用户为 `www`，非 `www-data`）及相应 `775`，并以 `www` 身份逐项验写。`upgrade.sh` 在备份/down/freeze 前和代码替换后各自愈一次；后台升级同步 bootstrap 后同样补齐。任一核心目录不可写都必须中止，不能继续进入 Composer/Artisan。
- **Nginx 占位符**：替换 `$INSTALL_DIR/nginx/*.conf` 和 `frontend/web/*.conf` 中的 `__PROJECT_ROOT__`
- **version.json**：注入 `release_url` 和 `network` 字段
- **Redis DB 分配**：安装器保持 `APP_NAME` 不变，按 phpdotenv 覆盖语义扫描同机 Manager 的 `.env`，对归一化后 `REDIS_HOST + REDIS_PORT` 相同的 Redis 实例从 DB 1 起分配独占的 `REDIS_DB`（关键运行状态/队列）与 `REDIS_CACHE_DB`（应用缓存）二元组；同实例配置无法静态确定时失败关闭，不同实例互不占用编号；默认 16 DB 最多自动分配 7 套，耗尽时需改用独立 Redis 实例。系统不接入会用 path/query 覆盖编号的 `REDIS_URL`；扫描到旧站点或目标站点的非空 `REDIS_URL` 时拒绝自动分配，须先转换为显式连接配置及实际 DB 编号
- **首次运行态分库**：`2026_09_04_000001_invalidate_sessions_for_runtime_cache_cutover` 迁移会递增全部用户/管理员的 `token_version` 并清空 refresh token，防止旧 `REDIS_CACHE_DB` 中的 JWT 黑名单失效后已登出 token 复活。升级后全部账号需重新登录一次
- **管理端安全刷新**：右上角按钮只定向失效 Setting/PayConfigCache 已登记的键与支付证书副本，不执行 `cache:clear`；队列 pause/restart、scheduler mutex、`runtime`、其它默认缓存、编译视图、会话文件、OPcache 和 Composer 缓存均保留

### 手工配置步骤（仅自动化失败时）

1. **Nginx 站点**：宝塔面板创建网站（目录 = INSTALL_DIR，PHP 8.3/8.4），配置文件 → root 站点路径下添加：
   ```
   include /www/wwwroot/ssl-manager/nginx/manager.conf;
   ```
2. **队列守护进程**（宝塔 → 计划任务 → 守护进程，以 www 用户运行；**进程数=2**，避免单 worker 被长任务如备份/恢复 timeout=3600 阻塞证书自动化）：
   ```
   /www/server/php/83/bin/php /www/wwwroot/ssl-manager/backend/artisan queue:work --queue tasks,notifications --sleep=3 --tries=3 --max-time 3600
   ```
3. **定时任务**（宝塔 → 计划任务 → 每分钟，**以 www 用户运行**）：
   ```
   /www/server/php/83/bin/php /www/wwwroot/ssl-manager/backend/artisan schedule:run
   ```

> **⚠ cron 必须以 www 运行**：`schedule:run` 勿用 root crontab 添加——root 写 file cache 后 www 的 FPM 读不到调度心跳键，`/api/health` 会误判心跳 stale/缺失（属主坑）。任务输出由宝塔面板保存，不额外重定向到项目日志。

### 目录结构

```
/www/wwwroot/ssl-manager/
├── version.json              # 版本配置（release_url/network/channel）
├── backend/                  # Laravel 后端（含 .env）
├── frontend/admin,user,web/  # 前端
├── plugins/                  # 插件目录
├── nginx/                    # manager.conf + render.sh + default/(受管) + custom/(自定义) + enabled/(渲染产物)，详见「nginx 路由自定义」
└── backups/                  # 备份和升级包
```

---

## 健康监控与告警（部署必读）

健康度由 `/api/health` 判活 + 调度心跳 + 管理后台展示 + 上游连通性告警组成。后台首页进入时检测一次，手工刷新时重新检测，不额外创建健康拨测 cron。

### /api/health 判活维度

`GET /api/health`（无鉴权、命名空间无关、不受维护模式拦截）返回 `status` 与 `checks`：

- `db`：连接探活失败 → `error`（503）。
- `cache`：同时只读探测默认缓存与 `runtime`，任一失败 → `error`（503，redis 宕机）。排在 db 之后、其余维度之前，避免整个 `/api/health` 变非结构化 500。
- `disk_free_gb`：低于 `health.disk_free_threshold_gb`（默认 1.0）→ `error`（503）。
- `queue_lag_seconds`：redis 驱动=各队列就绪深度 + **已到期**延时之和（阈 `health.queue_depth_threshold`，默认 500 条）；database 驱动=积压秒数（阈 `health.queue_lag_threshold`，默认 600 秒）。超阈 → `error`（503）。
- `heartbeat_age_seconds`：`schedule:heartbeat` 每分钟写入 `runtime`；**过旧**（> `health.heartbeat_stale_seconds`，默认 300）→ `error`（503，死 scheduler）；**缺失**（null）→ `degraded`（**200**，新装机未跑调度）。普通 `cache:clear` 不删除心跳。
- `check_statuses`：逐项返回 `ok/degraded/error` 供后台用绿/黄/红着色；`queue_lag_unit` 明确队列值单位（database=`seconds`、redis=`jobs`）。后台只消费服务端判定，不自行复制健康阈值。
- **freeze 期**（升级冻结）：`queue_lag` 与心跳 stale 均不参与 503 判定（worker/scheduler 已按升级流程停止），避免升级窗误报。

### 外部站点监控（可选）

如需无人访问后台时仍主动发现整机、网络、crond 或 PHP 故障，可选配外部站点监控拨测 `https://<域名>/api/health`。这不属于 ssl-manager 必需部署项。

### 存量机器升级后核对清单（§1.5）

`upgrade.sh` 的 cron 管理段在升级时只修正 `schedule:run` 的 PHP 绝对路径，不改变现有 schedule 命令的日志重定向。升级后请核对：

1. 宝塔计划任务只保留 `<域名>` 的 `schedule:run`，每分钟以 www 运行。
2. 后台首页“系统健康”可正常显示数据库、缓存、调度心跳、队列和磁盘状态。

**无宝塔 API key 时**，升级脚本跳过 cron/supervisor PHP 路径检查；`schedule:run` 缺失时按上方步骤 3 手工添加。

### 卡单孤儿清理

`schedule:sweep-orphan-orders`（每小时）清理 channel=auto 卡死的孤儿续费/重签单：

- **unpaid 分支**（默认开，可用 `RECONCILE_ORPHAN_UNPAID_ENABLED=false` 关闭）：超时未支付孤儿 → 删除新单、恢复旧证书 active，无退款无流水。
- **pending 分支无开关**：只有同时满足 `channel=auto`、pending、`api_id=null`、提交重试到顶、非产品缺失、无执行中 commit 的已扣费订单才进入；随后调用 `cancelPending` 退款并恢复旧证书。锁内若发现 late-commit 已推至 processing，会拒绝退款并留待后续流程。
- `schedule:reconcile-pending` 的每日 `reconcile_maxed` 管理员快照仍保留，用于事后核对自动退款集合和异常订单。

---

## nginx 路由自定义

Manager 的 nginx 路由采用三层目录机制，升级永不覆盖用户自定义内容。

### 三层目录

```
nginx/
├── default/{routes,snippets}/   # 系统默认，升级覆盖，勿直接编辑
├── custom/{routes,snippets}/    # 你的自定义，升级不覆盖，不进发布包
└── enabled/{routes,snippets}/   # 渲染产物，勿直接编辑
```

`manager.conf` 只 `include enabled/routes/*.conf`。`nginx/render.sh <安装目录>` 将 default + custom 合并渲染到 enabled：同名 conf 以 custom 优先（enabled 写 include 指针指向 custom，默认版被抑制，避免 nginx `duplicate location` 报错）；无同名 custom 则将 default 的占位替换后复制。

首次运行会将 `frontend/web/web.conf`（出厂模板）播种到 `custom/routes/web.conf`，之后由你自行维护。

三条部署路径（`bt-install.sh` / `upgrade.sh` / 后台 `PackageExtractor`）均对称调用 render.sh；后台路径经 `proc_open` 调用、不自动 reload（由管理员或 BT 后续 reload）。

### 常见操作

**覆盖现有路由**（如 `/admin`）：创建 `nginx/custom/routes/admin.conf`（与默认同名），内含完整 `location` 块。同名即抑制默认，无 duplicate location 冲突。

**新增路由**：创建 `nginx/custom/routes/<name>.conf`，内含 `location` 块。

**覆盖静态缓存 snippet**：创建 `nginx/custom/snippets/spa-static-cache.conf`。

**修改已有 custom 文件内容**：直接编辑，然后：

```bash
nginx -s reload
```

enabled 中的 include 指针已指向 custom 文件，无需重新渲染。

**增加或删除 custom 文件**（路由数量变化）：需重新渲染再 reload：

```bash
bash nginx/render.sh /www/wwwroot/ssl-manager --reload
```

`--reload` 会先 `nginx -t` 测试配置，通过才 reload；失败则回滚 enabled 目录、不执行 reload（站点不受影响）。

**server 级配置**（`rewrite`、`add_header`、`client_max_body_size` 等非 location 块配置）：不属于本机制范畴，在 BT 面板站点自定义 nginx 配置（server 块）中维护。

### 目录 custom/ 和 enabled/ 不进发布包

发布包只含 `default/` 和 `render.sh`，升级时先清空 `default/`（防旧路由残留），再递归复制包内 `default/`，最后调 render.sh 重建 enabled。custom/ 内容全程不动。

### 注意事项

- **覆盖必须用与默认完全相同的文件名**（如覆盖 `/api` → `custom/routes/api.conf`）。用不同文件名却写同一个 `location` 会产生 nginx `duplicate location`，导致下次 nginx 启动失败。
- **改完 custom/ 后请 `nginx -t` 再 reload**：custom/ 里的语法错误不会被部署流程拦截，会在下次 nginx 重启时让整个站点起不来。
- **`UPGRADE_AUTO_MIGRATE` 保持开启（默认）**：它关闭时后台升级到本版本不会自动渲染 enabled/；此时需手动 `php artisan migrate`（会执行自愈迁移）或手动 `bash <安装目录>/nginx/render.sh <安装目录>`。

---

## Composer 依赖安装

发行包包含与 `composer.lock` 锁定的 `backend/vendor`，并以
`vendor/composer/.ssl-manager-lock.sha256` 校验完整性。安装脚本优先使用包内依赖；
仅为兼容不带 vendor 的历史安装包才运行 `composer install`。

这是长期部署契约：生产服务器不负责解析新版本依赖。国内镜像同步延迟或官方源/GitHub
不可达时，安装与升级仍应只依赖已下载并通过 SHA-256 校验的发布包。vendor 必须在构建环境由
对应 lock 生成，随完整包、升级包和含 Composer 依赖的插件包一起交付；后台与 Shell 消费端都
必须先验 lock marker，再替换现有 vendor。

历史包触发受控 `composer install` 时，安装脚本、Shell 升级、后台升级和插件安装器都必须在
autoload 成功生成后原子刷新该 marker；标记写入失败按依赖安装失败处理，不得留下“依赖成功但
后续无法复用”的半完成状态。Composer 的 `post-autoload-dump` 钩子同时覆盖手工执行的
`composer install`、`composer update` 与 `composer dump-autoload`；生产环境仍不建议自行改变发布依赖集合。

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
- 添加 supervisor 守护进程（程序名为站点域名 `$SITE_DOMAIN`，保多站点唯一；`numprocs=2`，两处硬编码 `bt-install.sh` `bt_add_supervisor_process` 实参 + 手工提示对称。存量装机 `upgrade.sh` `${snumprocs:-1}` 保留现值，老装机可手工提 2）
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

备份和恢复由部署程序所在机器上的客户端执行，并通过现有数据库连接访问目标 MySQL；目标数据库可以在内网其它机器上，部署机不需要安装 MySQL 服务端，但必须安装客户端。只支持 Oracle MySQL 5.7、8.0、8.4，且 `mysql` / `mysqldump` 必须与目标服务端同系列。不要安装可能实际提供 MariaDB 的 `default-mysql-client`；宝塔部署优先使用 `/www/server/mysql/bin` 中目标 MySQL 自带的客户端。

恢复前会校验备份完整性、当前服务端和本机客户端版本、备份 `schema.json` 与当前 Schema 差异及空间事实。Schema 差异只展示事实并要求恢复人员显式确认，不推荐或自动切换程序版本；确认后允许跨程序版本恢复。成功恢复后正常退出维护和冻结状态，由恢复人员自行处理程序版本。

后台恢复走同步命令：

```bash
cd backend
php artisan database:restore backup_20260101_120000
# Schema 有差异且确认继续时：
php artisan database:restore backup_20260101_120000 --allow-schema-difference
```

恢复在目标库内流式导入影子表，再用一条 `RENAME TABLE` 同批切换全部业务表和需清空的运行时表，不创建第二数据库连接、临时数据库或永久状态表，也不落完整解压 SQL。`*_logs`（包括插件日志）保留目标库现状；队列、缓存、会话、刷新令牌和域名验证运行时表切换为空表。换表前失败保持原 active；换表后校验失败执行完整反向切换；新 active 已验证但 cleanup 失败时保持冻结并保留 old 表，修复原因后重跑同一命令续接。不要用 `gunzip | mysql` 绕过预检、生成列改写、日志保留和原子切换。

## 升级注意事项

### upgrade.sh 中断后的手工恢复（operator runbook）

`upgrade.sh` 是 `set -e`：freeze 点火后、unfreeze 前任一危险步骤失败/中断即退出。**数据侧已自动兜底**（P0-2 包U）：

- **storage 自动还原**：切代码窗内把活的 `backend/storage`（含 `storage/databak` 全部本地 DB 备份）`mv` 到安装目录同文件系统的 `.upgrade-preserve-$$`；失败退出 / `Ctrl-C` / `SSH 断连`（SIGINT/TERM/HUP）均由 `cleanup` trap **先把 storage 移回原位再清理**——storage 与 databak 不丢。保留目录在持久盘（非 `/tmp`），故即便 `SIGKILL`/断电（trap 跑不了）数据也存活在 `.upgrade-preserve-*/storage`。
- **自定义适配器 / 前端静态资源自动还原**：正常恢复步骤以 `_restore_preserved_extras consume` 把 `api_adapters`（自定义 Order/Acme 源）与 `frontend_config`（user 的 `logo.svg`、`qrcode.png`、登录配图 `login.svg` 回落资源）复制回原位，并在每项成功后消费对应 preserve 副本，成功 EXIT cleanup 不再重复覆盖或误报中断（消费失败只告警不中止升级，残留副本交 cleanup 统一清理）。若中断落在「rm 旧代码 ~ 恢复保留文件」窗内，`cleanup` 删除 preserve 前仍以默认守卫模式还原这些唯一在线副本；还原失败则保留 preserve 供人工恢复。二维码占位图不由升级包交付；admin 统一回落 user Logo；`platform-config.json` 不再备份或恢复，由升级包直接更新。

二维码占位图不进入升级包：升级时原样保留安装目录已有的 `qrcode.png`，用户端在后台未上传二维码时直接使用该 PNG；完整安装包携带默认的 400×400 PNG。

- **vendor 砖机兜底**：当前 vendor 以 `mv` 进 preserve，同时新升级包也携带可校验的 vendor。若切换窄窗中断导致在线 vendor 缺失，重跑时入口 `_check_stranded_preserve` 优先把 vendor-only 残留**回迁**到原位；即便历史升级包或异常环境没有可用的包内 vendor，composer 触发判定 `_need_composer_install` 见 `vendor/autoload.php` 缺失仍会**强制重装**（不因新旧 hash 相等误跳过），避免「artisan fatal + 每次重跑必失败」的砖机自循环。
- **搁浅数据入口拦截**：SIGKILL/断电后 storage 滞留 `.upgrade-preserve-*/storage` 而 `backend/storage` 缺失时，**重跑 `upgrade.sh` 会在入口被拦截并中止**（否则会新建空 storage 把真数据连同 databak 静默埋掉）。按终端指引先手工把 storage 移回、删除残留目录，再重跑：

  ```bash
  mv '<站点>/.upgrade-preserve-<pid>/storage' '<站点>/backend/storage'
  rm -rf '<站点>/.upgrade-preserve-<pid>'
  ```

  （无 storage 子目录的空壳残留会被入口顺手清理并留痕日志，vendor-only 残留则自动回迁，均无需人工。）

- **same-fs 断言**：`backend/storage` 与安装目录不在同一文件系统时（异构挂载），mv-out 前断言失败**中止升级、原地未破坏**，需调整挂载布局后重试。

**服务侧仍需人工恢复**（不自动 up）：freeze + down 会滞留（现象：非白名单 API 503——freeze TTL 2h 后自解，但 queue worker / scheduler 停摆**不会自解**）。失败时终端会自动打印下述 runbook。手工恢复顺序**必须先解冻再解维护**（与 `skills/backend/upgrade.md` 顺序契约一致；颠倒则 up 唤醒的 worker 在 freeze 下被 `release(60)` 烧 attempts）。**前置条件**：先确认代码目录完整（重跑 `upgrade.sh` 至成功、或 `upgrade.sh rollback`）——不确认就 up 会把半迁移库/半新代码放给流量并唤醒 worker，比卡 freeze 更坏：

```bash
cd <站点>/backend
php artisan upgrade:unfreeze   # ① 先解冻（严格先于 up）
php artisan up                 # ② 再解除维护（恢复 worker/scheduler 消费）
php artisan queue:restart      # ③ 重启常驻 worker
```

随后看 `storage/upgrades/status.json` 与升级日志决定：重跑 `upgrade.sh` 或走 `upgrade.sh rollback`（rollback 新格式分支只恢复 app/config/database/routes/bootstrap + 配置文件、**不触碰 storage**，自身已内置 unfreeze→up 配对）。

### Laravel 13 升级 release 部署

部署含 Laravel 13 升级的 release（首次为升级 PR 合并后的 release）到生产时：

- **所有在线用户会被登出**：Laravel 13 默认 session cookie 名格式由 `{app_name}_session` 改为 `{app_name}-session`（下划线→连字符），cookie 名不匹配 → 全部 session 失效
- **旧 Redis cache key 失效**：Laravel 13 默认 cache prefix 由 `{app_name}_cache_` 改为 `{app_name}-cache-`，旧 key 不再被读到，会自然过期、无业务影响
- **建议部署窗口**：业务低峰期发布；可在公告或登录页提前告知用户会被登出
- **不要通过 .env 显式保留旧 prefix**——本系统已决策接受用户重登，避免长期维护两套 prefix

### innodb_lock_wait_timeout 固化（超时加固分支）

`backend/config/database.php` 通过 PDO `MYSQL_ATTR_INIT_COMMAND`（`SET SESSION innodb_lock_wait_timeout=50`）在每次建立数据库连接时固化会话级锁等待超时，session 值覆盖 global：

- **无需再手动配置**：不用在 `my.cnf` / 云 RDS 参数组里另设 `innodb_lock_wait_timeout`，代码层已保证生效值为 50s
- **隐性行为变更**：若生产此前手动把 global `innodb_lock_wait_timeout` 调得比 50 更小（如 10s、20s），升级后业务连接的 session 值会被覆盖为 50s——行为从"锁等待更快失败"变为"固定等待 50s 才报 `1205`"。依赖更短锁等待超时做快速失败的场景（如有）需重新评估
- 详见 `skills/backend/order-fund.md` order 级互斥锁章节、`skills/backend/source-api.md` Sdk 超时约定章节

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
