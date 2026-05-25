# 完成检查 — Manager

提交前逐项检查，跳过不涉及的部分。

> 范围：仅 MySQL + 宝塔部署。

---

## 1. 确定变更范围

```bash
git status --short
git diff --stat
git diff --cached --stat
```

确认本次改动涉及的目录（backend / frontend/admin / frontend/user / frontend/shared / plugins / deploy）和敏感路径（migrations / 资金路径 / 索引 / 部署脚本）。变更范围决定后面要重点跑哪些测试。

**结构化范围产出**（后续阶段判定依据，必须在 finish-check 总结里逐项填）：

| 维度 | 本次涉及？ | 触发后续什么 |
|------|------------|--------------|
| backend/app/Services/Acme | 是/否 | ACME 测试集必跑 |
| backend/app/Services/Order | 是/否 | Order 测试集必跑 |
| backend/app/Models/Fund / Transaction / 资金路径 | 是/否 | §2.6 资金证据必贴 + §2.4 mysql 5.7 容器必跑 |
| backend/database/migrations | 是/否 | 检查 enum/索引/外键/DDL → 任一是 → §2.4 必跑 |
| 原生 SQL（`DB::raw`/`whereRaw`/`DB::statement`） | 是/否 | §2.4 必跑 |
| AppServiceProvider 连接/时区注入 | 是/否 | §2.4 必跑 |
| frontend/shared | 是/否 | admin + user 两端构建必验 |
| plugins/ | 是/否 | §4 插件检查必跑 |
| deploy/ 升级脚本 | 是/否 | 反模式 4/7 重点扫描 |
| tests/ 文件本身（新增/修改测试） | 是/否 | §2.3 测试集 + 评审改测试是否伪绿（删断言/mock 过度） |
| 通知模板 / NotificationTemplate / NotificationCenter | 是/否 | §7 部署风险加 `db:seed --class=NotificationTemplateSeeder` + 模板渲染单测 |
| 删除了类/配置/命令/表/字段/函数 | 是/否 | §1.5 删除审核必跑 |

每行"是/否"必须明确，不允许"不确定"；不确定的视为"是"。

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

改特定模块时优先跑对应测试：

- Models：`tests/Unit/Models/`
- ACME 单元：`tests/Unit/Services/Acme/`
- ACME 控制器：`tests/Feature/Controllers/Admin/AcmeControllerTest.php`、`tests/Feature/Controllers/User/AcmeControllerTest.php`
- Deploy 控制器：`tests/Feature/Controllers/Deploy/`
- 资金路径：`tests/Feature/FundAudit/`、`tests/Feature/Database/FundTransactionUniqueIndexesTest.php`

### 2.4 测试 — mysql 5.7 容器(可选,模拟 CI 环境)

**何时触发**：§1 结构化范围产出中以下任一行为"是" → §2.4 必跑（不允许主智能体自判跳过）：

- backend/app/Models/Fund / Transaction / 资金路径
- backend/database/migrations
- 原生 SQL（`DB::raw`/`whereRaw`/`DB::statement`）
- AppServiceProvider 连接/时区注入

> 本地 MySQL 一般是 8.x（你本地用 8.4），跑过 ≠ 5.7 兼容（项目声明最小版本）。CI 用 mysql:5.7，本地先验避免 PR 红 CI。

**容器搭建**（M 系列 Mac 必须 `--platform linux/amd64`，5.7 没 arm64 manifest）：

```bash
cd backend
docker run --platform linux/amd64 --rm -d --name manager-mysql-test \
  -e MYSQL_ROOT_PASSWORD=password \
  -e MYSQL_DATABASE=manager_test \
  -p 13306:3306 mysql:5.7
trap 'docker stop manager-mysql-test >/dev/null' EXIT
for i in $(seq 1 60); do
  docker exec manager-mysql-test mysqladmin ping -uroot -ppassword --silent 2>/dev/null && break
  sleep 2
done

export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=13306 \
       DB_DATABASE=manager_test DB_USERNAME=root DB_PASSWORD=password
php artisan migrate --force # 部分 Unit 测试无 RefreshDatabase 依赖主库表存在
php artisan test --parallel
unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

trap - EXIT && docker stop manager-mysql-test >/dev/null
```

> qemu 模拟下首次启动 30~60s，等就绪 loop 给 60×2s 余量。
> 跑完容器**必须清理**（`docker stop manager-mysql-test`）。

### 2.5 Laravel 专项检查

