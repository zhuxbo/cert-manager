# 升级系统与外部命令（BinaryLocator）

## 升级系统

### 升级冻结契约

首次 runtime 黑名单切库迁移在 freeze 期间跳过，后台升级写入 completed 后由 `RuntimeSessionCutover` 补执行；旧版后台进程通过该迁移注册的应用终止回调兼容。`upgrade.sh` 在 `up` 成功后按单文件路径补跑同一迁移。补跑时复用 HTTP 启动独占锁排空在途请求，复制旧 JWT 黑名单并保留过期时间，不吊销有效会话；失败保留旧库及清理保护以便重试。迁移名保留兼容已发布版本，已执行的实例后续不重跑。关闭自动迁移时后台不主动补跑。详见认证规范。

Redis 编号由 `RedisDatabaseConfig::preserve()` 读取当前应用已加载的配置（含 config cache），显式追加到本实例 `.env`，幂等且保留属主/权限与 `APP_NAME`；不扫描跨实例占用、不搬迁队列。新版 `UpgradeService` 在 apply 前调用；首次旧版后台进程通过 runtime 切库 migration 在清配置缓存前补调用（旧配置无 runtime store）；`upgrade.sh` 在切码前以旧应用 bootstrap 加载目标包中的同一实现。两库原本相同、编号无效或使用 `REDIS_URL` 时中止，不能套用新默认值掩盖配置问题。首次旧后台升级必须开启自动迁移。

升级期间应用进入只读维护态，避免 in-flight HTTP/Job 半执行：

- **freeze 文件锁**：`storage/framework/upgrade.lock` 文件存在=已 freeze；与 cache driver 完全解耦（`cache:clear` 不会清掉它）
- **HTTP**：`MaintenanceMode` 中间件返回 503 + Retry-After，白名单 `/api/health` / `/api/meta` / `/api/admin/upgrade/*` / admin 会话保活
- **Queue**：`SkipWhenUpgradeFrozen` middleware 在 Job 执行业务前 `release(60)` 早退
- **Schedule**：`routes/console.php` 各 `Schedule::command(...)` 链 `->skip(fn () => UpgradeFreezeLock::isFrozen())`，freeze 期间不触发 Command。**唯一例外 `upgrade:watchdog`**：自愈命令**不挂** skip、且 `->evenInMaintenanceMode()`，冻结/down 期必须存活（否则自废武功）——`ScheduleFreezeSkipTest` 对它单独断言 filtersPass=true
- **smoke test 失败处理**：不 unfreeze，回滚代码到旧版本，旧版 smoke 通过后再恢复服务；都失败保持 freeze + 报警

#### freeze 接入生产升级路径（危险窗挡 HTTP 写）

