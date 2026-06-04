# 后端开发规范

## 技术栈

- **框架**: Laravel 11.x
- **PHP**: 8.3+，双引号变量不加大括号（如 `"$var"` 而非 `"{$var}"`）；例外：变量后紧跟中文等非 ASCII 字符时必须加花括号（`"{$var}，中文"` 而非 `"$var，中文"`），因为 PHP 变量名匹配 `\x80-\xff` 字节
- **数据库**: MySQL 8.0
- **缓存/队列**: Redis
- **认证**: JWT (tymon/jwt-auth)
- **代码规范**: PSR-12、PHP Pint、PHPStan

## 目录结构

```
backend/
├── app/
│   ├── Http/Controllers/
│   │   ├── User/           # 用户端 /api/*
│   │   ├── Admin/          # 管理端 /api/admin/*
│   │   ├── V1/             # API v1
│   │   ├── V2/             # API v2
│   │   └── Callback/       # 回调处理
│   ├── Models/
│   ├── Services/           # 业务逻辑层
│   │   ├── Acme/          # ACME 订阅管理（封装下单 + 交付 EAB，不实现 RFC 8555）
│   │   ├── Order/         # 订单服务
│   │   └── Upgrade/       # 升级系统
│   ├── Jobs/               # 队列任务
│   └── Utils/
├── routes/
│   ├── api.user.php
│   ├── api.admin.php
│   ├── api.v1.php
│   ├── api.v2.php
│   └── api.callback.php
└── database/
    ├── migrations/
    └── seeders/
```

## 架构设计

### 纯 API 架构

- 无 session/cookie 依赖，完全前后端分离
- 统一响应格式：成功 `{"code": 1, "data": {...}}`，失败 `{"code": 0, "msg": "..."}`
- 统一异常处理：`ApiResponseException`

### JWT 多端认证

| 端        | 路由前缀               | 认证方式 |
| --------- | ---------------------- | -------- |
| 用户端    | `/api/`                | JWT      |
| 管理端    | `/api/admin/`          | JWT      |
| API v1/v2 | `/api/V1/`, `/api/v2/` | Token    |

---

## 升级系统

### 升级冻结契约

升级期间应用进入只读维护态，避免 in-flight HTTP/Job 半执行：

- **freeze 文件锁**：`storage/framework/upgrade.lock` 文件存在=已 freeze；与 cache driver 完全解耦（`cache:clear` 不会清掉它）
- **HTTP**：`MaintenanceMode` 中间件返回 503 + Retry-After，白名单 `/api/health` / `/api/meta` / `/api/admin/upgrade/*` / admin 会话保活
- **Queue**：`SkipWhenUpgradeFrozen` middleware 在 Job 执行业务前 `release(60)` 早退
- **Schedule**：`routes/console.php` 所有 `Schedule::command(...)` 链 `->skip(fn () => UpgradeFreezeLock::isFrozen())`，freeze 期间不触发 Command
- **smoke test 失败处理**：不 unfreeze，回滚代码到旧版本，旧版 smoke 通过后再恢复服务；都失败保持 freeze + 报警

### 关键服务

| 服务                   | 职责                                                                   |
| ---------------------- | ---------------------------------------------------------------------- |
| `UpgradeService`       | 升级主逻辑，`performUpgradeWithStatus()`                               |
| `UpgradeStatusManager` | 状态管理，动态步骤计算；`fail($msg, $details)` 支持结构化失败上下文    |
| `EnvironmentChecker`   | 读 `php-requirements.json`，校验 PHP 版本/扩展/函数；产出结构化 report |
| `PackageExtractor`     | 包解压和应用，权限检查                                                 |
| `ReleaseClient`        | Release 获取                                                           |
| `BackupManager`        | 备份和恢复                                                             |
| `VersionManager`       | 版本比较，环境检测                                                     |

**`PackageExtractor::applyBackendUpgrade` 同步策略（动态发现，非白名单）**：**遍历 source backend 顶层目录**逐个 `syncDirectory`（**只覆盖不删除**），`skipDirs` 排除 `storage`（运行时数据 + 升级自身状态 `upgrade.lock`/status；且 `removeEmptyDirectories` 会误删其空目录）和 `vendor`（单独同步）；根文件按清单 `artisan`+`composer.json/lock`+`php-requirements.json` 复制，`version.json` 由 `updateVersionJsonWithPreservedFields` 单独处理（保留 `release_url`/`network`）。早期用硬编码白名单，曾漏 `resources` 导致 `resources/docs/api/*.yaml`（对外 API 文档 `MetaController::apiDoc` 读取）等代码资源不随升级更新，症状是「代码更新了（app/routes → 路由注册，POST 405）但资源 404」；**改动态发现后新增顶层目录永不再漏**（upgrade 包打包已 exclude `storage/*`/`vendor/`/`tests/`，source 有什么同步什么天然安全）。

**两条升级路径删除语义不同、且都正确**：后台升级（PHP，在被升级代码内运行、不能全量删自身）→ 只覆盖不删除，旧版删除的文件会残留（本项目路由是显式白名单不扫目录，残留基本无害）；需彻底清理残留时走 `upgrade.sh`（外部 shell，`rm -rf` 各目录 + 整体 `cp` 全量替换、天然无残留）。一致性目标是「都不漏应更新的目录」，删除策略因运行环境不同而必须不同。

**升级器自更新有一次时序滞后**：本次升级跑的是服务器上的旧 `PackageExtractor`，逻辑修复要下一次升级才生效（或本次升级后手动补缺失资源）。回归测试见 `PackageExtractorTest`（动态发现新目录 / storage 跳过 / resources 同步）。

### 升级模式

| 特性             | PHP API 升级                      | Shell 脚本升级                                        |
| ---------------- | --------------------------------- | ----------------------------------------------------- |
| 触发方式         | 管理后台 API                      | `deploy/upgrade.sh`                                   |
| 升级包           | `upgrade` 包                      | `full` 包                                             |
| 维护模式         | 自动进入/退出                     | 自动进入/退出                                         |
| PHP 环境不达标   | 仅检测；前端弹窗指引用 upgrade.sh | 询问 BT API key 自动装扩展/启用函数；否则手工指引     |
| composer install | `--no-scripts` + 单独 discover    | `--no-dev --optimize-autoloader` + 兜底 dump-autoload |

### PHP 环境检测

- **需求清单**：`build/php-requirements.json`（与版本绑定）。build 时同时复制到 release zip 根（`full`/`upgrade` 包，供升级流程读）+ script zip 根（与 `install.sh`/`upgrade.sh` 同级，供安装流程读）。字段：`php_min` / `php_recommended` / `extensions.required[]` / `extensions.recommended[]` / `functions.required[]` / `functions.recommended[]`
- **install.sh 入口（安装时）**：
  - `bt-install.sh::select_php_version` 读 `php_min`，扫 `/www/server/php/*` 用 `version_compare` 过滤符合版本的 PHP（取代 hardcode `[84 83]`）
  - `bt-deps.sh::check_php_extensions` 读 `extensions.required[]`（剔除 PHP 内置 `bcmath/ctype/dom/...` 等不需要 BT 单独装的），用于扩展存在性校验和自动安装
  - `bt-deps.sh::check_disabled_functions` 读 `functions.required[]`，扫 php.ini + php-cli.ini 的 `disable_functions`，自动 sed 移除（备份原 ini）+ 重启 PHP-FPM
- **upgrade.sh 入口（升级时）**：
  - 解压后、切代码前调 `check_php_environment "$src_dir"`：读 release zip 内 `$src_dir/php-requirements.json`
  - PHP 版本错 → 手工指引 exit；仅扩展/函数错 → 询问是否走 BT API 自动修复
  - BT API 自动修复：`bt_resolve_key`（仅自动读 `api.json`，不当场 read 收 key）→ 调 `bt-deps.sh::auto_install_ext`（三路径 fallback：BT API → legacy script → ini 直写）装扩展 → 调 `bt-deps.sh::enable_functions` 直接 sed `php.ini` + `php-cli.ini` 移除禁用函数（绕过 BT API GetPHPConfig，因其在 CLI ini 单独配置时返回不准）→ 直接重新校验（升级流程全程 CLI 启新进程读 ini，不依赖 FPM 状态，故不再 sleep / reload FPM；FPM reload 推迟到升级末尾步骤 15b 统一处理）
  - 未探测到 BT key → 提示用户到面板"设置 → API 接口"启用并加 IP 白名单后重跑（与 install.sh `detect_bt_key` 一致，避免明文 key 进终端历史）
