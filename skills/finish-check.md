# 完成检查 — Manager

提交前逐项检查。"跳过不涉及"以 §1 范围表（脚本推导 + 棘轮规则）为准，不允许主智能体自由裁量。

> 范围：仅 MySQL + 宝塔部署。

**S 档降级（仅纯文档改动）**：`git diff HEAD --name-only && git diff --cached --name-only` 合并后 `grep -vE '\.md$'` 为空 → 跳过 §2、§3（保留 §3.2 对改动 md 跑 prettier）、§4；§8 降为 1 轮且 reviewer 模板"必须实际跑"第 1-4 项免除（第 5 项改为核对文档与代码一致性）。其余一切改动维持全量流程。

---

## 1. 确定变更范围

```bash
git status --short
git diff --stat
git diff --cached --stat
bash skills/scripts/derive-scope.sh   # 机器推导范围表，贴实际输出
```

确认本次改动涉及的目录（backend / frontend/admin / frontend/user / frontend/shared / plugins / deploy / build / .github）和敏感路径（migrations / 资金路径 / 索引 / 部署脚本 / 打包与 CI）。变更范围决定后面要重点跑哪些测试。

**结构化范围产出**（后续阶段判定依据，必须在 finish-check 总结里贴 `derive-scope.sh` 实际输出）：

| 维度                                                                           | 本次涉及？ | 触发后续什么                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------------ | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| backend/app/Services/Acme                                                      | 是/否      | ACME 测试集必跑                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| backend/app/Services/Order                                                     | 是/否      | Order 测试集必跑                                                                                                                                                                                                                                                                                                                                                                                                                                |
| backend/app/Models/Fund / Transaction / 资金路径                               | 是/否      | §2.6 资金证据必贴 + §2.4 mysql 5.7 容器必跑                                                                                                                                                                                                                                                                                                                                                                                                     |
| backend/database/migrations                                                    | 是/否      | 检查 enum/索引/外键/DDL → 任一是 → §2.4 必跑；改结构后**实跑 migrate + `db:structure --check` 验证生效**、增量迁移回灌建表迁移（反模式 21）                                                                                                                                                                                                                                                                                                     |
| 原生 SQL（`DB::raw`/`whereRaw`/`DB::statement`）                               | 是/否      | §2.4 必跑                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| AppServiceProvider 连接/时区注入                                               | 是/否      | §2.4 必跑                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| frontend/shared                                                                | 是/否      | admin + user 两端构建必验 + §3.8 `pnpm test:shared` 必跑                                                                                                                                                                                                                                                                                                                                                                                        |
| plugins/                                                                       | 是/否      | §4 插件检查必跑；含 backend/ 代码时后端条件层反模式按内容同主系统触发（鉴权/锁/并发/资金）                                                                                                                                                                                                                                                                                                                                                      |
| deploy/ 升级脚本 / 后台升级目录同步                                            | 是/否      | 反模式 7/21 + 19（仅其"部署脚本外部值"条目）重点扫描（升级同步动态发现、外部值 env 传参防注入）；必跑 `for t in deploy/test/test-*.sh; do bash "$t" \|\| exit 1; done` 贴每个脚本末行输出 + 退出码                                                                                                                                                                                                                                              |
| build/ 打包脚本 / .github/workflows                                            | 是/否      | 反模式 2/21 重点扫描：改打包清单后实跑 `bash build/build.sh` 打包并对产出 zip `unzip -l <zip> \| grep <新文件>` 贴输出（旗舰案例 php-requirements.json 正是单一形态漏文件）；改 CI 后贴 `git diff .github/workflows/ \| grep -E '^[-+].*(jobs:\|if:\|name:)'` 实际输出，被删/被条件短路的 job 逐个确认为有意变更                                                                                                                                |
| tests/ 文件本身（新增/修改测试）                                               | 是/否      | §2.3 测试集 + 反模式 14（flaky 四源：faker/共享 storage/时钟/tearDown）+ 15（伪绿：`assertOk`、provider 方向反置、`markTestSkipped` 吞 bug）；diff 含 `backend/tests/Feature/Http/Controllers/` 下新增/修改测试 → 必跑 `COMPAT_COMPARE=true php artisan test <这些测试文件>`，`fixture_missing` 即按 remote-release §3.0.2 程序 `COMPAT_CAPTURE=true` 补 capture 并 `git add backend/tests/Compat/fixtures/`（shift-left：免发布期补救 commit） |
| 鉴权 / 下载 / 解压 / CORS / 通知 / 公开端点（安全面）                          | 是/否      | §7 安全风险细化 + 反模式 17/18/19；**实际发一次绕过请求**验证防御生效                                                                                                                                                                                                                                                                                                                                                                           |
| 外部命令调用（`exec`/`proc_open`/二进制探测）                                  | 是/否      | 反模式 12（BinaryLocator + 开发机/生产环境差异）                                                                                                                                                                                                                                                                                                                                                                                                |
| 节流 / 防重 / 并发事务（`Cache::add` / 锁 / TaskJob / 死锁）                   | 是/否      | 反模式 10（task→业务行锁顺序，新增路径先 grep 现有路径对齐；涉及 tasks 索引/锁入口时跑 finish-check-greps Z12/Z13/Z14 和 Task 索引最终态测试）/ 20（check-then-act 原子化、占位失败回滚、防重占位 ≠ 互斥锁、死锁不可吞 / 不可续写）                                                                                                                                                                                                             |
| catch 自定义异常后日志/落库（`ApiResponseException`）                          | 是/否      | 反模式 16（消息走 `getApiResponse()['msg']`，`getMessage()` 恒空）                                                                                                                                                                                                                                                                                                                                                                              |
| 通知模板 / NotificationTemplate / NotificationCenter                           | 是/否      | §7 部署风险加 `db:seed --class=NotificationTemplateSeeder` + 模板渲染单测                                                                                                                                                                                                                                                                                                                                                                       |
| 删除了类/配置/命令/表/字段/函数                                                | 是/否      | §1.5 删除审核必跑                                                                                                                                                                                                                                                                                                                                                                                                                               |
| **兜底行**：上述任何行都未覆盖的改动路径（`derive-scope.sh` 的"残差路径"清单） | 文件清单   | 逐个声明归入上面哪一行，或写一句"确认无触发"理由；不允许整体留空                                                                                                                                                                                                                                                                                                                                                                                |

