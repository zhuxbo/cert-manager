# 完成检查 — Manager

目标是用与改动风险相称的证据确认可以交付。默认快检；先读实际 diff，再确定检查范围。命令清单是按条件选择的入口，不要求从头到尾全部执行。

> 范围：MySQL + 宝塔部署。本地检查不等于真实发布；发布仍遵守 `skills/remote-release.md`。

## 模式选择与快检入口

| 模式         | 触发条件                                                                                                                                                    | 完成要求                                                                  |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| 快检（默认） | 文档、文案、样式、局部前端逻辑、普通后端修复，且影响面能闭合                                                                                                | 相关代码审查 + 下表适用检查；不要求 freeze/账本、独立 reviewer 或全量测试 |
| 完整检查     | 资金、安全边界、迁移/SQL 兼容、事务锁/异步状态机、安装升级/打包部署、跨模块或外部 API 契约、公共运行/测试基础设施或依赖变化；或用户明确要求完整检查/模拟 CI | 按 §0–§8 执行受影响领域的注册 gate 和独立审核；仍不跑无关领域             |

以行为变化判定风险，不按文件数或目录名直接升级。未触发核心 mutation 的敏感文件纯注释/格式修正可快检；`derive-scope.sh` 输出 `MUTATION_REQUIRED=yes` 时保留既有核心目标门禁，进入完整检查。发现影响面无法闭合时先查直接调用链，仍无法闭合再升级并说明具体原因。用户说“快检”也不能豁免已确认的高风险验证。

开始时简述“模式、改动范围、将执行的检查”即可，不需要向用户确认常规选项或生成计划文档。任务已有无关脏改动时先区分本次范围；没有明确范围时检查全部未提交改动，不擅自清理或暂存他人的文件。

```bash
git status --short
python3 skills/scripts/finish-check-files.py list
git diff HEAD
git diff --cached
```

同时阅读属于本次任务的未跟踪文件；删除、暂存和未跟踪文件都计入范围。已提交变更用明确的 base/ref；工作区为空时不要把空 diff 当检查通过，也不要自行猜测检查最近一次提交。快检范围清晰时无需再跑 `derive-scope.sh`；涉及后端核心目标或完整检查时运行该脚本。

### 快检按影响选择

| 实际改动                         | 必要验证                                                                                                                           |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Markdown / JSON / Python / Shell | `python3 skills/scripts/finish-check-files.py check` 检查变更文件；脚本行为变化补对应现有测试                                      |
| AGENTS / skill / 薄入口          | 上述文件检查 + `make check-agent-config`；变更 review/finish-check 引用时补 staleness；门禁/范围脚本变化补对应脚本测试             |
| 单端文案 / 样式                  | 改动文件的 ESLint（适用类型）、Prettier、Stylelint（有样式时）；布局改变时检查页面效果；无需为文案新写测试或默认双端构建           |
| 前端逻辑 / 组件 / 类型 / 导入    | 改动文件 lint + 对应现有测试 + 受影响端 `pnpm build:admin` 或 `pnpm build:user`；shared 实现影响双端时构建双端，选相关 shared 测试 |
| 普通后端实现 / 校验              | Docker 内对改动 PHP 文件跑 Pint、PHPStan，并运行直接覆盖变化行为的测试；公共依赖或调用链影响不清时扩大分析/测试范围                |
| Controller 测试 / API 快照       | compare 受影响端点的全部测试（含不同目录的同端点测试）；仅断言调整不自动全量 capture；schema 契约变化按完整检查处理                |
| 单个插件内部改动                 | 仅该插件的相关测试/前端构建；共享加载、迁移、安装卸载或打包契约变化进入完整检查                                                    |

命令示例中的路径是占位，执行前从实际文件清单选取；PHP 路径相对容器的 `backend/`，前端路径相对所选 workspace：

```bash
docker compose exec -T app ./vendor/bin/pint --test app/...php tests/...php
docker compose exec -T app ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G app/...php
docker compose exec -T -e DB_DATABASE=ssl_manager_test app php artisan test tests/...Test.php
# 涉及 Controller 测试时，对受影响端点的全部测试 compare
docker compose exec -T -e DB_DATABASE=ssl_manager_test -e COMPAT_COMPARE=true app php artisan test tests/...Test.php
pnpm --dir frontend/admin exec eslint --max-warnings 0 src/...vue
pnpm --dir frontend/admin exec prettier --check src/...vue
pnpm --dir frontend/admin exec stylelint src/...vue
```

快检同样遵守资源边界：共享 `ssl_manager_test` 的测试串行执行；会重建 Laravel cache 的命令不与后端测试并行，格式修复先于测试/构建。

局部 PHPStan 同时纳入受影响的调用方；修改方法签名、公共基类、容器绑定或配置时使用全量分析。选择测试要覆盖行为变化及直接调用链；缺少有效用例时补一个能暴露问题的回归测试，不补只照抄实现的断言。测试返回 0 还需确认实际执行了所选用例，不能接受空测试或跳过全部用例。

快检结束前执行 `git diff --check` 和 `git diff --cached --check`，重新核对实际 diff。检查后又修改时，重跑受该修改影响的检查；已覆盖且输入未变的检查不重复跑。已知失败不能靠缩小范围掩盖。按 §6 同步真正受影响的文档，再简述模式、范围、实际结果及未解决问题；快检到此结束，不进入后面的完整流程，不生成 `REVIEW_PASS:` 或声称全量 CI 已通过。

---

## 0. 完整检查执行协议：先稳定源码，再并行只读门禁

finish-check 的并行单位是**有明确输入和资源锁的只读门禁**，不是任意命令。所有会写源码的动作（ESLint/Prettier/Stylelint `--fix`/`--write`、shfmt `-w`、Pint 修复）必须在冻结前串行完成；冻结后只能跑 check/test/build/package。任何命令改变源码，执行器会把该结果标记为 stale，退出码 86。

