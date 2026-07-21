# 升级系统与外部命令（BinaryLocator）

## 升级系统

### 升级冻结契约

升级期间应用进入只读维护态，避免 in-flight HTTP/Job 半执行：

- **freeze 文件锁**：`storage/framework/upgrade.lock` 文件存在=已 freeze；与 cache driver 完全解耦（`cache:clear` 不会清掉它）
- **HTTP**：`MaintenanceMode` 中间件返回 503 + Retry-After，白名单 `/api/health` / `/api/meta` / `/api/admin/upgrade/*` / admin 会话保活
- **Queue**：`SkipWhenUpgradeFrozen` middleware 在 Job 执行业务前 `release(60)` 早退
- **Schedule**：`routes/console.php` 各 `Schedule::command(...)` 链 `->skip(fn () => UpgradeFreezeLock::isFrozen())`，freeze 期间不触发 Command。**唯一例外 `upgrade:watchdog`**：自愈命令**不挂** skip、且 `->evenInMaintenanceMode()`，冻结/down 期必须存活（否则自废武功）——`ScheduleFreezeSkipTest` 对它单独断言 filtersPass=true
- **smoke test 失败处理**：不 unfreeze，回滚代码到旧版本，旧版 smoke 通过后再恢复服务；都失败保持 freeze + 报警

#### freeze 接入生产升级路径（危险窗挡 HTTP 写）

- **核心机理**：本仓已删 Laravel `PreventRequestsDuringMaintenance` 全局中间件，`artisan down` **对 HTTP 零拦截**（只暂停 worker/scheduler）；`freeze`（`MaintenanceMode` 中间件）才是唯一真正挡外部写请求（下单/支付回调/文档上传）的 HTTP 闸。
- **两条活路径都接 freeze**：web `UpgradeService::performUpgradeWithStatus`（进程内直调 `UpgradeFreezeLock`）+ shell `deploy/upgrade.sh`（调既有 `upgrade:freeze`/`upgrade:unfreeze` 命令）。freeze 在危险动作前点火（web 于 `apply` 前全程有效；shell 于 down 后立即点火，但锁存 `storage/`、随 `mv storage → preserve` 离开规范路径——**切代码窗 [mv, 恢复] 内 `isFrozen()=false`，由 storage 缺失致 app 无法 bootstrap（500）兜底，HTTP-503 有效覆盖自 storage 恢复起的 migrate/seed 窗**，upgrade.sh 注释已按此校准），unfreeze 于 `clear_cache` 后。**死 HTTP 端点** `/upgrade/{freeze,unfreeze,smoke}` 无编排器、不接不删（观察项）。
- **顺序契约（两侧对称）**：`unfreeze` 必须**严格先于** `artisan up`——up 唤醒被 down 暂停的 worker 去 pop job，若 freeze 仍在则 `SkipWhenUpgradeFrozen` 的 `release(60)` 开始烧 job attempts（tries=5 的 job ~5min 全落 failed_jobs）。**全子系统 6 处 up 全部与 unfreeze 配对且序正确**：web 成功路径 / web catch(\Throwable) / web rollback 成功+catch 两入口 / shell perform / shell rollback / fatal shutdown handler。测试断言：`UpgradePerformUpgradeFreezeTest` H2-A 用 Artisan facade mock 捕获 'up' 调用时刻 `!isFrozen`（CommandStarting 事件在测试态被框架不桥接，只能走 facade mock）；`UpgradeRunCommandTest` 对 fatal 路径同款序断言；`upgrade.sh` 靠行序 + 注释固化。
- **rollback 两入口补 unfreeze**（`UpgradeService::rollback` + `upgrade.sh rollback()`）：防失败升级滞留 freeze，先于 up；rollback 自身不 freeze。
- **失败/中断兜底**：`performUpgradeWithStatus` catch 扩到 `\Throwable`（`\Error`/TypeError 也就地 unfreeze + up），且 unfreeze 无条件（与 maintenance_mode 解耦——freeze 点火无条件，若 unfreeze 挂在 `if($inMaintenanceMode)` 内，配置关维护时失败会滞留 freeze 到 TTL）；**真 fatal（OOM/E_PARSE/E_COMPILE_ERROR，catch 接不住）走 `UpgradeRunCommand::handleFatalShutdown`：`unfreeze` → `up` → `fail`**（曾漏 unfreeze：up 后 worker 醒来烧 attempts + 2h 503——shutdown 自愈必须自己配对 unfreeze；**fail 置终态放最后**：up 在 shutdown 阶段二次 fatal 时 catch 接不住、fail 未执行 → status 保持 running 交 watchdog 接管重试，若先 fail 则 watchdog 只救 running 永不兜、down 永久残留）；SIGKILL 由 watchdog 兜底；shell 失败/中断的**数据侧已由 `deploy/upgrade.sh` 的 `cleanup` 守卫 + 入口残留检测兜底**（切代码窗 storage/databak 自动还原到原位；PRESERVE 目录在安装目录同 fs 持久盘，SIGKILL/断电 trap 不跑时数据仍存活、重跑入口 `_check_stranded_preserve` 拦截防新建空 storage 埋数据；见 P0-2 包U），**服务侧仍不自动 up**（freeze-TTL 只解 503，worker/scheduler 停摆待人工——失败时终端自动打印 runbook，与 watchdog「误 up 半迁移库比卡死更坏」同哲学，恢复指引见 `skills/ops/deploy-ops.md`）。
- **发布说明须写明**：升级危险窗内非白名单 API 短暂 503（含支付回调，网关自带重试缓冲）——今天 down 期 HTTP 全通，这是可见行为变更。

