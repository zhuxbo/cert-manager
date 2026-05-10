#!/bin/bash

# 远程发布脚本
# 将构建产物部署到远程 Linux 服务器
#
# 用法:
#   ./release.sh <版本号>      # 必须指定版本号
#   ./release.sh 0.0.15-beta  # 发布指定版本
#   ./release.sh --server cn  # 只发布到指定服务器
#   ./release.sh --test       # 测试 SSH 连接
#   ./release.sh --upload-only # 只上传，跳过构建

set -e

# ========================================
# 配置
# ========================================
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$SCRIPT_DIR"
CONFIG_FILE="$SCRIPT_DIR/release.conf"

# 默认配置
KEEP_VERSIONS=5
PARALLEL_UPLOAD=false
SSH_TIMEOUT=10

# 检测 Docker 是否需要 sudo（Linux 非 root 用户且不在 docker 组时需要）
DOCKER_SUDO=""
if ! docker info >/dev/null 2>&1; then
    if sudo docker info >/dev/null 2>&1; then
        DOCKER_SUDO="sudo"
    fi
fi

# ========================================
# 加载公共函数库
# ========================================
source "$SCRIPT_DIR/scripts/release-common.sh"

# ========================================
# 加载配置
# ========================================
load_config() {
    if [ ! -f "$CONFIG_FILE" ]; then
        log_error "配置文件不存在: $CONFIG_FILE"
        log_info "请复制 release.conf.example 并配置:"
        log_info "  cp $SCRIPT_DIR/release.conf.example $CONFIG_FILE"
        exit 1
    fi

    # 检查配置文件权限（应为 600）
    local perms=$(stat -c %a "$CONFIG_FILE" 2>/dev/null || stat -f %OLp "$CONFIG_FILE" 2>/dev/null)
    if [ "$perms" != "600" ]; then
        log_warning "配置文件权限不安全（当前: $perms），建议设置为 600:"
        log_info "  chmod 600 $CONFIG_FILE"
    fi

    # 加载配置
    source "$CONFIG_FILE"

    # 验证必要配置
    if [ ${#SERVERS[@]} -eq 0 ]; then
        log_error "未配置服务器列表 SERVERS"
        exit 1
    fi

    if [ -z "$SSH_USER" ]; then
        log_error "未配置 SSH_USER"
        exit 1
    fi

    if [ -z "$SSH_KEY" ]; then
        log_error "未配置 SSH_KEY"
        exit 1
    fi

    # 展开 SSH_KEY 路径中的 ~
    SSH_KEY="${SSH_KEY/#\~/$HOME}"

    if [ ! -f "$SSH_KEY" ]; then
        log_error "SSH 密钥文件不存在: $SSH_KEY"
        exit 1
    fi
}

# ========================================
# 确保 tag 存在并指向当前提交
# ========================================
ensure_tag() {
    local tag="$1"
    local head_commit=$(git rev-parse HEAD)
    local tag_commit=$(git rev-parse --verify "refs/tags/$tag" 2>/dev/null || echo "")

    if [ -z "$tag_commit" ]; then
        log_info "创建 tag: $tag"
        git tag "$tag"
        git push origin "$tag"
    elif [ "$tag_commit" != "$head_commit" ]; then
        log_warning "tag $tag 指向其他提交，更新到当前提交"
        git tag -d "$tag"
        git push origin ":refs/tags/$tag" 2>/dev/null || true
        git tag "$tag"
        git push origin "$tag"
    else
        log_info "tag $tag 已指向当前提交"
    fi
}

# ========================================
# 解析服务器配置
# 格式: "名称,主机,端口,目录,URL"
# ========================================
parse_server() {
    local server_str="$1"
    IFS=',' read -r SERVER_NAME SERVER_HOST SERVER_PORT SERVER_DIR SERVER_URL <<<"$server_str"
    SERVER_PORT=${SERVER_PORT:-22}
}

# ========================================
# SSH 命令封装
# ========================================
ssh_cmd() {
    local host="$1"
    local port="$2"
    shift 2
    ssh -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=$SSH_TIMEOUT \
        -p "$port" "$SSH_USER@$host" "$@"
}

rsync_cmd() {
    local src="$1"
    local host="$2"
    local port="$3"
    local dest="$4"
    rsync -avz --progress -e "ssh -i $SSH_KEY -o StrictHostKeyChecking=accept-new -p $port" \
        "$src" "$SSH_USER@$host:$dest"
}

# ========================================
# 测试 SSH 连接
# ========================================
test_ssh_connection() {
    local server_str="$1"
    parse_server "$server_str"

    log_info "测试连接: $SERVER_NAME ($SERVER_HOST:$SERVER_PORT)"

    if ssh_cmd "$SERVER_HOST" "$SERVER_PORT" "echo 'SSH 连接成功'" 2>/dev/null; then
        log_success "$SERVER_NAME: 连接成功"
        return 0
    else
        log_error "$SERVER_NAME: 连接失败"
        return 1
    fi
}

test_all_connections() {
    log_step "测试所有服务器连接..."
    local failed=0

    for server in "${SERVERS[@]}"; do
        if ! test_ssh_connection "$server"; then
            failed=$((failed + 1))
        fi
    done

    if [ $failed -gt 0 ]; then
        log_error "$failed 个服务器连接失败"
        return 1
    fi

    log_success "所有服务器连接正常"
    return 0
}

# ========================================
# 远程更新 releases.json
# ========================================
update_releases_json_remote() {
    local server_str="$1"
    local version="$2"
    local channel="$3"

    parse_server "$server_str"

    log_info "更新 releases.json..."

    local releases_file="$SERVER_DIR/releases.json"
    local version_dir="$SERVER_DIR/$channel/v$version"
    local rel_path="$channel/v$version"

    # 在远程执行 Python 脚本
    ssh_cmd "$SERVER_HOST" "$SERVER_PORT" "python3 << 'PYEOF'
$(generate_releases_update_script "$releases_file" "$version" "$channel" "$version_dir" "$rel_path")
PYEOF"
}

# ========================================
# 远程部署脚本
# ========================================
deploy_scripts_remote() {
    local server_str="$1"
    parse_server "$server_str"

    log_info "部署脚本..."

    local deploy_dir="$PROJECT_ROOT/deploy"

    # 部署 install.sh
    if [ -f "$deploy_dir/install.sh" ]; then
        process_deploy_script "$deploy_dir/install.sh" "$SERVER_URL" |
            ssh_cmd "$SERVER_HOST" "$SERVER_PORT" "cat > \"$SERVER_DIR/install.sh\" && chmod +x \"$SERVER_DIR/install.sh\""
        log_info "已部署: install.sh"
    fi

    # 部署 upgrade.sh
    if [ -f "$deploy_dir/upgrade.sh" ]; then
        process_deploy_script "$deploy_dir/upgrade.sh" "$SERVER_URL" |
            ssh_cmd "$SERVER_HOST" "$SERVER_PORT" "cat > \"$SERVER_DIR/upgrade.sh\" && chmod +x \"$SERVER_DIR/upgrade.sh\""
        log_info "已部署: upgrade.sh"
    fi
}

# ========================================
# 远程更新符号链接
# ========================================
update_symlinks_remote() {
    local server_str="$1"
    local version="$2"
    local channel="$3"

    parse_server "$server_str"

    log_info "更新符号链接..."

    local latest_dir="$SERVER_DIR/latest"
    [ "$channel" = "dev" ] && latest_dir="$SERVER_DIR/dev-latest"

    local version_dir="$SERVER_DIR/$channel/v$version"

    ssh_cmd "$SERVER_HOST" "$SERVER_PORT" "
        mkdir -p \"$latest_dir\"
        cd \"$latest_dir\"
        for pkg in \"$version_dir\"/ssl-manager-*.zip; do
            if [ -f \"\$pkg\" ]; then
                filename=\$(basename \"\$pkg\")
                pkg_type=\$(echo \"\$filename\" | sed 's/ssl-manager-\\([^-]*\\)-.*/\\1/')
                latest_name=\"ssl-manager-\${pkg_type}-latest.zip\"
                rm -f \"\$latest_name\"
                ln -s \"../$channel/v$version/\$filename\" \"\$latest_name\"
            fi
        done
    "
}

# ========================================
# 远程清理旧版本
# ========================================
cleanup_old_versions_remote() {
    local server_str="$1"
    local channel="$2"

    parse_server "$server_str"

    log_info "清理旧版本（保留 $KEEP_VERSIONS 个）..."

    ssh_cmd "$SERVER_HOST" "$SERVER_PORT" "
        cd \"$SERVER_DIR/$channel\" 2>/dev/null || exit 0
        ls -dt v* 2>/dev/null | tail -n +$((KEEP_VERSIONS + 1)) | xargs -r rm -rf
    "
}

# ========================================
# 上传到服务器
# ========================================
upload_to_server() {
    local server_str="$1"
    local version="$2"
    local channel="$3"
    local packages_dir="$4"

    parse_server "$server_str"

    log_step "部署到 $SERVER_NAME ($SERVER_HOST)..."

    local remote_version_dir="$SERVER_DIR/$channel/v$version"

    # 创建远程目录
    log_info "创建目录: $remote_version_dir"
    ssh_cmd "$SERVER_HOST" "$SERVER_PORT" "mkdir -p \"$remote_version_dir\" && rm -f \"$remote_version_dir\"/*.zip"

    # 上传包文件
    log_info "上传包文件..."
    for pkg in "$packages_dir"/ssl-manager-*.zip; do
        if [ -f "$pkg" ]; then
            local filename=$(basename "$pkg")
            log_info "  上传: $filename"
            rsync_cmd "$pkg" "$SERVER_HOST" "$SERVER_PORT" "$remote_version_dir/"
        fi
    done

    # 更新 releases.json
    update_releases_json_remote "$server_str" "$version" "$channel"

    # 部署脚本
    deploy_scripts_remote "$server_str"

    # 更新符号链接
    update_symlinks_remote "$server_str" "$version" "$channel"

    # 清理旧版本
    cleanup_old_versions_remote "$server_str" "$channel"

    log_success "$SERVER_NAME: 部署完成"
}

# ========================================
# 部署到所有服务器
# ========================================
deploy_to_all() {
    local version="$1"
    local channel="$2"
    local packages_dir="$3"
    local target_server="$4"

    local success=0
    local failed=0

    for server in "${SERVERS[@]}"; do
        parse_server "$server"

        # 如果指定了服务器，只部署到该服务器
        if [ -n "$target_server" ] && [ "$SERVER_NAME" != "$target_server" ]; then
            continue
        fi

        if upload_to_server "$server" "$version" "$channel" "$packages_dir"; then
            success=$((success + 1))
        else
            failed=$((failed + 1))
            log_error "$SERVER_NAME: 部署失败"
        fi
    done

    echo ""
    log_step "部署结果汇总"
    log_info "成功: $success 个服务器"
    [ $failed -gt 0 ] && log_error "失败: $failed 个服务器"

    return $failed
}

# ========================================
# 显示帮助
# ========================================
show_help() {
    cat <<EOF
用法: $0 [选项] [版本号]

选项:
  --test            测试所有服务器 SSH 连接
  --server NAME     只部署到指定服务器
  --upload-only     只上传，跳过构建
  -h, --help        显示帮助

示例:
  $0 0.0.15-beta        发布指定版本
  $0 --server cn        只发布到 cn 服务器
  $0 --test             测试连接
EOF
}

# ========================================
# 主流程
# ========================================
main() {
    local version=""
    local target_server=""
    local upload_only=false
    local test_only=false

    # 解析参数
    while [ $# -gt 0 ]; do
        case "$1" in
            --test)
                test_only=true
                shift
                ;;
            --server)
                target_server="$2"
                shift 2
                ;;
            --upload-only)
                upload_only=true
                shift
                ;;
            -h | --help)
                show_help
                exit 0
                ;;
            -*)
                log_error "未知选项: $1"
                show_help
                exit 1
                ;;
            *)
                version="$1"
                shift
                ;;
        esac
    done

    print_release_banner "SSL Manager 远程发布脚本"

    # 加载配置
    load_config

    # 测试模式
    if [ "$test_only" = true ]; then
        test_all_connections
        exit $?
    fi

    # 版本号必须通过命令行参数传入
    if [ -z "$version" ]; then
        log_error "必须指定版本号"
        log_info "用法: $0 <版本号>"
        exit 1
    fi

    # 确定通道
    local channel=$(get_channel "$version")

    # 正式版必须在 main 分支发布：强制检测分支 + 干净工作区，并打 tag
    if [ "$channel" = "main" ]; then
        local current_branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
        if [ "$current_branch" != "main" ]; then
            log_error "正式版必须在 main 分支发布，当前分支：${current_branch:-未知}"
            log_info "请先把 dev 合并到 main，并切换到 main 分支后再发布"
            exit 1
        fi
        # 强制要求工作区干净
        if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
            log_error "工作区存在未提交修改，正式版发布要求干净工作区"
            git status --short
            exit 1
        fi
        # 确保 main 已与 origin/main 同步
        git fetch origin main --quiet 2>/dev/null || true
        local local_head=$(git rev-parse HEAD)
        local remote_head=$(git rev-parse origin/main 2>/dev/null || echo "")
        if [ -n "$remote_head" ] && [ "$local_head" != "$remote_head" ]; then
            log_error "本地 main 与 origin/main 不一致，请先同步"
            log_info "  本地: $local_head"
            log_info "  远程: $remote_head"
            exit 1
        fi
        # 打版本 tag + 同步 latest tag 到当前提交
        ensure_tag "v$version"
        ensure_tag "latest"
    fi

    log_info "版本号: $version"
    log_info "发布通道: $channel"
    log_info "目标服务器: ${target_server:-全部}"

    # 测试连接
    if ! test_all_connections; then
        log_error "请先解决连接问题"
        exit 1
    fi

    # 构建
    local packages_dir="$BUILD_DIR/temp/packages"
    if [ "$upload_only" = false ]; then
        # 1. 容器化构建（生成 production-code）
        log_step "运行容器化构建..."
        if [ -f "$BUILD_DIR/build.sh" ]; then
            if ! $DOCKER_SUDO bash "$BUILD_DIR/build.sh" --version "$version" --channel "$channel"; then
                log_error "构建失败，终止发布"
                exit 1
            fi
            # sudo 构建后归还 temp 目录权限，确保后续打包步骤可写
            if [ -n "$DOCKER_SUDO" ]; then
                sudo chown -R "$(id -u):$(id -g)" "$BUILD_DIR/temp"
            fi
        else
            log_error "构建脚本不存在: $BUILD_DIR/build.sh"
            exit 1
        fi
        # 2. 打包
        build_packages "$BUILD_DIR" "$version"
    else
        log_info "跳过构建，使用已有包"
    fi

    # 检查包目录
    if [ ! -d "$packages_dir" ]; then
        log_error "包目录不存在: $packages_dir"
        exit 1
    fi

    # 部署
    local result=0
    deploy_to_all "$version" "$channel" "$packages_dir" "$target_server" || result=$?

    echo ""
    if [ $result -eq 0 ]; then
        log_success "发布完成！"
        echo ""
        log_info "验证命令:"
        for server in "${SERVERS[@]}"; do
            parse_server "$server"
            if [ -z "$target_server" ] || [ "$SERVER_NAME" = "$target_server" ]; then
                echo "  curl $SERVER_URL/releases.json | jq ."
            fi
        done
    else
        log_error "部分服务器发布失败"
    fi

    return $result
}

main "$@"