> 详见 [skills/backend-dev.md](../../skills/backend-dev.md)（Laravel 架构、迁移规范、自动续费/重签等章节）+ [skills/acme-module.md](../../skills/acme-module.md)（ACME 三步流程）

- [ ] 迁移幂等（`Schema::hasColumn`/`Schema::hasTable`/索引存在性 守卫），不写 down
- [ ] Model 的 `$fillable`、`$casts`、`$hidden` 是否需要更新
- [ ] Action 无 userId 构造参数（用户隔离由 UserScope 保证）
- [ ] 控制器只做请求验证 + 一行调用 Action
- [ ] ACME 三步流程（new → pay → commit）状态流转完整
- [ ] Sdk 通过 `ca.acme_url`/`ca.acme_token`（回落 `ca.url`/`ca.token`）调 Gateway
- [ ] Transaction 类型正确（`acme_order`/`acme_cancel` 等），一对一防重豁免列表收紧到 `['order']`
- [ ] 队列 Job 在测试环境同步执行（`QUEUE_CONNECTION=sync`）；事务内 dispatch 必须 `->afterCommit()`
- [ ] Observer / Model boot 钩子改动是否影响已有事件链（特别是 `Fund::updating` / `Transaction::creating`）

### 2.6 资金路径专项（涉及 funds/transactions/users.balance 时）

> 详见 [skills/backend-dev.md](../../skills/backend-dev.md) "资金确定性体系（4 道网）" 章节

- [ ] 状态转换走 CAS UPDATE（`Fund::transitionToSuccessful`），CAS WHERE 必须完整字段匹配（不能简化为单一 status）
- [ ] 写 transaction 不依赖应用层 `exists` 防重 — 靠 DB 唯一索引兜底
- [ ] 修改 `user.balance` 必须在 `DB::transaction(fn)` 内 + 同事务内创建对应 transaction
- [ ] 删除已入账 fund 路径必须事务内 `lockForUpdate` + 锁内 status/created_at 二次校验
- [ ] 新增"资金相关"测试必须登记 `fundAuditGuardedTestPaths()` 或 `fundAuditGuardExcludedTestPaths()`（元测试 `tests/Unit/FundAuditGuardCoverageTest.php` 强制兜底）
- [ ] 上线前先跑 `php artisan finance:audit`（dry-run），确认现有数据干净再加新约束/索引

**证据格式要求**（防止"声称做了"）：

- 涉及资金路径的 PR 必须在 finish-check 总结中贴出 `php artisan finance:audit --dry-run` 的 stdout（无违反贴"全部 invariant 通过"一行 + 退出码 0；有违反 ≤ 5 条贴完整列表，>5 条按 §1.5 截断规则贴前 5 行 + "... 共 N 命中"）
- 涉及新增资金测试时贴出 `php artisan test --filter=FundAuditGuardCoverage` 的退出码（确认测试登记到 guarded/excluded 列表）

### 2.7 PHP 8.3 规范

> 详见 [CLAUDE.md](../../CLAUDE.md) "核心指令" 章节 PHP 8.3+ 条目

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

> 大重构例外：和 prettier 同理，全仓库批改时可跑全量 `find . -name "*.sh" -not -path "./node_modules/*" -not -path "./vendor/*" -not -path "*/.husky/_/*" -not -path "./build/temp/*" -not -path "./frontend/base/*" | xargs shfmt -i 4 -ci -w`。

### 3.4 构建验证（admin + user 双端）

```bash
pnpm build
```

确认两端都构建成功。

### 3.5 Monorepo 专项检查

- [ ] 修改 `frontend/shared/` 后同时检查 admin 和 user 两端影响
- [ ] `frontend/base/` 保持只读（git subtree，不直接修改）
- [ ] `@shared/*` 路径别名引用正确解析
- [ ] Workspace 依赖（`workspace:*`）版本一致
- [ ] admin 和 user 各自的 API 路径前缀正确（不混用）

### 3.6 Vue 3 + Element Plus 专项

- [ ] 新增组件有 `defineOptions({ name: "XxxPage" })`（keep-alive 依赖组件名）
- [ ] `watch`/`watchEffect` 在组件卸载时清理
- [ ] `addEventListener`、`mitt.on`、定时器在 `onBeforeUnmount` 中移除
- [ ] `v-for` 有唯一 `:key`，不与 `v-if` 同时用在同一元素
- [ ] Pinia store 的 state 使用函数返回
- [ ] Element Plus 组件按需引入正确