#### upgrade:watchdog（升级进程硬杀自愈）

- **问题**：SIGKILL/OOM/`\Error` 打断升级 → `status.json` 卡 `running` + 维护/冻结无人解除 + `execute` 闸门永闭。
- **stale 判定**（`UpgradeStatusManager`）：`isStale = status==='running' && isTimeStale && !isProcessAlive`。心跳 `updated_at`（`save()` 单入口注入 `now()`，Carbon 同源可测）+ `pid`（`start()` 写 `getmypid()` + `pid_starttime`，即 `/proc/{pid}/stat` 第 22 字段进程启动时刻）。`isProcessAlive`：Linux 走 `/proc/{pid}` 存在 + **starttime 二元校验**——活 PID 但 starttime 与记录不符 = 原升级进程已死、PID 被长寿进程复用 → 判死（否则 watchdog 永不自愈 + execute 闸门永闭，只能手删 status.json）；旧格式无 `pid_starttime` 回落只判存在、starttime 读不到保守判活；非 Linux 回落 `posix_kill`（无 /proc 不校验）。shell 侧 `_handle_stale_upgrade_status` 的 inline PHP 探活保持镜像（同校验）。**PID（starttime 相符的）存活是「不动作」一票否决**——慢单步（大库 migrate/慢镜像 composer）超阈值但进程活着时绝不解维护（误 up 半迁移库 + 唤醒 worker pop 半迁移库比卡死更坏）。`isRunning() = running && !isStale`。
- **watchdog**：`Schedule::command('upgrade:watchdog')->everyMinute()->evenInMaintenanceMode()`（不挂 freeze skip）。`running && stale`（超时且进程死）**且冻结锁归属本升级（或无锁）**→ `fail` → `unfreeze` → `artisan up`（先解冻再 up，与升级路径同序）→ 去重 `SystemAlert('upgrade', dedupeKey='upgrade_watchdog', ttl=24h)`；`running && time-stale 但进程活` → 仅 `Log::warning`。恢复地板 = `upgrade.stale_seconds`（默认 3600s）+ 1min 周期；shell 卡死无 status.json、watchdog 不覆盖——其**数据还原/服务恢复由 shell 侧 `cleanup` 守卫 + runbook 承接**（P0-2 包U，见上条），watchdog 只管 PHP 侧 status.json 路径。**watchdog 只救 `status==='running'`**——已置 failed 的态（如 fatal shutdown 已处理）不再动作，因此 shutdown 自愈必须自己完成 unfreeze+up 配对（见上）。
- **冻结锁归属校验（防拆他方升级写闸）**：`stale` 只证明「status.json 追踪的那场 web 升级已死」，不证明当前 `upgrade.lock` 属于它——upgrade.sh 覆盖式升级（`artisan upgrade:freeze`，owner=shell）与 admin 手动 freeze（owner=manual）同用一把锁；残留 stale status + shell 升级并行时，若无归属校验，watchdog 会在 shell 升级 migrate/seed 危险窗内 unfreeze+up 拆掉唯一 HTTP 写闸（本仓已删 PreventRequestsDuringMaintenance）。自愈前经 `lockBelongsToTrackedUpgrade` 判归属：新格式锁仅 `owner_source=web && owner_pid==status.pid` 算本升级；旧格式锁（无 owner 字段，含 N-1 版 artisan 在 shell 切码前写的）回退时间判定——`frozen_at > 最后心跳+60s` 判他方（stale 前置保证两侧分离度 ≥ stale_seconds）；时间缺失/不可解析回落本升级（缺 `frozen_at` 的锁永不过期，watchdog 是它唯一清道夫）。**他方持锁 → 零动作**（不 fail/不 unfreeze/不 up），仅去重告警（`upgrade_watchdog_foreign`，24h），对方解锁后下一分钟照常自愈。锁 owner 由 `UpgradeFreezeLock::freeze(..., ownerSource)` 单点写入：web=`UpgradeService`、shell=`upgrade:freeze --source` 默认、manual=admin freeze 端点。

