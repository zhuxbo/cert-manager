#!/usr/bin/env bash
# e2e 测试公共库：日志 / fixtures / 临时 release server / cleanup
#
# 由各 case-*.sh 脚本 source 使用；不可独立执行。

# 防重复 source
[ -n "${E2E_LIB_LOADED:-}" ] && return 0
E2E_LIB_LOADED=1

# 颜色输出（CI 也保留 ANSI；CI 渲染器一般支持）
E2E_COLOR_RED='\033[0;31m'
E2E_COLOR_GREEN='\033[0;32m'
E2E_COLOR_YELLOW='\033[0;33m'
E2E_COLOR_BLUE='\033[0;34m'
E2E_COLOR_RESET='\033[0m'

e2e_log() { printf "${E2E_COLOR_BLUE}[INFO]${E2E_COLOR_RESET} %s\n" "$1"; }
e2e_pass() {
    printf "${E2E_COLOR_GREEN}[PASS]${E2E_COLOR_RESET} %s\n" "$1"
    E2E_PASS=$((${E2E_PASS:-0} + 1))
}
e2e_fail() {
    printf "${E2E_COLOR_RED}[FAIL]${E2E_COLOR_RESET} %s\n" "$1" >&2
    E2E_FAIL=$((${E2E_FAIL:-0} + 1))
}
e2e_warn() { printf "${E2E_COLOR_YELLOW}[WARN]${E2E_COLOR_RESET} %s\n" "$1" >&2; }
e2e_step() { printf "${E2E_COLOR_BLUE}[STEP]${E2E_COLOR_RESET} %s\n" "$1"; }

# 项目根（lib.sh 在 deploy/test/e2e/，根 = ../../../）
E2E_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
E2E_INSTALL_SH="$E2E_REPO_ROOT/deploy/install.sh"
E2E_UPGRADE_SH="$E2E_REPO_ROOT/deploy/upgrade.sh"
E2E_BUILD_PACKAGE_SH="$E2E_REPO_ROOT/build/scripts/package.sh"

# 计算文件 sha256（跨平台）
e2e_file_sha256() {
    local file="$1"
    if command -v sha256sum &>/dev/null; then
        sha256sum "$file" | awk '{print $1}'
    elif command -v shasum &>/dev/null; then
        shasum -a 256 "$file" | awk '{print $1}'
    elif command -v openssl &>/dev/null; then
        openssl dgst -sha256 "$file" | awk '{print $NF}'
    else
        e2e_fail "缺少 sha256 工具（sha256sum / shasum / openssl 均未安装）"
        return 1
    fi
}

# 创建一个 fixture zip（含最小目录结构）
# 用法：e2e_make_fixture_zip <output.zip>
# 产物：内含 backend/.env.example + backend/composer.json + frontend/admin/.gitkeep + version.json
e2e_make_fixture_zip() {
    local out="$1"
    local stage
    stage="$(mktemp -d)"
    trap "rm -rf '$stage'" RETURN

    mkdir -p "$stage/upgrade/backend" "$stage/upgrade/frontend/admin" "$stage/upgrade/frontend/user"
    cat >"$stage/upgrade/backend/.env.example" <<'EOF'
APP_NAME=Manager
APP_KEY=
EOF
    echo '{"name":"manager"}' >"$stage/upgrade/backend/composer.json"
    : >"$stage/upgrade/frontend/admin/.gitkeep"
    : >"$stage/upgrade/frontend/user/.gitkeep"
    echo '{"version":"1.0.0-fixture"}' >"$stage/upgrade/version.json"

    (cd "$stage" && zip -rq "$out" upgrade)
}

