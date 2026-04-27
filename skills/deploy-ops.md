# 部署运维规范

## 部署方式

| 方式 | 适用场景 | 安装命令 |
|------|---------|---------|
| Docker | 推荐，一键部署 | `curl -fsSL .../install.sh \| bash -s docker` |
| 宝塔面板 | 传统服务器 | `curl -fsSL .../install.sh \| bash` |

---

## Docker 部署

### 一键安装

```bash
# 自动检测环境
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash

# 指定 Docker 模式
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash -s -- docker

# 非交互式
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash -s -- docker -y
```

### 目录结构

```
/opt/ssl-manager/
├── .env                    # Docker 环境变量
├── docker-compose.yml
├── Dockerfile
├── version.json            # 版本配置（release_url/network/channel）
├── backend/                # Laravel 后端（含 .env）
├── frontend/               # 前端构建产物
│   ├── admin/
│   ├── user/
│   └── web/               # 自定义前端（升级保留）
├── plugins/                # 插件目录（在线安装）
├── nginx/                  # 应用路由配置（manager.conf 等）
├── config/                 # 配置文件
│   ├── nginx.conf
│   ├── site.conf
│   ├── php.ini
│   ├── my.cnf             # MySQL 配置（档位副本）
│   ├── mysql/             # MySQL 档位模板
│   └── ssl/               # SSL 证书（可选）
├── backups/                # 备份和升级包
└── data/                   # 持久化数据
    ├── mysql/
    ├── redis/
    └── logs/nginx/
```

### 容器挂载