本流程是日常完成检查，不等于在本地重跑全部 CI 矩阵。后端优先使用仓库 Docker：默认环境跑主测试，命中 §2.4 时补 MySQL 5.7；当前 CI 另覆盖 PHP 8.3/8.4 × MySQL 5.7 与 PHP 8.4/8.5 × MySQL 8.4，矩阵以 `.github/workflows/ci.yml` 为准。涉及 PHP 版本语法或扩展兼容时补相应环境验证，并明确本地实际覆盖组合。

**执行顺序**：先按 §1 确定必跑 gate，再冻结；先完成适用的 `script-checks`、`pint`、`phpstan`、`frontend-lint`、`agent-guards`，失败即修复并重新冻结，避免耗时测试排完后才发现格式或静态错误。随后启动测试、构建与打包；无共享资源的 gate 可并行，`package-invariants` 必须在本轮 `main-package` 成功后运行。Reviewer 可以提前阅读已冻结源码，与耗时门禁重叠；签字前必须核验相关门禁和当前 fingerprint。

冻结覆盖未跟踪非忽略文件，不要求为检查提前暂存；已有暂存区保持原状。若任务本身需要暂存，放在首次冻结前完成。建立本次运行目录并冻结源码：

```bash
FINISH_RUN=".superpowers/finish-check-runs/$(date +%Y%m%d-%H%M)-<简短主题>"
python3 skills/scripts/finish-check-exec.py freeze --run-dir "$FINISH_RUN"
```

冻结 fingerprint 覆盖 HEAD、暂存区、工作树、文件模式以及未跟踪非忽略文件。后续机械门禁统一通过执行器运行，示例：

```bash
python3 skills/scripts/finish-check-exec.py run \
  --run-dir "$FINISH_RUN" --gate make-test
```

受版本控制的 `skills/finish-check-gates.json` 绑定 gate 名、规范命令、最低资源锁和允许传入的环境变量。注册 gate 必须用 `--gate` 执行；`--name ... -- <command>` 用于附加验证及临时诊断记账，不能满足最终 `verify`，也不能冒充同名注册 gate。

执行器为每条命令保存起止时间、退出码、锁等待时长、实际执行时长、起止 fingerprint 和完整日志到运行目录。账本只保存脱敏命令模板、命令 hash、环境变量名及键值 hash，不保存环境变量值；自定义 `sh/bash -c` 的整段 shell payload 一律不展示。运行目录为 0700，状态、账本和日志为 0600。注册 gate 会清除 manifest 受控变量及所有 `MUTATE_*` / `MUTATION_*` 的宿主环境继承，仅接受显式 `--env`；命令等待锁后会重新校验 fingerprint，执行期间 fingerprint 漂移，即使原命令退出 0 也不能作为证据。

资源规则：

| 资源锁                      | 使用范围                                                                                   |
| --------------------------- | ------------------------------------------------------------------------------------------ |
| `source`                    | 执行器自动加共享锁；源码写入只允许发生在 freeze 前                                         |
| `backend-runtime:exclusive` | Laravel 测试、PHPStan、mutation、会 boot/rebuild Laravel cache 的插件后端命令              |
| `db:exclusive`              | `make test`、snapshot、插件数据库测试、MySQL 5.7、mutation、reviewer 数据库/运行时失败场景 |
| `frontend-root:exclusive`   | 根 workspace 的双端 build；shared/source tests 可不加此锁                                  |
| `plugin-<name>:exclusive`   | 同一插件的 install/build/package，防共享 `node_modules`/`dist`/输出 zip 竞争               |
| `package:exclusive`         | 主程序 collect/package；不得与另一个主程序打包并行                                         |

mutation gate 由 `skills/scripts/mutation-shards.py` 按精确 PHP 文件编排；缓存未命中的分片再通过 `skills/scripts/run-isolated-mutation.sh` 把冻结后端源码和插件源码复制到 `.superpowers/mutation-workspaces/`，随后一次性物化到 Docker 原生 volume 并在只读根文件系统的具名容器中运行。正式 mutation 不再从 macOS bind mount 高频读取源码/vendor；Pest、Laravel cache/storage 等高频临时写路径使用有大小上限的 `tmpfs`，显式分片缓存仍按需绑定项目内受控目录。插件安装/回滚、mutant 与测试产物只落隔离 volume/tmpfs。取消时必须删除并确认 PHP/MySQL/seed 容器及两个原生 volume 均已退出或移除，之后才释放 runtime/DB 锁。reviewer 可以把**源码阅读**与 mutation 重叠；Pint/PHPStan/Artisan/失败场景仍须按上表等待对应锁。

mutation 每次同时启动 `mutation-mysql` 一次性 MySQL 8.4，数据目录挂在 1 GiB `tmpfs`；正常测试实占应明显低于上限，若容量耗尽则门禁失败并调查异常数据膨胀，不通过扩大上限掩盖。该服务属于 Compose `tools` profile，日常 `docker compose up` 不会启动，也不会读写开发库。为减少反复建库/迁移的刷盘成本，该实例禁用 binlog/doublewrite 并使用 `innodb_flush_log_at_trx_commit=2`；InnoDB、事务、外键和唯一索引语义保持开启。包装脚本必须等待数据库就绪，且无论成功、失败或中断都同时删除 PHP 和 MySQL 临时容器。

每个分片同时绑定唯一 FQCN 和 `--path`，并要求输出恰好 1 个文件；不能再用 `Action` 等短类名的前缀匹配结果充当范围证据。完整核心 mutation 只在 main 正式发布前强制运行；开发机器不设置定时或夜间任务。普通 finish-check 由 `derive-scope.sh` 确定性判定：改动 6 个默认核心类时自动加入对应类，plan 的其他目标必须通过可重复的 `--mutation-target-class <FQCN>` 显式声明。脚本输出 `MUTATION_REQUIRED`、最终目标以及可直接执行的 run/verify 参数。

