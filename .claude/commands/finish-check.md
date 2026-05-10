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

> 默认走 `.env.testing` 的 mysql；本地 MySQL 偶发 "server has gone away" / "Connection refused"（资源压力间歇性闪断 / paratest 连接占满）时，**等 5s 重跑一次即可**，不是代码问题。重跑仍稳定失败才视为真实回归。

改特定模块时优先跑对应测试：

- Models：`tests/Unit/Models/`
- ACME 单元：`tests/Unit/Services/Acme/`
- ACME 控制器：`tests/Feature/Controllers/Admin/AcmeControllerTest.php`、`tests/Feature/Controllers/User/AcmeControllerTest.php`
- Deploy 控制器：`tests/Feature/Controllers/Deploy/`
- 资金路径：`tests/Feature/FundAudit/`、`tests/Feature/Database/FundTransactionUniqueIndexesTest.php`

### 2.4 测试 — mysql 5.7 容器(可选,模拟 CI 环境)

**何时必跑**：

- 改了 `database/migrations/` 中的 enum / 索引 / 外键 / DDL 类型字段
- 改了资金路径（`Fund.php` / `Transaction.php` / `FundController` / `TopUpController` / Fund 相关 invariant / 唯一索引）
- 改了原生 SQL（`DB::raw` / `DB::statement` / `whereRaw`）
- 改了 `AppServiceProvider` 的 connection / 时区注入

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

- [ ] 状态转换走 CAS UPDATE（`Fund::transitionToSuccessful`），CAS WHERE 必须完整字段匹配（不能简化为单一 status）
- [ ] 写 transaction 不依赖应用层 `exists` 防重 — 靠 DB 唯一索引兜底
- [ ] 修改 `user.balance` 必须在 `DB::transaction(fn)` 内 + 同事务内创建对应 transaction
- [ ] 删除已入账 fund 路径必须事务内 `lockForUpdate` + 锁内 status/created_at 二次校验
- [ ] 新增"资金相关"测试必须登记 `fundAuditGuardedTestPaths()` 或 `fundAuditGuardExcludedTestPaths()`（元测试 `tests/Unit/FundAuditGuardCoverageTest.php` 强制兜底）
- [ ] 上线前先跑 `php artisan finance:audit`（dry-run），确认现有数据干净再加新约束/索引

### 2.7 PHP 8.3 规范

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

逐项检查完毕后输出结果摘要和风险列表，等待用户确认"提交"再执行 git commit。