#### upgrade.sh 数据防删守卫（P0-2 包U，storage/databak 不丢）

`deploy/upgrade.sh` 是 `set -e` 全量替换升级（`rm -rf` 各目录 + 整体 cp），切代码窗需先把活的 `backend/storage`（含 `storage/databak` 全部本地 DB 备份）搬走再搬回。此窗一旦中断，储存数据面临被空 storage 覆盖的风险。四道守卫：

- **① PRESERVE_DIR 同文件系统 + same-fs 断言**：`PRESERVE_DIR="$INSTALL_DIR/.upgrade-preserve-$$"`（安装目录根级、**非** `TEMP_DIR` 内——EXIT trap 的 `rm -rf TEMP_DIR` 天然够不着它）。`mv storage → preserve` 前 `_assert_storage_same_fs`（`stat -c %d` / `-f %d` 双兼容取设备号）强制断言同 fs：**mv 跨 fs 不报错而是静默 copy+unlink**，复制窗中断会让 cleanup 用半份覆盖完好源 → 异构挂载时**断言失败中止升级、原地未破坏**（需调整挂载后重试）。
- **② cleanup trap 守卫还原**：`trap cleanup EXIT INT TERM HUP`——失败退出 / `Ctrl-C` / SSH 断连均**先 `_restore_preserved_storage` 把 storage（databak 最高优先级）移回原位再清理**。守卫自身失败绝不吞：保留 PRESERVE_DIR（唯一副本）、只删 TEMP_DIR、非零退出。trap 先 `trap '' INT TERM HUP` 防重入（还原幂等：存在性门 + rc 首行捕获，双跑无害）。PRESERVE 在持久盘（非 `/tmp`），故即便 **SIGKILL/断电**（trap 跑不了）数据也存活在 `.upgrade-preserve-*/storage`。**删 preserve 前先 `_restore_preserved_extras` 还原 api_adapters/frontend_config 副本**：中断落在「rm 旧代码 ~ 步骤 9 恢复」窗内时这些是唯一在线副本（原件已 rm、备份 zip 虽含但 rollback 自动选最新=绿灯重跑的无适配器备份救不回），还原失败则保留 preserve 供人工恢复（不静默销毁）。`platform-config.json` 不再进入 preserve，随升级包更新。
- **③ 入口残留检测搁浅中止 + vendor 回迁**：SIGKILL/断电后 storage 滞留 preserve 而 `backend/storage` 缺失时，重跑入口 `_check_stranded_preserve` 检测到 `preserve/storage` 存在即 **exit 1 中止**（否则后续 mkdir 出空 storage 把真数据连同 databak 静默埋掉），打印手工恢复指引（mv 回 + rm 残留）。**vendor-only 残留（无 storage、preserve 留 vendor 唯一副本、`backend/vendor` 缺失）回迁而非当空壳 rm**：直接清会毁唯一副本 + 后续 composer 因新旧 hash 相等误跳过 → artisan fatal 砖机自循环；回迁到原位并置 `NEED_COMPOSER_FORCE=1` 令后续 composer 强制重装对齐新 lock。其余空壳残留（storage 已消费、仅剩 api_adapters 等副本）顺手清理 + `ls` 列内容留痕（防静默清走无迹可查）。
- **④ 失败不自动 up + runbook**：`FREEZE_FIRED` / `UPGRADE_DONE` 双 flag——升级未完成且已冻结 → cleanup 打印 `_print_recovery_runbook`（unfreeze→up→`queue:restart` 三步，与 H2 顺序契约一致），**服务侧不自动 up**（与 watchdog「误 up 半迁移库比卡死更坏」同哲学）。仅打脚本 PID 的 kill 会让在途前台命令跑完 rc=0 → 强制提升非零，使「已冻结未完成」永不以 0 谎报成功。运维恢复步骤见 `skills/ops/deploy-ops.md`。
- **⑤ 入口残留升级状态处置（watchdog 互杀第二道，第一道=锁归属校验见上）**：step5 down/freeze 前 `_handle_stale_upgrade_status`——status.json 为 running 且进程死（SIGKILL/OOM 残留）→ 归档 `.stale.<epoch>`，消除 watchdog 在本次升级危险窗内的触发源；running 且进程活 → **中止**（并发双升级必互毁；PID 复用误判时 `UPGRADE_IGNORE_RUNNING=1` 逃生）；缺文件/损坏/终态/php 探测失败 → 不动交后端防线。PID 探活镜像 `UpgradeStatusManager::isProcessAlive`（`/proc` → `posix_kill` 回落）。
- **演练固化**：`deploy/test/test-upgrade-preserve-guard.sh`（22/22 双 bash 绿 + 反向注入自检；A7 vendor 回迁 / A8+A10 extras 还原 / A9 composer 判定 / B 组 `set -m` 真投递 SIGINT + 睡满哨兵 / C 组锁定 platform-config 不再 preserve 且升级包必须携带、admin logo 不再保护）+ `deploy/test/test-upgrade-stale-status.sh`（A 组纯 shell 分支 + B 组真 php verdict，双环境绿）+ CI 挂载。

