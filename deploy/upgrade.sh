#!/bin/bash

# SSL Manager 在线升级脚本
# 用法:
# ./upgrade.sh --url http://release.example.com
# ./upgrade.sh --url http://release.example.com --version 0.0.11-beta
# ./upgrade.sh --dir /www/wwwroot/mysite # 从 version.json 读取 release_url

set -e

# ========================================
# 配置
# ========================================
TEMP_DIR="/tmp/ssl-manager-upgrade-$$"
# release 服务 URL
# - 部署到 release 服务时，__RELEASE_URL__ 会被替换为实际地址
# - 如果未替换（本地运行），则需要通过 --url 参数或 version.json 配置
RELEASE_URL_PLACEHOLDER="__RELEASE_URL__"
if [[ "$RELEASE_URL_PLACEHOLDER" != "__RELEASE_URL__" ]]; then
    CUSTOM_RELEASE_URL="${CUSTOM_RELEASE_URL:-$RELEASE_URL_PLACEHOLDER}"
else
    CUSTOM_RELEASE_URL="${CUSTOM_RELEASE_URL:-}"
fi

# ========================================
# 颜色定义
# ========================================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# ========================================
# 日志函数
# ========================================
log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_step() { echo -e "${CYAN}[STEP]${NC} $1"; }

# upgrade.sh 所在目录 + 同级 scripts/ 子目录（用于 source bt-automate.sh 等）
UPGRADE_SH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_DIR="$UPGRADE_SH_DIR/scripts"

# ========================================
# 工具函数
# ========================================
cleanup() {
    if [ -d "$TEMP_DIR" ]; then
        rm -rf "$TEMP_DIR"
    fi
}
trap cleanup EXIT

get_timestamp() {
    # 与 PHP BackupManager 格式一致：2026-01-15_021459
    # 使用系统本机时区
    date '+%Y-%m-%d_%H%M%S'
}

# 全局变量：自动确认
AUTO_YES=false

# PHP CLI 绝对路径（detect_php_cmd 设置；环境变量 PHP_CMD 可覆盖探测）
# 所有 php artisan / composer install 都走这个路径，避免多版本系统下走错版本
PHP_CMD="${PHP_CMD:-}"

confirm() {
    local message="$1"
    local default="${2:-n}"

    # 自动确认模式
    if [ "$AUTO_YES" = true ]; then
        return 0
    fi

    if [ "$default" = "y" ]; then
        read -p "$message [Y/n]: " choice </dev/tty
        case "$choice" in
            n | N) return 1 ;;
            *) return 0 ;;
        esac
    else
        read -p "$message [y/N]: " choice </dev/tty
        case "$choice" in
            y | Y) return 0 ;;
            *) return 1 ;;
        esac
    fi
}

file_sha256() {
    local file="$1"
    if command -v sha256sum &>/dev/null; then
        sha256sum "$file" | cut -d' ' -f1
    elif command -v shasum &>/dev/null; then
        shasum -a 256 "$file" | cut -d' ' -f1
    else
        openssl dgst -sha256 "$file" | awk '{print $NF}'
    fi
}

# 把宝塔 PHP 目录名（如 83/84/810）渲染为友好版本号（如 8.3.21）。
# CLI 不可用时回落到目录名拼接。
#
# 与 deploy/scripts/common.sh::_php_pretty_version 对称。upgrade.sh 是独立部署入口
# （站在 install dir 顶层），不 source common.sh —— 因 common.sh 的 confirm 没有
# AUTO_YES 分支会覆盖本脚本的 confirm 函数。修改时请同步两处。
_php_pretty_version() {
    local ver="$1"
    local php_bin="/www/server/php/$ver/bin/php"
    if [ -x "$php_bin" ]; then
        local actual
        actual=$("$php_bin" -r 'echo PHP_VERSION;' 2>/dev/null)
        [ -n "$actual" ] && {
            echo "$actual"
            return 0
        }
    fi
    # 仅当 ver 为 2 位（83/84）时拼成 X.Y；3 位（810）直接打目录名
    if [ "${#ver}" -eq 2 ]; then
        echo "${ver:0:1}.${ver:1}"
    else
        echo "$ver"
    fi
}

# 探测一个可用的 PHP CLI（任意版本，仅用作工具：解析 JSON 等）。
# 成功设置全局 PHP_PROBE_BIN 并 return 0；找不到 return 1。
# 与 common.sh::_probe_any_php 对称，修改时请同步。
_probe_any_php() {
    if [ -n "${PHP_PROBE_BIN:-}" ] && [ -x "$PHP_PROBE_BIN" ]; then
        return 0
    fi
    local ver_dir php_bin
    for ver_dir in /www/server/php/*; do
        [ -d "$ver_dir" ] || continue
        php_bin="$ver_dir/bin/php"
        if [ -x "$php_bin" ]; then
            PHP_PROBE_BIN="$php_bin"
            return 0
        fi
    done
    if command -v php &>/dev/null; then
        PHP_PROBE_BIN=$(command -v php)
        return 0
    fi
    return 1
}

# 从 stdin 读单行 JSON 并提取顶层字段值（用于 BT API list 流式解析）。
# 用法：echo '{"id":1,"name":"foo"}' | _json_field name
# 调用方必须确保 PHP_CMD 已就绪（detect_php_cmd 之后）。
_json_field() {
    local field="$1"
    FIELD="$field" "$PHP_CMD" -r '
$d = @json_decode(stream_get_contents(STDIN), true);
echo is_array($d) && isset($d[getenv("FIELD")]) ? $d[getenv("FIELD")] : "";
' 2>/dev/null
}

# cron/supervisor 的 body/command 可能含多行或含 | 字符，
# 直接塞进 | 分隔的数组 entry 会被 IFS='|' read + here-string 截断
# （here-string 只取首行 + 多余 | 段并入末字段）。
# 故拼 entry 前对 body/command 单行 base64 编码（tr -d '\n' 去掉 base64 自带换行，
# 保证编码结果是无 | 无换行的单行 token），解析出 entry 后立即 _entry_decode 还原。
# base64 -d 在 GNU coreutils 与 FreeBSD/macOS 均可用，跨平台一致。
_entry_encode() {
    printf '%s' "$1" | base64 | tr -d '\n'
}

_entry_decode() {
    if [ -z "$1" ]; then
        return 0
    fi
    printf '%s' "$1" | base64 -d 2>/dev/null
}

# 从 php-requirements.json 读取顶层标量字段。
# 用法：_read_req_field <req_file> <field> [fallback]
# 与 common.sh::_read_req_field 对称，修改时请同步。
_read_req_field() {
    local req_file="$1"
    local field="$2"
    local fallback="${3:-}"
    if [ ! -f "$req_file" ]; then
        echo "$fallback"
        return 0
    fi
    if ! _probe_any_php; then
        echo "$fallback"
        return 0
    fi
    local result
    result=$(REQ_FILE="$req_file" FIELD="$field" "$PHP_PROBE_BIN" -r '
$d = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
echo is_array($d) && isset($d[getenv("FIELD")]) ? $d[getenv("FIELD")] : "";
' 2>/dev/null)
    if [ -n "$result" ]; then
        echo "$result"
    else
        echo "$fallback"
    fi
}

# 检查 backend/.env 的 cache/queue 是否启用 redis
# Laravel 实际读取：CACHE_DRIVER（config/cache.php）、QUEUE_CONNECTION（config/queue.php）
# 同时兼容 Laravel 11+ 的 CACHE_STORE 别名（虽然本项目未启用，但升级时 .env 可能已切到新 key）
# 返回 0=任一启用 redis；1=未启用 / .env 缺失 / PHP_CMD 不可用
# 用 PHP 解析以正确处理引号、行内注释、CRLF
_redis_required_from_env() {
    local env_file="$INSTALL_DIR/backend/.env"
    [ -f "$env_file" ] || return 1
    [ -n "$PHP_CMD" ] && [ -x "$PHP_CMD" ] || return 1

    ENV_FILE="$env_file" "$PHP_CMD" -r '
$content = @file_get_contents(getenv("ENV_FILE"));
if ($content === false) { exit(1); }
$keys = ["CACHE_DRIVER", "CACHE_STORE", "QUEUE_CONNECTION"];
foreach (preg_split("/\r?\n/", $content) as $line) {
    if (! preg_match("/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/", $line, $m)) continue;
    if (! in_array($m[1], $keys, true)) continue;
    $v = rtrim($m[2]);
    if ($v !== "" && ($v[0] === "\"" || $v[0] === "\x27")) {
        // 带引号：取首个匹配引号之间的内容
        $q = $v[0];
        $end = strpos($v, $q, 1);
        $v = $end === false ? substr($v, 1) : substr($v, 1, $end - 1);
    } else {
        // 裸值：行内注释 / 尾部空白裁掉
        $v = preg_replace("/\s+#.*$/", "", $v);
        $v = preg_split("/\s/", $v)[0];
    }
    if (strtolower(trim($v)) === "redis") { exit(0); }
}
exit(1);
' 2>/dev/null
}

# 把 latest/dev 占位符解析成具体版本号（与 install.sh _resolve_version 对齐）
# 用法：_resolve_version <releases.json file> <input_version: latest|dev|X.Y.Z[-beta]>
# 返回：解析后的具体版本号（如 0.4.23-beta）
#
# 实现：用 `{`/`}` 计数维护深度（depth），在 depth 1→≥2 时进入 release 块、≥2→1 时退出并判定。
# 旧实现（仅匹配 `^[[:space:]]*\{[[:space:]]*$/` 行作为块边界）会被 `assets` 内嵌的 `{`
# 误触发块重置，导致 indent=2 格式（json.dump 默认）下第一个 release 永远解析失败。
_resolve_version() {
    local releases_file="$1"
    local input="$2"
    if [[ "$input" != "latest" ]] && [[ "$input" != "dev" ]]; then
        echo "$input"
        return 0
    fi
    awk -v target="$input" '
        BEGIN { depth = 0; tag = ""; pre = "" }
        {
            line = $0
            n_open = 0; n_close = 0
            s = line; len = length(s)
            for (i = 1; i <= len; i++) {
                c = substr(s, i, 1)
                if (c == "{") n_open++
                else if (c == "}") n_close++
            }
            new_depth = depth + n_open - n_close

            if (depth == 1 && new_depth >= 2) { tag = ""; pre = "" }

            if (new_depth >= 2 || depth >= 2) {
                if (match(line, /"tag_name"[[:space:]]*:[[:space:]]*"v[^"]+"/)) {
                    t = substr(line, RSTART, RLENGTH)
                    gsub(/.*"tag_name"[[:space:]]*:[[:space:]]*"v/, "", t)
                    gsub(/".*/, "", t)
                    tag = t
                }
 if (match(line, /"prerelease"[[:space:]]*:[[:space:]]*true/)) pre = "true"
                if (match(line, /"prerelease"[[:space:]]*:[[:space:]]*false/)) pre = "false"
            }

            if (depth >= 2 && new_depth == 1) {
                if (tag != "") {
                    if (target == "latest" && pre == "false") { print tag; exit }
 if (target == "dev" && pre == "true" ) { print tag; exit }
                }
            }
            depth = new_depth
        }
    ' "$releases_file"
}

