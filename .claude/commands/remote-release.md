# 发布 Release 服务

输入版本号: $ARGUMENTS

## 版本号处理

- **不要添加 `v` 前缀**，脚本会自动添加
- 如果用户输入了 `v` 前缀，需要先去除
- 格式：`X.Y.Z`（正式版）或 `X.Y.Z-beta` / `X.Y.Z-alpha` / `X.Y.Z-rc.1`（预发布版）
- 允许重复发布同一版本（会覆盖）

## 通道判定

- 含 `-beta` / `-alpha` / `-rc` 等后缀 → `dev` 通道，可在任意分支发布
- 不含后缀（`X.Y.Z`） → `main` 通道，**必须在 main 分支发布**

## 执行步骤

### 1. 验证版本号

- 去除 `v` 前缀（如果有）
- 检查格式是否正确（如 `0.0.12-beta` 或 `1.0.0`）
- 未提供则提示输入

### 2. 预发布版（dev 通道）

直接执行：

```
bash build/release.sh <版本号>
```

完成后保留在当前分支，无需额外动作。

> **不需要跑 §3.0 的本机门禁**（变异测试 + 模拟 main CI）。预发布版本就是用来试错的，门禁仅在正式版（main 通道）启用 — 见 §3.0。

### 3. 正式版（main 通道）

#### 3.0 创建 dev → main PR **之前**：两道本机门禁

正式版仅 main 通道发布。在执行 §3.1 创建 dev → main PR **之前**，本机必须过下面两道门禁——它们都是 **CI 在 PR 阶段不跑、合并到 main 才跑（或根本不在 CI）** 的检查，只有本机提前跑才能在合并前发现、避免 main 变红。

##### 3.0.1 资金核心变异测试（CI 不跑）

验证资金核心代码（Acme/Order Action + Fund/Transaction Model + FundAudit）的测试质量没退步：

```
cd backend && composer test:mutate
```

- MSI 必须 ≥ `tests/.mutation-baseline.json` 的 `min_msi`；不达标则**停止发布**，先补测试让 MSI 回升，**禁止靠下调 baseline 放行**（除非已评估该 mutation 无害，如不可达分支）
- 约 5-7 分钟，仅本机跑（CI 不跑，避免 PR 等待）；`untested` mutation 即补测试指引

##### 3.0.2 本机模拟 main CI（重点：main-only 的 compat-snapshot）

`ci.yml` 的 `compat-snapshot` 带 `if: github.ref == 'refs/heads/main'`——**PR / dev push 都不触发，只有合并到 main 才跑**。新增 HTTP controller 测试漏 capture 快照 fixture 时，PR 全绿、合并后才因 `fixture_missing` 变红（已踩：`3f716ec` 新增 security 测试漏 capture）。合并前本机补跑这套抓出来。

前提：开发容器已起（`make ps` 确认 app/mysql/redis，否则 `make up`）+ 前端依赖已装（`pnpm install --frozen-lockfile`）。后端测试**必须锁测试库 `ssl_manager_test`**，否则 `make exec` 默认碰开发库被 RefreshDatabase 清空：

```bash
# 后端（共用测试库故依次跑；命令写全，勿塞进 zsh 未加引号的变量——zsh 不做单词分割会当成一条命令名）
docker compose exec -T -e DB_DATABASE=ssl_manager_test app composer test:snapshot                     # ★ main-only，PR 跑不到，必跑
docker compose exec -T -e DB_DATABASE=ssl_manager_test app php artisan test --parallel --processes=4  # = CI backend-core
for p in easy notice invoice; do docker compose exec -T -e DB_DATABASE=ssl_manager_test app php artisan test ../plugins/$p/backend/tests; done
# 前端 + 私钥扫描（裸跑 = CI lint + frontend-build + check-secrets）
pnpm lint && pnpm build:admin && pnpm build:user && make plugins-build
git grep -nE "BEGIN (RSA|OPENSSH|EC|DSA|ENCRYPTED) PRIVATE KEY" -- '*.php' '*.sh' '*.json' '*.yml' '*.env*' || echo "✓ 无私钥"
```

compat-snapshot 失败处理：

- `fixture_missing`（新测试缺基线）→ 给新测试 capture 并提交，重跑确认转绿：
  ```
  docker compose exec -T -e DB_DATABASE=ssl_manager_test -e COMPAT_CAPTURE=true app php artisan test <新测试文件路径>
  git add backend/tests/Compat/fixtures/ && git commit -m "fix(compat): 补 xxx fixture"
  ```
- 既有契约 `snapshot diff` → 若是预期破坏性变更，在用例顶部加 `expectsBreakingChange('reason')`；否则当 bug 修代码（**勿改快照迁就 bug**）

覆盖范围（诚实）：快路径只跑 PHP 8.4 + MySQL 8.4 单点。**CI 的 PHP 8.5、MySQL 5.7 各组本机快路径不跑**（8.5 无现成镜像、5.7 需 qemu 慢），合并后仍靠云端 CI 兜；要本机补全矩阵见 finish-check §2.4（5.7 容器）+ `make test-compat`（PHP 8.3/8.4）。

#### 3.1 发布前：合并 dev 领先的提交到 main

确认当前在 dev 分支且工作区干净，然后通过 PR 合并：

```
# 1. 推送 dev 最新提交
git push origin dev

# 2. 创建 PR (dev → main)
gh pr create --base main --head dev --title "release: v<版本号>" --body "release v<版本号>"

# 3. 等待 CI / 审核通过后合并（squash 或 merge 由仓库策略决定）
gh pr merge --merge   # 或 --squash，按项目惯例

# 4. 切到 main 拉取最新
git checkout main
git pull origin main
```

如果 dev 没有领先 main 的提交，跳过 PR，直接 `git checkout main && git pull`。

#### 3.2 执行发布

```
bash build/release.sh <版本号>
```

脚本会强制校验：

- 当前必须在 main 分支
- 工作区必须干净
- 本地 main 必须与 origin/main 一致
- 自动打 `v<版本号>` tag 并推送
- 自动把 `latest` tag 移到当前提交并推送

#### 3.3 发布后：同步分支 + 切回 dev

```
# 把 main 同步回 dev（如果 dev 落后）
git checkout dev
git merge --ff-only main
git push origin dev

# 留在 dev 分支继续开发
```

如果 `--ff-only` 失败（dev 上有 main 没有的提交），改用 `git merge main` 处理冲突后再推。

## 使用示例

```
/remote-release 0.0.13-beta     # 预发布版（dev 通道，任意分支可发）
/remote-release v0.0.13-beta    # 自动去除 v 前缀
/remote-release 1.0.0           # 正式版（必须 main 分支，自动打 tag + latest）
```

## 注意事项

- 构建失败时停止发布流程
- 正式版未在 main 分支或工作区不干净，脚本会直接报错退出，不会自动切换分支
- `latest` tag 始终指向最近一次正式版发布的提交
- 不要手动删除/修改 `v<版本号>` 和 `latest` tag，由脚本统一管理