- **核心机理**：本仓已删 Laravel `PreventRequestsDuringMaintenance` 全局中间件，`artisan down` **对 HTTP 零拦截**（只暂停 worker/scheduler）；`freeze`（`MaintenanceMode` 中间件）才是唯一真正挡外部写请求（下单/支付回调/文档上传）的 HTTP 闸。
- **两条活路径都接 freeze**：web `UpgradeService::performUpgradeWithStatus`（进程内直调 `UpgradeFreezeLock`）+ shell `deploy/upgrade.sh`（调既有 `upgrade:freeze`/`upgrade:unfreeze` 命令）。freeze 在危险动作前点火（web 于 `apply` 前全程有效；shell 于 down 后立即点火，锁存 `storage/` 并会随 `mv storage → preserve` 暂时离开规范路径；该切代码窗由稳定放在 `backend/.upgrade-bootstrap.lock` 的独占锁阻塞新 HTTP 请求，不再依赖 bootstrap 500 兜底；storage 恢复后 HTTP-503 继续覆盖 migrate/seed 窗），unfreeze 于 `clear_cache` 后。**死 HTTP 端点** `/upgrade/{freeze,unfreeze,smoke}` 无编排器、不接不删（观察项）。
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
- **② cleanup trap 守卫还原**：`trap cleanup EXIT INT TERM HUP`——失败退出 / `Ctrl-C` / SSH 断连均**先 `_restore_preserved_storage` 把 storage（databak 最高优先级）移回原位再清理**。守卫自身失败绝不吞：保留 PRESERVE_DIR（唯一副本）、只删 TEMP_DIR、非零退出。trap 先 `trap '' INT TERM HUP` 防重入（还原幂等：存在性门 + rc 首行捕获，双跑无害）。PRESERVE 在持久盘（非 `/tmp`），故即便 **SIGKILL/断电**（trap 跑不了）数据也存活在 `.upgrade-preserve-*/storage`。**删 preserve 前先 `_restore_preserved_extras` 还原 api_adapters/frontend_config 副本**：中断落在「rm 旧代码 ~ 步骤 9 恢复」窗内时这些是唯一在线副本（原件已 rm、备份 zip 虽含但 rollback 自动选最新=绿灯重跑的无适配器备份救不回），还原失败则保留 preserve 供人工恢复（不静默销毁）。正常步骤 9 使用 `consume` 模式，成功复制后立即消费对应副本，使成功 EXIT cleanup no-op，不重复覆盖或误报“中断升级遗留”；**消费（rm）失败只具名告警、不计入返回码**——此时 cp 已成功、数据面已正确，纯清理动作没有资格把一次正确的升级打断在步骤 9（那会触发恢复 runbook 并让站点滞留维护态）。`platform-config.json` 不再进入 preserve，随升级包更新。
- **③ 入口残留检测搁浅中止 + vendor 回迁**：SIGKILL/断电后 storage 滞留 preserve 而 `backend/storage` 缺失时，重跑入口 `_check_stranded_preserve` 检测到 `preserve/storage` 存在即 **exit 1 中止**（否则后续 mkdir 出空 storage 把真数据连同 databak 静默埋掉），打印手工恢复指引（mv 回 + rm 残留）。**vendor-only 残留（无 storage、preserve 留 vendor 唯一副本、`backend/vendor` 缺失）回迁而非当空壳 rm**：直接清会毁唯一副本 + 后续 composer 因新旧 hash 相等误跳过 → artisan fatal 砖机自循环；回迁到原位并置 `NEED_COMPOSER_FORCE=1` 令后续 composer 强制重装对齐新 lock。其余空壳残留（storage 已消费、仅剩 api_adapters 等副本）顺手清理 + `ls` 列内容留痕（防静默清走无迹可查）。
- **④ 失败不自动 up + runbook**：`FREEZE_FIRED` / `UPGRADE_DONE` 双 flag——升级未完成且已冻结 → cleanup 打印 `_print_recovery_runbook`（unfreeze→up→`queue:restart` 三步，与 H2 顺序契约一致），**服务侧不自动 up**（与 watchdog「误 up 半迁移库比卡死更坏」同哲学）。仅打脚本 PID 的 kill 会让在途前台命令跑完 rc=0 → 强制提升非零，使「已冻结未完成」永不以 0 谎报成功。运维恢复步骤见 `skills/ops/deploy-ops.md`。
- **⑤ 入口残留升级状态处置（watchdog 互杀第二道，第一道=锁归属校验见上）**：step5 down/freeze 前 `_handle_stale_upgrade_status`——status.json 为 running 且进程死（SIGKILL/OOM 残留）→ 归档 `.stale.<epoch>`，消除 watchdog 在本次升级危险窗内的触发源；running 且进程活 → **中止**（并发双升级必互毁；PID 复用误判时 `UPGRADE_IGNORE_RUNNING=1` 逃生）；缺文件/损坏/终态/php 探测失败 → 不动交后端防线。PID 探活镜像 `UpgradeStatusManager::isProcessAlive`（`/proc` → `posix_kill` 回落）。
- **演练固化**：`deploy/test/test-upgrade-preserve-guard.sh`（22/22 双 bash 绿 + 反向注入自检；A7 vendor 回迁 / A8+A10 extras 还原 / A9 composer 判定 / B 组 `set -m` 真投递 SIGINT + 睡满哨兵 / C 组锁定 platform-config 不再 preserve 且升级包必须携带、admin logo 不再保护）+ `deploy/test/test-upgrade-stale-status.sh`（A 组纯 shell 分支 + B 组真 php verdict，双环境绿）+ CI 挂载。