#### 定时备份互斥 + 失败告警（`schedule:backup`）

- **非阻塞抢锁**：`BackupCommand` 抢 `Cache::lock(backup:mutex)` 非阻塞 `get()`——抢不到（Create/RestoreBackupJob 持锁 3600s 中）→ 去重 `SystemAlert('backup', 'backup_lock_contention')` + 返回 **SUCCESS**（跳过≠失败），避免与半恢复库并发 dump 出垃圾备份污染灾备。
- **`--internal-no-lock` 重入旁路（对端契约）**：`CreateBackupJob`/`RestoreBackupJob` 已持 `backup:mutex`，重入命令时**必须**传 `--internal-no-lock`（`$owns=false`）绕过抢锁——**漏传则命令抢锁失败静默跳过、备份/`pre_restore` 快照缺失（恢复无护栏）**。两调用点 + 命令三处对称，Job 测试断调用参数含该 flag。
- **失败告警（仅 `$owns`）**：client-missing → `backup_client_missing`；dump/schema 异常 → `backup_dump_error`；成功清三个去重键（恢复后下次异常立即再告警）。`--internal-no-lock` 路径不告警（父 Job 自管进度）。

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
  - BT API 自动修复：`bt_resolve_key`（仅自动读 `api.json`，不当场 read 收 key）→ 调 `bt-deps.sh::auto_install_ext`（三路径 fallback：BT API → legacy script → ini 直写）装扩展 → 调 `bt-deps.sh::enable_functions` 直接 sed `php.ini` + `php-cli.ini` 移除禁用函数（绕过 BT API GetPHPConfig，因其在 CLI ini 单独配置时返回不准）→ 直接重新校验（升级流程全程 CLI 启新进程读 ini，不依赖 FPM 状态，故不再 sleep / reload FPM；FPM reload 推迟到升级末尾步骤 15a 统一处理）
  - 未探测到 BT key → 提示用户到面板"设置 → API 接口"启用并加 IP 白名单后重跑（与 install.sh `detect_bt_key` 一致，避免明文 key 进终端历史）