# 写 releases.json（全局唯一真相源；含全部版本 + 每个 asset 的 sha256）
# 用法：e2e_write_releases_json <out_path> <version> <upgrade_zip_filename> <upgrade_sha256>
# 生成单 release 块的 releases.json，结构与 build/scripts/release-common.sh 对齐：
#   {"releases":[{"tag_name":"vX.Y.Z","prerelease":false,"assets":[
#     {"name":"ssl-manager-upgrade-X.Y.Z.zip","sha256":"...","size":N},
#     {"name":"ssl-manager-full-X.Y.Z.zip","sha256":"placeholder_full","size":N},
#     {"name":"ssl-manager-script-X.Y.Z.zip","sha256":"placeholder_script","size":N}
#   ]}]}
e2e_write_releases_json() {
    local out="$1"
    local version="$2"
    local filename="$3"
    local sha="$4"
    # 多行 JSON，便于 awk 行级匹配（与 install.sh / upgrade.sh 解析逻辑兼容）
    cat >"$out" <<EOF
{
  "releases": [
    {
      "tag_name": "v$version",
      "prerelease": false,
      "build_time": "2026-05-05T00:00:00Z",
      "assets": [
        {
          "name": "$filename",
          "sha256": "$sha",
          "size": 12345
        },
        {
          "name": "ssl-manager-full-$version.zip",
          "sha256": "placeholder_full_sha256",
          "size": 67890
        },
        {
          "name": "ssl-manager-script-$version.zip",
          "sha256": "placeholder_script_sha256",
          "size": 1024
        }
      ]
    }
  ]
}
EOF
}

# 兼容旧 case 的 alias（已 deprecated；保留防止意外引用）
# 新 case 应使用 e2e_write_releases_json
e2e_write_manifest() {
    e2e_warn "e2e_write_manifest 已 deprecated，请改用 e2e_write_releases_json"
    e2e_write_releases_json "$@"
}

# 启动一个本地 HTTP server 提供 fixtures（用 python3 -m http.server）
# 用法：e2e_start_release_server <serve_dir> [port]
# port 缺省时自动选可用端口（避免上次残留占用）
# 设置全局 E2E_RELEASE_SERVER_PID + E2E_RELEASE_SERVER_PORT
e2e_start_release_server() {
    local dir="$1"
    local port="${2:-}"

    if ! command -v python3 &>/dev/null; then
        e2e_warn "python3 不可用，无法启动 fixture release server"
        return 1
    fi

    # 端口选择：用户指定 / 自动从 18443 起递增找可用端口
    if [ -z "$port" ]; then
        local probe=18443
        while [ "$probe" -lt 18500 ]; do
            if ! lsof -iTCP:"$probe" -sTCP:LISTEN >/dev/null 2>&1; then
                port="$probe"
                break
            fi
            probe=$((probe + 1))
        done
        if [ -z "$port" ]; then
            e2e_fail "找不到可用端口（18443-18500 均被占用）"
            return 1
        fi
    fi

    # python3 -m http.server 直接 exec，避免子 shell 嵌套使 PID 不直接对应进程
    (cd "$dir" && exec python3 -m http.server "$port" >/dev/null 2>&1) &
    E2E_RELEASE_SERVER_PID=$!
    E2E_RELEASE_SERVER_PORT="$port"

    # 等待 server 起（最多 3 秒）
    local i=0
    while [ "$i" -lt 30 ]; do
        if curl -fsS --max-time 1 "http://127.0.0.1:$port/" >/dev/null 2>&1; then
            return 0
        fi
        sleep 0.1
        i=$((i + 1))
    done

    e2e_fail "release server 启动超时（端口 ${port}）"
    return 1
}

e2e_stop_release_server() {
    if [ -n "${E2E_RELEASE_SERVER_PID:-}" ]; then
        # 先 SIGTERM 礼貌退出；2s 内仍存活则 SIGKILL
        kill -TERM "$E2E_RELEASE_SERVER_PID" 2>/dev/null || true
        local i=0
        while [ "$i" -lt 20 ] && kill -0 "$E2E_RELEASE_SERVER_PID" 2>/dev/null; do
            sleep 0.1
            i=$((i + 1))
        done
        kill -KILL "$E2E_RELEASE_SERVER_PID" 2>/dev/null || true
        wait "$E2E_RELEASE_SERVER_PID" 2>/dev/null || true
        E2E_RELEASE_SERVER_PID=""
        E2E_RELEASE_SERVER_PORT=""
    fi
}

# Cleanup 钩子（trap EXIT 用）
e2e_cleanup() {
    e2e_stop_release_server
    [ -n "${E2E_TMPDIR:-}" ] && [ -d "$E2E_TMPDIR" ] && rm -rf "$E2E_TMPDIR"
}