#### 定时备份互斥 + 失败告警（`schedule:backup`）

- **非阻塞抢锁**：`BackupCommand` 通过 `DatabaseOperationMutex` 非阻塞获取 MySQL named lock；抢不到则去重告警 `backup_lock_contention` 并返回 **FAILURE**，不产生任何备份文件。
- **Job/CLI 同一入口**：`CreateBackupJob` 先上报 `dumping` 阶段，再直接调用 `schedule:backup`；它不持有第二套 Cache 锁，由命令统一获取 `DatabaseOperationMutex`。遗留恢复 Job 的互斥改造归恢复编排任务，不在备份发布中引入兼容旁路。
- **失败告警**：client-missing → `backup_client_missing`；dump/schema 异常 → `backup_dump_error`；成功清三个去重键（恢复后下次异常立即再告警）。

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

**`PackageExtractor::applyBackendUpgrade` 同步策略（动态发现，非白名单）**：普通顶层目录动态同步，`storage` 始终排除；包内 `vendor` 先通过 lock SHA-256 标记校验。当前 `vendor` 已与目标 lock 对齐时直接原地复用，避免无意义的大目录复制和目录切换窗口；不一致或缺失时，才在覆盖任何代码前复制到安装盘同级临时目录并二次校验，磁盘满或权限失败时旧代码与旧 vendor 都保持不变，随后用同文件系统目录切换整体替换且不与旧 vendor 合并。HTTP 入口在加载 Composer 前持有 `backend/.upgrade-bootstrap.lock` 共享锁，后台升级、`upgrade.sh` 及插件安装/更新在发布文件时持独占锁；已进入请求执行完后才切换，新请求等待完整发布或失败回退结束。首次从无锁入口采用该机制时，升级器先把自包含锁片段原子注入旧 `public/index.php`，再按 `UPGRADE_LEGACY_REQUEST_DRAIN_TIMEOUT`（默认 300 秒，应不小于 FPM 请求硬上限）排空注入前已进入的旧请求；准备状态暂存于 `backend/.upgrade-bootstrap-prepared.json`，进程中断后重试继续剩余时间，独占锁取得后即删除。正常发布包直接使用已校验依赖；不带 vendor 的历史包仍走原 Composer 兼容路径，并在 autoload 重建成功后原子刷新 lock marker。

**两条升级路径删除语义不同、且都正确**：后台升级（PHP，在被升级代码内运行、不能全量删自身）→ 只覆盖不删除，旧版删除的文件会残留（本项目路由是显式白名单不扫目录，残留基本无害）；需彻底清理残留时走 `upgrade.sh`（外部 shell，`rm -rf` 各目录 + 整体 `cp` 全量替换、天然无残留）。一致性目标是「都不漏应更新的目录」，删除策略因运行环境不同而必须不同。

**升级器自更新有一次时序滞后**：本次后台升级跑的是服务器上的旧 `PackageExtractor`，逻辑修复要下一次升级才生效（或本次升级后手动补缺失资源）。因此从不带 `SSL_MANAGER_BOOTSTRAP_LOCK_V1` 的历史版本首次进入启动锁机制时，不能依赖目标包里的新 PHP 升级器自救，必须使用本次发布的 `upgrade.sh` 完成首次握手。入口已具备标记后，后台升级才受上述共享/独占锁保护。另一种可行设计是先发布桥接版本，但当前尚未实现；桥接版本除注入入口 marker 外，还必须持久化 draining 状态并等待旧请求排空。回归测试见 `PackageExtractorTest`（动态发现新目录 / storage 跳过 / resources 同步）与 `ApplicationBootstrapLockTest`（首次注入 / 中断续等 / 原生新装快路径 / 失败关闭）。