# 版本比较（v1 > v2 返回 0）
# 对齐后端 PHP version_compare 与前端 compareVersions：
# - 主版本段按整数比较（beta.10 > beta.9，避免字典序）
# - 主版本相同：正式版 > 预发布
# - 预发布关键字优先级 dev < alpha < beta < rc
# 不用 sort -V：GNU coreutils 8.32 实测把 0.5.2-beta.10 排在 0.5.2 之后，违反 SemVer
version_gt() {
    # 同时剥小写 v 和大写 V 前缀（对齐 PHP ltrim($v, 'vV') / TS /^v/i 大小写不敏感）
    local v1=${1#v}
    v1=${v1#V}
    local v2=${2#v}
    v2=${v2#V}

    [ "$v1" = "$v2" ] && return 1

    # 拆主版本号与预发布段
    local main1=${v1%%-*} main2=${v2%%-*}
    local pre1="" pre2=""
    [ "$v1" != "$main1" ] && pre1=${v1#*-}
    [ "$v2" != "$main2" ] && pre2=${v2#*-}

    # 主版本号按数字段比较
    local IFS=.
    local -a m1=($main1) m2=($main2)
    unset IFS
    local i len=${#m1[@]}
    [ ${#m2[@]} -gt $len ] && len=${#m2[@]}
    for ((i = 0; i < len; i++)); do
        local p1=${m1[i]:-0} p2=${m2[i]:-0}
        # 强制十进制，避免前导零被当作八进制（08/09 会语法错）
        p1=$((10#$p1)) p2=$((10#$p2))
        [ "$p1" -gt "$p2" ] && return 0
        [ "$p1" -lt "$p2" ] && return 1
    done

    # 主版本相同：正式版 > 预发布
    [ -z "$pre1" ] && [ -n "$pre2" ] && return 0
    [ -n "$pre1" ] && [ -z "$pre2" ] && return 1
    [ -z "$pre1" ] && [ -z "$pre2" ] && return 1

    # 都有预发布：拆关键字和数字（BSD/GNU sed 兼容写法）
    local kw1 kw2 num1 num2
    kw1=$(printf '%s' "$pre1" | sed 's/^\([a-zA-Z][a-zA-Z]*\).*/\1/' | tr '[:upper:]' '[:lower:]')
    kw2=$(printf '%s' "$pre2" | sed 's/^\([a-zA-Z][a-zA-Z]*\).*/\1/' | tr '[:upper:]' '[:lower:]')
    num1=$(printf '%s' "$pre1" | sed -n 's/^[a-zA-Z][a-zA-Z]*\.\{0,1\}\([0-9][0-9]*\).*/\1/p')
    num2=$(printf '%s' "$pre2" | sed -n 's/^[a-zA-Z][a-zA-Z]*\.\{0,1\}\([0-9][0-9]*\).*/\1/p')

    # 关键字优先级 — 未知关键字归到最高（保守，避免误降级）
    local ord1 ord2
    case "$kw1" in dev) ord1=0 ;; alpha) ord1=1 ;; beta) ord1=2 ;; rc) ord1=3 ;; *) ord1=99 ;; esac
    case "$kw2" in dev) ord2=0 ;; alpha) ord2=1 ;; beta) ord2=2 ;; rc) ord2=3 ;; *) ord2=99 ;; esac
    [ "$ord1" -gt "$ord2" ] && return 0
    [ "$ord1" -lt "$ord2" ] && return 1

    # 关键字相同：比数字
    num1=$((10#${num1:-0}))
    num2=$((10#${num2:-0}))
    [ "$num1" -gt "$num2" ]
}

# ========================================
# 检测函数
# ========================================

# 检测安装目录（仅支持宝塔部署，DEPLOY_MODE 固定为 bt）
detect_install() {
    # 老 docker 部署的拒绝（兼容性提示）
    _reject_legacy_docker() {
        if [ -f "$1/docker-compose.yml" ]; then
            log_error "检测到 Docker 部署目录: $1"
            log_error "已移除 Docker 部署支持。请迁移到宝塔部署后再运行 upgrade.sh。"
            return 1
        fi
        return 0
    }

    # 如果已手动指定目录
    if [ -n "$INSTALL_DIR" ]; then
        if [ -f "$INSTALL_DIR/backend/.ssl-manager" ] || [ -f "$INSTALL_DIR/backend/artisan" ]; then
            _reject_legacy_docker "$INSTALL_DIR" || return 1
            DEPLOY_MODE="bt"
            return 0
        fi
        log_error "指定目录无效: $INSTALL_DIR"
        return 1
    fi

    DEPLOY_MODE=""
    INSTALL_DIR=""

    # 搜索所有安装目录
    local found_dirs=()

    # 预设目录快速检测
    local preset_dirs=(
        "/www/wwwroot/ssl-manager"
        "/opt/ssl-manager"
    )

    for dir in "${preset_dirs[@]}"; do
        if [ -f "$dir/backend/.ssl-manager" ] || [ -f "$dir/backend/artisan" ]; then
            found_dirs+=("$dir")
        fi
    done

    # 系统范围搜索（补充非预设目录）
    while IFS= read -r marker; do
        [ -z "$marker" ] && continue
        local dir=$(dirname "$marker" | xargs dirname)
        local already_found=false
        for fd in "${found_dirs[@]}"; do
            [ "$fd" = "$dir" ] && already_found=true && break
        done
        $already_found || found_dirs+=("$dir")
    done < <(find /opt /www/wwwroot /home -maxdepth 4 -name ".ssl-manager" -path "*/backend/*" 2>/dev/null)

    if [ ${#found_dirs[@]} -eq 0 ]; then
        return 1
    elif [ ${#found_dirs[@]} -eq 1 ]; then
        INSTALL_DIR="${found_dirs[0]}"
        _reject_legacy_docker "$INSTALL_DIR" || return 1
        DEPLOY_MODE="bt"
        return 0
    else
        log_info "检测到多个 SSL Manager 安装："
        for i in "${!found_dirs[@]}"; do
            echo " $((i + 1)). ${found_dirs[$i]}"
        done

        while true; do
            read -p "请选择 (1-${#found_dirs[@]}): " choice </dev/tty
            if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le ${#found_dirs[@]} ]; then
                INSTALL_DIR="${found_dirs[$((choice - 1))]}"
                _reject_legacy_docker "$INSTALL_DIR" || return 1
                DEPLOY_MODE="bt"
                return 0
            fi
            log_error "无效选择"
        done
    fi
}

# 探测 PHP CLI 绝对路径（与 install.sh 选中版本对齐）
# 优先级：env PHP_CMD > BT vhost 反查 > 系统单版本探测
# 多版本但 vhost 反查失败 → 报错，要求显式 export PHP_CMD（不瞎猜）
detect_php_cmd() {
    # 环境变量已指定（最高优先级）
    if [ -n "$PHP_CMD" ]; then
        if [ ! -x "$PHP_CMD" ]; then
            log_error "环境变量 PHP_CMD 指向的路径不可执行: $PHP_CMD"
            return 1
        fi
        log_info "使用环境变量 PHP_CMD: $PHP_CMD"
        return 0
    fi

    log_step "探测 PHP CLI 版本"

    local found_ver=""
    local install_dir_norm="${INSTALL_DIR%/}"

    # 1. 反查 BT vhost：扫站点 nginx 配置，匹配 root 指向 $INSTALL_DIR 的站点，提取 enable-php-XX.conf
    # 用 awk 字符串比较避免 INSTALL_DIR 中的 . 在正则中错配
    if [ -d "/www/server/panel/vhost/nginx" ]; then
        local matched_vhost=""
        for vhost in /www/server/panel/vhost/nginx/*.conf; do
            [ -f "$vhost" ] || continue
            if awk -v dir="$install_dir_norm" '
                /^[[:space:]]*root[[:space:]]+/ {
                    line = $0
                    sub(/^[[:space:]]*root[[:space:]]+/, "", line)
                    sub(/[[:space:]]*;.*$/, "", line)
                    sub(/\/$/, "", line)
                    if (line == dir) { found = 1; exit }
                }
                END { exit (found ? 0 : 1) }
            ' "$vhost"; then
                matched_vhost="$vhost"
                break
            fi
        done

        if [ -n "$matched_vhost" ]; then
            found_ver=$(grep -oE 'enable-php-[0-9]+' "$matched_vhost" | head -1 | grep -oE '[0-9]+$')
            if [ -n "$found_ver" ]; then
                log_info "从 BT vhost $(basename "$matched_vhost") 识别 PHP 版本: $(_php_pretty_version "$found_ver")"
            fi
        fi
    fi

    # 2. fallback：扫 /www/server/php/* 并按 php-requirements.json 中 php_min 筛选（默认 8.3.0）
    # 多版本但 vhost 反查失败 → 报错，要求显式 export PHP_CMD（不瞎猜）
    if [ -z "$found_ver" ]; then
        local req_file="$UPGRADE_SH_DIR/php-requirements.json"
        local php_min
        php_min=$(_read_req_field "$req_file" "php_min" "8.3.0")

        local available=()
        for ver_dir in /www/server/php/*; do
            [ -d "$ver_dir" ] || continue
            local php_bin="$ver_dir/bin/php"
            [ -x "$php_bin" ] || continue
            local actual
            actual=$("$php_bin" -r 'echo PHP_VERSION;' 2>/dev/null) || continue
            if "$php_bin" -r "exit(version_compare('$actual','$php_min','>=')?0:1);" 2>/dev/null; then
                available+=("$(basename "$ver_dir")")
            fi
        done

        if [ ${#available[@]} -eq 1 ]; then
            found_ver="${available[0]}"
            log_info "系统仅装一个符合 >= $php_min 的 PHP 版本: $(_php_pretty_version "$found_ver")"
        elif [ ${#available[@]} -gt 1 ]; then
            log_error "系统装有多个符合要求的 PHP 版本（${available[*]}），但无法从 BT vhost 反查站点对应版本"
            log_info "INSTALL_DIR=$INSTALL_DIR"
            log_info "请手工指定: export PHP_CMD=/www/server/php/<ver>/bin/php"
            return 1
        else
            log_error "未检测到符合要求的 PHP 版本（需要 >= $php_min）"
            log_info "请在宝塔面板软件商店安装 PHP $php_min 或更高"
            return 1
        fi
    fi

    PHP_CMD="/www/server/php/$found_ver/bin/php"

    if [ ! -x "$PHP_CMD" ]; then
        log_error "PHP CLI 不可执行: $PHP_CMD"
        return 1
    fi

    log_success "PHP CLI: $PHP_CMD"
    return 0
}

# 获取当前版本
get_current_version() {
    local version_json="$INSTALL_DIR/version.json"
    local backend_version="$INSTALL_DIR/backend/version.json"

    # 优先从项目根目录的 version.json 读取
    if [ -f "$version_json" ]; then
        local ver=$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' "$version_json" | head -1 | cut -d'"' -f4)
        if [ -n "$ver" ]; then
            echo "$ver"
            return 0
        fi
    fi

    # 回退到 backend 目录（兼容旧版本路径）
    if [ -f "$backend_version" ]; then
        local ver=$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' "$backend_version" | head -1 | cut -d'"' -f4)
        if [ -n "$ver" ]; then
            echo "$ver"
            return 0
        fi
    fi

    echo "unknown"
}

# 从 version.json 读取 release_url
get_release_url() {
    local version_json="$INSTALL_DIR/version.json"
    local backend_version="$INSTALL_DIR/backend/version.json"

    # 优先从项目根目录读取
    if [ -f "$version_json" ]; then
        local url=$(grep -o '"release_url"[[:space:]]*:[[:space:]]*"[^"]*"' "$version_json" | head -1 | cut -d'"' -f4)
        if [ -n "$url" ]; then
            echo "$url"
            return 0
        fi
    fi

    # 回退到 backend 目录
    if [ -f "$backend_version" ]; then
        local url=$(grep -o '"release_url"[[:space:]]*:[[:space:]]*"[^"]*"' "$backend_version" | head -1 | cut -d'"' -f4)
        if [ -n "$url" ]; then
            echo "$url"
            return 0
        fi
    fi

    echo ""
}

# 从 version.json 读取 channel
get_channel() {
    local version_json="$INSTALL_DIR/version.json"
    local backend_version="$INSTALL_DIR/backend/version.json"

    # 优先从项目根目录读取
    if [ -f "$version_json" ]; then
        local channel=$(grep -o '"channel"[[:space:]]*:[[:space:]]*"[^"]*"' "$version_json" | head -1 | cut -d'"' -f4)
        if [ -n "$channel" ]; then
            echo "$channel"
            return 0
        fi
    fi

    # 回退到 backend 目录
    if [ -f "$backend_version" ]; then
        local channel=$(grep -o '"channel"[[:space:]]*:[[:space:]]*"[^"]*"' "$backend_version" | head -1 | cut -d'"' -f4)
        if [ -n "$channel" ]; then
            echo "$channel"
            return 0
        fi
    fi

    echo "main"
}

# ========================================
# 下载函数
# ========================================

download_upgrade_package() {
    local version="$1"
    local save_path="$2"

    # 检查必须的配置
    if [ -z "$CUSTOM_RELEASE_URL" ]; then
        log_error "未配置 release 服务 URL"
        log_info "请使用 --url 参数指定，或在 version.json 中配置 release_url"
        return 1
    fi

    local base_url="${CUSTOM_RELEASE_URL%/}" # 移除末尾斜杠
    local url=""

    # 构建 URL
    if [[ "$version" == "latest" ]]; then
        url="$base_url/latest/ssl-manager-upgrade-latest.zip"
    elif [[ "$version" == "dev" ]]; then
        url="$base_url/dev-latest/ssl-manager-upgrade-latest.zip"
    else
        # 开发版放在 dev/ 目录，正式版放在 main/ 目录
        if [[ "$version" =~ -(dev|alpha|beta|rc) ]]; then
            url="$base_url/dev/v$version/ssl-manager-upgrade-$version.zip"
        else
            url="$base_url/main/v$version/ssl-manager-upgrade-$version.zip"
        fi
    fi

    log_info "下载: $url"

    local curl_output=""
    local curl_exit=0
    curl_output=$(curl -fsSL --connect-timeout 10 --max-time 300 -o "$save_path" "$url" 2>&1) || curl_exit=$?

    if [ $curl_exit -eq 0 ]; then
        log_success "下载成功"
        return 0
    fi

    # 清理可能的部分下载
    [ -f "$save_path" ] && rm -f "$save_path"

    log_error "下载失败 (curl exit code: $curl_exit)"
    [ -n "$curl_output" ] && log_error "$curl_output"
    case $curl_exit in
        6) log_info "提示: 无法解析域名，请检查 DNS 或网络配置" ;;
        7) log_info "提示: 无法连接服务器" ;;
        22) log_info "提示: 服务器返回错误（文件可能不存在）" ;;
        28) log_info "提示: 下载超时" ;;
        35 | 51 | 60) log_info "提示: SSL/TLS 错误，旧系统可尝试 yum update ca-certificates" ;;
    esac
    return 1
}

# ========================================
# 升级流程
# ========================================

# 创建备份
create_backup() {
    # 日志输出到 stderr，避免被 $() 捕获
    log_step "创建备份..." >&2

    local backup_dir="$INSTALL_DIR/backups"
    local backup_id=$(get_timestamp)
    local backup_path="$backup_dir/$backup_id"
    local current_version=$(get_current_version)

    mkdir -p "$backup_path"

    # 备份后端代码（压缩包格式，与 PHP BackupManager 一致）
    log_info "备份后端代码..." >&2
    local backend_tmp="$TEMP_DIR/backup_backend"
    mkdir -p "$backend_tmp"

    # 只复制与 PHP BackupManager 相同的目录：app, config, database, routes, bootstrap
    for dir in app config database routes bootstrap; do
        if [ -d "$INSTALL_DIR/backend/$dir" ]; then
            cp -r "$INSTALL_DIR/backend/$dir" "$backend_tmp/"
        fi
    done

    # 只复制与 PHP BackupManager 相同的文件：composer.json, composer.lock, .env
    for file in composer.json composer.lock .env; do
        if [ -f "$INSTALL_DIR/backend/$file" ]; then
            cp "$INSTALL_DIR/backend/$file" "$backend_tmp/"
        fi
    done

    # 复制 version.json 到备份（放在 backend 同级目录）
    local version_src="$INSTALL_DIR/version.json"
    [ -f "$version_src" ] && cp "$version_src" "$backend_tmp/../version.json"

    # 创建 backend.zip
    (cd "$backend_tmp" && zip -qr "$backup_path/backend.zip" .)
    # 添加 version.json 到 zip（使用相对路径 ../version.json）
    [ -f "$backend_tmp/../version.json" ] && (cd "$backend_tmp" && zip -q "$backup_path/backend.zip" "../version.json")

    # 备份前端代码
    local has_frontend=false
    if [ -d "$INSTALL_DIR/frontend" ]; then
        log_info "备份前端代码..." >&2
        local frontend_tmp="$TEMP_DIR/backup_frontend"
        mkdir -p "$frontend_tmp"

        for app in admin user web; do
            if [ -d "$INSTALL_DIR/frontend/$app" ]; then
                cp -r "$INSTALL_DIR/frontend/$app" "$frontend_tmp/"
                has_frontend=true
            fi
        done

        if [ "$has_frontend" = true ]; then
            (cd "$frontend_tmp" && zip -qr "$backup_path/frontend.zip" .)
        fi
    fi

    # 记录备份信息（与 PHP BackupManager 格式一致）
    cat >"$backup_path/backup.json" <<EOF
{
    "id": "$backup_id",
    "version": "$current_version",
    "created_at": "$(date -Iseconds)",
    "includes": {
        "backend": true,
        "frontend": $has_frontend,
        "database": false
    }
}
EOF

    log_success "备份完成: $backup_path" >&2
    # 只输出路径到 stdout，供调用者捕获
    echo "$backup_path"
}

# 内部：跑一次 PHP 环境校验，把结果放到全局变量供 check_php_environment 主循环消费
# 输出变量：
#   PHP_ENV_OK                  全部通过时 true
#   PHP_ENV_VERSION_ERROR       true 表示 PHP 版本不达标
#   PHP_ENV_MISSING_EXT         空格分隔的缺失必需扩展（含 .env 推断出的 redis）
#   PHP_ENV_DISABLED_FN         空格分隔的被禁用必需函数
#   PHP_ENV_MISSING_REC         空格分隔的缺失推荐扩展（warning）
#   PHP_ENV_CURRENT_PHP / PHP_ENV_PHP_MIN / PHP_ENV_PHP_RECOMMENDED
#   PHP_ENV_REDIS_DYN_REQUIRED  true 表示 backend/.env 启用 redis，已把 redis 升级为必装
_php_env_run_checks() {
    local req_file="$1"

    PHP_ENV_OK=true
    PHP_ENV_VERSION_ERROR=false
    PHP_ENV_MISSING_EXT=""
    PHP_ENV_DISABLED_FN=""
    PHP_ENV_MISSING_REC=""
    PHP_ENV_CURRENT_PHP=$("$PHP_CMD" -r 'echo PHP_VERSION;' 2>/dev/null)
    # 用 env var 传 req_file（与 _read_req_field 一致），避免路径含单引号/空格时 PHP 字符串拼接断开
    PHP_ENV_PHP_MIN=$(REQ_FILE="$req_file" "$PHP_CMD" -r '
$d = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
echo is_array($d) && isset($d["php_min"]) ? $d["php_min"] : "";
' 2>/dev/null)
    PHP_ENV_PHP_RECOMMENDED=$(REQ_FILE="$req_file" "$PHP_CMD" -r '
$d = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
echo is_array($d) && isset($d["php_recommended"]) ? $d["php_recommended"] : "";
' 2>/dev/null)

    # PHP 版本
    if [ -n "$PHP_ENV_PHP_MIN" ]; then
        if ! PHP_MIN="$PHP_ENV_PHP_MIN" "$PHP_CMD" -r 'exit(version_compare(PHP_VERSION, getenv("PHP_MIN"), ">=") ? 0 : 1);' 2>/dev/null; then
            PHP_ENV_VERSION_ERROR=true
            PHP_ENV_OK=false
        fi
    fi

    # 必需扩展
    local missing_ext=()
    while IFS= read -r ext; do
        [ -z "$ext" ] && continue
        if ! EXT="$ext" "$PHP_CMD" -r 'exit(extension_loaded(getenv("EXT")) ? 0 : 1);' 2>/dev/null; then
            missing_ext+=("$ext")
        fi
    done < <(REQ_FILE="$req_file" "$PHP_CMD" -r '
$r = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
foreach ($r["extensions"]["required"] ?? [] as $x) { echo $x . PHP_EOL; }
' 2>/dev/null)

    # 动态必装：backend/.env 中 cache/queue 任一启用 redis 时，redis 升级为必装扩展
    # 用全局变量记录判定结果，下面推荐扩展循环用同一值跳过 redis，避免重复报告
    PHP_ENV_REDIS_DYN_REQUIRED=false
    if _redis_required_from_env; then
        PHP_ENV_REDIS_DYN_REQUIRED=true
        if ! EXT="redis" "$PHP_CMD" -r 'exit(extension_loaded(getenv("EXT")) ? 0 : 1);' 2>/dev/null; then
            # 防御性去重：php-requirements.json 未来若把 redis 移到 required 也不会重复
            local _already_listed=false
            for e in "${missing_ext[@]}"; do
                [ "$e" = "redis" ] && _already_listed=true && break
            done
            [ "$_already_listed" = false ] && missing_ext+=("redis")
        fi
    fi

    if [ ${#missing_ext[@]} -gt 0 ]; then
        PHP_ENV_MISSING_EXT="${missing_ext[*]}"
        PHP_ENV_OK=false
    fi

    # 推荐扩展（warning，不阻断）；redis 若已被升级为必装，从推荐路径跳过避免重复报告
    local missing_rec=()
    while IFS= read -r ext; do
        [ -z "$ext" ] && continue
        [ "$ext" = "redis" ] && [ "$PHP_ENV_REDIS_DYN_REQUIRED" = true ] && continue
        if ! EXT="$ext" "$PHP_CMD" -r 'exit(extension_loaded(getenv("EXT")) ? 0 : 1);' 2>/dev/null; then
            missing_rec+=("$ext")
        fi
    done < <(REQ_FILE="$req_file" "$PHP_CMD" -r '
$r = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
foreach ($r["extensions"]["recommended"] ?? [] as $x) { echo $x . PHP_EOL; }
' 2>/dev/null)
    if [ ${#missing_rec[@]} -gt 0 ]; then
        PHP_ENV_MISSING_REC="${missing_rec[*]}"
    fi

    # 必需函数检测：function_exists（CLI 进程实时状态）∪ ini 扫描（ini 配置最终生效）
    # 仅靠 function_exists 在某些场景会漏检：
    #   - PHP-CLI 用 php-cli.ini（默认不禁用），PHP-FPM 用 php.ini（禁用了），项目 CLI 模式不一定走 php.ini
    #   - BT API 改 ini 后，已运行进程不感知，但新启 PHP-CLI 会感知（不过我们调用是新进程，function_exists 应能感知；保留 ini 扫描作并集 防护）
    # 把 BT 站点 PHP 目录下所有 ini 都扫一遍，提取 disable_functions（合并集）
    local php_dir
    php_dir=$(dirname "$(dirname "$PHP_CMD")")
    local disabled_in_ini=""
    local ini_file ini_value
    for ini_file in "$php_dir/etc/php.ini" "$php_dir/etc/php-cli.ini" "$php_dir/etc/php-fpm.ini"; do
        [ -f "$ini_file" ] || continue
        # 提取非注释行的 disable_functions =，去掉两侧空白和引号，截断行尾注释
        ini_value=$(awk '
            /^[[:space:]]*disable_functions[[:space:]]*=/ {
                val = $0
                sub(/^[[:space:]]*disable_functions[[:space:]]*=[[:space:]]*/, "", val)
                sub(/[[:space:]]*;.*$/, "", val)
                gsub(/[[:space:]"]/, "", val)
                print val
                exit
            }
        ' "$ini_file")
        [ -n "$ini_value" ] && disabled_in_ini="${disabled_in_ini},${ini_value}"
    done

    local disabled_fn=()
    while IFS= read -r fn; do
        [ -z "$fn" ] && continue
        local is_disabled=false
        # 1. PHP-CLI function_exists（进程实时）
        if ! FN="$fn" "$PHP_CMD" -r 'exit(function_exists(getenv("FN")) ? 0 : 1);' 2>/dev/null; then
            is_disabled=true
        fi
        # 2. ini 扫描（覆盖 CLI 与 FPM 共享 ini / BT API 已写入但 PHP-CLI 副本未感知等场景）
        if [ "$is_disabled" = false ] && [ -n "$disabled_in_ini" ]; then
            if echo ",$disabled_in_ini," | grep -qE ",${fn},"; then
                is_disabled=true
            fi
        fi
        if [ "$is_disabled" = true ]; then
            disabled_fn+=("$fn")
        fi
    done < <(REQ_FILE="$req_file" "$PHP_CMD" -r '
$r = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
foreach ($r["functions"]["required"] ?? [] as $x) { echo $x . PHP_EOL; }
' 2>/dev/null)
    if [ ${#disabled_fn[@]} -gt 0 ]; then
        PHP_ENV_DISABLED_FN="${disabled_fn[*]}"
        PHP_ENV_OK=false
    fi
}

# 打印手工修复指引
_php_env_print_manual() {
    log_error "请通过宝塔面板（或包管理器）修复："
    if [ "$PHP_ENV_VERSION_ERROR" = true ]; then
        log_error "  1. PHP 版本：宝塔 → 软件商店 → 安装 PHP ${PHP_ENV_PHP_RECOMMENDED:-${PHP_ENV_PHP_MIN%.*}}+，网站设置切换 PHP 版本"
    fi
    if [ -n "$PHP_ENV_MISSING_EXT" ]; then
        log_error "  - 扩展：宝塔 → 软件商店 → PHP 管理 → 安装扩展（$PHP_ENV_MISSING_EXT）"
    fi
    if [ -n "$PHP_ENV_DISABLED_FN" ]; then
        log_error "  - 函数：编辑对应 PHP 版本的 php.ini / php-cli.ini / php-fpm.ini（凡含该函数的文件都要改），从 disable_functions 删除（$PHP_ENV_DISABLED_FN），保存后重启 PHP-FPM"
    fi
}

# 询问并通过宝塔 API 自动修复（仅扩展/函数；PHP 版本切换不在自动范围）
# return 0 修复完成（含部分失败）；1 用户拒绝 / 没有 BT API 可用 / 验证失败
_php_env_try_bt_fix() {
    local req_file="$1"

    echo ""
    log_info "可通过宝塔 API 自动安装扩展、启用被禁函数、重启 PHP-FPM"
    log_info "需要 API key：宝塔面板 → 设置 → API 密钥（并确认当前 IP 在白名单内）"
    # 走 confirm 函数统一处理：AUTO_YES=true 自动同意；交互模式从 /dev/tty 读避免管道场景误退
    if ! confirm "是否使用宝塔 API 自动修复？" "n"; then
        log_info "已选择手工修复"
        return 1
    fi

    if [ ! -f "$SCRIPT_DIR/bt-automate.sh" ]; then
        log_warning "未找到 $SCRIPT_DIR/bt-automate.sh，无法自动修复"
        return 1
    fi

    # shellcheck source=scripts/bt-automate.sh
    source "$SCRIPT_DIR/bt-automate.sh"

    # 与 install.sh detect_bt_key 对齐：自动从 /www/server/panel/config/api.json 探测
    # 探测失败直接走手工，不当场 read 收 key（避免明文 key 进终端历史 + 行为一致）
    if ! bt_resolve_key 2>/dev/null; then
        log_warning "未探测到宝塔 API key"
        log_info "请到面板 → 设置 → API 接口 启用并把当前 IP 加入白名单，然后重跑 upgrade.sh"
        return 1
    fi

    if ! bt_verify_api_key; then
        log_error "宝塔 API key 验证失败（无效 key / IP 不在白名单 / 面板关闭 API）"
        return 1
    fi
    log_success "宝塔 API 就绪"

    local php_ver_compact
    php_ver_compact=$(_bt_php_ver_compact "$PHP_ENV_CURRENT_PHP")

    # 装扩展：复用 bt-deps.sh::auto_install_ext（三路径 fallback：BT API → legacy script → ini 直写）
    # 升级包必带 scripts/、SCRIPT_DIR 已重定向到 $src_dir/scripts，bt-deps.sh 缺失意味着升级包损坏
    # 失败的扩展会保留为 failed，bt-deps.sh exit 非 0；这里用 || true 不阻断后续函数启用/FPM 重启
    if [ -n "$PHP_ENV_MISSING_EXT" ]; then
        if [ ! -f "$SCRIPT_DIR/bt-deps.sh" ]; then
            log_error "依赖脚本不存在: $SCRIPT_DIR/bt-deps.sh（升级包损坏或路径异常）"
            return 1
        fi
        # shellcheck disable=SC2086
        PHP_VERSION="$php_ver_compact" PHP_CMD="$PHP_CMD" \
            bash "$SCRIPT_DIR/bt-deps.sh" auto_install_ext $PHP_ENV_MISSING_EXT || true

        # BT API 装扩展会 reset 当前 PHP 的 ini（含 disable_functions），导致原本可用的函数被重新禁用
        # 重扫一次让本轮自动修复同时处理"装扩展副作用"产生的禁用函数
        if [ -n "$req_file" ]; then
            _php_env_run_checks "$req_file"
        fi
    fi

    # 启用函数（含装扩展副作用后新出现的禁用项）
    # 复用 bt-deps.sh::enable_functions 子命令：直接 sed 改 php.ini + php-cli.ini + php-fpm.ini
    # 不走 BT API GetPHPConfig（在 CLI ini 单独配置时返回不准；曾遇 "已为空" 但实际禁用的场景）
    if [ -n "$PHP_ENV_DISABLED_FN" ]; then
        # shellcheck disable=SC2086
        PHP_VERSION="$php_ver_compact" PHP_CMD="$PHP_CMD" \
            bash "$SCRIPT_DIR/bt-deps.sh" enable_functions $PHP_ENV_DISABLED_FN || true
    fi

    # 此处不再 bt_reload_php_fpm：升级流程全程 CLI（重新校验、artisan migrate、composer install 等都是新启 PHP-CLI 进程，
    # 直接读 ini 文件，不依赖 PHP-FPM reload）。bt-deps.sh::auto_install_ext 内部已用 systemctl restart 兜底 FPM；
    # 升级末尾步骤 15b 会做一次 BT API reload 给 web 入口生效（届时 FPM 已稳定，不撞 systemctl 余波）。
    return 0
}

# 校验 PHP 环境是否满足新版本需求
# 检测失败时分类处理：
#   - PHP 版本不达标 → 必须手工切换 PHP 版本，输出指引后 exit 1
#   - 仅扩展/函数不达标 → 询问宝塔 API 自动修复；不愿/失败回落手工指引 exit 1
check_php_environment() {
    local src_dir="$1"
    local req_file="$src_dir/php-requirements.json"

    if [ ! -f "$req_file" ]; then
        log_info "未找到 php-requirements.json，跳过 PHP 环境检测（旧版本兼容）"
        return 0
    fi

    log_step "校验 PHP 运行环境..."
    _php_env_run_checks "$req_file"
    log_info "当前 PHP: $PHP_ENV_CURRENT_PHP; 最低要求: ${PHP_ENV_PHP_MIN:-N/A}"

    # 提示 redis 动态升级为必装（仅在 .env 启用 redis 时输出，避免无关项目刷屏）
    if [ "$PHP_ENV_REDIS_DYN_REQUIRED" = true ]; then
        log_info "backend/.env 中 cache/queue 已启用 redis，redis 扩展按必装处理"
    fi

    # 推荐项警告（每次都输出，不阻断）
    if [ -n "$PHP_ENV_MISSING_REC" ]; then
        log_warning "缺失推荐扩展: $PHP_ENV_MISSING_REC（不阻断升级，但建议安装以获得最佳性能/功能）"
    fi

    if [ "$PHP_ENV_OK" = true ]; then
        log_success "PHP 环境校验通过"
        return 0
    fi

    # 输出失败摘要
    log_error "═══════════════════════════════════════════════════════"
    log_error "PHP 环境校验失败："
    if [ "$PHP_ENV_VERSION_ERROR" = true ]; then
        log_error "  - PHP 版本过低：当前 $PHP_ENV_CURRENT_PHP，需要 >= $PHP_ENV_PHP_MIN"
    fi
    if [ -n "$PHP_ENV_MISSING_EXT" ]; then
        log_error "  - 缺失必需扩展: $PHP_ENV_MISSING_EXT"
    fi
    if [ -n "$PHP_ENV_DISABLED_FN" ]; then
        log_error "  - 必需函数被禁用: $PHP_ENV_DISABLED_FN"
    fi

    # PHP 版本错误 → 必须手工
    if [ "$PHP_ENV_VERSION_ERROR" = true ]; then
        log_error ""
        log_error "PHP 版本切换敏感（影响整个站点），不在自动修复范围。请按以下步骤："
        log_error "  1. 宝塔 → 软件商店 → 安装 PHP ${PHP_ENV_PHP_RECOMMENDED:-${PHP_ENV_PHP_MIN%.*}}+"
        log_error "  2. 网站设置 → 把当前站点 PHP 版本切到新版本"
        log_error "  3. 切换后重新执行：bash upgrade.sh <版本号>"
        log_error "  4. 重新执行时本脚本会再次检测，如扩展/函数仍不达标会引导自动修复"
        log_error "═══════════════════════════════════════════════════════"
        exit 1
    fi

    # 仅扩展/函数错误 → 询问 BT API 自动修复
    if _php_env_try_bt_fix "$req_file"; then
        # 不再 sleep 等 FPM 重载：CLI 重新校验启新进程直接读 ini，与 FPM 状态无关
        log_step "重新校验 PHP 环境..."
        _php_env_run_checks "$req_file"

        if [ "$PHP_ENV_OK" = true ]; then
            log_success "PHP 环境校验通过"
            return 0
        fi

        # 仍未通过 → 手工指引退出
        log_error ""
        log_error "自动修复后仍有问题："
        [ -n "$PHP_ENV_MISSING_EXT" ] && log_error "  - 缺失必需扩展: $PHP_ENV_MISSING_EXT"
        [ -n "$PHP_ENV_DISABLED_FN" ] && log_error "  - 必需函数被禁用: $PHP_ENV_DISABLED_FN"
        log_error ""
        _php_env_print_manual
        log_error "═══════════════════════════════════════════════════════"
        exit 1
    fi

    # 用户拒绝自动修复 → 手工指引退出
    log_error ""
    _php_env_print_manual
    log_error "═══════════════════════════════════════════════════════"
    exit 1
}

# 扫 cron / supervisor 中的 PHP 绝对路径，列出与当前 PHP_CMD 不一致的项
# 对 install.sh 自管（命令含 $INSTALL_DIR/backend/artisan）且类型内唯一的项，自动覆盖更新
# 不满足"自管 + 唯一"的项保留原"列出 + 警告"行为，由用户手工到面板改
# 通常在切换 PHP 版本后才有不一致；常规升级是 no-op
update_jobs_php_path() {
    local target_php="$PHP_CMD"

    # 需要 bt-automate + BT_KEY
    if [ ! -f "$SCRIPT_DIR/bt-automate.sh" ]; then
        return 0
    fi

    if ! declare -f bt_list_crontab_all >/dev/null 2>&1; then
        # shellcheck source=scripts/bt-automate.sh
        source "$SCRIPT_DIR/bt-automate.sh"
    fi

    # check_php_environment 走 BT 自动修复分支时已经探测过 BT_KEY；这里再尝试一次以覆盖直接通过 PHP 检测的场景
    if [ -z "$BT_KEY" ]; then
        bt_resolve_key 2>/dev/null || {
            log_info "未探测到宝塔 API key，跳过 cron/supervisor PHP 路径检查"
            log_info "（如最近切换了 PHP 版本，请手工到宝塔面板核对计划任务和 Supervisor 守护进程的 PHP 路径）"
            return 0
        }
    fi

    if ! bt_verify_api_key 2>/dev/null; then
        log_info "宝塔 API key 不可用，跳过 cron/supervisor PHP 路径检查"
        return 0
    fi

    log_step "扫描 cron / supervisor 的 PHP 绝对路径..."
    log_info "  期望 PHP: $target_php"

    # install.sh 自管特征（cron: schedule:run；supervisor: queue:work），按 INSTALL_DIR 锚定
    local installer_cron_marker="$INSTALL_DIR/backend/artisan schedule:run"
    local installer_supervisor_marker="$INSTALL_DIR/backend/artisan queue:work"

    # 分类容器：install.sh 自管 vs 其他（仅手工提示）
    local installer_cron_entries=() other_cron_entries=()
    local installer_supervisor_entries=() other_supervisor_entries=()

    # cron
    # 扫描分两类不一致：
    #   - 含 PHP 绝对路径但版本不对（如 /www/server/php/83/bin/php → 应改 84）
    #   - 命令含 install.sh 自管 marker 但用裸 php（依赖 PATH，可能跑错版本）
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        local body id name
        body=$(echo "$line" | _json_field "sBody")
        [ -z "$body" ] && continue

        local is_installer=false
        # set -e 下不能写 `cmd && x=y` —— grep 无匹配时整体非零会触发 exit
        if echo "$body" | grep -qF "$installer_cron_marker"; then
            is_installer=true
        fi

        local found_paths=""
        local has_bare_php=false
        if echo "$body" | grep -qE '/www/server/php/[0-9]+/bin/php'; then
            # 多个匹配用逗号分隔（避免换行混进 | 分隔的 entry 字段后被 IFS read 截断）
            found_paths=$(echo "$body" | grep -oE '/www/server/php/[0-9]+/bin/php' | sort -u | tr '\n' ',' | sed 's/,$//')
        elif echo "$body" | grep -qE '(^|[[:space:];&|])php[[:space:]]+'; then
            # 命令开头 / 分隔符后紧跟 `php ` 的裸命令（避开 php-cli / php-fpm / php8.X 等变体）
            has_bare_php=true
        fi

        local needs_fix=false
        if [ -n "$found_paths" ]; then
            local paths_arr p
            IFS=',' read -ra paths_arr <<<"$found_paths"
            for p in "${paths_arr[@]}"; do
                [ "$p" != "$target_php" ] && needs_fix=true && break
            done
        elif [ "$has_bare_php" = true ] && [ "$is_installer" = true ]; then
            # 裸 php 走 PATH，CLI 默认版本可能与站点不同，install.sh 自管的任务一律视为需修
            needs_fix=true
        fi
        # 非 installer 自管 + 裸 php → 不动（用户脚本，可能有意依赖 PATH）

        if [ "$needs_fix" = false ]; then
            continue
        fi

        id=$(echo "$line" | _json_field "id")
        name=$(echo "$line" | _json_field "name")
        local display_paths="${found_paths:-裸 php（走 PATH，版本不确定）}"
        # body 可能多行/含 |，编码后入 entry 末字段（解析后 _entry_decode 还原）
        local body_enc
        body_enc=$(_entry_encode "$body")

        if [ "$is_installer" = true ]; then
            local ctype cwhere1
            ctype=$(echo "$line" | _json_field "type")
            cwhere1=$(echo "$line" | _json_field "where1")
            if [ "$ctype" = "minute-n" ] && [ -n "$cwhere1" ]; then
                installer_cron_entries+=("$id|$name|$display_paths|$ctype|$cwhere1|$body_enc")
            else
                other_cron_entries+=("$id|$name|$display_paths|$body_enc")
            fi
        else
            other_cron_entries+=("$id|$name|$display_paths|$body_enc")
        fi
    done < <(bt_list_crontab_all 2>/dev/null)

    # supervisor
    # 识别策略与 cron 不同：supervisor 有"进程目录"（path 字段）作为工作目录，
    # 命令字符串可能是裸 `php artisan queue:work`（依赖工作目录），不含 $INSTALL_DIR
    # 故联合判定：command 含 `artisan queue:work` + path 等于 $INSTALL_DIR/backend
    local installer_supervisor_path="${INSTALL_DIR%/}/backend"
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        local command program user path numprocs
        command=$(echo "$line" | _json_field "command")
        [ -z "$command" ] && continue
        path=$(echo "$line" | _json_field "path")
        local path_norm="${path%/}"

        local is_installer=false
        # (a) 命令含完整路径 install.sh 锚定
        if echo "$command" | grep -qF "$installer_supervisor_marker"; then
            is_installer=true
        # (b) 命令含 artisan queue:work + 工作目录是本站 backend（裸 php / 裸 artisan 场景）
        elif echo "$command" | grep -qE 'artisan[[:space:]]+queue:work' &&
            [ "$path_norm" = "$installer_supervisor_path" ]; then
            is_installer=true
        fi

        local found_paths=""
        local has_bare_php=false
        if echo "$command" | grep -qE '/www/server/php/[0-9]+/bin/php'; then
            found_paths=$(echo "$command" | grep -oE '/www/server/php/[0-9]+/bin/php' | sort -u | tr '\n' ',' | sed 's/,$//')
        elif echo "$command" | grep -qE '(^|[[:space:];&|])php[[:space:]]+'; then
            has_bare_php=true
        fi

        local needs_fix=false
        if [ -n "$found_paths" ]; then
            local paths_arr p
            IFS=',' read -ra paths_arr <<<"$found_paths"
            for p in "${paths_arr[@]}"; do
                [ "$p" != "$target_php" ] && needs_fix=true && break
            done
        elif [ "$has_bare_php" = true ] && [ "$is_installer" = true ]; then
            needs_fix=true
        fi

        if [ "$needs_fix" = false ]; then
            continue
        fi

        program=$(echo "$line" | _json_field "program")
        # 兼容旧 list 函数仅返回 name 的场景
        [ -z "$program" ] && program=$(echo "$line" | _json_field "name")
        local display_paths="${found_paths:-裸 php（走 PATH，版本不确定）}"
        # command 可能多行/含 |，编码后入 entry 末字段（解析后 _entry_decode 还原）
        local command_enc
        command_enc=$(_entry_encode "$command")

        if [ "$is_installer" = true ]; then
            user=$(echo "$line" | _json_field "user")
            numprocs=$(echo "$line" | _json_field "numprocs")
            installer_supervisor_entries+=("$program|${user:-www}|$path|$numprocs|$display_paths|$command_enc")
        else
            other_supervisor_entries+=("$program|$display_paths|$command_enc")
        fi
    done < <(bt_list_supervisor_all 2>/dev/null)

    local total_mismatch=$((${#installer_cron_entries[@]} + ${#other_cron_entries[@]} + \
        ${#installer_supervisor_entries[@]} + ${#other_supervisor_entries[@]}))
    if [ "$total_mismatch" -eq 0 ]; then
        log_success "  cron / supervisor 的 PHP 路径与当前一致"
        return 0
    fi

    # ===== 自动修复阶段：install.sh 自管 + 类型内唯一才覆盖更新 =====
    local auto_fixed=0

    if [ ${#installer_cron_entries[@]} -eq 1 ]; then
        local entry=${installer_cron_entries[0]}
        local cid cname paths ctype cwhere1 cbody_enc cbody new_body
        IFS='|' read -r cid cname paths ctype cwhere1 cbody_enc <<<"$entry"
        cbody=$(_entry_decode "$cbody_enc")
        # 两步替换：① 已是绝对路径但版本不对 → sed 整体替换 ② 裸 php token → 替换为 target_php
        # 裸 php 模式：命令开头 / 空格 / ; & | 后紧跟 `php ` 才认（避开 php-cli / php-fpm / php8.X）
        new_body=$(echo "$cbody" | sed -E "s#/www/server/php/[0-9]+/bin/php#$target_php#g")
        new_body=$(echo "$new_body" | sed -E "s#(^|[[:space:];&|])php([[:space:]]+)#\1${target_php}\2#g")
        log_step "自动更新 cron [$cname] PHP 路径（install.sh 自管，唯一；保留原频率 $ctype=$cwhere1）"
        # 三段语义：① 删旧 → ② 加新（失败时 → ③ 用原 body 回滚）
        # 防止 DelCrontab 成功 + AddCrontab 失败的窗口里 cron 静默消失
        if _bt_api_post "/crontab?action=DelCrontab" "--data-urlencode 'id=$cid'" >/dev/null 2>&1; then
            sleep 1
            if bt_add_crontab "$cname" "$ctype" "$cwhere1" "$new_body"; then
                auto_fixed=$((auto_fixed + 1))
            else
                log_warning "  cron [$cname] 添加新版失败，尝试用原命令回滚..."
                if bt_add_crontab "$cname" "$ctype" "$cwhere1" "$cbody"; then
                    log_info "  原 cron 已恢复（PHP 路径仍是旧版本，需手工修改）"
                    other_cron_entries+=("$cid|$cname|$paths|$cbody_enc")
                else
                    log_error "  ⚠️  cron [$cname] 自动更新 + 回滚均失败！请到宝塔面板手工添加"
                    log_error "  原命令: $cbody"
                    log_error "  新命令: $new_body"
                fi
            fi
        else
            log_warning "  cron [$cname] DelCrontab 失败，跳过自动修复"
            other_cron_entries+=("$cid|$cname|$paths|$cbody_enc")
        fi
    elif [ ${#installer_cron_entries[@]} -gt 1 ]; then
        log_info "  检测到 ${#installer_cron_entries[@]} 个 install.sh 风格 cron 任务，非唯一，保留手工提示"
        for entry in "${installer_cron_entries[@]}"; do
            local cid cname paths ctype cwhere1 cbody_enc
            IFS='|' read -r cid cname paths ctype cwhere1 cbody_enc <<<"$entry"
            other_cron_entries+=("$cid|$cname|$paths|$cbody_enc")
        done
    fi

    if [ ${#installer_supervisor_entries[@]} -eq 1 ]; then
        local entry=${installer_supervisor_entries[0]}
        local sprogram suser spath snumprocs paths scommand_enc scommand new_cmd
        IFS='|' read -r sprogram suser spath snumprocs paths scommand_enc <<<"$entry"
        scommand=$(_entry_decode "$scommand_enc")
        # 与 cron 对称：先替换绝对路径，再替换裸 php token
        new_cmd=$(echo "$scommand" | sed -E "s#/www/server/php/[0-9]+/bin/php#$target_php#g")
        new_cmd=$(echo "$new_cmd" | sed -E "s#(^|[[:space:];&|])php([[:space:]]+)#\1${target_php}\2#g")
        log_step "自动更新 supervisor [$sprogram] PHP 路径（install.sh 自管，唯一）"
        # bt_add_supervisor_process 内部即"先 Remove 再 Add"覆盖语义；
        # AddProcess 失败时进程已被删除，需用原命令回滚（与 cron 三段式对称）
        if bt_add_supervisor_process "$sprogram" "$suser" "$spath" "$new_cmd" "${snumprocs:-1}"; then
            auto_fixed=$((auto_fixed + 1))
        else
            log_warning "  supervisor [$sprogram] 添加新版失败，尝试用原命令回滚..."
            if bt_add_supervisor_process "$sprogram" "$suser" "$spath" "$scommand" "${snumprocs:-1}"; then
                log_info "  原 supervisor 已恢复（PHP 路径仍是旧版本，需手工修改）"
                other_supervisor_entries+=("$sprogram|$paths|$scommand_enc")
            else
                log_error "  ⚠️  supervisor [$sprogram] 自动更新 + 回滚均失败！请到宝塔面板手工添加"
                log_error "  运行用户: $suser  工作目录: $spath  进程数: ${snumprocs:-1}"
                log_error "  原命令: $scommand"
                log_error "  新命令: $new_cmd"
            fi
        fi
    elif [ ${#installer_supervisor_entries[@]} -gt 1 ]; then
        log_info "  检测到 ${#installer_supervisor_entries[@]} 个 install.sh 风格 supervisor 进程，非唯一，保留手工提示"
        for entry in "${installer_supervisor_entries[@]}"; do
            local sprogram suser spath snumprocs paths scommand_enc
            IFS='|' read -r sprogram suser spath snumprocs paths scommand_enc <<<"$entry"
            other_supervisor_entries+=("$sprogram|$paths|$scommand_enc")
        done
    fi

    local remaining=$((${#other_cron_entries[@]} + ${#other_supervisor_entries[@]}))
    if [ "$remaining" -eq 0 ]; then
        log_success "  cron / supervisor 全部自动修复完成（共 $auto_fixed 项）"
        return 0
    fi

    if [ "$auto_fixed" -gt 0 ]; then
        log_info "  已自动修复 $auto_fixed 项；以下仍需手工处理："
    fi

    log_warning "═══════════════════════════════════════════════════════"
    log_warning "检测到 $remaining 个任务使用的 PHP 路径与当前不一致："
    log_warning ""

    if [ ${#other_cron_entries[@]} -gt 0 ]; then
        log_warning "Cron 任务 (${#other_cron_entries[@]} 个) — 宝塔面板 → 计划任务 → 编辑命令："
        for entry in "${other_cron_entries[@]}"; do
            local id name paths body_enc body
            IFS='|' read -r id name paths body_enc <<<"$entry"
            body=$(_entry_decode "$body_enc")
            log_warning "  [id=$id] $name"
            log_warning "    旧路径: $paths"
            log_warning "    命令:  $body"
        done
        log_warning ""
    fi

    if [ ${#other_supervisor_entries[@]} -gt 0 ]; then
        log_warning "Supervisor 守护进程 (${#other_supervisor_entries[@]} 个) — 宝塔 → 软件商店 → Supervisor → 编辑："
        for entry in "${other_supervisor_entries[@]}"; do
            local name paths command_enc command
            IFS='|' read -r name paths command_enc <<<"$entry"
            command=$(_entry_decode "$command_enc")
            log_warning "  $name"
            log_warning "    旧路径: $paths"
            log_warning "    命令:  $command"
        done
        log_warning ""
    fi

    log_warning "请将上述命令中的旧路径替换为：$target_php"
    log_warning "（不自动替换以避免误改用户配置；cron/supervisor 改完会自动生效，无需重启服务）"
    log_warning "═══════════════════════════════════════════════════════"
}

# 执行升级
perform_upgrade() {
    local target_version="$1"
    local upgrade_file="$2"

    log_step "开始升级到版本 $target_version"

    # 1. 记录旧版本 composer.json 和 composer.lock hash
    local old_composer_json_hash=""
    local old_composer_lock_hash=""
    if [ -f "$INSTALL_DIR/backend/composer.json" ]; then
        old_composer_json_hash=$(file_sha256 "$INSTALL_DIR/backend/composer.json")
        log_info "当前 composer.json hash: ${old_composer_json_hash:0:16}..."
    fi
    if [ -f "$INSTALL_DIR/backend/composer.lock" ]; then
        old_composer_lock_hash=$(file_sha256 "$INSTALL_DIR/backend/composer.lock")
        log_info "当前 composer.lock hash: ${old_composer_lock_hash:0:16}..."
    fi

    # 2. 创建备份
    local backup_path=$(create_backup)

    # 3. 提前解压升级包（任何 mv/rm 之前；保证 PHP 环境检测失败时现场未被破坏）
    log_step "解压升级包..."
    local extract_dir="$TEMP_DIR/extract"
    mkdir -p "$extract_dir"
    unzip -q "$upgrade_file" -d "$extract_dir"

    # 查找解压后的目录结构
    local src_dir="$extract_dir"
    if [ -d "$extract_dir/ssl-manager" ]; then
        src_dir="$extract_dir/ssl-manager"
    elif [ -d "$extract_dir/upgrade" ]; then
        src_dir="$extract_dir/upgrade"
    elif [ -d "$extract_dir/full" ]; then
        src_dir="$extract_dir/full"
    fi

    # 升级包自带 deploy/scripts/*.sh（bt-automate.sh 等），重定向 SCRIPT_DIR 到这里；
    # 不持久化到 INSTALL_DIR，升级结束 trap cleanup 会跟着 TEMP_DIR 一起清理
    if [ -d "$src_dir/scripts" ] && [ -f "$src_dir/scripts/bt-automate.sh" ]; then
        SCRIPT_DIR="$src_dir/scripts"
        log_info "使用升级包内脚本目录: $SCRIPT_DIR"
    fi

    # 4. PHP 环境检测（必须在 maintenance mode / mv storage / rm 旧代码之前；
    # 不达标立即 exit 1 → trap cleanup 只删 TEMP_DIR，原安装目录完整未动）
    check_php_environment "$src_dir"

    # 5. 进入维护模式（必须在移动 vendor 之前）
    log_step "进入维护模式..."
    cd "$INSTALL_DIR/backend"
    "$PHP_CMD" artisan down --retry=60 || true

    # 6. 提取需要保留的文件到临时目录
    log_step "保留关键文件..."
    local preserve_dir="$TEMP_DIR/preserve"
    mkdir -p "$preserve_dir"

    # 保留 .env（不保留 version.json，升级需要更新版本号）
    [ -f "$INSTALL_DIR/backend/.env" ] && cp "$INSTALL_DIR/backend/.env" "$preserve_dir/"
    # 保留 storage（使用 mv 避免大目录复制失败导致数据丢失）
    if [ -d "$INSTALL_DIR/backend/storage" ]; then
        mv "$INSTALL_DIR/backend/storage" "$preserve_dir/"
    fi
    # 保留 vendor（加速升级）
    if [ -d "$INSTALL_DIR/backend/vendor" ]; then
        log_info "保留 vendor 目录（加速升级）..."
        mv "$INSTALL_DIR/backend/vendor" "$preserve_dir/"
    fi
    # frontend/web 不移动，在清理旧代码时跳过（避免脚本中断导致丢失）
    # 保留前端用户配置文件（logo、平台配置等）
    mkdir -p "$preserve_dir/frontend_config"
    # admin: logo.svg, platform-config.json
    for file in logo.svg platform-config.json; do
        [ -f "$INSTALL_DIR/frontend/admin/$file" ] && cp "$INSTALL_DIR/frontend/admin/$file" "$preserve_dir/frontend_config/admin_$file"
    done
    # user: logo.svg, platform-config.json, qrcode.png
    for file in logo.svg platform-config.json qrcode.png; do
        [ -f "$INSTALL_DIR/frontend/user/$file" ] && cp "$INSTALL_DIR/frontend/user/$file" "$preserve_dir/frontend_config/user_$file"
    done
    # 保留自定义 API 适配器（Order/Api 和 Acme/Api 对称扫描；按 bucket 归档避免重名冲突）
    # 跳过：核心入口 Api.php、默认实现 default/、各接口契约文件（新增接口需登记到 case 清单）
    for spec in "order:Services/Order/Api" "acme:Services/Acme/Api"; do
        local bucket="${spec%%:*}"
        local rel="${spec#*:}"
        local api_adapter_dir="$INSTALL_DIR/backend/app/$rel"
        [ -d "$api_adapter_dir" ] || continue

        local has_custom=false
        for item in "$api_adapter_dir"/*; do
            [ ! -e "$item" ] && continue
            local name=$(basename "$item")
            case "$name" in
                Api.php | default | OrderSourceApiInterface.php | AcmeSourceApiInterface.php)
                    continue
                    ;;
            esac
            [ "$has_custom" = false ] && mkdir -p "$preserve_dir/api_adapters/$bucket"
            cp -r "$item" "$preserve_dir/api_adapters/$bucket/"
            has_custom=true
            log_info "保留自定义 API 适配器: $bucket/$name"
        done
        [ "$has_custom" = true ] && log_info "已保留 $bucket 自定义 API 适配器"
    done

    # 7. 删除旧代码
    log_step "清理旧代码..."
    # 只删除后端代码目录（保留 storage 已移走）
    rm -rf "$INSTALL_DIR/backend/app"
    rm -rf "$INSTALL_DIR/backend/bootstrap"
    rm -rf "$INSTALL_DIR/backend/config"
    rm -rf "$INSTALL_DIR/backend/database"
    rm -rf "$INSTALL_DIR/backend/public"
    rm -rf "$INSTALL_DIR/backend/resources"
    rm -rf "$INSTALL_DIR/backend/routes"
    rm -rf "$INSTALL_DIR/backend/tests"
    rm -f "$INSTALL_DIR/backend/artisan"
    rm -f "$INSTALL_DIR/backend/composer.json"
    rm -f "$INSTALL_DIR/backend/composer.lock"

    # 删除前端目录（保留 frontend/web，避免丢失用户自定义前端）
    rm -rf "$INSTALL_DIR/frontend/admin" 2>/dev/null || true
    rm -rf "$INSTALL_DIR/frontend/user" 2>/dev/null || true
    rm -rf "$INSTALL_DIR/admin" 2>/dev/null || true
    rm -rf "$INSTALL_DIR/user" 2>/dev/null || true

    # 8. 复制新代码（使用 /. 确保复制隐藏文件如 .ssl-manager）
    log_step "应用新版本..."
    if [ -d "$src_dir/backend" ]; then
        cp -r "$src_dir/backend/." "$INSTALL_DIR/backend/"
    fi

    # 复制前端（frontend 目录结构）
    if [ -d "$src_dir/frontend" ]; then
        mkdir -p "$INSTALL_DIR/frontend"
        cp -r "$src_dir/frontend"/* "$INSTALL_DIR/frontend/" 2>/dev/null || true
    fi
    # 兼容旧的目录结构（admin/user 在根目录）
    for app in admin user; do
        if [ -d "$src_dir/$app" ] && [ ! -d "$src_dir/frontend/$app" ]; then
            mkdir -p "$INSTALL_DIR/$app"
            cp -r "$src_dir/$app"/* "$INSTALL_DIR/$app/" 2>/dev/null || true
        fi
    done

    # 复制 nginx 配置目录（路由配置）
    if [ -d "$src_dir/nginx" ]; then
        mkdir -p "$INSTALL_DIR/nginx"
        cp -r "$src_dir/nginx"/* "$INSTALL_DIR/nginx/"
        log_info "已更新 nginx 配置"

        # 替换 __PROJECT_ROOT__ 占位符
        local project_root
        # 宝塔环境使用实际安装目录
        project_root="$INSTALL_DIR"

        if [ -f "$INSTALL_DIR/nginx/manager.conf" ]; then
            sed -i "s|__PROJECT_ROOT__|$project_root|g" "$INSTALL_DIR/nginx/manager.conf"
            log_info "已替换 manager.conf 中的路径占位符"
        fi
    fi

    # 替换 web.conf 中的占位符
    if [ -f "$INSTALL_DIR/frontend/web/web.conf" ]; then
        local project_root
        project_root="$INSTALL_DIR"
        sed -i "s|__PROJECT_ROOT__|$project_root|g" "$INSTALL_DIR/frontend/web/web.conf"
        log_info "已替换 web.conf 中的路径占位符"
    fi

    # 复制根目录版本配置（保留用户的 release_url）
    if [ -f "$src_dir/version.json" ]; then
        local old_release_url=""
        local old_version_json="$INSTALL_DIR/version.json"

        # 用 PHP 读取旧的 release_url（正确处理 JSON 转义）
        if [ -f "$old_version_json" ] && [ -x "$PHP_CMD" ]; then
            old_release_url=$(OLD_VJ="$old_version_json" "$PHP_CMD" -r '
$d = @json_decode(@file_get_contents(getenv("OLD_VJ")), true);
echo is_array($d) && isset($d["release_url"]) ? $d["release_url"] : "";
' 2>/dev/null)
        fi

        # 复制新的 version.json
        cp "$src_dir/version.json" "$INSTALL_DIR/"

        # 如果存在旧的 release_url，合并到新的 version.json
        if [ -n "$old_release_url" ]; then
            # 用 PHP 处理 JSON 合并（管理员手工运行，变量来自可信的本地文件）
            NEW_VJ="$INSTALL_DIR/version.json" RELEASE_URL="$old_release_url" "$PHP_CMD" -r '
$path = getenv("NEW_VJ");
$d = @json_decode(@file_get_contents($path), true);
if (! is_array($d)) { exit(1); }
$d["release_url"] = getenv("RELEASE_URL");
file_put_contents($path, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' 2>/dev/null
            log_info "保留 release_url 配置: $old_release_url"
        fi
    fi

    # 9. 恢复保留的文件
    log_step "恢复保留文件..."
    [ -f "$preserve_dir/.env" ] && cp "$preserve_dir/.env" "$INSTALL_DIR/backend/"
    # 注意：不恢复 version.json，使用升级包中的新版本

    # 恢复 storage（已使用 mv 保留，直接移回）
    if [ -d "$preserve_dir/storage" ]; then
        rm -rf "$INSTALL_DIR/backend/storage" 2>/dev/null || true
        mv "$preserve_dir/storage" "$INSTALL_DIR/backend/"
    fi

    # 恢复 vendor
    if [ -d "$preserve_dir/vendor" ]; then
        mv "$preserve_dir/vendor" "$INSTALL_DIR/backend/"
    fi

    # frontend/web 已在原地保留，无需恢复

    # 恢复前端用户配置文件
    if [ -d "$preserve_dir/frontend_config" ]; then
        log_info "恢复前端用户配置..."
        # admin
        for file in logo.svg platform-config.json; do
            [ -f "$preserve_dir/frontend_config/admin_$file" ] && cp "$preserve_dir/frontend_config/admin_$file" "$INSTALL_DIR/frontend/admin/$file"
        done
        # user
        for file in logo.svg platform-config.json qrcode.png; do
            [ -f "$preserve_dir/frontend_config/user_$file" ] && cp "$preserve_dir/frontend_config/user_$file" "$INSTALL_DIR/frontend/user/$file"
        done
    fi

    # 恢复自定义 API 适配器（按 bucket 还原到对应 Services/<X>/Api 目录）
    for spec in "order:Services/Order/Api" "acme:Services/Acme/Api"; do
        local bucket="${spec%%:*}"
        local rel="${spec#*:}"
        local bucket_dir="$preserve_dir/api_adapters/$bucket"
        [ -d "$bucket_dir" ] && [ "$(ls -A "$bucket_dir" 2>/dev/null)" ] || continue

        local api_adapter_dir="$INSTALL_DIR/backend/app/$rel"
        mkdir -p "$api_adapter_dir"
        cp -r "$bucket_dir"/* "$api_adapter_dir/"
        log_info "已恢复 $bucket 自定义 API 适配器"
    done

    # 9.1 预先修复权限（在执行 artisan 命令前）
    log_step "预设权限..."

    # backend/storage（Laravel storage）和根目录 backups（备份、升级包）
    local backend_storage="$INSTALL_DIR/backend/storage"
    local backups_dir="$INSTALL_DIR/backups"
    local version_file="$INSTALL_DIR/version.json"

    # 确保目录存在（宿主机上创建）
    for subdir in logs framework/cache framework/sessions framework/views app/public; do
        mkdir -p "$backend_storage/$subdir" 2>/dev/null || true
    done
    mkdir -p "$backups_dir" "$backups_dir/upgrades" 2>/dev/null || true

    # 宝塔模式
    chown -R www:www "$backend_storage" 2>/dev/null || true
    chmod -R 775 "$backend_storage" 2>/dev/null || true
    chown -R www:www "$backups_dir" 2>/dev/null || true
    chmod -R 775 "$backups_dir" 2>/dev/null || true
    [ -f "$version_file" ] && chown www:www "$version_file" && chmod 664 "$version_file"
    # .env 文件（让 www 可读，用于升级时备份）
    [ -f "$INSTALL_DIR/backend/.env" ] && chown www:www "$INSTALL_DIR/backend/.env" && chmod 600 "$INSTALL_DIR/backend/.env"

    # 10. 检测依赖变化，决定是否运行 composer install
    log_step "检测依赖变化..."
    local new_composer_json_hash=""
    local new_composer_lock_hash=""
    if [ -f "$INSTALL_DIR/backend/composer.json" ]; then
        new_composer_json_hash=$(file_sha256 "$INSTALL_DIR/backend/composer.json")
        log_info "新版本 composer.json hash: ${new_composer_json_hash:0:16}..."
    fi
    if [ -f "$INSTALL_DIR/backend/composer.lock" ]; then
        new_composer_lock_hash=$(file_sha256 "$INSTALL_DIR/backend/composer.lock")
        log_info "新版本 composer.lock hash: ${new_composer_lock_hash:0:16}..."
    fi

    local need_composer=false
    # 检查 composer.json 或 composer.lock 是否有变化
    if [ -z "$old_composer_json_hash" ] || [ "$old_composer_json_hash" != "$new_composer_json_hash" ]; then
        need_composer=true
        log_info "composer.json 已变化，需要更新依赖"
    elif [ -z "$old_composer_lock_hash" ] || [ "$old_composer_lock_hash" != "$new_composer_lock_hash" ]; then
        need_composer=true
        log_info "composer.lock 已变化，需要更新依赖"
    else
        log_info "依赖未变化，跳过 composer install"
    fi

    # 探测 composer phar 路径（无论 install/dump-autoload 都要用，提前到 if 块外）
    # 用 $PHP_CMD 显式驱动，避免 shebang #!/usr/bin/env php 走错版本
    local composer_bin=""
    if [ -x "/usr/local/bin/composer" ]; then
        composer_bin="/usr/local/bin/composer"
    elif command -v composer &>/dev/null; then
        composer_bin="$(command -v composer)"
    else
        log_error "未找到 composer（已检查 /usr/local/bin/composer 和 PATH）"
        exit 1
    fi
    log_info "使用 composer: $composer_bin"

    if [ "$need_composer" = true ]; then
        log_step "安装 Composer 依赖..."

        # 从 version.json 读取网络配置（安装时用户选择）
        local use_china_mirror=false
        if [ -f "$INSTALL_DIR/version.json" ]; then
            local network=$(grep -o '"network"[[:space:]]*:[[:space:]]*"[^"]*"' "$INSTALL_DIR/version.json" 2>/dev/null | head -1 | cut -d'"' -f4)
            if [ "$network" = "china" ]; then
                use_china_mirror=true
                log_info "从 version.json 读取网络配置: 使用国内镜像"
            fi
        fi

        # 临时 COMPOSER_HOME（一次性，跑完即删；不污染持久目录，不进宝塔备份）
        local tmp_home
        tmp_home="$(mktemp -d /tmp/composer-home-XXXXXX)"

        cd "$INSTALL_DIR/backend"
        # 镜像配置写到 tmp_home（不污染项目 composer.json）
        if [ "$use_china_mirror" = true ]; then
            env HOME="$tmp_home" COMPOSER_HOME="$tmp_home" \
                "$PHP_CMD" "$composer_bin" config -g repo.packagist composer https://mirrors.aliyun.com/composer/
        fi

        # 与后台 UpgradeService::runComposerInstall 不对称：此处保留 scripts（不加 --no-scripts）。
        # 原因：upgrade.sh 是 root SSH 入口，调用时 web 流量已隔离 / 维护模式生效，
        #       即使 package:discover 加载老代码 fatal，也只在终端报错而非死锁前端轮询；
        #       而后台升级走 PHP-FPM www 用户，必须 --no-scripts 防止 fatal 让 vendor 半成品。
        # 同时下方有无条件 dump-autoload --no-scripts 作为兜底，覆盖 classmap 漂移场景。
        local rc=0
        COMPOSER_ALLOW_SUPERUSER=1 HOME="$tmp_home" COMPOSER_HOME="$tmp_home" \
            "$PHP_CMD" "$composer_bin" install --no-dev --optimize-autoloader || rc=$?

        rm -rf "$tmp_home"

        if [ "$rc" -ne 0 ]; then
            log_error "composer install 失败"
            exit 1
        fi

        # vendor/ 由 root 重建，统一 chown 给 www
        chown -R www:www "$INSTALL_DIR/backend/vendor" 2>/dev/null || true
    fi

    # 无条件重新生成 autoload（修复 classmap 漂移；对健康部署无害，秒级完成）
    # 场景：跨小版本升级未触发 composer install，但 vendor 内文件路径/PSR-4 映射可能已变
    # （如 Laravel 13.8.0 ReflectsClosures 跨目录），旧 classmap 还指向旧路径会撞 Failed to open stream。
    # 这里强制重建一次保证 autoload 与 vendor 现状一致。
    log_step "重新生成 autoload..."
    local autoload_home
    autoload_home="$(mktemp -d /tmp/composer-home-XXXXXX)"
    cd "$INSTALL_DIR/backend"
    local dump_rc=0
    COMPOSER_ALLOW_SUPERUSER=1 HOME="$autoload_home" COMPOSER_HOME="$autoload_home" \
        "$PHP_CMD" "$composer_bin" dump-autoload --optimize --no-scripts || dump_rc=$?
    rm -rf "$autoload_home"
    if [ "$dump_rc" -ne 0 ]; then
        # 与 backend UpgradeService::runDumpAutoload 对齐：autoload 不一致让后续 migrate 加载到
        # 不存在的类，必须 fail-fast。此时 storage/vendor 已通过"恢复保留文件"步骤移回 INSTALL_DIR，
        # trap cleanup 仅删 TEMP_DIR 不会丢数据；用户修好后重跑 upgrade.sh 即可（dump-autoload 幂等）
        log_error "dump-autoload 失败（autoload 不一致后续 migrate 必然 ClassNotFound，已中止）"
        log_info "请手工执行: cd $INSTALL_DIR/backend && composer dump-autoload --optimize --no-scripts"
        log_info "然后重跑 bash upgrade.sh"
        exit 1
    fi
    chown -R www:www "$INSTALL_DIR/backend/vendor" 2>/dev/null || true

    # 11. 运行数据库迁移
    log_step "运行数据库迁移..."
    cd "$INSTALL_DIR/backend"
    "$PHP_CMD" artisan migrate --force

    # 11.1 初始化/更新数据
    log_step "更新数据..."
    cd "$INSTALL_DIR/backend"
    "$PHP_CMD" artisan db:seed --force || true

    # 11.2 数据库结构校验
    log_step "数据库结构校验..."
    local structure_check_result=0
    local structure_output=""
    cd "$INSTALL_DIR/backend"
    # 检查结构差异
    structure_output=$("$PHP_CMD" artisan db:structure --check 2>&1) || true
    if echo "$structure_output" | grep -q "数据库结构完全一致"; then
        log_success "数据库结构校验通过"
    else
        log_warning "检测到数据库结构差异："
        echo "$structure_output" | head -50
        echo ""
        log_warning "尝试自动修复（仅 ADD 操作）..."
        if "$PHP_CMD" artisan db:structure --fix --skip-foreign-keys 2>&1; then
            log_success "数据库结构自动修复完成"
        else
            log_warning "部分结构差异需要手动处理"
            structure_check_result=1
        fi
        echo ""
        echo -e "${YELLOW}提示: 使用以下命令查看和修复结构差异：${NC}"
        echo " $PHP_CMD artisan db:structure --check # 查看差异"
        echo " $PHP_CMD artisan db:structure --fix # 自动修复"
    fi

    # 12. 清理缓存
    log_step "清理缓存..."
    cd "$INSTALL_DIR/backend"
    "$PHP_CMD" artisan config:cache || true
    "$PHP_CMD" artisan route:cache || true

    # 13. 完整性校验
    log_step "完整性校验..."
    local check_passed=true

    if [ ! -f "$INSTALL_DIR/backend/.env" ]; then
        log_warning ".env 文件不存在"
        check_passed=false
    fi

    if [ ! -d "$INSTALL_DIR/backend/storage/logs" ]; then
        log_warning "storage/logs 目录不存在"
        mkdir -p "$INSTALL_DIR/backend/storage/logs"
    fi

    if [ ! -f "$INSTALL_DIR/backend/vendor/autoload.php" ]; then
        log_warning "vendor/autoload.php 不存在"
        check_passed=false
    fi

    if [ "$check_passed" = true ]; then
        log_success "完整性校验通过"
    else
        log_warning "部分校验未通过，请检查"
    fi

    # 14. 退出维护模式
    log_step "退出维护模式..."
    cd "$INSTALL_DIR/backend"
    "$PHP_CMD" artisan up

    # 14b. 重启队列 worker（让常驻 worker 跑完当前 job 后退出，supervisor 自动拉起新进程加载新代码）
    log_step "重启队列 worker..."
    if "$PHP_CMD" artisan queue:restart >/dev/null 2>&1; then
        log_info "已发送 queue:restart 信号（worker 跑完当前 job 后自动加载新代码）"
    else
        log_warning "queue:restart 失败（如未启用队列可忽略）"
    fi

    # 15. 扫描 cron / supervisor 的 PHP 绝对路径（PHP 版本切换后保护性检查 + 自动修复 install.sh 自管项）
    update_jobs_php_path

    # 15b. 重载 PHP-FPM 清 opcache，加载新代码
    # 仅宝塔环境（PHP_CMD 形如 /www/server/php/83/bin/php）；其他环境提示手工重启
    # 独立于 update_jobs_php_path：那里 BT_KEY 不可用会提前 return 不 source；这里自己再尝试一次
    log_step "重载 PHP-FPM..."
    local php_ver_compact
    php_ver_compact=$(echo "$PHP_CMD" | sed -nE 's|^/www/server/php/([0-9]+)/bin/php$|\1|p')
    if [ -z "$php_ver_compact" ]; then
        log_info "非宝塔 PHP 路径，跳过自动 reload PHP-FPM"
        log_info "（如需清 opcache 加载新代码，请手工重启对应版本 PHP-FPM）"
    else
        if ! declare -f bt_reload_php_fpm >/dev/null 2>&1 && [ -f "$SCRIPT_DIR/bt-automate.sh" ]; then
            # shellcheck source=scripts/bt-automate.sh
            source "$SCRIPT_DIR/bt-automate.sh"
        fi
        if declare -f bt_reload_php_fpm >/dev/null 2>&1 &&
            { [ -n "$BT_KEY" ] || bt_resolve_key 2>/dev/null; } &&
            bt_verify_api_key 2>/dev/null; then
            bt_reload_php_fpm "$php_ver_compact" || log_warning "PHP-FPM reload 失败，可手工到面板 → 软件商店 → PHP-FPM → 重载"
        else
            log_info "BT API 不可用，跳过 PHP-FPM 自动 reload"
            log_info "（opcache validate_timestamps 开启时新代码约 2 秒内自动加载；如需立即生效请手工重启 PHP-FPM）"
        fi
    fi

    # 最终权限检查
    log_step "确认文件权限..."

    # 宝塔模式：设置整个安装目录的权限
    chown -R www:www "$INSTALL_DIR" 2>/dev/null || true
    # 确保关键目录可写
    chmod -R 775 "$INSTALL_DIR/backend/storage" 2>/dev/null || true
    chmod -R 775 "$INSTALL_DIR/backups" 2>/dev/null || true
    [ -f "$INSTALL_DIR/version.json" ] && chmod 664 "$INSTALL_DIR/version.json"
    # .env 文件（让 www 可读，用于升级时备份）
    [ -f "$INSTALL_DIR/backend/.env" ] && chown www:www "$INSTALL_DIR/backend/.env" && chmod 600 "$INSTALL_DIR/backend/.env"

    # .env 文件敏感信息保护（root 和 web 用户可读）
    [ -f "$INSTALL_DIR/.env" ] && chmod 640 "$INSTALL_DIR/.env"

    # 重启服务以加载新配置
    # 宝塔环境：reload Nginx 以加载更新后的 manager.conf
    log_step "重载 Nginx 配置..."
    if command -v nginx &>/dev/null; then
        nginx -t 2>/dev/null && nginx -s reload 2>/dev/null && log_info "Nginx 已重载" || log_warning "Nginx 重载失败，请手动执行: nginx -s reload"
    elif [ -f /etc/init.d/nginx ]; then
        /etc/init.d/nginx reload 2>/dev/null && log_info "Nginx 已重载" || log_warning "Nginx 重载失败"
    else
        log_warning "未找到 Nginx，请手动重载 Nginx 配置"
    fi

    log_success "升级完成！版本: $target_version"
    log_info "备份位置: $backup_path"
}

# 回滚
rollback() {
    log_step "执行回滚"

    local backup_dir="$INSTALL_DIR/backups"
    # 排除 upgrades 目录，只查找实际的备份目录（包含 backup.json 的目录）
    local latest_backup=$(find "$backup_dir" -maxdepth 1 -type d -name "20*" -exec ls -td {} + 2>/dev/null | head -1)

    if [ -z "$latest_backup" ]; then
        log_error "未找到可用备份"
        exit 1
    fi

    # 读取备份信息
    local backup_info="$latest_backup/backup.json"
    if [ -f "$backup_info" ]; then
        local backup_version=$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' "$backup_info" | head -1 | cut -d'"' -f4)
        log_info "使用备份: $latest_backup"
        log_info "备份版本: $backup_version"
    else
        log_info "使用备份: $latest_backup"
    fi

    if ! confirm "确认回滚到此备份？"; then
        exit 0
    fi

    # 进入维护模式
    cd "$INSTALL_DIR/backend"
    "$PHP_CMD" artisan down || true

    # 恢复文件（支持新旧两种备份格式）
    log_info "恢复文件..."

    # 新格式：backend.zip
    if [ -f "$latest_backup/backend.zip" ]; then
        log_info "从 backend.zip 恢复后端代码..."
        local restore_tmp="$TEMP_DIR/restore_backend"
        mkdir -p "$restore_tmp"
        unzip -qo "$latest_backup/backend.zip" -d "$restore_tmp"

        # 恢复后端目录（保留 storage 和 vendor）
        for dir in app config database routes bootstrap; do
            if [ -d "$restore_tmp/$dir" ]; then
                rm -rf "$INSTALL_DIR/backend/$dir"
                cp -r "$restore_tmp/$dir" "$INSTALL_DIR/backend/"
            fi
        done

        # 恢复重要文件
        for file in composer.json composer.lock; do
            if [ -f "$restore_tmp/$file" ]; then
                cp "$restore_tmp/$file" "$INSTALL_DIR/backend/"
            fi
        done

        # 恢复 .env（如果存在）
        [ -f "$restore_tmp/.env" ] && cp "$restore_tmp/.env" "$INSTALL_DIR/backend/"

        # 恢复 version.json（unzip 会将 ../version.json 解压到当前目录）
        if [ -f "$restore_tmp/version.json" ]; then
            cp "$restore_tmp/version.json" "$INSTALL_DIR/"
        fi

        rm -rf "$restore_tmp"
    # 旧格式：code/backend 目录
    elif [ -d "$latest_backup/code/backend" ]; then
        log_info "从 code/backend 恢复后端代码（旧格式）..."
        rm -rf "$INSTALL_DIR/backend"
        cp -r "$latest_backup/code/backend" "$INSTALL_DIR/"

        # 恢复配置（旧格式）
        [ -f "$latest_backup/backend.env" ] && cp "$latest_backup/backend.env" "$INSTALL_DIR/backend/.env"
        [ -f "$latest_backup/version.json" ] && cp "$latest_backup/version.json" "$INSTALL_DIR/"
    else
        log_error "未找到有效的备份文件"
        exit 1
    fi

    # 恢复前端代码
    if [ -f "$latest_backup/frontend.zip" ]; then
        log_info "从 frontend.zip 恢复前端代码..."
        mkdir -p "$INSTALL_DIR/frontend"
        unzip -qo "$latest_backup/frontend.zip" -d "$INSTALL_DIR/frontend/"
    fi

    # 退出维护模式
    cd "$INSTALL_DIR/backend"
    "$PHP_CMD" artisan up

    log_success "回滚完成"
}

# ========================================
# 显示帮助
# ========================================
show_help() {
    cat <<EOF
SSL Manager 在线升级脚本

用法: $0 [选项]

选项:
 --url URL 指定 release 服务 URL（覆盖 version.json 配置）
 --version, -v VERSION 指定升级版本
 latest 最新稳定版（默认）
 dev 最新开发版
 x.x.x 指定版本号
 --file FILE 使用本地升级包（跳过下载）
 --dir DIR 指定安装目录（默认自动检测）
 -y, --yes 自动确认，非交互模式
 check 仅检查更新
 rollback 回滚到上一版本
 -h, --help 显示帮助

环境变量:
 FORCE_CHINA_MIRROR=1 强制使用国内镜像

示例:
 $0 --url http://release.example.com # 升级到最新稳定版
 $0 --url http://release.example.com -v 1.0.0 # 升级到指定版本
 $0 --dir /www/wwwroot/mysite # 从 version.json 读取 release_url
 $0 --file /path/to/pkg.zip # 使用本地包升级
 $0 rollback # 回滚

注意: release 服务 URL 必须通过 --url 参数指定，或在 version.json 中配置 release_url

EOF
    exit 0
}

# ========================================
# 显示横幅
# ========================================
show_banner() {
    echo ""
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC} ${GREEN}SSL Manager 在线升级程序${NC} ${CYAN}║${NC}"
    echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# ========================================
# 主流程
# ========================================
main() {
    local target_version="latest"
    local upgrade_file=""
    local action="upgrade"

    # 解析参数
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --version | -v)
                target_version="$2"
                shift 2
                ;;
            --url)
                CUSTOM_RELEASE_URL="$2"
                shift 2
                ;;
            --file)
                upgrade_file="$2"
                shift 2
                ;;
            --dir)
                INSTALL_DIR="$2"
                shift 2
                ;;
            -y | --yes)
                AUTO_YES=true
                shift
                ;;
            check)
                action="check"
                shift
                ;;
            rollback)
                action="rollback"
                shift
                ;;
            -h | --help)
                show_help
                ;;
            *)
                shift
                ;;
        esac
    done

    show_banner

    # 检查 root 权限
    if [ "$EUID" -ne 0 ]; then
        log_error "请使用 root 权限运行此脚本"
        exit 1
    fi

    # 检测安装目录
    if ! detect_install; then
        log_error "未找到 SSL Manager 安装目录"
        log_info "请确保系统已安装，或使用 install.sh 进行安装"
        exit 1
    fi

    log_info "安装目录: $INSTALL_DIR"
    log_info "部署模式: $DEPLOY_MODE"

    # 探测 PHP CLI（artisan / composer install 都走绝对路径，避免多版本错配）
    if ! detect_php_cmd; then
        exit 1
    fi

    local current_version=$(get_current_version)
    log_info "当前版本: $current_version"

    # 如果没有指定目标版本，根据 version.json 的 channel 选择
    if [ "$target_version" = "latest" ]; then
        local current_channel=$(get_channel)
        if [ "$current_channel" = "dev" ]; then
            target_version="dev"
            log_info "当前通道: dev，自动使用 dev 通道升级"
        fi
    fi

    # 如果没有通过命令行指定 --url，则从 version.json 读取 release_url
    if [ -z "$CUSTOM_RELEASE_URL" ]; then
        CUSTOM_RELEASE_URL=$(get_release_url)
        if [ -n "$CUSTOM_RELEASE_URL" ]; then
            log_info "使用 version.json 配置的 release URL: $CUSTOM_RELEASE_URL"
        fi
    else
        log_info "使用命令行指定的 release URL: $CUSTOM_RELEASE_URL"
    fi

    # 执行动作
    case "$action" in
        check)
            log_info "检查更新功能请在管理后台使用"
            ;;
        rollback)
            rollback
            ;;
        upgrade)
            # 创建临时目录
            mkdir -p "$TEMP_DIR"

            # 如果没有指定本地包，则下载
            if [ -z "$upgrade_file" ]; then
                # 完整性校验链：先下 releases.json + 解析 latest/dev 占位符为具体版本
                # 让后续下载用具体版本路径（与 install.sh 设计对齐），sha256 校验可拿到 asset.sha256
                local releases_file="$TEMP_DIR/releases.json"
                local base_url="${CUSTOM_RELEASE_URL%/}"
                log_step "下载 releases.json 索引..."
                if ! curl -fsSL --connect-timeout 10 --max-time 30 \
                    -o "$releases_file" "$base_url/releases.json" 2>/dev/null; then
                    log_error "下载 releases.json 失败（$base_url/releases.json）"
                    log_info "请检查 release 服务可达性，或用 --file 指定本地升级包跳过下载"
                    exit 1
                fi

                # 解析 latest/dev → 具体版本（_resolve_version 对非占位符输入透传）
                local resolved_version
                resolved_version=$(_resolve_version "$releases_file" "$target_version")
                if [ -z "$resolved_version" ]; then
                    log_error "无法从 releases.json 解析 $target_version 对应的具体版本"
                    log_info "可能原因：releases.json 缺少 prerelease=$([ \"$target_version\" = dev ] && echo true || echo false) 的条目"
                    exit 1
                fi
                if [ "$resolved_version" != "$target_version" ]; then
                    log_info "$target_version → v$resolved_version"
                    target_version="$resolved_version"
                fi

                log_step "下载升级包..."
                upgrade_file="$TEMP_DIR/ssl-manager-upgrade.zip"
                if ! download_upgrade_package "$target_version" "$upgrade_file"; then
                    log_error "无法下载升级包"
                    exit 1
                fi

                # 完整性校验链：sha256 强校验（覆盖所有版本，不再跳过 latest/dev）
                log_step "校验升级包 sha256..."
                local asset_name="ssl-manager-upgrade-${target_version}.zip"
                local expected_sha
                expected_sha=$(awk -v ver="v$target_version" -v aname="$asset_name" '
                    BEGIN { in_rel = 0; in_asset = 0 }
                    match($0, /"tag_name"[[:space:]]*:[[:space:]]*"v[^"]+"/) {
                        s = substr($0, RSTART, RLENGTH)
                        gsub(/.*"tag_name"[[:space:]]*:[[:space:]]*"/, "", s); gsub(/".*/, "", s)
                        in_rel = (s == ver) ? 1 : 0; in_asset = 0; next
                    }
                    in_rel && match($0, /"name"[[:space:]]*:[[:space:]]*"[^"]+\.zip"/) {
                        s = substr($0, RSTART, RLENGTH)
                        gsub(/.*"name"[[:space:]]*:[[:space:]]*"/, "", s); gsub(/".*/, "", s)
                        in_asset = (s == aname) ? 1 : 0; next
                    }
                    in_rel && in_asset && match($0, /"sha256"[[:space:]]*:[[:space:]]*"[^"]+"/) {
                        s = substr($0, RSTART, RLENGTH)
                        gsub(/.*"sha256"[[:space:]]*:[[:space:]]*"/, "", s); gsub(/".*/, "", s)
                        print s; exit
                    }
                ' "$releases_file")

                if [ -z "$expected_sha" ]; then
                    log_error "releases.json 缺失 v$target_version $asset_name sha256"
                    exit 1
                fi
                local actual_sha=$(file_sha256 "$upgrade_file")
                expected_sha=$(echo "$expected_sha" | tr 'A-Z' 'a-z')
                actual_sha=$(echo "$actual_sha" | tr 'A-Z' 'a-z')
                if [ "$actual_sha" != "$expected_sha" ]; then
                    log_error "升级包 SHA256 校验失败"
                    log_error " 期望: $expected_sha"
                    log_error " 实际: $actual_sha"
                    exit 1
                fi
                log_success "升级包 sha256 校验通过"
            fi

            # 验证升级包
            if [ ! -f "$upgrade_file" ]; then
                log_error "升级包不存在: $upgrade_file"
                exit 1
            fi

            # 获取目标版本（仅 --file 路径会走到：占位符未被前面 releases.json 解析为具体版本）
            # 关键：仅匹配 zip 中一级目录的 version.json（顶层 <top>/version.json）
            # 旧实现 `*/version.json` 会跨层匹配 backend/version.json 等组件版本号，导致取错版本
            if [ "$target_version" = "latest" ] || [ "$target_version" = "dev" ]; then
                local pkg_root_version_path
                pkg_root_version_path=$(unzip -l "$upgrade_file" 2>/dev/null |
                    awk '$NF ~ /^[^/]+\/version\.json$/ { print $NF; exit }')
                if [ -n "$pkg_root_version_path" ]; then
                    local pkg_version
                    pkg_version=$(unzip -p "$upgrade_file" "$pkg_root_version_path" 2>/dev/null |
                        grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | cut -d'"' -f4)
                    if [ -n "$pkg_version" ]; then
                        target_version="$pkg_version"
                    fi
                fi
            fi

            # 版本检查（禁止降级）
            if [ "$current_version" != "unknown" ] && [ "$target_version" != "latest" ] && [ "$target_version" != "dev" ]; then
                if ! version_gt "$target_version" "$current_version"; then
                    log_error "目标版本 ($target_version) 不高于当前版本 ($current_version)"
                    log_info "不允许降级操作"
                    exit 1
                fi
            fi

            log_info "目标版本: $target_version"
            echo ""

            if [ "$AUTO_YES" != true ]; then
                if ! confirm "确认升级？"; then
                    log_info "已取消升级"
                    exit 0
                fi
            fi

            perform_upgrade "$target_version" "$upgrade_file"
            ;;
    esac
}

# 运行主流程
main "$@"