### 3.7 样式检查

- [ ] 组件样式使用 `scoped`
- [ ] 深度选择器使用 `:deep()` 而非 `::v-deep` / `/deep/`
- [ ] TailwindCSS 类名与自定义 SCSS 无冲突

---

## 4. 插件检查（如涉及 plugins/ 目录）

- [ ] `plugin.json` 必填字段：`name` / `requires` / `provider`；按需 `php_ext` / `admin_bundle` / `admin_css` / `user_bundle` / `user_css`
- [ ] 插件迁移幂等（同主系统约束）
- [ ] Widget 插槽注册正确（如 `user-dashboard-top`）
- [ ] 插件动态加载不影响主应用启动
- [ ] `build.json` 的 `include` 数组包含所有需要打包的子目录（`backend/` / `frontend/web/` / `nginx/` 等）

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

- [ ] 核心逻辑改动 → 更新 `CLAUDE.md`
- [ ] 用户面功能 → 更新 `README.md`（仅用户层关心的功能特性，内部架构机制不写）
- [ ] 部署相关 → 更新 `DEPLOY.md`
- [ ] 升级回滚相关 → 更新 `UPGRADE.md`
- [ ] 模块架构改动 → 更新 `skills/*.md`（按领域）
- [ ] 跑 `bash skills/scripts/check-review-checklist-staleness.sh`，把 warning 项贴入 finish-check 总结的"已知局限性"段；若 warning 数 ≥ 3 → 必须列入 follow-up 维护任务（避免清单长期失真）

---

## 7. 已知局限性和潜在风险

按以下分类列出风险项：

### 兼容性风险

- Manager 调 Gateway 的接口是否版本一致（Sdk 调用参数/返回值）
- 数据库迁移能否在线上平滑执行（迁移幂等？现有数据是否会让新唯一索引创建失败？）
- API 变更是否影响 admin/user 两端前端
- 插件接口变更是否影响已安装插件

### 安全风险

- 新增 API 是否有认证中间件（JWT / Token）
- 用户输入是否经 Request 类验证
- UserScope 是否覆盖新增查询
- 支付相关改动（yansongda/pay）是否安全（CAS 路径金额/方式校验完整？回调失败让支付平台重试而非吞错？）
- 敏感字段在响应中是否隐藏

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
- 是否需要重启队列 worker（宝塔 Supervisor 重启 `ssl-manager-queue`）
- 前端构建产物是否需要清除 CDN 缓存
- 插件是否需要重新发布（`plugins/release-plugin.sh`）

---

## 8. 独立 Review 循环（必跑，不可跳过）

**目的**：用干净上下文的独立 reviewer subagent 以"破坏模式"找毛病，避开主智能体改完测试通过就停手的天然偏差。

**质量门定义**：声明"完成"前必须满足 — reviewer 输出过 **以 `REVIEW_PASS:` 为前缀的签字行**（前缀后人读说明文字可变，仅前缀参与 `grep -F` 验证）。`REVIEW_PASS:` 仅表示 critical/high 已清零；reviewer 可同时列出 medium/low 供用户决议，不影响签字。

### 8.1 执行结构（循环到收敛，最多 5 轮）

```
loop:
  ① 派 reviewer subagent（见 §8.2 调用方式）
  ② 主智能体读 reviewer 报告
     ├─ Critical / High：必须修 → 修完跳回 §2/§3（只对改动文件）→ 重新执行 §8
     ├─ Medium：报告给用户决议（当场修 / follow-up issue / 接受）—— 主智能体不擅自处理
     │   └─ 用户选"当场修"：视同 Critical/High 处理（修完跳回 §2/§3 → 重新执行 §8；修完后主智能体必须把此项加入下一轮 reviewer prompt 的"上一轮 medium 用户决议为'当场修'的项"字段，避免下轮重复报告）
     └─ Low / Nit：默认 follow-up，不阻塞
  ③ reviewer 输出 `REVIEW_PASS:` 前缀的签字行 → 主智能体 grep 验证 → 退出循环
  ④ 第 5 轮结束仍有 critical/high 未收敛 → 主智能体停止执行，
     输出 `REVIEW_STALLED:` 前缀标记，由用户决定拆 PR / 接受残留 / 强行继续
```

**强制约束**：

