#!/bin/bash

# SSL Manager 在线升级脚本
# 用法:
#   ./upgrade.sh --url http://release.example.com
#   ./upgrade.sh --url http://release.example.com --version 0.0.11-beta
#   ./upgrade.sh --dir /www/wwwroot/mysite  # 从 version.json 读取 release_url

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

confirm() {
    local message="$1"
    local default="${2:-n}"

    # 自动确认模式
    if [ "$AUTO_YES" = true ]; then
        return 0
    fi

    if [ "$default" = "y" ]; then
        read -p "$message [Y/n]: " choice < /dev/tty
        case "$choice" in
            n|N) return 1 ;;
            *) return 0 ;;
        esac
    else
        read -p "$message [y/N]: " choice < /dev/tty
        case "$choice" in
            y|Y) return 0 ;;
            *) return 1 ;;
        esac
    fi
}

file_sha256() {
    local file="$1"
    if command -v sha256sum &> /dev/null; then
        sha256sum "$file" | cut -d' ' -f1
    elif command -v shasum &> /dev/null; then
        shasum -a 256 "$file" | cut -d' ' -f1
    else
        openssl dgst -sha256 "$file" | awk '{print $NF}'
    fi
}

is_china_server() {
    if [ -n "$FORCE_CHINA_MIRROR" ]; then
        [ "$FORCE_CHINA_MIRROR" = "1" ] && return 0 || return 1
    fi

    # 云服务商检测
    local aliyun_region=$(timeout 1 curl -s "http://100.100.100.200/latest/meta-data/region-id" 2>/dev/null || echo "")
    if [ -n "$aliyun_region" ] && [[ "$aliyun_region" =~ ^cn- ]]; then
        return 0
    fi

    # Baidu 可达 + Google 不可达
    if timeout 2 curl -s --head "https://www.baidu.com" >/dev/null 2>&1; then
        if ! timeout 3 curl -s --head "https://www.google.com" >/dev/null 2>&1; then
            return 0
        fi
    fi

    return 1
}

check_docker_compose() {
    if command -v docker-compose &> /dev/null; then
        echo "docker-compose"
        return 0
    elif docker compose version &> /dev/null; then
        echo "docker compose"
        return 0
    fi
    return 1
}

# 版本比较（v1 > v2 返回 0）
version_gt() {
    local v1=$(echo "$1" | sed 's/^v//' | sed 's/-.*//')
    local v2=$(echo "$2" | sed 's/^v//' | sed 's/-.*//')

    if [ "$v1" = "$v2" ]; then
        return 1
    fi

    local sorted=$(printf '%s\n%s' "$v1" "$v2" | sort -V | tail -1)
    [ "$sorted" = "$v1" ] && return 0 || return 1
}

# ========================================
# 检测函数
# ========================================