### 升级模式

| 特性           | PHP API 升级                                    | Shell 脚本升级                                           |
| -------------- | ----------------------------------------------- | -------------------------------------------------------- |
| 触发方式       | 管理后台 API                                    | `deploy/upgrade.sh`                                      |
| 升级包         | `upgrade` 包                                    | `full` 包                                                |
| 维护模式       | 自动进入/退出                                   | 自动进入/退出                                            |
| PHP 环境不达标 | 仅检测；前端弹窗指引用 upgrade.sh               | 询问 BT API key 自动装扩展/启用函数；否则手工指引        |
| Composer       | 包内 vendor 校验复用；历史包兼容 `--no-scripts` | 包内 vendor 校验复用；历史包兼容 install + dump-autoload |

发布包变大后，各入口都按单次下载设置完整超时：`install.sh` 的入口脚本包为 120 秒，安装完整包、`upgrade.sh` 完整包和后台 `ReleaseClient` 升级包均为 300 秒；后台 curl 失败后，HTTP 客户端仍拥有完整的 300 秒回退尝试。`deploy/test/test-package-download-timeouts.sh` 固化这些下限，并同时守卫插件包的单次 120 秒配置。

### PHP 环境检测

- **需求清单**：`build/php-requirements.json`（与版本绑定）。build 时同时复制到 release zip 根（`full`/`upgrade` 包，供升级流程读）+ script zip 根（与 `install.sh`/`upgrade.sh` 同级，供安装流程读）。字段：`php_min` / `php_recommended` / `extensions.required[]` / `extensions.recommended[]` / `functions.required[]` / `functions.recommended[]`
- **install.sh 入口（安装时）**：
  - `bt-install.sh::select_php_version` 读 `php_min`，扫 `/www/server/php/*` 用 `version_compare` 过滤符合版本的 PHP（取代 hardcode `[84 83]`）
  - `bt-deps.sh::check_php_extensions` 读 `extensions.required[]`（剔除 PHP 内置 `bcmath/ctype/dom/...` 等不需要 BT 单独装的），用于扩展存在性校验和自动安装
  - `bt-deps.sh::check_disabled_functions` 读 `functions.required[]`，扫 php.ini + php-cli.ini 的 `disable_functions`，自动 sed 移除（备份原 ini）+ 重启 PHP-FPM
- **upgrade.sh 入口（升级时）**：
  - 解压后、切代码前调 `check_php_environment "$src_dir"`：读 release zip 内 `$src_dir/php-requirements.json`
  - PHP 版本错 → 手工指引 exit；仅扩展/函数错 → 询问是否走 BT API 自动修复
  - BT API 自动修复：`bt_resolve_key`（仅自动读 `api.json`，不当场 read 收 key）→ 调 `bt-deps.sh::auto_install_ext`（三路径 fallback：BT API → legacy script → ini 直写）装扩展 → 调 `bt-deps.sh::enable_functions` 直接 sed `php.ini` + `php-cli.ini` 移除禁用函数（绕过 BT API GetPHPConfig，因其在 CLI ini 单独配置时返回不准）→ 直接重新校验（升级流程全程 CLI 启新进程读 ini，不依赖 FPM 状态，故不在环境检查阶段额外 reload；FPM reload 统一放到升级末尾、解除维护态之前处理）
  - 未探测到 BT key → 提示用户到面板"设置 → API 接口"启用并加 IP 白名单后重跑（与 install.sh `detect_bt_key` 一致，避免明文 key 进终端历史）