例如 plan 要求额外检查 `ActionFileTrait`：

```bash
bash skills/scripts/derive-scope.sh \
  --mutation-target-class 'App\Services\Order\Traits\ActionFileTrait'
```

普通按需 mutation 只运行输出的 `MUTATION_EFFECTIVE_TARGETS`，不展开无关默认类：

```bash
python3 skills/scripts/finish-check-exec.py run \
  --run-dir "$FINISH_RUN" --gate mutation \
  --env 'MUTATE_TARGET_CLASSES=App\Services\Order\Traits\ActionFileTrait'
```

未触发 mutation 的普通改动不运行它；触发后必须等实际 PASS，不能用 dry-run 代替。main 正式发布使用不带 `MUTATE_TARGET_CLASSES` 的 mutation gate，因此固定运行 `skills/mutation-shards.json` 中全部 6 个核心分片；`build/release.sh` 会在创建 tag 前校验当前 main fingerprint 的完整 mutation 证据，缺失或失效即拒绝发布。

完整分片证据缓存位于忽略目录 `.superpowers/mutation-shard-cache/v1/`，每片 JSON 为 0600。只有退出成功、`pending=0`、`timeout=0`、计数闭合且文件数为 1 的结果才可缓存。目标文件或声明依赖变化只失效相关分片；共享后端/插件代码、配置、依赖锁或 mutation 工具变化全部失效。每片在 `skills/mutation-shards.json` 显式声明相关测试模式，公共测试入口和辅助文件始终纳入所有分片指纹；正式 miss 只执行该指纹中 `Feature/`、`Unit/` 下的精确测试文件，使实际执行范围与缓存证据范围闭合。既有相关测试修改/删除只失效命中的分片，其他测试文件的内容变化不再导致无关分片重跑。新增跨模块测试时必须把该文件模式登记到所有被覆盖分片；未登记测试既不参与该片执行，也不能用于其正式 MSI。损坏、缺字段或含 timeout 的缓存一律按 miss 处理。最终 MSI 使用所有选中分片的 `tested / generated` 加权汇总，禁止把 timeout 算作击杀或平均各片百分比。缓存只用于 gate 内部加速，最终 PASS 仍必须由当前 generation/fingerprint 的 gate 汇总产生。

survivor 默认全部计入未杀死，不自动猜测“等价变异”。处理顺序固定为：先补能证明行为差异的测试；若变异暴露的是重复实现，删除冗余代码；只有在输入类型、状态不变量或不可达条件已有独立证据时，才允许用精确 `// @pest-mutate-ignore: <Mutator>` 排除单个 mutator，并在相邻注释写明等价理由。禁止整行无类型 ignore、按分数批量豁免或把未分析 survivor 从汇总分母移除。

开发期可用 `mutation-shards.py probe --shard <id> --test-path backend/tests/...Test.php` 限定覆盖基线和生成范围。该入口固定输出 `PROBE_ONLY_PARTIAL`，只用于快速确认某个测试文件能否杀死相关 mutation；局部分数和 mutant 数不得进入正式账本、分片缓存或发布验收。局部基线可能让 Pest 推导出过短的 mutant 超时预算，因此 probe 只要出现 `timeout` 就以 `PROBE_ONLY_INVALID` 失败，不能接受 timeout 堆出的假 100%。正式 gate 始终省略 `--test-path`，继续执行完整分片。

若 reviewer 发现问题并修改源码：

1. 当前 generation 的 build/package/mutation/test/review 证据全部保留在账本中，但不再有效；
2. 修复与格式化完成后重新执行 `freeze`，generation 自动递增；
3. 重新推导范围并重跑该范围全部必需的注册 gate。当前执行器不支持跨 generation 复用 gate PASS；只有 mutation 内部按依赖指纹复用分片缓存。不得用“文件看起来无关”引用旧代证据；打包依赖全部发布源；
4. 声明完成前用 `verify` 明确列出本次范围要求的全部 gate 名，旧 generation 的 PASS 不会被接受：

```bash
python3 skills/scripts/finish-check-exec.py verify \
  --run-dir "$FINISH_RUN" \
  --require make-test \
  --require phpstan \
  --require frontend-build \
  --require mutation \
  --expect-env 'mutation:MUTATE_TARGET_CLASSES=App\Services\Order\Traits\ActionFileTrait'
```

凡 gate 通过 `--env` 显式传值，最终 `verify` 必须逐项提供同值 `--expect-env`；未声明预期的 gate 只接受无显式环境变量的 PASS，防止用不同 mutation 目标或网络策略的结果冒充本轮证据。

`derive-scope.sh` 提供路径、关键词和 mutation 目标事实；本 skill 决定模式及适用 gate。执行器只保证调度、隔离和证据属于同一源码状态，不替代语义范围判断。

---

## 1. 确定变更范围

```bash
git status --short
git diff --stat
git diff --cached --stat
bash skills/scripts/derive-scope.sh   # 读取命中项，核对真实行为
```

确认本次改动涉及的目录（backend / frontend/admin / frontend/user / frontend/shared / plugins / deploy / build / .github）和敏感路径（migrations / 资金路径 / 索引 / 部署脚本 / 打包与 CI）。变更范围决定后面要重点跑哪些测试。

**范围证据**：保留脚本结果和必要的命中说明，最终报告只摘要适用 gate 与结果，不粘贴整张范围表。

范围推导和文件格式检查共用 `skills/scripts/finish-check-files.py`：默认合并 HEAD diff、暂存 diff 和未跟踪未忽略文件；删除项参与范围判断，格式检查跳过已不存在的文件。显式 `--base` 保持对应 Git diff 范围，不把未跟踪文件混入历史比较。临时诊断可用 `list --null` 安全取得带空格的文件名；禁止另外用不完整的 `git diff --name-only` 判断文档降级。