- **后端 web 入口（管理后台触发）**：`UpgradeService::performUpgradeWithStatus()` 的 `check_environment` 步骤（extract 之后、apply 之前）。不通过抛 `PhpEnvironmentException`，catch 块把 `details` 写入 `status.json.error_details`，前端 ElDialog 弹窗展示
- **cron/supervisor PHP 路径**：upgrade.sh 升级末尾调 `update_jobs_php_path`，扫 `bt_list_crontab_all` + `bt_list_supervisor_all` 中含 `/www/server/php/XX/bin/php`（或裸 `php` token）与当前 `$PHP_CMD` 不一致的项。对 install.sh 自管（cron 含 `$INSTALL_DIR/backend/artisan schedule:run`；supervisor 含 `artisan queue:work` 且 path=`$INSTALL_DIR/backend`）且类型内唯一的项，自动覆盖更新（cron 走"先删后加 + 失败用原 body 回滚"三段语义；supervisor 走 `bt_add_supervisor_process` 自带 Remove+Add，失败也回滚）。不满足"自管+唯一"的项保留列表 + 手工提示
- **cron 日志策略**：`schedule:run` 只在 PHP 路径不一致时修复，保留原命令主体和日志策略；新安装不重定向输出，由宝塔面板保存任务日志。
- **升级末尾 PHP-FPM reload（步骤 15a）**：upgrade.sh 在权限检查前显式调 `bt_reload_php_fpm`，让 web 入口清 opcache 加载新代码。失败提示手工到面板 reload；非宝塔 PHP 路径跳过
- **fatal 兜底**：`UpgradeRunCommand::handle()` 注册 `register_shutdown_function` → `handleFatalShutdown`（静态、注入 `error_get_last()`，便于直测），捕获 `E_ERROR / E_PARSE` 等 fatal：双守卫（非 fatal / 非 running 早退）后 `unfreeze` → `artisan up` → `fail`（序契约见「freeze 接入」节；fail 放最后让 up 二次 fatal 时 status 留 running 交 watchdog 接管），避免卡 running 死锁 + freeze 滞留
- **classmap 自愈**：upgrade.sh composer 块后**无条件**跑 `dump-autoload --optimize --no-scripts`，修复跨小版本升级时 vendor 路径变更（如 `Pdo\Mysql` polyfill / `ReflectsClosures` 跨目录）导致的 classmap 漂移
- **composer 触发收口 `_need_composer_install`**：依赖变化判定统一走此函数，判据「`vendor/autoload.php` 缺失 ∨ `NEED_COMPOSER_FORCE=1`（入口回迁旧 vendor）∨ composer.json/lock hash 变化」任一即装。**vendor 缺失必装是砖机兜底**——中断丢 vendor 后重跑时 `backend/composer.json` 已是新版本、新旧 hash 相等会误跳过 composer → artisan fatal 自循环，runbook 的「重跑」指引失效；从新 lock 重建始终正确幂等，宁可多装一次

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
- **证书下载**（`Services/Order/Traits/ActionFileTrait`）—— 失败处理分两档（`71db6d7b`）：**用户显式请求单格式**（type=iis/tomcat）时 openssl/keytool 失败必须硬报错 + `Log::error`，绝不静默给残缺包；仅 **type=all 聚合路径**可 try-catch `BinaryNotFoundException` 后 `return` 跳过该段（best-effort），且须 returnCode + file_exists 双判 + Log 留痕、不 `> /dev/null` 丢 stderr。同族先例：SM2 绝不静默降级 RSA（`21b5ab45`）、fail-closed 拒 dual-sm2（`06e40f74`）
- **备份/恢复**（`Services/Backup/BackupService` 链路 / `Jobs/RestoreBackupJob` / `Http/Controllers/Admin/DatabaseBackupController`）—— mysqldump/mysql 失败由 `ApiResponse` 错误返回，传 `diagnose` 到 errors 字段
- **插件/Release 下载**（`Services/Plugin/PluginManager` / `Services/Upgrade/ReleaseClient`）—— curl 找不到回落到 `file_get_contents` 等（保持原 `ResolvesExecutablePath` null 语义）