- **后端 web 入口（管理后台触发）**：`UpgradeService::performUpgradeWithStatus()` 的 `check_environment` 步骤（extract 之后、apply 之前）。不通过抛 `PhpEnvironmentException`，catch 块把 `details` 写入 `status.json.error_details`，前端 ElDialog 弹窗展示
- **cron/supervisor PHP 路径**：upgrade.sh 升级末尾调 `update_jobs_php_path`，扫 `bt_list_crontab_all` + `bt_list_supervisor_all` 中含 `/www/server/php/XX/bin/php`（或裸 `php` token）与当前 `$PHP_CMD` 不一致的项。对 install.sh 自管（cron 含 `$INSTALL_DIR/backend/artisan schedule:run`；supervisor 含 `artisan queue:work` 且 path=`$INSTALL_DIR/backend`）且类型内唯一的项，自动覆盖更新（cron 走"先删后加 + 失败用原 body 回滚"三段语义；supervisor 走 `bt_add_supervisor_process` 自带 Remove+Add，失败也回滚）。不满足"自管+唯一"的项保留列表 + 手工提示
- **升级末尾 PHP-FPM reload（步骤 15b）**：upgrade.sh 在权限检查前显式调 `bt_reload_php_fpm`，让 web 入口清 opcache 加载新代码。失败提示手工到面板 reload；非宝塔 PHP 路径跳过
- **fatal 兜底**：`UpgradeRunCommand::handle()` 注册 `register_shutdown_function`，捕获 `E_ERROR / E_PARSE` 等 fatal，若 status 仍 running 则写 failed，避免卡 running 死锁
- **classmap 自愈**：upgrade.sh composer 块后**无条件**跑 `dump-autoload --optimize --no-scripts`，修复跨小版本升级时 vendor 路径变更（如 `Pdo\Mysql` polyfill / `ReflectsClosures` 跨目录）导致的 classmap 漂移

### 数据库结构校验

升级后自动校验数据库结构与标准 `structure.json` 是否一致。

**配置项** (`config/upgrade.php`):

| 配置                   | 说明                                   |
| ---------------------- | -------------------------------------- |
| `auto_structure_check` | 是否自动校验（默认 true）              |
| `auto_structure_fix`   | 是否自动修复 ADD 类型差异（默认 true） |

**校验流程**:

1. 迁移完成后调用 `DatabaseStructureService::check()`
2. 无差异 → 记录日志
3. 有差异且可自动修复 → 执行 `fix()`（仅 ADD 操作）
4. 有差异但无法自动修复 → 记录警告（不阻断升级）

**手动命令**:

```bash
php artisan db:structure --check        # 检测差异
php artisan db:structure --fix          # 自动修复（仅 ADD）
php artisan db:structure --export       # 导出标准结构
```

**注意**: 每次迁移变更后需重新导出 `structure.json`。

---

## BinaryLocator 外部命令调用

### 总则

- 任何 `exec(...)` 涉及外部二进制（php/composer/openssl/java/keytool/mysqldump/mysql/curl）的调用，**必须**先通过 `app(\App\Services\Binary\BinaryLocator::class)` 解析路径
- 拼命令时用 `escapeshellarg($path).' arg1 arg2'`，**不允许变量插值**（防注入）
- composer 调用方直接用 `$composerCmd = $locator->composer()` 返回的完整 `{php} {phar}` 串，不要再用 `which composer` 或裸 `'composer'` 命令（始终以本进程解析出的 PHP 为前缀，避开多版本 PHP 系统下 phar 自带 `#!/usr/bin/env php` shebang 找错版本）
- BinaryLocator 是 singleton，进程内 memoize（同一个 worker 每个工具只探测一次），重复调用零成本

### 异常处理约定

- **升级流程**（`UpgradeService` / `UpgradeController`）—— PHP / composer 找不到由 preflight 阻塞，业务路径不需 catch
- **证书下载**（`Services/Order/Traits/ActionFileTrait`）—— openssl/keytool 失败跳过对应格式（IIS PFX / Tomcat JKS），try-catch `BinaryNotFoundException` 后 `return` 跳过该段，不影响其它格式输出
- **备份/恢复**（`Services/Backup/BackupService` 链路 / `Jobs/RestoreBackupJob` / `Http/Controllers/Admin/DatabaseBackupController`）—— mysqldump/mysql 失败由 `ApiResponse` 错误返回，传 `diagnose` 到 errors 字段
- **插件/Release 下载**（`Services/Plugin/PluginManager` / `Services/Upgrade/ReleaseClient`）—— curl 找不到回落到 `file_get_contents` 等（保持原 `ResolvesExecutablePath` null 语义）

### 探测策略（不要碰）

- 全部走 `proc_open([$path, $flag])` 数组形式校验，**不能用 `is_executable` / `file_exists`**（FPM 下走 `open_basedir` 检查，宝塔白名单外的路径会被误判为不可用）
- `proc_open` 数组形式调用（execve），不走 shell，天然防注入 + 避开 `open_basedir`
- **探测顺序统一两条腿**：候选路径常量（绝对路径列表） → shell PATH 兜底，FPM/CLI 走完全一致路径。**不再用 Symfony `ExecutableFinder`** —— open_basedir 非空时它强制只在 open_basedir 内目录找命令，FPM 下永远 miss、CLI 下被候选路径覆盖，留着只让"开发机能跑、生产挂"的差异被偷偷接住
- **shell 兜底**（`probeViaShell`）：候选路径全 miss 时跑 `sh -c 'command -v $tool'` 拿绝对路径 + probeWith 二次校验工具行为。**显式传 env `SHELL_FALLBACK_PATH`** 给 sh —— 宝塔 PHP-FPM 默认 `clear_env=yes` 不传 PATH，不显式注入子 sh 拿不到 PATH 必然失败。**返回绝对路径**而非裸名 —— 调用方按绝对路径 exec，不依赖调用方进程 env PATH
- `SHELL_FALLBACK_PATH` 顺序：`/opt/homebrew/{sbin,bin}` 排在 `/usr/bin` 前面 —— macOS `/usr/bin/openssl` 是 LibreSSL（`openssl version` 输出 "LibreSSL ..." 不含 "OpenSSL"，探测假阳性失败），Homebrew 提前避开；生产 Linux 无 `/opt/homebrew/` 自动跳过
- 版本探测参数因工具而异：
  - `openssl version`（子命令，不是 `--version` 全局选项）—— OpenSSL 3.0.x 不识别 `--version`（3.2+ 才加），但 `version` 子命令 1.x/2.x/3.x 全系列支持；Ubuntu 24.04 默认 3.0.13 是踩过的坑
  - `java -version` 输出到 stderr、`keytool` 中文 locale 不含 'keytool' 字符串 —— 这两个仅校验 exit 0，不校验 stdout 内容
- composer 探测同样是 `候选路径 → shell PATH 兜底`（与其他工具对齐，不再特殊处理"候选 vs PATH 反向"）
- 进程内 memoize（`$resolved[$tool]` 字典）

### 宝塔 PHP-FPM / PHP-CLI ini 分离

宝塔 `/www/server/php/{ver}/etc/` 下 FPM 读 `php.ini` + pool conf，CLI 读 `php-cli.ini`（独立文件）。两边 `disable_functions` / `open_basedir` 可能完全不同 —— 常见踩坑：FPM 已放开 `proc_open` / `exec`，但 CLI 仍禁用，导致 `composer install` 内部子进程调用失败。

- **`BinaryLocator::inspectFpmIni()`**：读当前进程的 `php_ini_loaded_file()` + `ini_get('disable_functions')`
- **`BinaryLocator::inspectCliIni()`**：起 CLI 子进程（`{php} -r 'echo php_ini_loaded_file()."|".ini_get("disable_functions");'`）读 CLI 真实 ini，**不能只查当前 FPM 进程的 `ini_get('disable_functions')`**（漏一半）
- 两个方法都返回 `{ini_path, disable_functions, disable_functions_ok}`，`ok` 要求 `proc_open` 和 `exec` 都未被禁

### preflight 集成

- `App\Services\Upgrade\UpgradePreflight::check()` 一次性跑 4 项检查（FPM ini → php → composer → CLI ini），即便中间项失败也跑完让运维一次看到所有问题
- 返回结构：`{blocking: [{code, reason, fix}], items: [{tool, status, path?, diagnose?}], ini: {fpm: {disable_functions_ok, ini_path}, cli: {disable_functions_ok, ini_path}}}`
- 4 项 blocking code：`fpm_proc_open_disabled` / `php_cli_missing` / `composer_missing` / `cli_proc_open_disabled`；fix 文案统一指向"使用 upgrade.sh 升级"或编辑指定 ini 文件
- BinaryLocator 内部意外错误（非 `BinaryNotFoundException`）被兜成 `health_check_failed` blocking，让 Controller 仍能返 503 + 友好错误，不冒泡成 500
- **`UpgradeController::execute` 入口**（`isRunning` 短路后）先跑 preflight，任一 blocking → 503 + 完整诊断到 errors 字段
- **`GET /api/admin/upgrade/binary-health` 端点**：纯展示 8 个工具状态（php/composer/openssl/java/keytool/mysqldump/mysql/curl）+ FPM/CLI ini，不阻塞，供前端升级页面参考