**已提交 / 聚合变更**：将本次比较基准解析为固定 commit，保存在 `FINISH_BASE`；diff、文件清单和范围推导都使用同一个基准。注册 `script-checks` 只检查未提交文件，干净工作区的 PASS 不能证明聚合文件已检查；额外执行以下检查，并让 reviewer 核对当前代日志、实际 base 与文件覆盖。该具名命令是附加证据，不冒充注册 gate。快检的已提交范围直接运行对应 `check --base`，无需创建执行器运行目录。

```bash
bash skills/scripts/derive-scope.sh --base "$FINISH_BASE"
python3 skills/scripts/finish-check-exec.py run \
  --run-dir "$FINISH_RUN" --name scoped-script-checks -- \
  python3 skills/scripts/finish-check-files.py check --base "$FINISH_BASE"
```

路径/关键词命中是候选范围，结合新增和删除代码核对实际语义。注释、示例、同名变量或未改变的行为可排除，记录一句依据；脚本未命中但实际涉及安全、资金、并发等行为时必须补入。残差路径按领域归类，不要求为每个文档单独填写一行“否”。

| 实际影响                                   | 完整检查 gate / 专项                                                                                                |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| 后端实现、依赖、公共测试入口               | `pint`、`phpstan`、`make-test`；§2.5–§2.7 只看本次涉及条目                                                          |
| 资金 / 迁移 / 原生 SQL / 连接时区          | 上述后端 gate + `mysql57`；资金补 §2.6，结构变化实跑迁移及结构验证                                                  |
| API schema、Controller 测试、snapshot 机制 | `compat-snapshot`；缺失/差异先定位并定向 capture，禁止为放行滥加 `expectsBreakingChange`                            |
| 前端实现、共享配置或依赖                   | `frontend-lint`、`frontend-build`；shared 行为变化加 `frontend-tests`                                               |
| 插件后端共享契约 / 生命周期                | `plugin-backend-tests`；仅插件内部变化按快检入口处理                                                                |
| 安装升级 / 部署                            | `deploy-tests`，涉及后端时加后端 gate；验证失败回滚及生产入口                                                       |
| 打包清单、产物加载或 CI 构建链             | `main-package` 后运行 `package-invariants`；受影响插件跑对应 package gate；只有 CI 文案/非打包 job 变化时不强制打包 |
| 范围/执行器/门禁编排                       | `executor-tests`；只改说明文字时做配置/引用检查和场景核对                                                           |
| 适用文件格式                               | `script-checks`                                                                                                     |
| 完整后端或跨领域检查                       | `agent-guards`；纯前端、文档、工具检查用 §6 对应单项，避免拉起无关 PHP 环境                                         |
| `MUTATION_REQUIRED=yes`                    | `mutation`，目标和 `--expect-env` 使用脚本输出                                                                      |

ACME / Order / 通知等测试已被本轮全量测试覆盖时不再重复；鉴权绕过、并发、串行快照、特定环境等未被覆盖的场景仍需验证。删除审核见 §1.5，安全验证见 §7。用户明确要求“模拟 CI”时对照当前 CI 声明实际覆盖组合，不能将一个 Docker 环境称为全部矩阵。

---

## 1.5 删除审核（仅当本次改动删除了类 / 配置 / 命令 / 表 / 字段 / 函数时执行）

**Scope**：本节延续 §1 的 working tree + 暂存区。**关键词清单直接来自 `git diff` / `git diff --cached` 里的 `-` 行** —— 删了什么就 grep 什么，不固化、不内置。

**背景**：单元测试只能证明"剩余功能还在"，**证明不了"残留物没了"**。删除/收窄场景必须额外做反向断言，常见无声残留：

- `config(['database.connections.X.foo' => ...])` — Laravel 写不存在键不报错
- `match ($driver) { 'mysql' => ..., default => throw }` — 永远命中 mysql 分支，default 死代码无人触发
- 死注释 / 死字面量（"// 兼容 Docker 路径..."）— lint / phpstan / 测试都不读
- 死断言（`expect($toTz)->not->toBeNull()`）— 死代码 + 死测试互相自洽，反而绿灯
- CLI / 安装脚本的非默认路径（`-y` 模式 / 复用站点）— 交互 e2e 不覆盖

**清单**：

1. 从 `git diff` / `git diff --cached` 的 `-` 行里挖出本次删除的概念（类名 / 配置键 / 命令 / 字符串字面量 / 文件 / 表 / 字段）
2. 对被删除概念查实际引用（字面量用 `git grep -F`，正则用 `-E`），确认是否为有效残留：

   ```bash
   git grep -nF '已删类名'
   git grep -nE 'database\.connections\.(已删连接)' -- '*.php'
   git grep -nF '已删命令名' -- '*.php' '*.sh' '*.md'
   ```

3. 检查 `tests/` 是否还有引用已删概念的断言/夹具
4. 检查 `*.md` / 行内注释是否还有误导（README / skills / 代码注释字面量）
5. 复杂场景（删除概念跨多目录、需要按文件类型分批 / 用豁免列表过滤特例）：临时反向断言脚本写在 `.superpowers/`（已 gitignore），用完即弃，不入库

**证据格式要求**（防止"声称做了"）：

- 保留对应命令和实际结果；最终报告摘要删除项及残留结论，有问题时引用具体命中
- 输出格式约定（避免上下文爆炸）：
  - 命中 0 条 → 贴 `` `<command>` → 0 命中 `` 一行即可
  - 命中 1-5 条 → 贴完整 stdout
  - 命中 > 5 条 → 贴前 5 行 + 最后一行 `... 共 N 命中`
- 主智能体不得仅声称"已 grep 全部通过"而不贴输出

**判定通过**：清单 1~4 全部 grep 0 命中；命中只剩"故意保留的反向断言/兼容拒绝/工具链文件"等明确豁免。

**常见漏删模式**：