- 声明完成前**必须用 `grep -F "REVIEW_PASS:"` 检测 reviewer 输出前缀**，并在最终报告中引用该行。没有这行 grep 命中 → 流程未完成。冒号后的人读说明文字不参与 grep。
- Reviewer 输出以 `REVIEW_FAIL:` 为前缀的签字行 → 等同于有 critical/high 问题，**必须按"修 critical/high → 重跑 §8"路径处理**，不允许只读 `REVIEW_PASS:` 缺失就推断"继续循环"。
- Medium 问题不允许主智能体擅自修或忽略 — 必须列给用户决议。**用户选"当场修"时视同 Critical/High：修完必须重新执行 §8**，不允许跳过下一轮 review。
- 第 5 轮硬停 — 不论是否还有问题，主智能体必须停下来等用户指令，不允许进入第 6 轮。停止时**必须输出可 grep 标记**：`REVIEW_STALLED: 已达 5 轮上限，等待用户决策` —— 与 `REVIEW_PASS:` / `REVIEW_FAIL:` 对称，便于用户和外部工具感知流程实际卡在哪一步。

### 8.2 Reviewer Subagent 调用方式

用 Claude Code 的 Agent tool 派出独立子对话：

```
Agent({
  subagent_type: "feature-dev:code-reviewer",
  description: "<3-5 字描述>",
  prompt: <prompt 模板见 skills/review-checklist.md "Reviewer Subagent 任务模板" 章节>
})
```

**Fallback**：若 `feature-dev:code-reviewer` 不可用（subagent_type 未注册 / 报错），改用通用 reviewer：

```
Agent({
  description: "<3-5 字描述>",
  prompt: <同上>
})
```

**Prompt 模板的单一来源**：`skills/review-checklist.md` 中 "Reviewer Subagent 任务模板" 章节是唯一权威。本文件不内嵌模板内容，避免漂移。主智能体派 reviewer 前先读该章节，按模板填空（改动范围 / 已知 review 历史 / 主要功能背景）。

**Plan 文档路径必填**（无 plan 时显式填"无"）：主智能体必须把 `.superpowers/plans/` 下对应 plan 文档的路径填入 reviewer prompt 的"相关 plan 文档"字段（让 reviewer 可读到设计期"杀手场景 + 对端检查"两栏）；若本次改动确实无 plan，显式填"无"，否则视为主智能体认定本次改动无 plan（设计期清单缺失，reviewer 会跳过 plan 阅读无法验证设计期一致性）。

### 8.3 终止防御机制

防无限循环和噪音：

1. **传"已知问题 + 决议"给下一轮 reviewer**：每轮把上一轮发现作为 context，让它不要重复报告同一处
2. **置信度阈值**：`confidence ≥ 80` 才报告（过滤理论问题）
3. **轮次硬上限**：5 轮；第 5 轮后无论结果主智能体停止，输出 `REVIEW_STALLED:` 标记等用户决策（拆 PR / 接受残留 / 强行继续）
4. **三类机器可验证标记**（前缀固定，全程用 `grep -F` 验证前缀，不允许凭语义判断近义句；前缀**后**的人读说明文字可在不同场景下调整）：
   - `REVIEW_PASS:` — reviewer 通过签字（critical/high 清零；可附 medium/low 供用户决议），主智能体见到即退出循环
   - `REVIEW_FAIL:` — reviewer 失败签字（critical/high > 0），主智能体进入"修 → 重跑 §8"路径
   - `REVIEW_STALLED:` — 主智能体在第 5 轮硬停时自己输出，reviewer 不输出此标记

### 8.4 真实参考

`feat: 升级链路加入 PHP 环境检测与流程加固`（commit `9dd8ce1d`）实际跑了 4 轮 review 才收敛：

- 第 1 轮 → DRY / 跨脚本工具复制 / python3 → PHP / 一致性问题（5-6 条）
- 第 2 轮 → 防御机制完全失效（清单未打进升级包）/ 数据丢失风险（检测过晚导致 storage 被 trap cleanup 删）/ shell 函数名笔误（log_warn vs log_warning）
- 第 3 轮 → 字符串拼接路径不安全 / autoload 兜底对称性缺失 / 新方法无单测
- 第 4 轮 → 0 new → 通过

每一轮的发现都对应 `skills/review-checklist.md` 反模式 1-13 的某条，案例锚定可双向验证。本节就是把这次自然形成的流程显式化，防止下次"跑一次就停"。

---

逐项检查完毕、阶段 8 reviewer 输出以 `REVIEW_PASS:` 为前缀的签字行并被主智能体 `grep -F` 命中后，输出结果摘要和风险列表，等待用户确认"提交"再执行 git commit。