---

## 代码规范

### 命令

```bash
./vendor/bin/pint         # 代码格式化
./vendor/bin/phpstan analyse  # 静态分析
php artisan test          # 测试
```

### 开发流程

1. 遵循功能优先开发
2. PSR-12 编码规范
3. 统一异常处理
4. 分类日志记录

---

## 常用 Artisan 命令

```bash
php artisan upgrade:check     # 检查更新
php artisan upgrade:run       # 执行升级
php artisan upgrade:rollback  # 回滚
php artisan db:structure --check   # 数据库结构校验
php artisan db:structure --fix     # 自动修复结构
php artisan queue:work --queue tasks,notifications  # 队列 worker（消费 TaskJob / NotificationJob）
```

---

## 缓存与日志架构

### 缓存驱动

- 默认使用 `file` 驱动，不强制依赖 Redis
- 生产环境推荐使用 Redis 提升性能
- SnowFlake、RateLimiter 通过 `Cache` facade 操作

### 日志批量写入

- `LogBuffer` 服务：收集请求期间的日志，请求结束后批量写入
- `FlushLogs` 中间件：响应发送后触发日志刷入
- 所有日志模型使用默认数据库连接

### Cert 中间证书缓存与 retrieved 时序

- `Cert::retrieved` 钩子对 `active + issuer` 的证书，从 `Cert::chainMap()`（请求 / Job 级容器缓存的全部中间证书）取 `intermediate_cert`；缺失时把 `status` 改写为 `approving`（中间证书未就绪 → 表现为签发中，**影响 Deploy 部署判断 / V1·V2 cacheTime / 文档上传拦截，有意设计，勿当纯输出移除**）。
- **不能用 `with('chain')` 预加载**：Laravel `retrieved` 事件早于 `with()` eager load 触发，retrieved 内访问预加载关联会触发 lazy load（N+1 重现）。故用 `chainMap()` 一次性全表缓存（Chain 是 CA 中间证书、`common_name` 唯一、数量有限）替代逐条 `Chain::where()`，列表 N+1 → 每请求 / Job 仅 1 次全表查询。
- **缓存用 `app()->scoped` 而非 `instance`**：FPM 每请求新容器天然刷新；`queue:work` 常驻 worker 在每个 job 边界由框架 `resetScope → forgetScopedInstances` 自动清，避免跨 job 读到陈旧中间证书（`instance` 不随 job 清）。`setIntermediateCert` 写新 Chain 后 `forgetInstance('cert.chainMap')` 让同请求 / 同 job 内即时失效。

---

## Token 认证体系

| Token 类型  | 中间件              | 路由前缀               | 用途             |
| ----------- | ------------------- | ---------------------- | ---------------- |
| ApiToken    | `api.v1` / `api.v2` | `/api/v1/`, `/api/v2/` | 第三方 API 调用  |
| DeployToken | `api.deploy`        | `/api/deploy/`         | 部署工具证书管理 |

### 共同特性

- Token 使用 SHA-256 hash 存储
- 支持 IP 白名单（最多 100 个，`allowed_ips` 字段 2000 字符）
- 支持速率限制（`rate_limit` 字段，每分钟请求数）
- 请求结束后异步更新 `last_used_at` 和 `last_ip`

### DeployToken 特性

- 每个用户仅一个 DeployToken（唯一约束）
- 通过 `UserScope` 限制只能访问用户自己的 Order
- 支持查询证书、续费/重签、部署回调

### certimate URL 拉取模式

- `GET /api/deploy/?order={id|domain}&field=certificate|private_key`：返回纯 PEM 文本（`Content-Type: text/plain`），适配 certimate `BizUpload` 节点 URL 源
- `field=certificate` 返回 `cert + intermediate_cert`（fullchain）；`field=private_key` 返回私钥
- `order` 支持单个数字 ID（跟随 renewed 链）或单个域名（按 `common_name` 精确匹配，`issued_at` 降序取最新 active 证书），续费后 certimate URL 无需变更
- 不带 `field` 时走原 JSON 分页逻辑（向后兼容）

### 凭据不进 URL：用短时签名 URL

- **JWT access_token 是全权限长效凭据，绝不可拼进 URL query** —— 进了就落入浏览器历史 / 服务端 access log / Referer，被截获即等于泄漏该用户全部 API 权限。
- iframe / img / `<a download>` 这类无法带 `Authorization` header 的场景（如文档预览/下载），**一律用分钟级短时签名 URL**（`URL::temporarySignedRoute` + `signed` 中间件验签），不复用 access_token。
- 文档预览实现（参考）：`GET order/document-preview-url/{id}`（走 JWT header 鉴权 + 归属校验）返回 `temporarySignedRoute('{role}.order.document-preview', now()->addMinutes(10), ['id' => $id])`；`order/document-preview/{id}` 路由用 `withoutMiddleware([JWT 中间件类])->middleware('signed')` 脱离 JWT、仅验签名。归属安全链：取 URL 接口经 UserScope 限本人（admin 全局）→ 签名防 docId 篡改 → signed 预览路由本身无需再查归属。
- 前端两步：先调「取签名 URL」接口（header 带 JWT），再把返回的签名 URL 作 iframe/img/下载 src。`withoutMiddleware` 移除组中间件需传**展开后的中间件类名**（不是组别名）；`route:list` 仍显示组名属正常（运行时 pipeline 才排除），以 HTTP 测试「无 JWT + 有效签名 → 200」验证真正生效。
- **坑（access_token 不进 URL 的前提）**：User 控制器构造函数若有 `$this->guard->id() || $this->error()` 登录校验，必须对签名预览路由（`request()->routeIs('user.order.document-preview')`）放行，否则无 JWT 的签名请求在构造函数就被挡死、返回「用户不存在」（HTTP 200 JSON）—— 签名预览形同虚设。HTTP 测试须断言**真文件流**（`content-type=application/pdf` / `attachment` disposition），只 `assertOk()` 会因 200 JSON 假绿。Admin 控制器构造函数无此登录校验，故不受影响。

---

## 资金确定性体系（4 道网）

> **目的**：把资金安全从"LLM 审 + 单测 + 锁/事务"的**抽样**强度，升级为"DB 约束 + 应用层 CAS + 自动不变式校验"的**确定性**强度。当 LLM/审核找不到新问题、单测覆盖不到新路径时，多道独立网仍能拦住资金错账或在小时级被发现。

### 设计原则

区分**物理阻断**和**事后发现**两类能力，它们不互相替代：

- **物理阻断**：INSERT/UPDATE 之前挡住错账发生 — DB 唯一索引、CAS UPDATE、事务 + 锁
- **事后发现**：不阻止发生但保证发现 — Pest afterEach hook、每日 cron 对账

**关键**：已删除的 fund 即便 invariant 报 orphan transaction，钱已入账、订单已消失，损失已发生。事后发现仅作"代码 bug + 物理层未覆盖路径"的兜底。

### 物理阻断层

#### 1. DB 唯一索引

文件：`database/migrations/2026_05_07_*_add_fund_transaction_unique_indexes.php`

| 索引                                                                | 含义                                                                                                                                                                                          |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `funds(pay_method, pay_sn)` 唯一                                    | 防"不同 fund 同一支付编号"。MySQL BTREE 索引中 NULL 互不相等，处理中订单 (pay_sn=NULL) 多行合法；落地后的 (pay_method, pay_sn) 才进入唯一性判定                                               |
| `transactions(type, transaction_id) WHERE type != 'order'` 部分唯一 | 防"同一事件被重复入账"。MySQL generated VIRTUAL 列 + 完全唯一索引模拟（`CASE WHEN type='order' THEN NULL ELSE CONCAT(type,':',transaction_id) END`，NULL 不参与唯一约束，效果等价于部分索引） |

旧 3 列索引 `funds_type_pay_method_pay_sn_unique` 已被本 migration 删除（语义弱于新 2 列、且 refunds/reverse 走 UPDATE 同行不冲突）。

唯一冲突由 `app/Bootstrap/ApiExceptions.php::causedByDuplicateKey` 翻译为业务消息（"支付编号重复请勿重复支付" / "交易记录已存在"）+ 状态码 409。识别方式：`PDOException` + SQLSTATE 23000 + MySQL errcode 1062 + 约束名识别表。

