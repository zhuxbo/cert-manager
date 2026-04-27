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

### 3. 正式版（main 通道）

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
/cert-release 0.0.13-beta     # 预发布版（dev 通道，任意分支可发）
/cert-release v0.0.13-beta    # 自动去除 v 前缀
/cert-release 1.0.0           # 正式版（必须 main 分支，自动打 tag + latest）
```

## 注意事项

- 构建失败时停止发布流程
- 正式版未在 main 分支或工作区不干净，脚本会直接报错退出，不会自动切换分支
- `latest` tag 始终指向最近一次正式版发布的提交
- 不要手动删除/修改 `v<版本号>` 和 `latest` tag，由脚本统一管理