### 探测策略（不要碰）

- 全部走 `proc_open([$path, $flag])` 数组形式校验，**不能用 `is_executable` / `file_exists`**（FPM 下走 `open_basedir` 检查，宝塔白名单外的路径会被误判为不可用）
- `proc_open` 数组形式调用（execve），不走 shell，天然防注入 + 避开 `open_basedir`
- **双管道并发排空（防死锁）**：凡声明 stdout+stderr 两个 `pipe` 的 proc_open（`probeWith` / `inspectCliIni`）一律走 `drainPipes()`（`stream_select` 轮询两管道读到双双 EOF），**禁止只 `stream_get_contents($pipes[1])` 读 stdout 不读 stderr** —— 子进程向 stderr 写满管道缓冲区（Linux ~64KB）会阻塞写、父进程又卡在读 stdout 等 EOF，双向死锁。现有调用都是 `--version` 这类小输出不触发，纯防御；stderr 排空后丢弃、不参与判定（仅 stdout 文本判定）。单管道探测（`probeViaShell` / `command -v` 那种 `[1 => ['pipe','w']]` + 命令内 `2>/dev/null`）无此问题，不必改
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

### PFX / IIS 包算法（防回归）

- **`ActionFileTrait::addCertToZip` 生成 IIS `.pfx` 走 openssl CLI** `pkcs12 -export -keypbe PBE-SHA1-3DES -certpbe PBE-SHA1-3DES -macalg SHA1`，不用 PHP `openssl_pkcs12_export`（OpenSSL 3.x 默认 AES-256/PBKDF2-SHA256，Windows Server 2008/2012/2016 报"密码错误"无法导入）
- **单条命令、不加 `-legacy`**：3DES 在 OpenSSL 3.x default provider、1.x 原生可用；`-legacy` 是 3.0 新增选项，1.x/LibreSSL 报 `Unrecognized flag` 反需回落兜底，3.x 上显式指定 3DES 时它是空操作（实测带不带产物字节相同）。只有 **RC2-40 加密证书**才需 legacy provider，本系统不用（40 位弱加密、新系统在弃用）。`tests/Unit/Services/Order/PfxDownloadTest.php` 用 **DER 字节级 OID 断言**锁定算法（含 3DES OID `1.2.840.113549.1.12.1.3`、不含 AES-256 OID）——**别改回 AES、别加回 `-legacy`**
- `-certfile` 需有效中间证书；`download()` 入口已过滤空 `intermediate_cert`（`Cert::intermediate_cert` 是依赖 `issuer` + `cert.chainMap` 的 computed accessor，非真实列；测试构造需注入 chainMap + 设 issuer 还原此前提）

---