- **backend** 读写：支持在线升级
- **plugins** 读写：支持在线安装插件
- **backups** 读写：备份和升级包
- **version.json** 读写：切换通道
- **frontend/web** 只读：升级时在宿主机保留不删除
- **nginx/** 只读：`config/site.conf` 通过 `include /var/www/html/nginx/manager.conf` 引入
- **config/my.cnf** 只读：挂载到 MySQL 容器 `/etc/mysql/conf.d/custom.cnf`

### MySQL 档位（容器化 MySQL 镜像 8.4）

| 档位 | 内存 | 场景 |
|------|------|------|
| minimal | ~150MB | 1-2G VPS，万级以下订单 |
| standard | ~300MB | 2-4G VPS，十万级订单（默认） |
| performance | ~600MB | 4G+ 专用机，大规模部署 |

切换：`cp config/mysql/<profile>.cnf config/my.cnf && docker compose restart mysql`

### 常用命令

```bash
docker compose ps              # 查看状态
docker compose logs -f         # 查看日志
docker compose logs -f php     # PHP 日志
docker compose restart         # 重启
docker compose down            # 停止
docker compose exec php sh     # 进入容器
docker compose exec php php artisan <cmd>  # Artisan
```

---

## 宝塔面板部署

### 一键安装

```bash
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash -s -- bt
```

### 两阶段安装

| 阶段 | 执行者 | 职责 |
|------|--------|------|
| 环境准备 | `deploy/scripts/bt-install.sh` | 环境检测、代码下载、nginx 占位符替换、Composer 安装、权限设置 |
| 应用安装 | `backend/public/install.php` | 环境检测（PhpChecker/FunctionChecker/ToolChecker）、Composer 依赖、.env 生成、迁移、种子 |

### 系统要求

- PHP 8.3 或 8.4（`bt-install.sh` 仅检测这两个版本）
- MySQL 5.7+
- Redis
- Composer **2.8+**（低版本可能出现依赖安装错误，`ToolChecker` 检测）

### PHP 扩展

宝塔默认 PHP 已包含大部分必需扩展。通常需要在**面板手工安装**的是 `redis`、`fileinfo`、`intl`（宝塔面板 → 软件商店 → PHP 8.x → 设置 → 安装扩展）。

完整扩展清单见 [PhpChecker.php:14-17](../backend/public/install-assets/Checker/PhpChecker.php)，Web 安装向导会逐项检测。

### PHP 禁用函数

宝塔默认禁用 `putenv`、`proc_open`、`exec`、`pcntl_*` 等函数。`bt-deps.sh` 会自动解除以下函数（同时处理 `php.ini` 和 `php-cli.ini`，自动备份）：

```
putenv, proc_open, proc_close, proc_get_status, proc_terminate,
exec, shell_exec, pcntl_signal, pcntl_alarm, pcntl_async_signals
```

最低必需（[FunctionChecker.php:15-20](../backend/public/install-assets/Checker/FunctionChecker.php) 阻塞安装）：`exec`、`putenv`、`pcntl_signal`、`pcntl_alarm`。

### 脚本自动处理

- **权限**：`chown -R www:www $INSTALL_DIR`（宝塔 Web 用户为 `www`，非 `www-data`），`chmod -R 775 storage bootstrap/cache backups`
- **Nginx 占位符**：替换 `$INSTALL_DIR/nginx/*.conf` 和 `frontend/web/*.conf` 中的 `__PROJECT_ROOT__`
- **version.json**：注入 `release_url` 和 `network` 字段

### 手工配置步骤（脚本执行后）

1. **Nginx 站点**：宝塔面板创建网站（目录 = INSTALL_DIR，PHP 8.3/8.4），配置文件 → root 站点路径下添加：
   ```
   include /www/wwwroot/ssl-manager/nginx/manager.conf;
   ```
2. **Web 安装向导**：访问 `http://域名/install.php` 完成 Composer 依赖、迁移、种子
3. **队列守护进程**（宝塔 → 计划任务 → 守护进程，以 www 用户运行）：
   ```
   /www/server/php/83/bin/php /www/wwwroot/ssl-manager/backend/artisan queue:work --queue tasks,notifications --sleep=3 --tries=3 --max-time 3600
   ```
4. **定时任务**（宝塔 → 计划任务 → 每分钟，以 www 用户运行）：
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

在 Web 安装向导中安装（`InstallExecutor.php` → `ComposerRunner.php`）。

### 网络检测优先级

1. `FORCE_CHINA_MIRROR` 环境变量
2. 云服务商元数据（阿里云、腾讯云、华为云中国区）
3. 百度可达 + Google 不可达
4. GitHub API 访问速度

中国大陆自动使用腾讯云 Composer 镜像。

---

## 环境检测

### Docker 检测

`VersionManager.isDockerEnvironment()`:
1. 检查 `/.dockerenv` 文件
2. 检查 `/proc/1/cgroup` 包含 `docker` 或 `kubepods`

### 宝塔检测

1. 存在 `/www/server` 目录
2. 存在 `www` 系统用户
3. 安装目录在 `/www/wwwroot/` 下

### 环境差异

| 项目 | Docker | 宝塔 |
|------|--------|------|
| Web 用户 | www-data | www |
| 安装路径 | /var/www/html | /www/wwwroot/* |
| version.json | /var/www/html/data/ | 项目根目录 |

---

## 升级

### 升级模式

| 特性 | PHP API 升级 | Shell 脚本升级 |
|------|-------------|---------------|
| 触发方式 | 管理后台 API | `deploy/upgrade.sh` |
| 升级包 | upgrade 包 | full 包 |
| 适用环境 | Docker / 宝塔 | Docker / 宝塔 |

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

---

## 常见问题

### 500 服务器错误
- 检查 `storage/logs` 日志
- 确保 PHP 扩展已安装

### Redis 连接失败
- 检查 Redis 服务
- 验证 .env 配置

### 权限问题
- 检查 storage、bootstrap/cache、backups 可写
- 检查 web 用户（宝塔 `www` / Docker `www-data`）所有权

### PHP 函数被禁用
- Web 安装向导会逐项报错，按提示处理
- 宝塔：重新运行 `bt-deps.sh` 自动解除
- 手工：宝塔面板 PHP 管理 → 禁用函数

### Composer 版本过低
- 低于 2.8 可能出现依赖安装错误
- 升级：`composer self-update`