- **后端 web 入口（管理后台触发）**：`UpgradeService::performUpgradeWithStatus()` 的 `check_environment` 步骤（extract 之后、apply 之前）。不通过抛 `PhpEnvironmentException`，catch 块把 `details` 写入 `status.json.error_details`，前端 ElDialog 弹窗展示
- **cron/supervisor PHP 路径**：upgrade.sh 升级末尾调 `update_jobs_php_path`，扫 `bt_list_crontab_all` + `bt_list_supervisor_all` 中含 `/www/server/php/XX/bin/php`（或裸 `php` token）与当前 `$PHP_CMD` 不一致的项。对 install.sh 自管（cron 含 `$INSTALL_DIR/backend/artisan schedule:run`；supervisor 含 `artisan queue:work` 且 path=`$INSTALL_DIR/backend`）且类型内唯一的项，自动覆盖更新（cron 走"先删后加 + 失败用原 body 回滚"三段语义；supervisor 走 `bt_add_supervisor_process` 自带 Remove+Add，失败也回滚）。不满足"自管+唯一"的项保留列表 + 手工提示
- **cron 日志策略**：`schedule:run` 只在 PHP 路径不一致时修复，保留原命令主体和日志策略；新安装不重定向输出，由宝塔面板保存任务日志。
- **升级末尾 PHP-FPM reload**：upgrade.sh 完成最终权限修正后、仍处于 freeze + 维护态时显式调 `bt_reload_php_fpm`，成功或完成非阻断处置后才依次 `upgrade:unfreeze`、`artisan up`、`queue:restart`，避免恢复流量后旧 worker 与新代码竞争。
  - **通道优先级 `/etc/init.d/php-fpm-XX reload` > `systemctl reload` > 宝塔 API**（`_bt_php_fpm_send_reload`）。init 脚本是单次 `kill -USR2 $(cat php-fpm.pid)`，退出码可信且**不需要 BT API key**——这条也是 reload 不再被 key 门控的原因：过去 key 取不到就整段跳过 reload，`opcache.validate_timestamps=0` 的机器升级后会持续跑旧代码。宝塔 API 排最后：实测面板 `class/system.py::ServiceAdmin` 执行完 init 脚本后并不看其退出码，而是轮询 `check_service_status` → `public.is_php_fpm_process_exists` → `is_process_exists_by_exe`（psutil 遍历 `/proc/*/exe`），判否时**再补发最多 6 次 `systemctl reload`**、返回失败前还重复执行一次原命令，故「只发一次 reload」在 API 通道上不成立，额外重载会在等待窗口内反复翻新 worker 代际。
  - **成败只认本机可观测证据，不采信宝塔自陈的 `status`**：线上实测出现过 reload 已生效（旧 worker 代际已退、master 在、健康入口经 FPM 返回本项目 JSON）而宝塔仍返回 `{"status": false, "msg": "php-fpm-XX服务启动失败"}`。该消息在面板里只有 `class/system.py:1281` 一处，其前置条件是 `check_service_status`（→ `public.is_php_fpm_process_exists` → `is_process_exists_by_exe` → psutil 先 `pids()` 取快照、再逐个 `Process(pid).exe()`，取不到即 `except: continue`）判否。**根因**：宝塔的 php-fpm 以 `--daemonize` 启动，reload 时 master 按原始 argv `execvp` 自身后**再 fork 脱离**，于是每次 reload 都换一个新 master PID（实测 FPM 日志 `fpm is running, pid` 663089 → 663093 → 663096 …）；psutil 的快照-再查询之间正好存在「旧 master 已消失、新 master 未进快照」的窗口，命中即判否。该窗口宽度与机器相关：**实测某台约 10 次点重载有一半失败，另一些机器 10/10 正常**。它还会自我放大——一旦判否，面板补发最多 6 次 `systemctl reload`，每次再制造同一窗口。故宝塔的 `status` 不能充当成败权威（面板 UI 手工重载同样会偶发显示失败，与实际结果无关）。
  - 成功判据：①reload 已成功发出；②**master 已换代**（强因果，见下）**或** reload 前记录的旧 worker 代际全部退出，且 master 存在；③reload 后本站 `/api/health` 经 Nginx → FPM Socket → 新 worker → Laravel 返回本项目 JSON。**③ 取不到站点域名时跳过并降级告警，不据此判失败**——那是「测不了」而非「测失败」，据此判失败会把一次真实成功的 reload 拖满 timeout 再误报，而此时站点仍停在维护态。
  - **② 的因果绑定**：`master PID 变化只可能由 reload 造成`，不受 `pm.process_idle_timeout` 影响，且在 ondemand 空闲池（0 worker、建不起代际基线）下依然可用，故列为首选证据；但并非所有部署都换 PID（非 daemonize / systemd 托管可能原地保留），因此只作充分条件、不作必要条件。判据是「出现一个不在旧集合里的**新** master」且**旧集合非空**——不能用集合整体不等：那会把「master 消失」「多 master 收缩」也算成换代，而旧集合为空时 reload 只能走 rc 不可信的通道，「冒出一个 master」可能只是别的东西把 FPM 拉起来。回落到 worker 代际时另有一道防伪绿闸：**探活合成出来的基线 worker 会被 idle 回收**，若只看「旧代际消失」，一次**根本没发生的 reload** 也能靠时间流逝凑齐条件②；故对合成基线要求其退出同时满足 reload 后 `BT_PHP_FPM_CAUSAL_WINDOW`（默认 = 一个采样间隔）与基线建立后 `BT_PHP_FPM_BASELINE_MAX_AGE`（默认 6 秒，取 idle_timeout 常见默认 10 秒的余量；idle 计时从**探活**而非 reload 起算，探活先 https 后 http 可能先耗数秒）两道窗口，超窗且 master 未换代即判证据不足、明确失败。
  - **① 的可信度分级**：首选对已识别 master 直接 `kill -USR2`——只有它的退出码真代表「信号送达目标进程」。宝塔 init 脚本的 `reload` 分支以 `echo " done"` 收尾，`kill` 失败（pid 文件残留等）照样 `exit 0`，rc 不能当证据；systemctl / API 更间接。识别不到 master 时才依次回落 init.d → systemctl → API。
  - 代际识别：版本归属锚 `/proc/<pid>/exe`，角色由 **cmdline** 区分（master 恒为 `php-fpm: master process (...)`）——不用「parent 不在同版本 PID 集合内」推断 master，因为 master 死后 worker 被 reparent 到 1 同样满足该条件，孤儿 worker 仍持有继承的 listen fd 能应答探活，会让代际 + 探活双证据同时被绕过而输出假成功；防 PID 复用用 `/proc/<pid>/stat` starttime（剥 `(comm)` 用贪婪 `##*) `，comm 内含 `") "` 时非贪婪会让 starttime 错位成 0）。
  - **权限收尾拆成两段**：主权限修正（`_finalize_install_permissions`：全树 chown + storage/backups/`.env` 位）前移到 reload 之前，避免新 master/worker 在文件树仍变动时加载代码；但其后的 `upgrade:unfreeze` / `up` / `queue:restart` 仍以 root 运行并会在 storage 下**新建** root 属主文件（file 缓存驱动的 `framework/cache/data/xx/yy` 二级目录 0755 会让 www 之后无法在其中写入，daily 日志跨日同理），故 `check_queue_worker_status` 之后再补一次**只覆盖 storage 与 bootstrap/cache** 的窄范围 chown。
  - 等待期间每 2 秒输出旧 worker 剩余数、当前 worker 数、master 与健康入口状态，30 秒仅作故障上限。原本无 worker 的 ondemand `0/0` 空闲态无法直接比较代际：upgrade.sh 按安装目录反查本站 vhost/普通域名，`bt_reload_php_fpm` **在发送 reload 前**通过 `curl --resolve <domain>:443|80:127.0.0.1` 请求维护态放行的 `/api/health`（HTTP 200/503 均可）生成并记录旧 worker 身份，再发送 reload。既建不起代际基线、master 也未换代时不宣告成功：等满 `BT_PHP_FPM_WAIT_TIMEOUT` 后如实告警交人工（非阻断，upgrade.sh 只 `log_warning`）。非宝塔 PHP 路径跳过
