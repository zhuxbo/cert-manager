#!/bin/bash

# cloud-deploy 插件前端构建脚本
#
# 目的：让「干净 clone」也能可靠产出 user + admin 两个 IIFE bundle。
#
# 背景（脆弱性根因）：
#   插件前端是根 monorepo workspace 之外的独立项目。若不在插件前端目录放
#   pnpm-workspace.yaml，则在该目录跑 `pnpm install` 会被根 workspace「劫持」
#   （Scope: all N workspace projects），插件自身依赖不会装进本地 node_modules，
#   后续 `pnpm build` 报 `vite: command not found`。
#   而 pnpm 11 默认禁止依赖的 build 脚本（供应链安全），esbuild / vue-demi 的
#   postinstall 被跳过会触发 `ERR_PNPM_IGNORED_BUILDS`，vite 构建直接失败。
#
#   修复点：每个插件前端子项目都需要一份带 `allowBuilds` 的 pnpm-workspace.yaml，
#   让 pnpm 把该目录当独立 workspace 根（隔离根 monorepo）并放行 esbuild/vue-demi。
#   但根 .gitignore 忽略了 `plugins/*/frontend/*/pnpm-workspace.yaml`（避免死文件入库），
#   故本脚本在「install 之前」按需生成它——干净 clone 无需任何手工准备即可构建。
#
# 用法：
#   bash plugins/cloud-deploy/build.sh            # 构建 user + admin
#   bash plugins/cloud-deploy/build.sh user       # 仅 user
#   bash plugins/cloud-deploy/build.sh admin      # 仅 admin
#
# 产物：plugins/cloud-deploy/frontend/{user,admin}/dist/cloud-deploy-plugin.iife.js
# 发布打包由 plugins/release-plugin.sh 负责（它会把 dist/* 拷进 frontend/{side}/ 再装 zip）。

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'
log_info() { echo -e "${CYAN}[BUILD]${NC} $1"; }
log_ok() { echo -e "${GREEN}[OK]${NC} $1"; }
log_err() { echo -e "${RED}[ERROR]${NC} $1" >&2; }

# 解析要构建的端
SIDES=(user admin)
if [ "${1:-}" = "user" ] || [ "${1:-}" = "admin" ]; then
    SIDES=("$1")
fi

# 选取 pnpm（优先 corepack 锁定的版本）
PNPM_BIN="pnpm"
if command -v corepack >/dev/null 2>&1; then
    corepack enable >/dev/null 2>&1 || true
fi
if ! command -v "$PNPM_BIN" >/dev/null 2>&1; then
    log_err "未找到 pnpm。请先安装 Node >=22.13 并 'corepack enable'（仓库 packageManager 字段会锁定 pnpm 版本）"
    exit 1
fi

# 插件前端独立 workspace 内容（隔离根 monorepo + 放行 esbuild/vue-demi 的 build 脚本）。
# 本插件已把 pnpm-workspace.yaml 入库（见本目录 .gitignore 例外）；此处仅作「缺失兜底」——
# 干净 clone 正常已带该文件，被误删或未入库时才由本脚本补回，避免覆盖已入库文件污染 git。
WORKSPACE_CONTENT='# 插件前端独立 workspace 根（与根 monorepo 隔离 + allowBuilds 放行 esbuild/vue-demi 的 build 脚本）。
allowBuilds:
  esbuild: true
  vue-demi: true'

for side in "${SIDES[@]}"; do
    side_dir="$PLUGIN_DIR/frontend/$side"
    if [ ! -f "$side_dir/package.json" ]; then
        log_err "缺少 $side_dir/package.json，跳过 $side"
        continue
    fi

    if [ -f "$side_dir/pnpm-workspace.yaml" ]; then
        log_info "$side 已带 pnpm-workspace.yaml（入库版），沿用。"
    else
        log_info "$side 缺 pnpm-workspace.yaml，补回（allowBuilds: esbuild/vue-demi）..."
        printf '%s\n' "$WORKSPACE_CONTENT" >"$side_dir/pnpm-workspace.yaml"
    fi

    log_info "安装 $side 依赖（独立 workspace，不被根 monorepo 劫持）..."
    # workspace 文件存在即让 pnpm 把本目录当独立根；无需 --ignore-workspace。
    # --frozen-lockfile：已入库 pnpm-lock.yaml，CI/他人构建可复现。
    (cd "$side_dir" && "$PNPM_BIN" install --frozen-lockfile)

    log_info "类型检查并构建 $side IIFE bundle..."
    (cd "$side_dir" && "$PNPM_BIN" run build)

    artifact="$side_dir/dist/cloud-deploy-plugin.iife.js"
    if [ ! -s "$artifact" ]; then
        log_err "$side 构建未产出 bundle: $artifact"
        exit 1
    fi
    log_ok "$side: $artifact ($(du -h "$artifact" | cut -f1))"
done

log_ok "前端构建完成。"