`Fund::creating` / `Transaction::creating` 钩子内的 `exists` 校验**保留**作为前端速失败提示，不是防重主屏障 — DB 唯一索引才是兜底。

#### 2. Fund.status CAS UPDATE

签名：

```php
Fund::transitionToSuccessful(
    int|string $id,
    string $expectedAmount,      // 上游回调金额
    string $expectedType,        // 'addfunds'（保留扩展空间）
    string $expectedPayMethod,   // 'alipay' / 'wechat'
    string $paySn                // 写入的支付编号
): ?self
```

**关键约束**：CAS WHERE 必须保留 5 字段完整匹配（id + amount + type + pay_method + status=0）— 否则金额/支付方式不匹配的回调也会把本地 fund 标成功并按本地 amount 入账，造成攻击面。

实现要点：

- 查询构建器 `update()` 不触发 Eloquent `updating` 钩子 — 这是 CAS 的优势（避免 SELECT-then-UPDATE 模式），但调用方必须**显式**调 createRecord 等价逻辑写 transaction
- 调用方契约：必须在 `DB::transaction(fn)` 内调用，让 CAS UPDATE + Transaction::create + balance 修改原子提交
- `Fund::updating` 钩子里现存的 `getOriginal('status')` 校验仍要保留 — 给走 Eloquent save 路径的代码（如 Admin update fund 备注、refunds/reverse）兜底

新加资金状态转换路径**禁止**用 `lockForUpdate + 重读 + save` 模式 — 用 CAS。

三处现有调用：`User\TopUpController` / `Admin\FundController` / `User\FundController` 的 `addfundsSuccessful`。

#### 3. 事务 + 锁 + 锁内二次校验

destroy/batchDestroy/Order::delete 等"删除已入账 fund"路径无法被 CAS 或唯一索引拦截（删除 SQL 本身合法）。必须事务内 `lockForUpdate` + 锁内 status/created_at 重读校验。已落地：

- `app/Http/Controllers/Admin/FundController.php::destroy / batchDestroy`
- `app/Services/Order/Traits/ActionTrait.php::delete`

#### 4. Transaction 防重豁免

`app/Models/Transaction.php` `creating` 钩子的 `exists` 校验排除列表收紧到 `['order']` — 仅 SSL 证书重签增域名场景允许重复 `transaction_id`。`acme_order` 是一对一交付 EAB，必须防重。

DB 部分唯一索引 `WHERE type != 'order'` 与此一致，覆盖应用层漏失。

### 事后发现层

#### 5. CI Pest afterEach hook

文件：`tests/Pest.php`

注册 hook，限定到资金相关测试目录（`Feature/FundAudit`、`Feature/Http/Controllers/{User,Admin}/{Fund,TopUp}*`、`Unit/Services/{Order,Acme}/ActionTest.php`、`Feature/Models/{Transaction,Fund,User}Test.php`）。

每个测试 afterEach 自动调 `app(\App\Services\FundAudit\FundInvariants::class)->all()`，违反 → `test()->fail($msg)`。`RefreshDatabase` 包外层事务，hook 在 rollback 之前跑 — 能看到测试期间的所有变更。

新增动了资金的测试**不需要**手写资金断言 — hook 自动守门。

#### 6. 每天 03:00 finance:audit cron

文件：`app/Console/Commands/FundAuditCommand.php` + `routes/console.php` 注册 `Schedule::command('finance:audit')->dailyAt('03:00')`。

命令调 `FundInvariants::all()` 全量对账，违反 → 走 `NotificationCenter`（`code=finance_audit`）发邮件给 `site.adminEmail` + `Log::error` 兜底。可选 `--freeze-on-violation` 自动把涉事 user.status=0 禁用。

命令本身始终返回 0（不被 retry）；仅 invariant 自身崩溃返回 1。错开 AutoRenew 00:00 时段。

### 4 条 invariant SQL

`App\Services\FundAudit\FundInvariants` 4 个公开方法：

| Layer | 方法                   | 含义                                                                                                                                     |
| ----- | ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| L1    | `accountingIdentity()` | 每个 user：`SUM(transactions.amount per user) == users.balance`                                                                          |
| L2    | `eventUniqueness()`    | `(type, transaction_id) WHERE type != 'order'` 不允许重复（DB 唯一索引的事后监控）                                                       |
| L3    | `statePairing()`       | 每条 `funds.status ∈ (1,2)` 必须有对应 transaction（`user_id + type + transaction_id=fund.id`），双向：fund 缺 tx 与 tx 缺 fund 都报警   |
| L4    | `amountPairing()`      | 每条 `funds.status ∈ (1,2)` 与对应 transaction.amount **按业务符号严格匹配**（addfunds/reverse 同号、deduct/refunds 反号），不仅比绝对值 |

返回 `array<InvariantViolation>`，空数组表示通过。

**L1 不变式前提**：所有 `user.balance` 变更必须经 `Transaction::create` 路径（`Transaction::creating` 钩子内 `bcadd` 修改 balance + 写流水原子）。**禁止**直接 SQL/Eloquent 改 balance；生产历史余额迁移走 admin "添加资金记录" 手工充值（`POST /api/admin/fund` type=addfunds），自然产生 transaction 流水。测试 helper（`UserFactory::withBalance` / `CreatesTestData::createTestUser`）也通过 `Transaction::create` 走钩子路径，**不直写 balance**——直写会被 Pest invariant hook 立即拦下。

**性能**：起步规模（500 user / 1 万 transactions）单次 < 200ms。索引前提：`transactions(user_id)`（外键已有）、`transactions(type, transaction_id)`（Task 2 唯一索引顺带覆盖）。

### 已知"order 类型允许重复 transaction_id"特例

仅 SSL 证书重签增域名场景：一笔 order 在重签时新增了 N 个域名 → 再次扣费 → 共享同一 `transaction_id`。`Transaction.php` 防重排除 `'order'` 类型，DB 部分唯一索引 `WHERE type != 'order'` 也排除。

### 新增资金路径的 checklist

1. 状态转换用 CAS UPDATE 而非 SELECT-then-UPDATE，CAS WHERE 必须完整字段匹配（不能简化为单一 status 条件）
2. 写 transaction 不依赖应用层 `exists` 防重 — DB 唯一索引兜底
3. 修改 user.balance 必须在 `DB::transaction(fn)` 内 + 同事务内创建对应 transaction
4. 测试只需直接调用业务路径，invariant hook 自动守门 — 不需要手写"transaction 已写"等资金断言
5. 删除已入账 fund 路径必须事务内 `lockForUpdate` + 锁内 status/created_at 二次校验
6. 上线前先跑 `php artisan finance:audit` 确认现有数据干净，否则改约束之后下一次相关 INSERT 触发"交易记录已存在"误报

## 安全补强

### 邮件验证码防爆破（VerifyCodeRateLimiter）

- 中间件 `App\Http\Middleware\VerifyCodeRateLimiter` 挂在发码/校验路由（注册、重置密码）；`VerifyCodeHelper` 按失败次数计数 + 冷却，验证码用 `random_int` 生成
- 重置密码接口去掉 `exists:users,email` 校验（避免邮箱枚举），邮箱不存在也返回统一成功文案

### 归档解压统一防护（ArchiveGuard）

- `App\Services\Upgrade\ArchiveGuard`：`assertSafeEntries()`（解压前校验 zip 条目无 `..`/绝对路径/symlink 逃逸）+ `assertExtractedWithin()`（解压后校验落地路径在目标目录内）
- 所有解压路径统一走它：`PackageExtractor`（升级）/ `BackupManager`（备份）/ `PluginManager`（插件安装）。**新增解压点必须接入**，不要各写各的 zip-slip 校验

### sync 终态守卫（防复活）

- `Order\Action::sync`/`Acme\Action::sync` 锁内用上游状态回写本地前，若本地已是终态（`cancelled`/`revoked`/`renewed`/`reissued`/`failed`）则 `unset($data['status'])`，防上游旧状态把已取消/已吊销订单复活回 active（与 commitCancel 串行化配合）

### tasks 死锁防护与并发错误处理

**线上现象（2026-06）**：同一订单被 V2 `get`（内联 sync）+ `POST /api/order/sync` + queue worker 多入口高频并发，都抢 `tasks` 表 `WHERE order_id=X AND action IN (commit,sync,revalidate) AND status IN (executing,stopped) FOR UPDATE`，触发 InnoDB 死锁（1213）；TaskJob 的 `catch (Throwable)` 又把死锁异常当普通业务异常吞掉后继续 `$task->update()`，外层 `DB::transaction` 提交时抛 `PDOException: There is no active transaction`，job 失败被 queue 无脑重试 → 雪崩刷屏。