- **OPcache 清理统一走 `App\Support\Opcache::reset()`**：换代码后必须清，否则 `opcache.validate_timestamps=0` 的机器继续跑旧字节码。三种"没清成"语义不同，不能一律当失败：①扩展未加载 → skipped；②当前 SAPI 未启用（CLI 下 `opcache.enable_cli` 默认 0，这是命令行常态）→ 含义是"本来就没缓存可清"；裸调时它与真失败一样表现为 `opcache_reset()` 返回 false，故 `Opcache` 先用 `opcache_get_status()` 探测再决定是否 reset，把它归为 skipped 而非 failed；③配了 `opcache.restrict_api` 且调用脚本路径不匹配 → PHP 发 `E_WARNING`，Laravel 引导后 `error_reporting = -1`，`HandleExceptions` 会转成 `ErrorException`——**裸调会中断升级**（实测容器内带完整 bootstrap 复现），故由 `Opcache::reset()` 就地接住并返回结构化结果，`UpgradeService` 只记账不阻断。**命令行进程只能清自己的 OPcache，够不到 PHP-FPM 常驻进程**：`cache:clear-all` 在 CLI 下即使 reset 成功也必须提示"FPM 不受影响"，否则是假成功信号；线上真正换掉字节码需由部署/升级流程重载 PHP-FPM。
- **fatal 兜底**：`UpgradeRunCommand::handle()` 注册 `register_shutdown_function` → `handleFatalShutdown`（静态、注入 `error_get_last()`，便于直测），捕获 `E_ERROR / E_PARSE` 等 fatal：双守卫（非 fatal / 非 running 早退）后 `unfreeze` → `artisan up` → `fail`（序契约见「freeze 接入」节；fail 放最后让 up 二次 fatal 时 status 留 running 交 watchdog 接管），避免卡 running 死锁 + freeze 滞留
- **classmap 自愈**：新包的 autoload 在构建时优化生成；仅历史不带 vendor 的兼容路径会跑 `dump-autoload --optimize --no-scripts`。
- **composer 触发收口 `_need_composer_install`**：依赖变化判定统一走此函数，判据「`vendor/autoload.php` 缺失 ∨ `NEED_COMPOSER_FORCE=1`（入口回迁旧 vendor）∨ composer.json/lock hash 变化」任一即装。**vendor 缺失必装是砖机兜底**——中断丢 vendor 后重跑时 `backend/composer.json` 已是新版本、新旧 hash 相等会误跳过 composer → artisan fatal 自循环，runbook 的「重跑」指引失效；从新 lock 重建始终正确幂等，宁可多装一次

