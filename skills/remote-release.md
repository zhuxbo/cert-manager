# 发布 Release 服务

输入版本号：由工具薄入口或用户请求传入。

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

> **不需要跑 §3.0 的 main CI 模拟或 §3.2 的完整 mutation**。预发布版本就是用来试错的，正式门禁仅在 main 通道启用。

### 3. 正式版（main 通道）

#### 3.0 创建 dev → main PR **之前**：本机模拟 main CI

正式版仅 main 通道发布。在执行 §3.1 创建 dev → main PR **之前**，本机先补跑 PR 阶段缺失的 main-only 检查，避免合并后 main 才变红。

##### 3.0.1 本机模拟 main CI（重点：main-only 的 compat-snapshot）

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

#### 3.2 当前 main 完整 mutation 强制门禁

切换并同步 main 后，对**当前 main fingerprint** 运行全部 6 个核心类。不要传
`MUTATE_TARGET_CLASSES`，否则只是普通开发期的目标集，不能满足正式版完整门禁：

```bash
MAIN_MUTATION_RUN=".superpowers/finish-check-runs/main-release-<版本号>"
python3 skills/scripts/finish-check-exec.py freeze --run-dir "$MAIN_MUTATION_RUN"
python3 skills/scripts/finish-check-exec.py run \
  --run-dir "$MAIN_MUTATION_RUN" --gate mutation
python3 skills/scripts/finish-check-exec.py verify \
  --run-dir "$MAIN_MUTATION_RUN" --require mutation
```

- MSI 必须 ≥ `backend/tests/.mutation-baseline.json` 的 `min_msi`；不达标则停止发布并补测试，禁止下调 baseline 放行。
- mutation 按 `skills/mutation-shards.json` 的六个精确文件逐片运行，完整结果缓存到 `.superpowers/mutation-shard-cache/v1/`；中断或修复后只重跑失效分片，最终按 mutant 数加权汇总。缓存不能替代当前 main fingerprint 的汇总 gate 证据。
- 旧 11818 秒日志实际扩展到 11 个文件、3544 个 mutant 且未完成，不能证明精确六片每次需要 3 小时。Fund 精确完整样本为 108 个 mutant、79 killed、29 untested，mutant 阶段 393.67–411.96 秒，整片最新墙钟 530.424 秒；首次六片完整运行结束前不承诺固定时长。
- 开发机器不配置定时或夜间 mutation；完整门禁仅由 main 正式发布触发。
- `build/release.sh` 会再次执行上述 `verify`，并要求固定目录
  `.superpowers/finish-check-runs/main-release-<版本号>` 的证据与当前源码一致；验证发生在创建 `v<版本号>` / `latest` tag 之前，缺失、失败或 stale 均拒绝发布。

#### 3.3 执行发布

```
bash build/release.sh <版本号>
```

脚本会强制校验：

- 当前必须在 main 分支
- 工作区必须干净
- 本地 main 必须与 origin/main 一致
- 自动打 `v<版本号>` tag 并推送
- 自动把 `latest` tag 移到当前提交并推送
- main/dev 的远程目录和 `releases.json` 记录分别只保留最新 `KEEP_VERSIONS` 条（默认各 5 条）
- 每台目标服务器上传后必须通过远程文件/哈希/latest 链接校验和公网全量下载哈希校验，任一失败则发布失败

#### 3.4 发布后：同步分支 + 切回 dev

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