**三层修复（缺一不可，对应 `TaskJob` / `Order\Action` / `create_tasks_table` 迁移）**：

1. **复合索引 `tasks(order_id, action, status)`（降频）**：让 `FOR UPDATE` 精确定位，二级索引间隙锁范围从"整个 order_id 区间"收窄到精确区间，消除大部分 sync×commit 跨 action 抢锁的死锁。命中 `sync`/`checkRepeat`/`createTask`/`batchCommitCancel`/`refundForSyncedCancel` 所有 task 查询。
2. **TaskJob 死锁不可吞（治本）**：`TaskJob::handle` 的 `catch (Throwable)` 对并发错误（`Illuminate\Database\DeadlockException` 或 `DetectsConcurrencyErrors::causedByConcurrencyError`：1213/1205/序列化失败）**重新抛出**，绝不在已被 MySQL 回滚的事务里继续 `$task->update()` 或让闭包正常返回触发 commit。抛出 → 外层事务回滚 → queue `--tries --delay` 错峰重试自愈；重试耗尽由 `failed()` 钩子兜底标记 `task=failed`（守卫 `status==='executing'`，否则普通异常路径重复 update）——不标记会永久卡 executing 被 `checkRepeat` 当"处理中"阻塞该订单后续 commit/sync。
3. **sync 事务死锁自动重试（web 入口自愈）**：`Order\Action::sync` / `refundForSyncedCancel` / `Acme\Action::sync` 的 `DB::transaction(..., 3)` 加 `attempts=3`。controller 直调时本事务为最外层、上游 `get` 在事务外，重试只重跑锁+写回，安全。**`commit` 绝不加重试**——其上游下单 `$this->api->$action()` 在事务内（`Action.php:401`），重试 = 重复下单/重复扣费；且 commit 只锁 order 行、不执行 `tasks FOR UPDATE`，本就不是死锁受害者。

**关键认识**：死锁是 InnoDB 行锁并发写的正常现象，**无法根除，只能降频 + 重试自愈**（MySQL 官方亦要求应用层重试）；把"偶发死锁"放大成"持续雪崩"的是错误的死锁后处理（吞异常 + 死事务上继续写 + 无脑重试），那才是真正的炸点。嵌套事务（TaskJob 包 sync/commit）里 Laravel 对并发错误直接抛 `DeadlockException` 到最外层、不在内层重试（`ManagesTransactions::handleTransactionException` 的 `transactions > 1` 分支）——故 `attempts` 只在 controller 直调（最外层）时生效，TaskJob 路径统一由 job 级重试兜底。**减少多入口并发（如 V2 get 去内联 sync）不是根治方向**：并发不可消除、且会动对外 API 契约。

### 节流统一 Cache::add 原子占位

**坑**：业务防重/节流若用 `Cache::get` 判断 + `Cache::set` 写入（check-then-act），两步之间有窗口，并发请求都读到空 → 都放行 → 击穿（重复调上游 sync/pay/commit、超发验证码）。

**规则**：所有"N 秒内防重复"节流统一用 `Cache::add($key, $val, $ttl)`（SETNX 语义，redis/database/array driver 均原子）—— 返回 true = 抢占成功放行，false = 已有占位拒绝。已落地的范式（新增节流点照此写，勿再用 get+set）：

- `ActionTrait::checkDuplicate`（Order new/batchNew/renew/reissue/sync/revalidate/updateDCV 7 入口防重）：返回 `0`=放行 / `>0`=剩余秒数拒绝；`Cache::add` 抛异常时 catch 降级**放行**（return 0），不阻塞业务
- `Acme\Action::sync` 内联 `acme_sync_` 节流：占位放在 `find`+api_id 校验**之后**、上游调用**之前**（acme 不存在始终走 error，不因占位变 success）；占位后删除原末尾 `Cache::set`
- V1/V2 `ApiController::get` 的 `api_get_`：`Cache::add` 决定是否进 sync/pay/commit（并发只放一个）；**末尾保留 `Cache::set`** 按最终 status 刷新滑动窗口（active=120s/其他=10s，避免已签发证书每 10s 重复 sync 打上游）
- `VerifyCodeHelper::checkSendCooldown` 发送冷却：占位前移到发送前 → **所有发送失败路径必须 `releaseSendCooldown` 释放占位**（sendSmsCode else / sendEmailCode 未配置 return / catch），否则失败后正常用户白卡 60s；今日超限也要释放冷却
- 计数型配额（每日上限）仿 `AliyunDriver::enforceAndIncrementDailyQuota`：`Cache::add(0)+increment` 后判 `>limit`，超限 `decrement` 回滚

**例外（不必改）**：登录限流 `LoginRateLimiter` / `VerifyCodeRateLimiter` 走 Laravel `RateLimiter` facade，`tooManyAttempts`+`hit` 有固有 TOCTOU（注释已承认，登录/发码场景可接受）；自定义 `RateLimiter` 中间件滑动窗口已是 `Cache::add+increment` 原子；互斥锁用 `Cache::lock`（`ValidateCommand`/`SnowFlake`/Backup Job）。

### 批量操作上限（`config/batch.php`）

- `max_ids=100`：所有 `GetIdsRequest` 的 ids 数量上限（`BaseRequest::messages` 统一错误文案）
- `max_upstream=20`：batchPay/batchCommitCancel 等"逐条调上游"循环的硬上限，防单请求打爆上游

## MySQL 兼容性

- 兼容 MySQL 5.7，不使用 `json` 字段类型
- 数组类型字段使用 `string` 存储，由 Laravel 模型 `'array'` cast 自动 JSON 序列化

## 列类型与累加防溢出

**坑**：累加型计数列选小整数（TINYINT UNSIGNED max 255）且无硬截断，溢出抛 SQLSTATE[22003] 1264；若 catch 把异常 message 原样回写另一短字符串列，因 MySQL 异常 message 包含完整 SQL 回显（含上一次列值），形成"error 嵌套自我放大"链，列被反复写直到 VARCHAR 撑爆截断成乱码。真实案例：`cname_delegations.fail_count` 累加到 256 触发该链，把 `schedule:validate` 子进程拖到 fatal exit 255。

**规则**：

1. **累加点硬截断**：用 `min($v + 1, $cap)` 而非裸 `$v++`；$cap 取"列上限"和"业务上限"较小值（如 fail_count 取 100 — 超过没意义且远小于 TINYINT 上限）
2. **catch 写回 error/message 字段必须限长**：`mb_substr($e->getMessage(), 0, N)`，N 取列长度 ~80%（VARCHAR(255) → 200 留余量），断掉"异常 message 含 SQL 回显 → SQL 含旧列值 → 嵌套放大"链
3. **选型预留余量**：纯状态枚举（0/1/2）用 TINYINT 无妨；**累加计数即使业务上限只到 100，仍优先 SMALLINT UNSIGNED**，给抗冲击空间，除非压缩 1 字节是硬要求

## 迁移规范

- **enum vs string**：系统核心字段用 `enum` 保证约束（如 `product_type`、`status`）；插件可扩展的字段用 `string`，方便插件写入自定义值
- **优先 Laravel 系统方法**：迁移优先使用 `Schema::table` + Blueprint 方法，避免 `DB::statement` 裸 SQL
- **up 幂等**：修改表结构的迁移必须先检查当前状态（`Schema::hasColumn`/`Schema::hasTable`），避免重复执行报错
- **不写 down**：迁移只写 `up()`，不写 `down()`。生产环境不做回滚，回滚用新迁移前进修复
- **所有结构变更同步回 create 迁移**：任何 `add_/update_/drop_/rename_xxx_table` 增量迁移做的结构改动（加字段、删字段、改类型/长度/默认值/注释、加/删/重命名索引、加/删外键、改 enum 值、改字段顺序等）都必须同步回对应的 `create_yyy_table` 原迁移，让新装直接拿到最终 schema、老库继续走幂等增量。两侧字段顺序、类型、长度、默认值、nullable、索引、注释完全一致，避免新老库 schema 漂移导致 `db:structure --check` 误报
- **structure.json 不手动改**：迁移变动后发布前通过 `php artisan db:structure --export` 重新导出
- **导出前用干净测试库**：避免开发库脏数据或插件表干扰

### 迁移幂等示例