### 数据库结构校验

升级后自动校验数据库结构与标准 `structure.json` 是否一致。

- 升级校验只比较核心结构语义；插件等额外表属于信息项，不作为删除建议，也不阻断仅新增结构的自动修复。
- InnoDB 外键的 `RESTRICT` / `NO ACTION` 仅在比较时按等价规则处理；备份恢复、回滚补偿及持久外键计划保留来源规则，不将归一化值写回数据库。MySQL 5.7 的 INPLACE ADD 仍可能由引擎将 `RESTRICT` 规范化为 `NO ACTION`，验证须同时检查发出的 SQL 和同版本直接 DDL 基线，不要求元数据文本跨版本一致。`db:structure --check` 和 `--fix` 的手动提示均显示已有外键修改的当前定义与标准定义。
- 备份侧 `<backup>.schema.json` 使用独立的恢复比较入口；备份显式记录字符集、生成列表达式时才比较这些扩展元数据，旧备份缺少字段时保持可恢复。

#### 平台设置升级顺序

- migration 只负责表结构（如扩展 `settings.type` 枚举）；设置项补齐、类型整理和旧 `platform-config.json` 导入统一由 `SettingSeeder` 幂等处理。
- 两条自动升级路径均按 `migrate --force` → `db:seed --force` 执行；Seeder 失败必须中止升级，不得吞错。
- `storage/app/legacy-platform-config` 只能在 Seeder 成功后删除；关闭 `auto_seed` 或 Seeder 失败时保留，供修复后重跑。

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