# 检测安装目录和部署模式
detect_install() {
    # 如果已手动指定目录，验证并检测模式
    if [ -n "$INSTALL_DIR" ]; then
        # 优先检测 .ssl-manager 标记文件，回退到 artisan
        if [ -f "$INSTALL_DIR/backend/.ssl-manager" ] || [ -f "$INSTALL_DIR/backend/artisan" ]; then
            if [ -f "$INSTALL_DIR/docker-compose.yml" ]; then
                DEPLOY_MODE="docker"
            else
                DEPLOY_MODE="bt"
            fi
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
        "/opt/ssl-manager"
        "/www/wwwroot/ssl-manager"
    )

    for dir in "${preset_dirs[@]}"; do
        # 优先检测 .ssl-manager，回退到 artisan
        if [ -f "$dir/backend/.ssl-manager" ] || [ -f "$dir/backend/artisan" ]; then
            found_dirs+=("$dir")
        fi
    done

    # 系统范围搜索（补充非预设目录）
    while IFS= read -r marker; do
        [ -z "$marker" ] && continue
        local dir=$(dirname "$marker" | xargs dirname)
        # 避免重复
        local already_found=false
        for fd in "${found_dirs[@]}"; do
            [ "$fd" = "$dir" ] && already_found=true && break
        done
        $already_found || found_dirs+=("$dir")
    done < <(find /opt /www/wwwroot /home -maxdepth 4 -name ".ssl-manager" -path "*/backend/*" 2>/dev/null)

    # 根据找到的数量处理
    if [ ${#found_dirs[@]} -eq 0 ]; then
        return 1
    elif [ ${#found_dirs[@]} -eq 1 ]; then
        INSTALL_DIR="${found_dirs[0]}"
        [ -f "$INSTALL_DIR/docker-compose.yml" ] && DEPLOY_MODE="docker" || DEPLOY_MODE="bt"
        return 0
    else
        # 多个安装，让用户选择
        log_info "检测到多个 SSL Manager 安装："
        for i in "${!found_dirs[@]}"; do
            local dir="${found_dirs[$i]}"
            local mode="宝塔"
            [ -f "$dir/docker-compose.yml" ] && mode="Docker"
            echo "  $((i+1)). $dir [$mode]"
        done

        while true; do
            read -p "请选择 (1-${#found_dirs[@]}): " choice < /dev/tty
            if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le ${#found_dirs[@]} ]; then
                INSTALL_DIR="${found_dirs[$((choice-1))]}"
                [ -f "$INSTALL_DIR/docker-compose.yml" ] && DEPLOY_MODE="docker" || DEPLOY_MODE="bt"
                return 0
            fi
            log_error "无效选择"
        done
    fi
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

    # 回退到 backend 目录（Docker 环境）
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

    local base_url="${CUSTOM_RELEASE_URL%/}"  # 移除末尾斜杠
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
        6)  log_info "提示: 无法解析域名，请检查 DNS 或网络配置" ;;
        7)  log_info "提示: 无法连接服务器" ;;
        22) log_info "提示: 服务器返回错误（文件可能不存在）" ;;
        28) log_info "提示: 下载超时" ;;
        35|51|60) log_info "提示: SSL/TLS 错误，旧系统可尝试 yum update ca-certificates" ;;
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
    cat > "$backup_path/backup.json" << EOF
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

    # 3. 进入维护模式（必须在移动 vendor 之前）
    log_step "进入维护模式..."
    if [ "$DEPLOY_MODE" = "docker" ]; then
        local compose_cmd=$(check_docker_compose)
        cd "$INSTALL_DIR"
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan down --retry=60" || true
    else
        cd "$INSTALL_DIR/backend"
        php artisan down --retry=60 || true
    fi

    # 4. 提取需要保留的文件到临时目录
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
    # 保留自定义 API 适配器（排除核心文件 Api.php 和 default/）
    local api_adapter_dir="$INSTALL_DIR/backend/app/Services/Order/Api"
    if [ -d "$api_adapter_dir" ]; then
        local has_custom=false
        mkdir -p "$preserve_dir/api_adapters"
        for item in "$api_adapter_dir"/*; do
            [ ! -e "$item" ] && continue
            local name=$(basename "$item")
            # 跳过核心文件
            if [ "$name" = "Api.php" ] || [ "$name" = "default" ]; then
                continue
            fi
            # 复制自定义适配器
            cp -r "$item" "$preserve_dir/api_adapters/"
            has_custom=true
            log_info "保留自定义 API 适配器: $name"
        done
        [ "$has_custom" = true ] && log_info "已保留自定义 API 适配器"
    fi

    # 5. 删除旧代码
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

    # 6. 解压升级包
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

    # 7. 复制新代码（使用 /. 确保复制隐藏文件如 .ssl-manager）
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
        if [ "$DEPLOY_MODE" = "docker" ]; then
            project_root="/var/www/html"
        else
            # 宝塔环境使用实际安装目录
            project_root="$INSTALL_DIR"
        fi

        if [ -f "$INSTALL_DIR/nginx/manager.conf" ]; then
            sed -i "s|__PROJECT_ROOT__|$project_root|g" "$INSTALL_DIR/nginx/manager.conf"
            log_info "已替换 manager.conf 中的路径占位符"
        fi
    fi

    # 替换 web.conf 中的占位符
    if [ -f "$INSTALL_DIR/frontend/web/web.conf" ]; then
        local project_root
        if [ "$DEPLOY_MODE" = "docker" ]; then
            project_root="/var/www/html"
        else
            project_root="$INSTALL_DIR"
        fi
        sed -i "s|__PROJECT_ROOT__|$project_root|g" "$INSTALL_DIR/frontend/web/web.conf"
        log_info "已替换 web.conf 中的路径占位符"
    fi

    # 复制根目录版本配置（保留用户的 release_url）
    if [ -f "$src_dir/version.json" ]; then
        local old_release_url=""
        local old_version_json="$INSTALL_DIR/version.json"

        # 使用 Python 读取旧的 release_url（正确处理 JSON 转义）
        if [ -f "$old_version_json" ] && command -v python3 &> /dev/null; then
            old_release_url=$(python3 -c "
import json
try:
    with open('$old_version_json', 'r') as f:
        data = json.load(f)
    print(data.get('release_url', ''))
except:
    pass
" 2>/dev/null)
        fi

        # 复制新的 version.json
        cp "$src_dir/version.json" "$INSTALL_DIR/"

        # 如果存在旧的 release_url，合并到新的 version.json
        if [ -n "$old_release_url" ]; then
            # 使用 Python 处理 JSON 合并（管理员手工运行，变量来自可信的本地文件）
            python3 << PYEOF
import json
with open("$INSTALL_DIR/version.json", "r") as f:
    data = json.load(f)
data["release_url"] = "$old_release_url"
with open("$INSTALL_DIR/version.json", "w") as f:
    json.dump(data, f, indent=2)
PYEOF
            log_info "保留 release_url 配置: $old_release_url"
        fi
    fi

    # 8. 恢复保留的文件
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

    # 恢复自定义 API 适配器
    if [ -d "$preserve_dir/api_adapters" ] && [ "$(ls -A "$preserve_dir/api_adapters" 2>/dev/null)" ]; then
        local api_adapter_dir="$INSTALL_DIR/backend/app/Services/Order/Api"
        mkdir -p "$api_adapter_dir"
        cp -r "$preserve_dir/api_adapters"/* "$api_adapter_dir/"
        log_info "已恢复自定义 API 适配器"
    fi

    # 8.1 预先修复权限（在执行 artisan 命令前）
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

    if [ "$DEPLOY_MODE" = "docker" ]; then
        # 在容器内执行权限修复（宿主机可能没有 www-data 用户）
        cd "$INSTALL_DIR"
        # backend/storage
        $compose_cmd exec -T php chown -R www-data:www-data /var/www/html/backend/storage 2>/dev/null || true
        $compose_cmd exec -T php chmod -R 775 /var/www/html/backend/storage 2>/dev/null || true
        # 根目录 backups
        $compose_cmd exec -T php chown -R www-data:www-data /var/www/html/backups 2>/dev/null || true
        $compose_cmd exec -T php chmod -R 775 /var/www/html/backups 2>/dev/null || true
        # version.json（版本配置，需要可写以支持切换通道）
        $compose_cmd exec -T php chown www-data:www-data /var/www/html/version.json 2>/dev/null || true
        $compose_cmd exec -T php chmod 664 /var/www/html/version.json 2>/dev/null || true
        # .env 文件（让 www-data 可读，用于升级时备份）
        $compose_cmd exec -T php chown www-data:www-data /var/www/html/backend/.env 2>/dev/null || true
        $compose_cmd exec -T php chmod 600 /var/www/html/backend/.env 2>/dev/null || true
        # frontend 目录（宿主机上设置，让 www-data 可读以支持备份）
        chown -R 82:82 "$INSTALL_DIR/frontend" 2>/dev/null || true
        chmod -R 755 "$INSTALL_DIR/frontend" 2>/dev/null || true
    else
        # 宝塔模式
        chown -R www:www "$backend_storage" 2>/dev/null || true
        chmod -R 775 "$backend_storage" 2>/dev/null || true
        chown -R www:www "$backups_dir" 2>/dev/null || true
        chmod -R 775 "$backups_dir" 2>/dev/null || true
        [ -f "$version_file" ] && chown www:www "$version_file" && chmod 664 "$version_file"
        # .env 文件（让 www 可读，用于升级时备份）
        [ -f "$INSTALL_DIR/backend/.env" ] && chown www:www "$INSTALL_DIR/backend/.env" && chmod 600 "$INSTALL_DIR/backend/.env"
    fi

    # 9. 检测依赖变化，决定是否运行 composer install
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

        # 执行 composer install
        if [ "$DEPLOY_MODE" = "docker" ]; then
            cd "$INSTALL_DIR"
            if [ "$use_china_mirror" = true ]; then
                $compose_cmd exec -T php composer config repo.packagist composer https://mirrors.aliyun.com/composer/
            fi
            $compose_cmd exec -T php composer install --no-dev --optimize-autoloader
        else
            cd "$INSTALL_DIR/backend"
            if [ "$use_china_mirror" = true ]; then
                composer config repo.packagist composer https://mirrors.aliyun.com/composer/
            fi
            COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
        fi
    fi

    # 10. 运行数据库迁移
    log_step "运行数据库迁移..."
    if [ "$DEPLOY_MODE" = "docker" ]; then
        cd "$INSTALL_DIR"
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan migrate --force"
    else
        cd "$INSTALL_DIR/backend"
        php artisan migrate --force
    fi

    # 10.1 初始化/更新数据
    log_step "更新数据..."
    if [ "$DEPLOY_MODE" = "docker" ]; then
        cd "$INSTALL_DIR"
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan db:seed --force" || true
    else
        cd "$INSTALL_DIR/backend"
        php artisan db:seed --force || true
    fi

    # 10.2 数据库结构校验
    log_step "数据库结构校验..."
    local structure_check_result=0
    local structure_output=""
    if [ "$DEPLOY_MODE" = "docker" ]; then
        cd "$INSTALL_DIR"
        # 检查结构差异
        structure_output=$($compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan db:structure --check" 2>&1) || true
        if echo "$structure_output" | grep -q "数据库结构完全一致"; then
            log_success "数据库结构校验通过"
        else
            log_warning "检测到数据库结构差异："
            echo "$structure_output" | head -50
            echo ""
            log_warning "尝试自动修复（仅 ADD 操作）..."
            if $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan db:structure --fix --skip-foreign-keys" 2>&1; then
                log_success "数据库结构自动修复完成"
            else
                log_warning "部分结构差异需要手动处理"
                structure_check_result=1
            fi
            echo ""
            echo -e "${YELLOW}提示: 使用以下命令查看和修复结构差异：${NC}"
            echo "  php artisan db:structure --check  # 查看差异"
            echo "  php artisan db:structure --fix    # 自动修复"
        fi
    else
        cd "$INSTALL_DIR/backend"
        # 检查结构差异
        structure_output=$(php artisan db:structure --check 2>&1) || true
        if echo "$structure_output" | grep -q "数据库结构完全一致"; then
            log_success "数据库结构校验通过"
        else
            log_warning "检测到数据库结构差异："
            echo "$structure_output" | head -50
            echo ""
            log_warning "尝试自动修复（仅 ADD 操作）..."
            if php artisan db:structure --fix --skip-foreign-keys 2>&1; then
                log_success "数据库结构自动修复完成"
            else
                log_warning "部分结构差异需要手动处理"
                structure_check_result=1
            fi
            echo ""
            echo -e "${YELLOW}提示: 使用以下命令查看和修复结构差异：${NC}"
            echo "  php artisan db:structure --check  # 查看差异"
            echo "  php artisan db:structure --fix    # 自动修复"
        fi
    fi

    # 11. 清理缓存
    log_step "清理缓存..."
    if [ "$DEPLOY_MODE" = "docker" ]; then
        cd "$INSTALL_DIR"
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan config:cache" || true
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan route:cache" || true
    else
        cd "$INSTALL_DIR/backend"
        php artisan config:cache || true
        php artisan route:cache || true
    fi

    # 12. 完整性校验
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

    # 13. 退出维护模式
    log_step "退出维护模式..."
    if [ "$DEPLOY_MODE" = "docker" ]; then
        cd "$INSTALL_DIR"
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan up"
    else
        cd "$INSTALL_DIR/backend"
        php artisan up
    fi

    # 最终权限检查
    log_step "确认文件权限..."

    if [ "$DEPLOY_MODE" = "docker" ]; then
        # Docker 模式：在容器内执行权限修复
        cd "$INSTALL_DIR"
        # backend 整个目录需要 www-data 可写以支持在线升级
        $compose_cmd exec -T php chown -R www-data:www-data /var/www/html/backend 2>/dev/null || true
        $compose_cmd exec -T php chmod -R 755 /var/www/html/backend 2>/dev/null || true
        $compose_cmd exec -T php chmod -R 775 /var/www/html/backend/storage 2>/dev/null || true
        # 根目录 backups
        $compose_cmd exec -T php chown -R www-data:www-data /var/www/html/backups 2>/dev/null || true
        $compose_cmd exec -T php chmod -R 775 /var/www/html/backups 2>/dev/null || true
        # version.json（版本配置，需要可写以支持切换通道）
        $compose_cmd exec -T php chown www-data:www-data /var/www/html/version.json 2>/dev/null || true
        $compose_cmd exec -T php chmod 664 /var/www/html/version.json 2>/dev/null || true
        # .env 文件（让 www-data 可读，用于升级时备份）
        $compose_cmd exec -T php chown www-data:www-data /var/www/html/backend/.env 2>/dev/null || true
        $compose_cmd exec -T php chmod 600 /var/www/html/backend/.env 2>/dev/null || true
        # frontend 目录（宿主机上设置，让 www-data 可读以支持备份）
        chown -R 82:82 "$INSTALL_DIR/frontend" 2>/dev/null || true
        chmod -R 755 "$INSTALL_DIR/frontend" 2>/dev/null || true
    else
        # 宝塔模式：设置整个安装目录的权限
        chown -R www:www "$INSTALL_DIR" 2>/dev/null || true
        # 确保关键目录可写
        chmod -R 775 "$INSTALL_DIR/backend/storage" 2>/dev/null || true
        chmod -R 775 "$INSTALL_DIR/backups" 2>/dev/null || true
        [ -f "$INSTALL_DIR/version.json" ] && chmod 664 "$INSTALL_DIR/version.json"
        # .env 文件（让 www 可读，用于升级时备份）
        [ -f "$INSTALL_DIR/backend/.env" ] && chown www:www "$INSTALL_DIR/backend/.env" && chmod 600 "$INSTALL_DIR/backend/.env"
    fi

    # .env 文件敏感信息保护（root 和 web 用户可读）
    [ -f "$INSTALL_DIR/.env" ] && chmod 640 "$INSTALL_DIR/.env"

    # 重启服务以加载新配置
    if [ "$DEPLOY_MODE" = "docker" ]; then
        log_step "重启服务..."
        cd "$INSTALL_DIR"
        $compose_cmd restart nginx php queue scheduler 2>/dev/null || true
        log_info "服务已重启"
    else
        # 宝塔环境：reload Nginx 以加载更新后的 manager.conf
        log_step "重载 Nginx 配置..."
        if command -v nginx &> /dev/null; then
            nginx -t 2>/dev/null && nginx -s reload 2>/dev/null && log_info "Nginx 已重载" || log_warning "Nginx 重载失败，请手动执行: nginx -s reload"
        elif [ -f /etc/init.d/nginx ]; then
            /etc/init.d/nginx reload 2>/dev/null && log_info "Nginx 已重载" || log_warning "Nginx 重载失败"
        else
            log_warning "未找到 Nginx，请手动重载 Nginx 配置"
        fi
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
    if [ "$DEPLOY_MODE" = "docker" ]; then
        local compose_cmd=$(check_docker_compose)
        cd "$INSTALL_DIR"
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan down" || true
    else
        cd "$INSTALL_DIR/backend"
        php artisan down || true
    fi

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
    if [ "$DEPLOY_MODE" = "docker" ]; then
        $compose_cmd exec -T php sh -c "cd /var/www/html/backend && php artisan up"
    else
        cd "$INSTALL_DIR/backend"
        php artisan up
    fi

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
  --url URL              指定 release 服务 URL（覆盖 version.json 配置）
  --version, -v VERSION  指定升级版本
                         latest   最新稳定版（默认）
                         dev      最新开发版
                         x.x.x    指定版本号
  --file FILE            使用本地升级包（跳过下载）
  --dir DIR              指定安装目录（默认自动检测）
  -y, --yes              自动确认，非交互模式
  check                  仅检查更新
  rollback               回滚到上一版本
  -h, --help             显示帮助

环境变量:
  FORCE_CHINA_MIRROR=1   强制使用国内镜像

示例:
  $0 --url http://release.example.com              # 升级到最新稳定版
  $0 --url http://release.example.com -v 1.0.0     # 升级到指定版本
  $0 --dir /www/wwwroot/mysite                     # 从 version.json 读取 release_url
  $0 --file /path/to/pkg.zip                       # 使用本地包升级
  $0 rollback                                      # 回滚

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
    echo -e "${CYAN}║${NC}           ${GREEN}SSL Manager 在线升级程序${NC}                        ${CYAN}║${NC}"
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
            --version|-v)
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
            -y|--yes)
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
            -h|--help)
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
                log_step "下载升级包..."
                upgrade_file="$TEMP_DIR/ssl-manager-upgrade.zip"
                if ! download_upgrade_package "$target_version" "$upgrade_file"; then
                    log_error "无法下载升级包"
                    exit 1
                fi
            fi

            # 验证升级包
            if [ ! -f "$upgrade_file" ]; then
                log_error "升级包不存在: $upgrade_file"
                exit 1
            fi

            # 获取目标版本（如果是 latest，需要从包中读取）
            if [ "$target_version" = "latest" ] || [ "$target_version" = "dev" ]; then
                # 尝试从升级包中读取版本
                local pkg_version=$(unzip -p "$upgrade_file" "*/version.json" 2>/dev/null | grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | cut -d'"' -f4 || echo "")
                if [ -n "$pkg_version" ]; then
                    target_version="$pkg_version"
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