```php
// 添加字段
if (! Schema::hasColumn('orders', 'new_field')) {
    Schema::table('orders', function (Blueprint $table) {
        $table->string('new_field')->nullable()->after('existing_field');
    });
}

// 删除字段
$columns = ['col_a', 'col_b'];
$toDrop = array_filter($columns, fn ($col) => Schema::hasColumn('orders', $col));
if (! empty($toDrop)) {
    Schema::table('orders', function (Blueprint $table) use ($toDrop) {
        $table->dropColumn($toDrop);
    });
}

// 创建表
if (Schema::hasTable('new_table')) {
    return;
}
Schema::create('new_table', function (Blueprint $table) { ... });

// 修改 enum 值（扩展/缩减）
Schema::table('products', function (Blueprint $table) {
    $table->enum('product_type', ['ssl', 'codesign', 'smime', 'docsign', 'acme'])->default('ssl')->change();
});
```

---

## 自动续费/重签

### 数据结构

| 字段            | 位置      | 说明                |
| --------------- | --------- | ------------------- |
| `auto_renew`    | orders 表 | 订单级自动续费开关  |
| `auto_reissue`  | orders 表 | 订单级自动重签开关  |
| `auto_settings` | users 表  | 用户级默认设置 JSON |

### 回落逻辑

订单设置为 `null` 时回落到用户设置：

```php
->where(function ($query) {
    $query->where('auto_renew', true)
        ->orWhere(function ($q) {
            $q->whereNull('auto_renew')
              ->whereHas('user', fn ($u) => $u->where('auto_settings->auto_renew', true));
        });
})
```

### 续费 vs 重签判断

- `period_till - expires_at < 7天`：续费（订单周期与证书到期接近）
- `period_till - expires_at > 7天`：重签（订单周期内还有余量）

### AutoRenewCommand 执行流程

**调度配置**：每小时执行（`routes/console.php`）

**执行步骤**：

1. `getRenewOrders()` 查询续费订单：
   - `auto_renew = true`（订单级或用户级回落）
   - 证书即将到期（15天内）且未过期超过15天
   - 订单周期与证书到期时间差 < 7 天
   - 产品支持续费（`renew = 1`）
   - **排除 acme 通道**（由 ACME 客户端自行续签）

2. `getReissueOrders()` 查询重签订单：
   - `auto_reissue = true`（订单级或用户级回落）
   - 证书即将到期（15天内）且未过期超过15天
   - 订单周期与证书到期时间差 > 7 天
   - **排除 acme 通道**

3. `processOrder()` 处理单个订单：
   - **委托有效性检查**：`checkDelegationValidity()` 即时验证所有域名是否有有效委托
   - 无有效委托 → 跳过订单，不发起续费/重签
   - 续费时检查用户余额（`balance + |credit_limit|`）
   - 强制使用 `delegation` 验证方法
   - 调用 `Action::renew()` 或 `Action::reissue()`

4. `autoPayAndCommit()` 自动支付提交：
   - 调用 `Action::pay($orderId, true)` 完成支付并提交到 CA

### 相关命令

`php artisan schedule:auto-renew` - 同时处理续费和重签

---

## PurgeCommand 自动取消（临近退款期处理中订单）

`schedule:purge` 每天 02:00 执行，扫描 `created_at` 在 `refund_period - 2 ~ refund_period` 天之间的处理中订单，调 `Order\Action::cancel` 取消并退款。

### 限定条件

- `cert.status = 'processing'`
- `cert.action IN ('new', 'renew')`（**重签订单 reissue 不取消**，避免连带把原订单 latestCert 改 cancelled）
- `products.refund_period >= 5`（退款期 < 5 天的产品跳过）

### 实现位置

`backend/app/Console/Commands/PurgeCommand.php` 两段 query 的 `whereHas('latestCert', ...)` 闭包同时加 `whereIn('action', ['new','renew'])`：

- L121-133：预同步 query（refund_period - 4 ~ refund_period - 2 天的订单创建 sync 预热任务）
- L147-154：取消 query（refund_period - 2 ~ refund_period 天的订单走 sync + cancel）

---

## 同步取消退款开关（site.autoRefundOnSync）

多级代理场景下，上级 Manager 可能先取消订单（如其自身的 PurgeCommand 触发）；下级 Manager 的 `Order\Action::sync` 同步上游状态时，默认仅更新本地 `cert.status='cancelled'`，**不退款**。是否退款给末端用户由各级 Manager 管理员自决。

### 开关

- 分组：`site`，key：`autoRefundOnSync`，type：`boolean`，默认 `false`
- 后端读取：`get_system_setting('site', 'autoRefundOnSync')`
- 前端：admin 站点设置页面自动按 SettingGroup 渲染 boolean toggle

### 触发条件（四个必须全部成立）

1. 上游返回 `data.status === 'cancelled'`
2. `cert.status ∈ {processing, approving, cancelling}`（过渡态；排除 active 已签发 / 终态）
3. `cert.action ∈ {new, renew}`（排除 reissue 重签）
4. 开关 `site.autoRefundOnSync === true`

### 资金路径

私有 helper `Order\Action::refundForSyncedCancel(Order, array $certData)`：

1. `DB::transaction` 闭包
2. `Order::with('latestCert')->whereHas('latestCert')->lock()->find($order->id)` 加 order 行锁
3. 锁内二次校验触发条件 2/3/4（data.status 已外层校验）
4. 防重检查 `Transaction::where(['type'=>'cancel','transaction_id'=>$order->id])->exists()`
5. 若不重复且 amount > 0：调 `OrderUtil::getCancelTransaction($order->toArray())` + `Transaction::create($tx)`（`Transaction::creating` 钩子内自带 user.lockForUpdate + balance 增加）
6. `$cert->update($certData + [status='cancelled', cancelled_at=now()])`
7. `$order->cancelled_at = now(); $order->save();`
8. callback task / deleteTask（复用 sync L532-538 副作用，createTask 内部已 `->afterCommit()`）

### sync 集成

`Order\Action::sync` 在 `$hasStatusChanged` 计算之后、邮件通知/callback 之前插入四条件 if：命中后调 `refundForSyncedCancel($order, $data)` 并 `$this->success()` 提前结束 sync。Helper 内已接管 cert.update / order.save / callback / deleteTask 所有副作用。

### 设计决策

- **不检查 refund_period**：以上游状态为权威，与主动 `cancel()` 路径行为不同。上游已取消意味着资金已从上游退回，本系统应该传递给末端用户，不受退款期限制。
- **cancelling 状态进入 helper**：若已有 `commitCancel` / `PurgeCommand` 创建的 cancel task 存在，helper 完成后 cert.status='cancelled'，残留 cancel task 被 TaskJob 调度时 `Action::cancel` 锁内首先检查 status === 'cancelled' 立即报错回滚，不会重复调上游 api、不会重复退款。helper 内**不主动删 cancel task**，避免 order→task 与项目惯例 task→order 锁顺序倒置引发死锁。
- **资金确定性体系契合**：事务+锁、应用层防重 + DB 唯一索引 `transactions_type_transaction_id_unique` 兜底、afterEach FundInvariants 守门（测试登记在 `tests/Support/FundAuditGuard.php::fundAuditGuardedTestPaths()`）。

### 测试覆盖

- `tests/Feature/Services/Order/SyncedCancelRefundTest.php`：12 个用例覆盖开关开/关 / status / action / 0 元订单 / 并发幂等 / 已退款防重 / revoked 不触发
- `tests/Feature/Commands/PurgeCommandTest.php`：补 2 个用例验证 reissue 不取消 / new 仍取消

### 部署注意

- 升级后需跑一次 `php artisan db:seed --class=Database\\Seeders\\SettingSeeder` 让设置项落库
- 默认 `false` 即维持现状行为，无回滚风险，可作为开关式金丝雀

---

## 委托验证

### 验证方法转换

用户选择 `delegation` 验证方法时：

1. `ActionTrait::generateDcv()` 将 method 转换为 `txt`
2. 设置 `dcv['is_delegate'] = true` 和 `dcv['ca']` 标记
3. `generateValidation()` 查找用户的 CnameDelegation 记录
4. validation 数组包含 `delegation_id`、`delegation_target`、`delegation_valid`、`delegation_zone`

### 委托前缀

| 前缀              | CA                  | 匹配规则           |
| ----------------- | ------------------- | ------------------ |
| `_dnsauth`        | DigiCert、TrustAsia | 严格子域匹配       |
| `_pki-validation` | Sectigo             | 优先子域，回落根域 |
| `_certum`         | Certum              | 优先子域，回落根域 |

> ACME 通道证书由客户端自行验证，不走委托体系，不使用 `_acme-challenge` 前缀。

### TXT 记录自动写入

**触发时机**：订单创建时，`ActionTrait::generateCsr()` 调用 `writeDelegationTxtRecords()`

**处理流程**：