- 删了 `config/X.php` 的连接定义，没删 `AppServiceProvider` 往该连接注入的代码
- 删了 Command 类，没删 README/skills 里的 `php artisan X` 示例
- 删了表 / 字段，没删 Model `$fillable` / `$casts` / Observer 引用
- 删了路由，没删前端 API call

---

## 2. 后端检查

> 目录：`backend/`

### 2.1 代码格式化

```bash
docker compose exec -T app ./vendor/bin/pint --test
```

有问题则在 freeze 前通过 Docker 对改动文件运行 Pint 修复。

### 2.2 PHPStan 静态分析

```bash
docker compose exec -T app ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G
```

**0 errors 才算通过**。本次 diff 引入的告警必须修；历史遗留只在阻碍当前验收时处理，其他问题记录，不扩展本轮改动。

### 2.3 测试 — 本地 mysql paratest

```bash
make test
```

> `make test` 显式指定隔离测试库，本地不裸跑宿主 PHP；MySQL 偶发 "server has gone away" / "Connection refused"（资源压力间歇性闪断 / paratest 连接占满）时，**最多重跑 2 次**。第 3 次仍失败 → 当真实回归处理，必须排查根因，禁止"重试到通过"。
>
> **重跑只赦免基础设施闪断，不赦免测试本身的不确定性**：若失败与断言/数据相关（非连接闪断），是 flaky bug，按 `review-checklist.md` 反模式 14 排查根因（faker 随机数据撞校验 / paratest 共享 storage 跨 worker 误删 / `time()` 时钟不可控 / tearDown 吞 rollback / Pest skip eager 求值），禁止靠重跑掩盖。新增或改测试时主动收敛这四类不确定源，并防伪绿（反模式 15：只断 `assertOk`、安全 provider 方向反置、`markTestSkipped` 吞 bug）。

改特定模块时优先跑对应测试，用于开发期快速反馈、失败定位、plan 明确验收和 reviewer 修复后的增量复验：

- Models：`tests/Unit/Models/`
- ACME 单元：`tests/Unit/Services/Acme/`
- ACME 控制器：`tests/Feature/Http/Controllers/Admin/AcmeControllerTest.php`、`tests/Feature/Http/Controllers/User/AcmeControllerTest.php`
- Order：`tests/Unit/Services/Order/`（含 Api/Traits/Utils）+ `tests/Feature/Services/Order/`（sync 并发/退费等）+ 三端控制器 `tests/Feature/Http/Controllers/{Admin,User,Deploy}/OrderControllerTest.php`
- Deploy 控制器：`tests/Feature/Http/Controllers/Deploy/`
- 资金路径：`tests/Feature/FundAudit/`、`tests/Feature/Database/FundTransactionUniqueIndexesTest.php`
- 通知模板渲染：`php artisan test --filter=Notification`（覆盖 Builders / 模板渲染 / NotificationJob / NotificationCenter）

完整检查的后端机械门禁以全量 `make test` 为判据；快检使用前述定向入口。已经执行全量且源码 fingerprint 未变化时，不在最终阶段为了“再确认”重复运行被其完整覆盖的同源定向测试；但 plan 明确要求的特殊环境、OpenSSL/外部协议验收、真实绕过请求、串行/并行差异场景不属于可删除的重复项。全量失败后再跑定向测试定位。

### 2.4 测试 — mysql 5.7 容器（资金或数据库行为变化时）

**何时触发**：实际修改以下行为时执行，路径内纯说明/格式变化不触发：

- Fund / Transaction / 资金记账行为
- 数据库迁移或结构
- 原生 SQL（`DB::raw`/`whereRaw`/`DB::statement`）
- AppServiceProvider 连接/时区注入

> 本地 MySQL 一般是 8.x，跑过不等于 5.7 兼容；CI 同时覆盖 5.7 与 8.4，本地按本节条件验证 5.7。

**固定入口**（复用 Compose app 容器，在同一 Docker 网络启动隔离的 MySQL 5.7；固定测试库
`ssl_manager_test` 与 5.7 兼容 collation，自动等待、迁移、测试和清理）：

```bash
make test-mysql57
```

> M 系列 Mac 使用 `linux/amd64`（MySQL 5.7 无 arm64 manifest）；qemu 模拟下首次启动通常需
> 30~60 秒，脚本给 120 秒就绪余量。Compose app 未运行时先执行 `make up`。
> 脚本使用唯一容器名，并通过 EXIT trap 自动清理；迁移或测试失败时保留原退出码。

### 2.5 Laravel 专项检查

> 详见 [skills/backend/](backend/)（core Laravel 架构、database 迁移规范、auto-renew 自动续费等）+ [skills/backend/acme-module.md](backend/acme-module.md)（ACME 三步流程）

- [ ] 迁移幂等（`Schema::hasColumn`/`Schema::hasTable`/索引存在性 守卫），不写 down
- [ ] Model 的 `$fillable`、`$casts`、`$hidden` 是否需要更新
- [ ] Action 无 userId 构造参数（用户隔离由 UserScope 保证）
- [ ] 控制器只做请求验证 + 一行调用 Action
- [ ] ACME 三步流程（new → pay → commit）状态流转完整
- [ ] Sdk 通过 `ca.acme_url`/`ca.acme_token`（回落 `ca.url`/`ca.token`）调 Gateway
- [ ] Transaction 类型正确（`acme_order`/`acme_cancel` 等），一对一防重豁免列表收紧到 `['order']`
- [ ] 队列 Job 在测试环境同步执行（`QUEUE_CONNECTION=sync`）；`TaskJob::dispatch` 一律 `->afterCommit()`，且异步 Job 显式 `->onQueue()` 到 tasks/notifications 之一（生产 worker 不监听 default）——机器验证见 `skills/scripts/finish-check-greps.sh`
- [ ] Observer / Model boot 钩子改动是否影响已有事件链（特别是 `Fund::updating` / `Transaction::creating`）