每行"是/否"必须明确，不允许"不确定"；不确定的视为"是"。**棘轮规则**：`derive-scope.sh` 判"是"的行不得改判"否"——要降级必须附一行理由（如"关键字出现在注释/删除行"）并接受 reviewer 反向断言抽查；人工只可加判"是"（安全面/删除审核两行由脚本给提示、人工判定）。

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
2. 对每个概念跑全代码 grep（git grep 默认 fixed-string，需要 regex 时加 `-E`），命中即清：

   ```bash
   git grep -n '已删类名'
   git grep -nE 'database\.connections\.(已删连接)' -- '*.php'
   git grep -n '已删命令名' -- '*.php' '*.sh' '*.md'
   ```

3. 检查 `tests/` 是否还有引用已删概念的断言/夹具
4. 检查 `*.md` / 行内注释是否还有误导（README / skills / 代码注释字面量）
5. 复杂场景（删除概念跨多目录、需要按文件类型分批 / 用豁免列表过滤特例）：临时反向断言脚本写在 `.superpowers/`（已 gitignore），用完即弃，不入库

**证据格式要求**（防止"声称做了"）：

- 每个被 grep 的概念必须在 finish-check 总结中贴出对应命令的**实际终端输出片段**
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
cd backend && ./vendor/bin/pint --test
```

有问题则 `./vendor/bin/pint` 修复。

### 2.2 PHPStan 静态分析

```bash
cd backend && ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G
```

**0 errors 才算通过**。本次 diff 引入的告警必须修；历史遗留也建议顺手修，避免错误集累积。

### 2.3 测试 — 本地 mysql paratest

```bash
cd backend && php artisan test --parallel
```

> 默认走 `.env.testing` 的 mysql；本地 MySQL 偶发 "server has gone away" / "Connection refused"（资源压力间歇性闪断 / paratest 连接占满）时，**最多重跑 2 次**。第 3 次仍失败 → 当真实回归处理，必须排查根因，禁止"重试到通过"。
>
> **重跑只赦免基础设施闪断，不赦免测试本身的不确定性**：若失败与断言/数据相关（非连接闪断），是 flaky bug，按 `review-checklist.md` 反模式 14 排查根因（faker 随机数据撞校验 / paratest 共享 storage 跨 worker 误删 / `time()` 时钟不可控 / tearDown 吞 rollback / Pest skip eager 求值），禁止靠重跑掩盖。新增或改测试时主动收敛这四类不确定源，并防伪绿（反模式 15：只断 `assertOk`、安全 provider 方向反置、`markTestSkipped` 吞 bug）。

改特定模块时优先跑对应测试：

- Models：`tests/Unit/Models/`
- ACME 单元：`tests/Unit/Services/Acme/`
- ACME 控制器：`tests/Feature/Http/Controllers/Admin/AcmeControllerTest.php`、`tests/Feature/Http/Controllers/User/AcmeControllerTest.php`
- Order：`tests/Unit/Services/Order/`（含 Api/Traits/Utils）+ `tests/Feature/Services/Order/`（sync 并发/退费等）+ 三端控制器 `tests/Feature/Http/Controllers/{Admin,User,Deploy}/OrderControllerTest.php`
- Deploy 控制器：`tests/Feature/Http/Controllers/Deploy/`
- 资金路径：`tests/Feature/FundAudit/`、`tests/Feature/Database/FundTransactionUniqueIndexesTest.php`
- 通知模板渲染：`php artisan test --filter=Notification`（覆盖 Builders / 模板渲染 / NotificationJob / NotificationCenter）

### 2.4 测试 — mysql 5.7 容器（条件必跑：§1 范围表标"§2.4 必跑"的行任一为"是"，不允许自判跳过；均为"否"才可跳）

**何时触发**：§1 结构化范围产出中以下任一行为"是" → §2.4 必跑（不允许主智能体自判跳过）：

- backend/app/Models/Fund / Transaction / 资金路径
- backend/database/migrations
- 原生 SQL（`DB::raw`/`whereRaw`/`DB::statement`）
- AppServiceProvider 连接/时区注入

> 本地 MySQL 一般是 8.x（你本地用 8.4），跑过 ≠ 5.7 兼容（项目声明最小版本）。CI 用 mysql:5.7，本地先验避免 PR 红 CI。

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
- [ ] 例外：变量后紧跟中文等非 ASCII 字符时必须加（`"{$var}，中文"` 而非 `"$var，中文"`）
- [ ] 禁止 raw SQL：原生 SQL 通过 Eloquent / Query Builder（`DB::raw`/`whereRaw` 仅在 mysql 函数表达式时使用，如 `DATE_SUB(NOW(), INTERVAL N DAY)`）

---

## 3. 前端检查

> 目录：`frontend/`

### 3.1 Lint 全量（admin + user + shared）

```bash
pnpm lint
```

在项目根目录运行，包含 ESLint + Prettier + Stylelint。

### 3.2 Markdown 格式化（本次改动的 md）

```bash
git diff --name-only | grep "\.md$" | xargs npx --prefix frontend/admin prettier --write
```

Prettier 原生支持 markdown（无需额外插件）。`.prettierrc.js` 在仓库根，`prettier` 装在 `frontend/admin/`。
**仅对本次 PR 改过的 md 跑 `--write`**，避免顺手修历史格式问题污染 PR。

> 大重构例外：当本次 PR 涉及全仓库范围调整（如目录结构、命名约定、批量替换）时，可跑全量 prettier；改动量大本就脱离常规 PR 体量，不再适用"避免污染"约束。

### 3.3 Shell 脚本格式化（本次改动的 .sh）

先确认 `shfmt` 已安装：

```bash
command -v shfmt && shfmt --version
```

**未安装时停下来等用户授权**——不要自动跑 `brew install`/`go install`。把缺失情况告诉用户，等指示再装。安装命令：

- macOS：`brew install shfmt`
- 通用：`go install mvdan.cc/sh/v3/cmd/shfmt@latest`

`shfmt` 就位后跑：

```bash
git diff --name-only | grep "\.sh$" | xargs shfmt -i 4 -ci -w
```

[`shfmt`](https://github.com/mvdan/sh) 是 shell 脚本事实标准格式化工具（Go 实现），统一缩进、对齐、case 模式空格。

- 参数：`-i 4`（4 空格缩进）、`-ci`（case 块缩进）、`-w`（原地写入）；`-d` 仅 diff 不写入
- **仅对本次改过的 .sh 跑 `-w`**，避免顺手修历史不规范污染 PR
- 跑完务必 `bash -n <file>` 验证语法（shfmt 一般不会破坏语义，但保险起见）

> 大重构例外：和 prettier 同理，全仓库批改时可跑全量 `find . -name "*.sh" -not -path "./node_modules/*" -not -path "./vendor/*" -not -path "*/.husky/_/*" -not -path "./build/temp/*" | xargs shfmt -i 4 -ci -w`。

### 3.4 构建验证（admin + user 双端）

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
- [ ] `build.json` 的 `include` 数组包含所有需要打包的子目录（`backend/` / `frontend/web/` / `nginx/` 等）；发布 zip 不变量由 `plugins/release-plugin.sh` 的 `verify_zip_invariants` 硬校验（vendor 不进包 / composer.json 与 lock 配对），build 与 --publish-only 两路径都过

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
- 若是测试临时遗留（如 `backend/storage/databak_unit_*`）→ 直接 `rm -rf` 清理
- 若被生产代码 `require` / `use` 引用 → 必须 `git add`（grep 全代码库验证引用）

---

## 6. 文档同步

- [ ] 长期项目级规则改动 → 更新 `AGENTS.md`；领域实现细节只更新对应 skill
- [ ] 用户面功能 → 更新 `README.md`（仅用户层关心的功能特性，内部架构机制不写）
- [ ] 部署相关 → 更新 `DEPLOY.md`
- [ ] 升级回滚相关 → 更新 `UPGRADE.md`
- [ ] 模块架构改动 → 更新 `skills/*.md`（按领域）
- [ ] 跑 `make check-agent-config`，确认 `CLAUDE.md`、Claude/Codex 薄入口与权威 skill 未漂移
- [ ] 跑 `bash skills/scripts/check-review-checklist-staleness.sh && bash skills/scripts/finish-check-greps.sh`，把 warning / FAIL 项贴入 finish-check 总结的"已知局限性"段；greps 脚本 FAIL > 0 必须当场修（硬零断言，恒红会驯化走过场；其中 Z12/Z13/Z14 分别守 Task 锁入口、tasks 索引最终态快照、`Task::lockForMutation` scope 接线）；staleness warning 数 ≥ 3 → 必须列入 follow-up 维护任务（避免清单长期失真）。涉及 tasks 索引迁移时补跑：

```bash
make test ARGS="tests/Feature/Database/TaskIndexFinalStateTest.php"
```

---

## 7. 已知局限性和潜在风险

按以下分类列出风险项：

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

## 8. 独立 Review 循环（必跑，不可跳过）

**目的**：用干净上下文的独立 reviewer subagent 以"破坏模式"找毛病，避开主智能体改完测试通过就停手的天然偏差。

**质量门定义**：声明"完成"前必须满足 — **落盘的 round 文件**中存在 **以 `REVIEW_PASS:` 为前缀的签字行**（前缀后回执字段与人读说明可变，仅前缀参与 `grep -F` 验证）。`REVIEW_PASS:` 仅表示 critical/high 已清零；reviewer 可同时列出 medium/low 供用户决议，不影响签字。**签字必须有产物**：grep 的对象是 `.superpowers/reviews/` 下的落盘文件，不是主智能体自己上下文里的转述——自己写一行再 grep 自己 = 流程未执行。

### 8.1 执行结构（循环到收敛，最多 5 轮）

```
进入 §8 前：建本次运行专属目录 .superpowers/reviews/$(date +%Y%m%d-%H%M)-<简短主题>/
loop:
  ① 派 reviewer subagent（见 §8.2 调用方式），prompt 中"本轮报告写入"字段填该目录 round-<N>.md
  ② reviewer 返回后第一步：确认报告已写入 round-<N>.md（reviewer 落盘 + 主智能体核对；
     文件缺失或与返回正文不一致 → 本轮无效重派）；实跑
     grep -Fn "REVIEW_PASS:" <round 文件> 或 grep -Fn "REVIEW_FAIL:" <round 文件>，
     并确认报告含 "## 证据回执" 固定段（grep -F "证据回执"，缺失视同 REVIEW_FAIL 退回重派）
  ③ 主智能体读 reviewer 报告
     ├─ Critical / High：必须修 → 修完跳回 §2/§3（只对改动文件）→ 重新执行 §8
     │   └─ 修复是否生效由下一轮 reviewer 复验判定（"已修待复验"），主智能体的"已修"标注不构成验证
     ├─ Medium：报告给用户决议（当场修 / follow-up issue / 接受）—— 主智能体不擅自处理
     │   └─ 用户选"当场修"：视同 Critical/High 处理（修完跳回 §2/§3 → 重新执行 §8；修完后主智能体必须把此项加入下一轮 reviewer prompt 的"上一轮 medium 用户决议为'当场修'的项"字段，避免下轮重复报告）
     └─ Low / Nit：默认 follow-up，不阻塞
  ④ round 文件 grep 命中 `REVIEW_PASS:` → 退出循环
  ⑤ 第 5 轮结束仍有 critical/high 未收敛 → 主智能体停止执行，
     输出 `REVIEW_STALLED:` 前缀标记，由用户决定拆 PR / 接受残留 / 强行继续
```

**强制约束**：

- 声明完成前**必须对落盘 round 文件实跑 `grep -Fn "REVIEW_PASS:"`**，并在最终报告中按 §1.5 证据格式引用"文件路径 + 命中行"。没有落盘文件或没有命中行 → 流程未完成。冒号后的回执/说明文字不参与 grep。
- 轮次数 = 本次 run 目录内 `round-*.md` 文件数（可数文件防虚报；不可用整个 reviews/ 目录计数，跨运行会累积旧轮）。
- Reviewer 输出以 `REVIEW_FAIL:` 为前缀的签字行 → 等同于有 critical/high 问题，**必须按"修 critical/high → 重跑 §8"路径处理**，不允许只读 `REVIEW_PASS:` 缺失就推断"继续循环"。
- Medium 问题不允许主智能体擅自修或忽略 — 必须列给用户决议。**用户选"当场修"时视同 Critical/High：修完必须重新执行 §8**，不允许跳过下一轮 review。
- 第 5 轮硬停 — 不论是否还有问题，主智能体必须停下来等用户指令，不允许进入第 6 轮。停止时**必须输出可 grep 标记**：`REVIEW_STALLED: 已达 5 轮上限，等待用户决策`。**输出 STALLED 前必须在最终报告中逐轮引用第 1~5 轮 round 文件各自的 `REVIEW_FAIL:` 签字行**（STALLED 成立必然意味着每轮均 FAIL；缺任一轮签字或 round 文件 = 未达 5 轮，不得输出 STALLED——堵"第 1 轮就喊 STALLED 把球踢给用户"的逃逸口）。

### 8.2 Reviewer Subagent 调用方式

用 Claude Code 的 Agent tool 派出独立子对话（默认通用 reviewer，不指定 subagent_type）：

```
Agent({
  description: "<3-5 字描述>",
  prompt: <prompt 模板见 skills/review-checklist.md "Reviewer Subagent 任务模板" 章节>
})
```

若环境已注册专用 reviewer 类型（如 `feature-dev:code-reviewer`，查 installed plugins / agents 确认）则可优先指定 `subagent_type` 使用；未确认已注册时不要先试——必失败的派发只会留下"降级当异常"的即兴空间。最终报告引用 REVIEW_PASS 签字行处须同行注明本轮实际使用的 subagent_type（通用则写 general-purpose）。

**Prompt 模板的单一来源**：`skills/review-checklist.md` 中 "Reviewer Subagent 任务模板" 章节是唯一权威。本文件不内嵌模板内容，避免漂移。主智能体派 reviewer 前先读该章节，按模板填空（改动范围 / 已知 review 历史 / 主要功能背景）。

**Plan 文档路径必填**（无 plan 时显式填"无"）：主智能体必须把 `.superpowers/plans/` 下对应 plan 文档的路径填入 reviewer prompt 的"相关 plan 文档"字段（让 reviewer 可读到设计期"杀手场景 + 对端检查"两栏）；若本次改动确实无 plan，显式填"无"，否则视为主智能体认定本次改动无 plan（设计期清单缺失，reviewer 会跳过 plan 阅读无法验证设计期一致性）。

### 8.3 终止防御机制

防无限循环和噪音：

1. **传"已知问题 + 决议"给下一轮 reviewer**：每轮把上一轮发现（含上一轮 round 文件路径）作为 context，让它不要**原样重复报告**同一处；但"已修待复验"项下一轮 reviewer 必须复验运行路径生效后才真正豁免（防半修——"声称修了"正是 `76a2f58` 三处教训的根因），复验规则见模板"已知 review 历史"段
2. **置信度阈值**：`confidence ≥ 80` 才报告（过滤理论问题）
3. **轮次硬上限**：5 轮；第 5 轮后无论结果主智能体停止，输出 `REVIEW_STALLED:` 标记（须附逐轮 FAIL 凭证，见 §8.1）等用户决策（拆 PR / 接受残留 / 强行继续）
4. **三类机器可验证标记**（前缀固定，全程用 `grep -F` 验证前缀，不允许凭语义判断近义句；前缀**后**的人读说明文字可在不同场景下调整）：
   - `REVIEW_PASS:` — reviewer 通过签字（critical/high 清零；可附 medium/low 供用户决议），主智能体见到即退出循环
   - `REVIEW_FAIL:` — reviewer 失败签字（critical/high > 0），主智能体进入"修 → 重跑 §8"路径
   - `REVIEW_STALLED:` — 主智能体在第 5 轮硬停时自己输出，reviewer 不输出此标记

### 8.4 真实参考

`9dd8ce1d`（升级链路 PHP 环境检测）实际跑了 4 轮 review 才收敛、第 4 轮 0 new 通过——每轮发现都对应反模式清单某条（明细 `git show 9dd8ce1d` 及 review-checklist 各反模式案例可查）。本节把这次自然形成的流程显式化，防"跑一次就停"。

**反面教材**：`76a2f58` 一次性补三处半修（测试绿但运行路径未生效），reviewer 必须实际制造绕过请求 —— 详见 review-checklist.md 反模式 2 第二例。

---

逐项检查完毕、阶段 8 的落盘 round 文件被主智能体 `grep -F "REVIEW_PASS:"` 命中并在总结中引用"文件路径 + 命中行"后，输出结果摘要和风险列表，等待用户确认"提交"再执行 git commit。**签字行随 commit body 要点或 PR body 落库**（`.github/workflows/review-pass-gate.yml` 在 PR→main 时校验双通道任一命中；防无声遗忘而非伪造）。