1. 检查 `dcv['is_delegate'] = true`
2. 按 `delegation_id` 分组收集验证 tokens
3. 跳过无效委托（`delegation_valid = false`）或已写入的记录
4. 调用 `DelegationDnsService::setTxtByLabel()` 批量写入 TXT 记录
5. 更新 validation 中的 `auto_txt_written` 和 `auto_txt_written_at` 标记

**validation 字段说明**：

| 字段                  | 说明            |
| --------------------- | --------------- |
| `delegation_id`       | 委托记录 ID     |
| `delegation_target`   | CNAME 目标 FQDN |
| `delegation_valid`    | 委托是否有效    |
| `delegation_zone`     | 委托的根域名    |
| `auto_txt_written`    | TXT 是否已写入  |
| `auto_txt_written_at` | 写入时间        |

### 即时检测

`ValidateCommand::checkDelegationValidity()` 在验证前即时检测委托记录状态。

### Sectigo 本地 DCV 计算开关

`ActionTrait::generateDcv()` 中针对 Sectigo + cname/http/https 的本地哈希计算（CSR → DER → MD5/SHA256，拼出 `_<md5>` host 和 `<sha1>.<sha2>.<uv>.sectigo.com` value）由系统设置 `site.sectigoDcv` 控制，**默认关闭**（不入 seeder，缺失视为 false）。

- 关闭时：走 `$dcv = ['method' => $method]` 降级，dns/file 字段由上游 `/api/v2/new` 响应回填，再经 `mergeDcv()` 合并写入 cert。这是与 DigiCert/Certum 等其他 CA 一致的行为
- 开启时：本地直接算出 dns.value / file.content，订单创建即可向用户展示验证值（不必等上游回包）
- 手工开启方式：在 `settings` 表 site 组新增一行 `key=sectigoDcv, type=boolean, value=true`（无管理界面入口，按需 SQL 配置）

### DCV 数据合并

从上游 API 更新 dcv 时必须保留委托标记，使用 `ActionTrait::mergeDcv()` 方法：

```php
// 保留 is_delegate 和 ca 标记
$cert->dcv = $this->mergeDcv($result['data']['dcv'] ?? null, $cert->dcv);
```

涉及位置（`Action.php`）：

- 提交订单后更新 dcv
- 同步订单时更新 dcv
- 修改验证方法时（processing 状态）

### 前端判断逻辑

`validation.vue` 的 `getDisplayMethod()` 根据 `dcv.is_delegate` 返回验证方法：

```javascript
const getDisplayMethod = dcv => {
  if (dcv?.is_delegate) return "delegation";
  return dcv?.method;
};
```

### 委托验证自动续签数据流

```
用户创建订单（validation_method=delegation）
    ↓
ActionTrait::generateDcv()
    → method 转换为 txt
    → 设置 is_delegate=true, ca=xxx
    ↓
ActionTrait::generateValidation()
    → CnameDelegationService::findValidDelegation() 查找委托
    → 找不到则 createOrGet() 创建
    → 填充 delegation_id, delegation_target, delegation_valid
    ↓
ActionTrait::writeDelegationTxtRecords()
    → 按 delegation_id 分组
    → DelegationDnsService::setTxtByLabel() 批量写入
    → 标记 auto_txt_written=true
    ↓
订单提交到 CA（dcv.method=txt）
    ↓
ValidateCommand 定时验证
    → checkDelegationValidity() 即时检测
    → 触发 CA 验证
    ↓
证书签发完成
    ↓
[到期前 15 天] AutoRenewCommand（排除 acme 通道）
    → checkDelegationValidity() 即时检查委托有效性
    → 无有效委托 → 跳过，不发起续费/重签
    → 有有效委托 → 强制使用 delegation 验证方法
    → 重新走上述流程
```

### 委托 DNS 清理

`DelegationCleanupCommand` 每天 06:00 清理无效的委托 TXT 记录：

- **保留**：`processing` 状态订单使用的委托记录
- **删除**：代理域名下所有其他 TXT 记录
- **清理数据库标记**：移除已删除记录对应的 `auto_txt_written` 标记

### 相关服务

| 服务                     | 文件位置               | 职责                     |
| ------------------------ | ---------------------- | ------------------------ |
| `CnameDelegationService` | `Services/Delegation/` | 委托记录管理、有效性检测 |
| `DelegationDnsService`   | `Services/Delegation/` | DNS TXT 记录操作         |
| `AutoDcvTxtService`      | `Services/Delegation/` | 订单维度的自动 TXT 写入  |

---

## 关键文件索引

### 委托验证与自动续签

| 文件                                             | 关键方法/位置                 | 说明                                  |
| ------------------------------------------------ | ----------------------------- | ------------------------------------- |
| `Services/Order/Traits/ActionTrait.php`          | `generateDcv()`               | delegation→txt 转换，设置 is_delegate |
| `Services/Order/Traits/ActionTrait.php`          | `generateValidation()`        | 委托记录查找/创建                     |
| `Services/Order/Traits/ActionTrait.php`          | `writeDelegationTxtRecords()` | 订单创建时写入 TXT                    |
| `Services/Order/Traits/ActionTrait.php`          | `getDelegationPrefixForCa()`  | CA 前缀映射                           |
| `Services/Order/Traits/ActionTrait.php`          | `mergeDcv()`                  | API 响应合并保留委托标记              |
| `Services/Delegation/CnameDelegationService.php` | `findDelegation()`            | 智能匹配委托记录（用于即时验证场景）  |
| `Services/Delegation/CnameDelegationService.php` | `findValidDelegation()`       | 智能匹配有效委托记录（已弃用）        |
| `Services/Delegation/CnameDelegationService.php` | `checkAndUpdateValidity()`    | 即时检测 CNAME 并更新有效性           |
| `Services/Delegation/DelegationDnsService.php`   | `setTxtByLabel()`             | 批量写入 TXT 记录                     |
| `Services/Delegation/AutoDcvTxtService.php`      | `handleOrder()`               | 订单级 TXT 处理                       |
| `Console/Commands/AutoRenewCommand.php`          | `checkDelegationValidity()`   | 发起前即时检查委托有效性              |
| `Console/Commands/AutoRenewCommand.php`          | `processOrder()`              | 自动续费/重签处理                     |
| `Console/Commands/AutoRenewCommand.php`          | `autoPayAndCommit()`          | 自动支付提交                          |
| `Console/Commands/ValidateCommand.php`           | `checkDelegationValidity()`   | 验证前即时检测                        |
| `Console/Commands/DelegationCleanupCommand.php`  | `handle()`                    | 清理非 processing 状态的 DNS 记录     |

### 调度配置

| 命令                  | 调度       | 说明               |
| --------------------- | ---------- | ------------------ |
| `schedule:validate`   | 每分钟     | 证书验证任务       |
| `schedule:auto-renew` | 每小时     | 自动续费/重签      |
| `delegation:check`    | 每天 05:30 | CNAME 委托健康检查 |
| `delegation:cleanup`  | 每天 06:00 | 委托 DNS 清理      |

---

## 测试

### 运行测试

```bash
php artisan test --parallel                           # 全部测试（需 MySQL，推荐加 --parallel 与 CI 一致）
php artisan test --parallel --exclude-group=database  # 纯单元测试（无需数据库）
php artisan test --coverage --min=80                  # 覆盖率报告
```

> **CI 经验**：本地务必用 `--parallel` 跑测试，与 CI 保持一致。`paratest`（并行测试）对 PHP Warning 的处理比 `phpunit` 更严格——例如无命名空间文件中的 `use Mockery;`、`use ZipArchive;` 等全局类 use 语句，`phpunit` 仅输出 Warning 继续运行，而 `paratest` 会直接 fatal exit 导致 CI 失败。

> **并行 storage 隔离**：paratest 各 worker 共享同一 `storage/` 真实磁盘但各自独立 DB（RefreshDatabase）。一测试造真实磁盘文件（`storage_path('app/verification/...')`）、另一测试触发扫/删目录的命令（如 `PurgeCommand` 扫 `verification/` 根按本 worker DB 判“孤立”删除）→ 并行时跨 worker 误删对方文件 → `file_exists` 偶发 false。`TestCase::isolateWorkerStorage()` 已按 `TEST_TOKEN` 把运行时 `storage_path()` + Storage 门面（local/public disk）重定向到 `storage/framework/testing/worker-{token}`，**新写“造真实磁盘文件”的测试自动隔离、无需额外处理**（隔离只覆盖运行时 `storage_path()`/门面，不动 framework cache/log/session — 后者用 bootstrap config 路径）。普通 `artisan test --parallel` 无 coverage、窗口小常测不出，**变异门禁 `XDEBUG_MODE=coverage` 放大并发窗口才稳定复现**（曾致 `DocumentSubmit/PreviewTest` 偶发挂）。