### 2.6 资金路径专项（涉及 funds/transactions/users.balance 时）

> 详见 [skills/backend/order-fund.md](backend/order-fund.md) "资金确定性体系（4 道网）" 章节

- [ ] 状态转换走 CAS UPDATE（`Fund::transitionToSuccessful`），CAS WHERE 必须完整字段匹配（不能简化为单一 status）
- [ ] 写 transaction 不依赖应用层 `exists` 防重 — 靠 DB 唯一索引兜底
- [ ] 修改 `user.balance` 必须在 `DB::transaction(fn)` 内 + 同事务内创建对应 transaction
- [ ] 删除已入账 fund 路径必须事务内 `lockForUpdate` + 锁内 status/created_at 二次校验
- [ ] 新增"资金相关"测试必须登记 `fundAuditGuardedTestPaths()` 或 `fundAuditGuardExcludedTestPaths()`（元测试 `tests/Unit/FundAuditGuardCoverageTest.php` 强制兜底）
- [ ] 上线前先跑 `php artisan finance:audit`（不带 `--freeze-on-violation`，仅对账不冻结用户），确认现有数据干净再加新约束/索引

**证据格式要求**（防止"声称做了"）：

- 涉及资金路径的 PR 必须在 finish-check 总结中贴出 `php artisan finance:audit` 的 stdout（**绝不带 `--freeze-on-violation`**；无违反时贴实际输出行"资金审计校验通过"；有违反 ≤ 5 条贴完整列表，>5 条按 §1.5 截断规则贴前 5 行 + "... 共 N 命中"）。注意：该命令发现违反也返回 0（设计如此，告警走邮件），**退出码不能作为数据干净的证据，判定以 stdout 文案为准**
- 涉及新增资金测试时贴出 `php artisan test --filter=FundAuditGuardCoverage` 的退出码（确认测试登记到 guarded/excluded 列表）

### 2.7 PHP 8.3 规范

> 详见 `skills/backend/core.md` 的 PHP 规范。

- [ ] 双引号变量不加大括号（`"$var"` 而非 `"{$var}"`）
- [ ] 例外：变量后紧跟中文等非 ASCII 字符时必须加（`"{$var}，中文"` 而非 `"$var，中文"`）—— 不是风格问题：PHP 标识符合法字节含 `\x80-\xff`，`"$var，"` 会把全角字符首字节并进变量名，实测输出**整个值消失**（同族问题在 shell 侧更狠，见 `skills/review-checklist.md` 反模式 7 第二例：曾让硬零门禁整项静默 PASS）。shell 侧由 Z16 机器守，PHP 侧靠本条人工守
- [ ] 禁止 raw SQL：原生 SQL 通过 Eloquent / Query Builder（`DB::raw`/`whereRaw` 仅在 mysql 函数表达式时使用，如 `DATE_SUB(NOW(), INTERVAL N DAY)`）

---

## 3. 前端检查

> 目录：`frontend/`

### 3.1 完整前端检查：Lint 全量（admin + user + shared）

```bash
pnpm lint:check
```

在项目根目录运行，包含 ESLint + Prettier + Stylelint 的只读检查。失败时在源码冻结前串行执行 `pnpm lint` 修复，再重跑 `pnpm lint:check`；`pnpm lint` 内含 `--fix`/`--write`，禁止与 test/build/package 并行。

### 3.2 Markdown 格式化（本次改动的 md）

```bash
python3 skills/scripts/finish-check-files.py check --kind markdown
```

Prettier 原生支持 markdown（无需额外插件）。`.prettierrc.js` 在仓库根，`prettier` 装在 `frontend/admin/`。
检查失败时，在 freeze 前仅对本次 PR 改过的 md 跑 `--write`，再重跑 `--check`；避免顺手修历史格式问题污染 PR。

> 大重构例外：当本次 PR 涉及全仓库范围调整（如目录结构、命名约定、批量替换）时，可跑全量 prettier；改动量大本就脱离常规 PR 体量，不再适用"避免污染"约束。

### 3.3 Shell 脚本格式化（本次改动的 .sh）

先确认 `shfmt` 已安装：

```bash
command -v shfmt && shfmt --version
```

缺少工具时先完成不依赖它的检查，明确记录该项未执行；不要将缺失记作通过。需要安装系统工具时再按当前授权处理。安装命令：

- macOS：`brew install shfmt`
- 通用：`go install mvdan.cc/sh/v3/cmd/shfmt@latest`

`shfmt` 就位后跑：

```bash
python3 skills/scripts/finish-check-files.py check --kind shell
```