> **API 快照对照（compat-snapshot）+ tearDown 吞 rollback 陷阱**：`compat-snapshot` job 仅 push main / tag 触发（dev PR 不跑），改了 API schema 或新增 Controller 测试后**必须** `composer test:snapshot:capture` 重新生成 fixtures 并提交，否则合 main 首跑即大面积 diff。更隐蔽的是 `TestCase::tearDown` 把 `SnapshotListener::finalizeTest()`（compare 模式命中 diff 会 `Assert::fail()` 抛异常）放在 `parent::tearDown()` 之前——**任何在 `parent::tearDown()` 之前、可能抛异常的清理逻辑都必须 `try/finally` 兜住 `parent::tearDown()`**，否则异常跳过 RefreshDatabase 的事务 rollback → 连接持锁泄漏 + 事务层级逐测试漂移 → 串行跑全套时后续测试 setUp/seed 撞锁，雪崩成 `Lock wait timeout`（单次 50s × N，job 直接卡满超时）。**只有串行全套暴露**：`--parallel` 各 worker 独立库/连接把泄漏掩盖，单文件也因同连接层级漂移不自锁而看不出。排查时 job 日志会被 MySQL service 容器 health-check 的 `Access denied ... using password: NO` 噪音淹没，真正错因在 `Run snapshot compare` step 的 `php artisan test` 输出尾部。

### 测试分组

- `#[Group('database')]` - 需要数据库连接的集成测试
- 无标记 - 纯单元测试，可在任何环境运行

### 测试文件

| 目录/文件                                            | 类型   | 说明                  |
| ---------------------------------------------------- | ------ | --------------------- |
| `tests/Unit/Services/Order/Utils/DomainUtilTest.php` | 纯单元 | 域名工具类（66 测试） |
| `tests/Unit/Services/Order/Utils/CsrUtilTest.php`    | 纯单元 | CSR 工具类（39 测试） |
| `tests/Unit/Services/Delegation/*StaticTest.php`     | 纯单元 | 委托服务静态方法      |
| `tests/Unit/Services/Delegation/*Test.php`           | 集成   | 委托服务数据库操作    |
| `tests/Unit/Services/Order/AutoRenewServiceTest.php` | 集成   | 自动续费判定逻辑      |

### CreatesTestData Trait

`tests/Traits/CreatesTestData.php` 提供测试数据创建方法：

| 方法                     | 说明                         |
| ------------------------ | ---------------------------- |
| `createTestUser()`       | 创建测试用户                 |
| `createTestProduct()`    | 创建测试产品（使用 Factory） |
| `createTestOrder()`      | 创建测试订单                 |
| `createTestCert()`       | 创建测试证书                 |
| `createTestDelegation()` | 创建测试委托记录             |
| `generateTestCsr()`      | 生成测试 CSR                 |

### 编写测试规范

1. **纯单元测试**：测试静态方法、工具函数，不依赖数据库
2. **集成测试**：需要数据库时，添加 `#[Group('database')]` 标记
3. **使用 DataProvider**：参数化测试用例
4. **Mock 策略**：外部服务（DNS、上游 API）使用 Mockery 模拟
5. **测试必须反映真实约束**：不要为架构上不可能的场景编写测试（如 `latestCert` 为 null），也不要在代码中用防御性检查掩盖此类错误——如果真的发生，应让系统抛出异常暴露问题，而非静默返回

### 资金核心变异测试

`pest-plugin-mutate`（Pest 4 自带）针对资金核心代码做变异测试，验证测试质量没有静默退化（覆盖了但断言不强 → 改代码不报错）。

**范围**（6 个 class，spec 决策）：

- `App\Models\Fund`
- `App\Models\Transaction`
- `App\Services\Acme\Action`
- `App\Services\Order\Action`
- `App\Services\Order\AutoRenewService`（自动续费/重签的判断逻辑，资金敏感）
- `App\Services\FundAudit\FundInvariants`

**触发场景**：

- **改了上述 5 个 class 中任一文件 → 必须主动跑 `composer test:mutate` 自检**（最重要的触发点，开发者自我把关）
- 正式版（main 通道）release 前必跑（`/remote-release` 命令的 §3.0 步骤）
- **预发布版（dev 通道）不跑**（试错性质，门禁仅在正式版生效）
- **CI 不跑**（避免 PR 等待 5-7 min，且 release 前已有人工门禁兜底）

**门槛**：

- baseline 文件 `backend/tests/.mutation-baseline.json` 入库，`min_msi` 字段为门槛
- pest 跑出的 MSI 必须 ≥ `min_msi`，否则 fail
- baseline **只升不降**：跑出更高 MSI 时手动上调，不允许靠下调 baseline 让发布通过

**baseline 演进规则**：

baseline 不是终点，而是逐步提升的安全网。演进发生在四个时机，每次都用**独立的 `chore:` commit**（不混进业务 PR）。

| 时机                             | 触发者                    | 操作                                                                                                                                                              |
| -------------------------------- | ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A) 补了测试**                  | 改资金代码的 PR 作者      | 本机 `composer test:mutate` 看 MSI；如果升高 ≥ 2%，单独 commit 调高 `min_msi`（最多 = `floor(实测 - 2)`，留 2% 缓冲）                                             |
| **B) release 前发现自然升高**    | 跑 `/remote-release` 的人 | 跑完看分数高于 baseline ≥ 3%，先 commit 调高 baseline 再走发布流程                                                                                                |
| **C) 范围扩展**                  | 决策加新核心 class 的人   | 在 `backend/scripts/test-mutate.sh` 加新 `--class=...`，重跑出新 baseline，整体调整 `min_msi`                                                                     |
| **D) 退步（MSI 跌破 baseline）** | 发现退步的人              | **禁止下调 baseline**——必须先补测试让 MSI 回升；除非该 untested mutation 已评估无害（如不可达分支），此时应在源码加 `// pest-mutate-ignore` 标记，而非动 baseline |

**长期阶段路线**：

| 阶段               | min_msi 目标 | 实测  | 重点                                                                                   |
| ------------------ | ------------ | ----- | -------------------------------------------------------------------------------------- |
| 第一阶段（已完成） | 77           | 80.12 | 6 class baseline 落地                                                                  |
| 第二阶段（已完成） | 88           | 90.96 | FundInvariants 加 message 弱断言 + 加入 AutoRenewService                               |
| 第三阶段           | 93+          | —     | 消化剩余 15 个 untested（多为 ConcatSwitchSides 等价突变，性价比低）或扩范围到退费明细 |

**首次跑出 baseline**：

```bash
cd backend
XDEBUG_MODE=coverage ./vendor/bin/pest --mutate \
    --class='App\Models\Fund' \
    --class='App\Models\Transaction' \
    --class='App\Services\Acme\Action' \
    --class='App\Services\Order\Action' \
    --class='App\Services\Order\AutoRenewService' \
    --class='App\Services\FundAudit\FundInvariants' \
    --covered-only --parallel
# 看 Score: X%，把 floor(X - 3) 写到 tests/.mutation-baseline.json 的 min_msi
```

**日常使用**：

```bash
cd backend
composer test:mutate                  # 跑全量门禁（按 baseline 门槛）
composer test:mutate -- --bail        # 遇到第一个 untested 立即停（debug 用）
composer test:mutate -- --class='App\Models\Fund'   # 仅跑某个 class
```

**依赖**：

- 本机 PHP 必须装 xdebug 或 pcov（变异测试需要 code coverage driver）
- 本机必须装 jq（`brew install jq` / `apt install jq`）

**为什么不入 CI**：

- 全量 5-7 min（用 `--covered-only` 优化后），单跑某个 class 约 6 min
- PR path-filter 触发会让改资金代码的 PR 等额外 5-7 min
- release 前门禁是关键时刻，本地跑足够保证质量；开发者改资金代码时主动跑做第一道把关

---

## PHP 8.x 废弃函数

项目要求 PHP 8.3+，以下函数已废弃，不要使用：

| 废弃函数                           | 替代方案                                                         | 废弃版本 |
| ---------------------------------- | ---------------------------------------------------------------- | -------- |
| `curl_close($ch)`                  | `unset($ch)` 或不调用（PHP 8.0 起 curl handle 是对象，自动释放） | PHP 8.4  |
| `$reflection->setAccessible(true)` | 直接删除（PHP 8.1 起反射默认可访问）                             | PHP 8.4  |

### TencentCloud SDK

`app/Services/Delegation/Sdk/TencentCloud/` 已从官方 SDK 复制为本地维护，需按当前 PHP 版本修复废弃调用。