[`shfmt`](https://github.com/mvdan/sh) 是 shell 脚本事实标准格式化工具（Go 实现），统一缩进、对齐、case 模式空格。

- 参数：`-i 4`（4 空格缩进）、`-ci`（case 块缩进）、`-d` 仅 diff 不写入
- 检查失败时，在 freeze 前仅对本次改过的 `.sh` 跑 `-w`，避免顺手修历史不规范污染 PR
- 修复后务必 `bash -n <file>` 验证语法，再重跑 `shfmt -d`

> 大重构例外：和 prettier 同理，全仓库批改时可跑全量 `find . -name "*.sh" -not -path "./node_modules/*" -not -path "./vendor/*" -not -path "*/.husky/_/*" -not -path "./build/temp/*" | xargs shfmt -i 4 -ci -w`。

### 3.4 完整前端检查：构建验证（admin + user 双端）

```bash
pnpm build
```

确认两端都构建成功。

### 3.5 Monorepo 专项检查

- [ ] 修改 `frontend/shared/` 后同时检查 admin 和 user 两端影响
- [ ] `@shared/*` 路径别名引用正确解析
- [ ] Workspace 依赖（`workspace:*`）版本一致
- [ ] admin 和 user 各自的 API 路径前缀正确（不混用）

### 3.6 Vue 3 + Element Plus 专项

- [ ] 新增组件有 `defineOptions({ name: "XxxPage" })`（keep-alive 依赖组件名）
- [ ] `watch`/`watchEffect` 在组件卸载时清理
- [ ] `addEventListener`、`mitt.on`、定时器在 `onBeforeUnmount` 中移除
- 模板基础错误（`v-for` 唯一 `:key`、`v-if` 与 `v-for` 混用同元素等）由 ESLint vue3 essential 强制（error 级），§3.1 `pnpm lint` 即覆盖，无需人肉勾选
- [ ] 新增轮询/定时器先查 `frontend/shared` 是否已有可复用 composable；组件级轮询上提父级统一调度（反模式 22）
- [ ] Pinia store 的 state 使用函数返回
- [ ] Element Plus 组件按需引入正确

### 3.7 样式检查

- [ ] 组件样式使用 `scoped`
- [ ] 新代码深度选择器使用 `:deep()`，禁用 `/deep/`；存量布局组件的 `::v-deep`（8 处，stylelint 放行）不强制改、渐进迁移
- [ ] TailwindCSS 类名与自定义 SCSS 无冲突

### 3.8 Shared 单元测试（frontend/shared 改动时必跑）

```bash
pnpm test:shared
```

`frontend/shared/tests/` 的 node --test 用例；其中品牌清洗与后端 `PlatformConfigService` 为对称副本，共享夹具 `backend/tests/Fixtures/brand-normalize-cases.json` 锁定双端输出等价（反模式 4 配套），CI `frontend-build` job 同步执行。

---

## 4. 插件检查（如涉及 plugins/ 目录）

- [ ] `plugin.json` 必填字段：`name` / `requires`；**含 `backend/` 目录的插件**另必填 `provider`（纯前端插件如 api-docs 无 provider 是合法形态）；按需 `php_ext` / `admin_bundle` / `admin_css` / `user_bundle` / `user_css`
- [ ] 插件迁移幂等（同主系统约束）；**插件后端代码与主系统同等约束**——安全/并发/资金类反模式按内容触发（见 §1 范围表 plugins/ 行），机器检查：`git grep -nE "query\(['\"](token|api_key|secret)" -- ':(glob)plugins/*/backend/**'` 应 0 命中（凭据不进 URL query）
- [ ] Widget 插槽注册正确（如 `user-dashboard-top`）
- [ ] 插件动态加载不影响主应用启动
- [ ] `build.json` 的 `include` 数组包含所有需要打包的子目录（`backend/` / `frontend/web/` / `nginx/` 等）；发布 zip 不变量由 `plugins/release-plugin.sh` 的 `verify_zip_invariants` 硬校验（composer.json / lock / 已锁定 vendor 配对），build 与 --publish-only 两路径都过

---

## 5. Git Diff 审查

```bash
git diff
git diff --cached
git status --short | grep "^??"
```

- [ ] 没有误改的文件（`composer.lock`、`pnpm-lock.yaml` 意外变更等）
- [ ] 没有 `dd()`、`dump()`、`var_dump`、`console.log()`、`debugger` 调试残留
- [ ] 没有硬编码的 URL、密钥、Token
- [ ] 删除的代码直接删除，不注释保留
- [ ] `.env` / 配置文件没被意外修改
- [ ] 没有未使用的 `use`（PHP）或 `import`（TS/Vue）— Pint 会自动清理 PHP 端
- [ ] 新增命名符合项目风格
- [ ] **未跟踪文件（`??`）**：
- 只清理能确认由本次检查创建的临时产物；不按名称猜测归属并删除
- 本次新增源码/测试应纳入 diff 审核与交付清单；检查本身不要求 `git add`

---

## 6. 文档同步与规则检查

只有本次变更影响使用方式、外部接口、配置、架构、部署或智能体行为时，同步受影响的现有文档。项目规则写 `AGENTS.md`，领域细节写对应 skill；不为完成检查新增说明文档。

按输入选择检查，`agent-guards` 已完整覆盖的单项不重复执行：

- AGENTS / skill / 薄入口变动：`make check-agent-config`。
- review/finish-check 引用或被引用代码删除/改名：`bash skills/scripts/check-review-checklist-staleness.sh`；warning 说明影响，不为历史告警自动展开治理。
- 后端运行代码、相关部署脚本或硬零规则变化：`bash skills/scripts/finish-check-greps.sh`；FAIL 必须查明，不能直接忽略。tasks 索引/锁入口变化补 `make test ARGS="tests/Feature/Database/TaskIndexFinalStateTest.php"`，已有同输入全量覆盖时不重复。
- Controller 测试删除/重命名、快照机制变化或完整后端检查：`bash skills/scripts/check-orphan-fixtures.sh`。PHP 反射检查需可用的 Compose app；环境缺失不得记作通过。

修改孤儿 fixture 检测本身时，验证测试不存在、类文件不存在、测试名称不匹配的探针确实失败，并验证合法 fixture 通过；只调整说明时无需重造探针。

---

## 7. 已知局限性和潜在风险

只检查并报告与本次改动相关的实际风险；以下分类用于检索，不需要逐类填写“无”。

### 兼容性风险

- Manager 调 Gateway 的接口是否版本一致（Sdk 调用参数/返回值）
- 数据库迁移能否在线上平滑执行（迁移幂等？现有数据是否会让新唯一索引创建失败？）
- API 变更是否影响 admin/user 两端前端
- 插件接口变更是否影响已安装插件

### 安全风险

- 新增 API 是否有认证中间件（JWT / Token）；UserScope 是否覆盖新增查询
- 用户输入是否经 Request 类验证；敏感字段在响应中是否隐藏（`makeHidden`）
- 支付相关改动（yansongda/pay）是否安全（CAS 路径金额/方式校验完整？回调失败让支付平台重试而非吞错？）
- **免登录 / 自证端点是否挂限流**（防爆破/枚举/滥用）；账号枚举防护（去 `exists:users`、查无此人不分叉响应、限流 key 归一化大小写）—— 反模式 17
- **改密所有入口**是否同事务 bump `token_version` + 清 refresh token；凭据不进 URL（短时签名 URL）、admin 详情 `makeHidden` 他人 token；鉴权配置双空 fail-close —— 反模式 17
- **外部 URL 下载**（升级包 / 插件包）：sha256 fail-closed、SSRF 白名单制（拒 169.254 云元数据 / CGNAT）、重定向限 https、最终下载 URL 再校验；解压走 ArchiveGuard —— 反模式 18
- **敏感数据落库**：通知携密 / 安全字段走专用 Builder 不回落 Default；携密 Job `implements ShouldBeEncrypted`（jobs/failed_jobs 是第二落库面）；CORS 白名单不 reflect 任意 Origin；用户可控字节下载默认 `attachment` + `nosniff`，内联类型走白名单 + 兜底 CSP（现行：图片与 PDF inline）—— 反模式 19
- **机制可达性**：任何"声称有鉴权 / 签名 / 限流"的防御，实际发一次绕过请求验证真被拦（反模式 2 第二例 + 15，防半修假绿）。**证据格式**：总结里贴一行"发了什么绕过请求 → 响应码/是否被拦"，"已验证"式声称不算证据

### 数据风险

- 迁移是否导致数据丢失
- 批量操作有无数量限制
- 自动续费/重签（auto_renew/auto_reissue）逻辑是否受影响
- Excel 导出（phpspreadsheet）大数据量是否有内存问题
- 资金路径四道网完整（DB 唯一索引 / CAS UPDATE / 锁内二次校验 / Pest invariant 守门）

### 性能风险

- 是否引入 N+1 查询（检查 `with()` 预加载）
- 大表查询是否走索引
- 前端新依赖是否影响 bundle 体积（admin + user 分别检查）
- 通知服务（SMS/邮件）批量发送是否有限流

### 部署风险

- 是否需要跑迁移
- 是否需要 `php artisan config:clear` / `cache:clear`
- 是否需要 `php artisan db:seed --class=NotificationTemplateSeeder`（新增通知模板）
- 是否需要重启队列 worker（宝塔 Supervisor 重启队列进程，程序名为站点域名）
- 前端构建产物是否需要清除 CDN 缓存
- 插件是否需要重新发布（`plugins/release-plugin.sh`）

---

## 8. 独立 Review（完整检查或用户明确要求时）

快检由主智能体审查实际 diff 和直接影响链；完整检查使用当前工具原生子智能体做一次独立审核。Codex 使用 `collaboration.spawn_agent`，Claude Code 使用通用 Agent。环境没有子智能体时如实报告独立审核未执行，不以主智能体自签替代。

### 8.1 范围与证据

从 `skills/review-checklist.md` 的“Reviewer Subagent 任务模板”填入目标、可复跑 diff、相关文件、当前 gate 证据和已知问题。只审查本次 diff、直接调用链和受影响的对称实现；无 plan 就填“无”，不为审核创建或搜索历史计划。

完整检查报告写入 `.superpowers/reviews/<本次运行>/round-<N>.md`，记录当前 generation/fingerprint、核验的 gate 和具体发现。Reviewer 可以与耗时门禁并行阅读代码；签字前通过执行器核验当前证据。已有门禁覆盖的测试不再运行；缺少失败/绕过证据且涉及该风险时才补对应场景，不凑测试或抽查数量。

用户单独要求独立代码审核而未要求完整检查时，可直接使用该模板并说明验证范围；不因此强制重跑所有 gate。没有完整门禁证据时报告代码审核结论，不生成用于发布的 `REVIEW_PASS:` 签字。

### 8.2 发现与复验

- 必须修复：有明确路径/代码证据、影响本次正确性或安全性的缺陷。授权任务内直接修复并验证，不按 Medium 级别机械停下来问用户。
- 建议改进：不阻塞验收的优化，简要记录，不扩展本次实现。
- 无关事项：本次未引入且不影响验收的历史问题，不自动修复。

首轮覆盖完整的本次改动。修复后只复审修复及其直接影响链；如果修改扩大契约或风险，扩大相应范围。完整检查的源码变化仍按 §0 重新 freeze 并验证当前代所需 gate，不引用旧代 PASS。不能因为审核发现一个小建议就修改并重启整轮检查。

默认一轮，无阻塞即结束。确有必须修复项才进入复验，最多 5 轮；到上限仍未收敛时列出残留问题和已完成验证，输出 `REVIEW_STALLED:` 并请求用户决定后续范围。没有新修改、新失败或未闭合风险时不追加一轮“再确认”。

### 8.3 完整检查签字

报告保留 `## 证据回执` 和最后一行 `REVIEW_PASS:` / `REVIEW_FAIL:`；前者表示当前范围 gate 有效且无必须修复项，后者列明缺陷或未完成的必要验证。主智能体实跑 `grep -n '^REVIEW_PASS:' <round 文件>` 并引用文件位置，不能自写签字再验证自己。

完整检查通过后可以在获授权的 commit/PR body 中附真实签字。PR→main 的 `.github/workflows/review-pass-gate.yml` 仍要求签字：日常快检不伪造此标记，合入 main 前对聚合变更完成完整检查和独立审核。具体发布步骤见 `skills/remote-release.md`。

## 9. 结果与停止条件

报告检查模式、覆盖范围、实际命令结果、未解决问题；日志较长时链接结果，不复述全部规则。完整检查可用 `finish-check-exec.py summary --run-dir <运行目录>` 读取耗时；没有测量不承诺提速比例。

验收满足、适用检查通过且无已知阻塞后结束。仅在当前规则实际漏检、误报或失效时修正；纯提速建议留作后续，不要求每次 finish-check 自优化流程或产出复盘。`main` / `dev` 等待用户明确“提交”后才执行 commit，推送和发布另需授权。
