#!/bin/bash

# SSL证书管理系统 - 宝塔面板自动化脚本
#
# 提供 BT API 调用自动化能力：建站 / supervisor / cron
# 设计为可选模块：BT_KEY 缺失或 API 失败时降级为手工提示，不阻塞主流程
#
# 用法：
#   source bt-automate.sh
#   bt_resolve_key                                    # 探测 BT_KEY，成功返回 0
#   bt_create_site <domain> <php_version> <root_path> # BT API 建站
#   bt_setup_supervisor <name> <command> <user> <dir> # 写 supervisor 配置
#   bt_setup_cron <name> <schedule> <command>         # 写宝塔 cron 任务
#
# 设计权衡：
#   - 所有 API 调用通过 curl + md5sum 计算签名，不引入额外依赖
#   - 失败一律 return 1 + log_warning，调用方决定是否退化提示
#   - 幂等：建站/supervisor/cron 重复执行不应失败（已存在 → warn skip）

# 防止重复 source
if [ -n "${BT_AUTOMATE_LOADED:-}" ]; then
    return 0 2>/dev/null || true
fi
BT_AUTOMATE_LOADED=1

# 依赖 common.sh 的日志函数；调用方应已 source common.sh
# 兜底：未加载 common.sh 时定义最小 stub，避免 unbound function
if ! type log_info &>/dev/null; then
    log_info() { echo "[INFO] $1"; }
    log_success() { echo "[OK] $1"; }
    log_error() { echo "[ERROR] $1" >&2; }
    log_warning() { echo "[WARN] $1"; }
    log_step() { echo "[STEP] $1"; }
fi

# ========================================
# BT API 配置
# ========================================

# BT 面板 API 端点动态探测（端口存在 /www/server/panel/data/port.pl）
# 协议：BT 11.x 默认 http，启用面板 SSL 后改 https；先试 https 再 fallback http
# 优先级：env BT_API_BASE > 探测 port.pl + 协议 > 兜底 https://127.0.0.1:8888
# 惰性解析：不在 source 阶段执行，由 _bt_api_post 首次调用时解析
# （source 即 curl 会让无宝塔环境下 set -e -o pipefail 的调用方直接退出；与 bt-deps.sh 调用处解析对齐）
_resolve_bt_api_base() {
    if [ -n "${BT_API_BASE:-}" ]; then return 0; fi
    local port=""
    if [ -r "/www/server/panel/data/port.pl" ]; then
        port=$(tr -d '[:space:]' </www/server/panel/data/port.pl)
    fi
    port="${port:-8888}"

    # 用 HEAD 探活，必须返回 HTTP 状态行才认（SSL 握手失败 / TCP 连不上都会拿不到状态行）
    # || true：探活失败是预期分支，不让调用方的 set -e / pipefail 中断
    local proto status_line
    for proto in https http; do
        status_line=$(curl -ksI --connect-timeout 3 --max-time 3 "$proto://127.0.0.1:$port/" 2>/dev/null | head -1) || true
        if echo "$status_line" | grep -qE "^HTTP/"; then
            BT_API_BASE="$proto://127.0.0.1:$port"
            return 0
        fi
    done
    # 两种协议都不通 → 兜底 https（让后续 _bt_api_post 调用时的 curl 错误暴露具体问题）
    BT_API_BASE="https://127.0.0.1:$port"
}

# 探测到的 BT_KEY（bt_resolve_key 成功后填充）
BT_KEY="${BT_KEY:-}"

# supervisor 插件安装等待超时（秒）；install_plugin 是后台任务
BT_PLUGIN_INSTALL_TIMEOUT="${BT_PLUGIN_INSTALL_TIMEOUT:-180}"

# ========================================
# 工具函数
# ========================================

# md5 校验（兼容 Linux md5sum 和 macOS md5）
_bt_md5() {
    local input="$1"
    if command -v md5sum &>/dev/null; then
        printf '%s' "$input" | md5sum | awk '{print $1}'
    elif command -v md5 &>/dev/null; then
        printf '%s' "$input" | md5
    else
        log_error "缺少 md5sum 或 md5 命令，无法计算 BT API 签名"
        return 1
    fi
}

# 计算 BT API 签名
# 用法：_bt_sign <output_var_prefix>
# 输出（赋值）：BT_REQ_TIME / BT_REQ_TOKEN
_bt_sign() {
    if [ -z "$BT_KEY" ]; then
        log_error "BT_KEY 未设置，请先调用 bt_resolve_key"
        return 1
    fi

    BT_REQ_TIME="$(date +%s)"
    local key_md5
    key_md5="$(_bt_md5 "$BT_KEY")" || return 1
    BT_REQ_TOKEN="$(_bt_md5 "${BT_REQ_TIME}${key_md5}")" || return 1
    return 0
}

# 调用 BT API（POST）
# 用法：_bt_api_post <action_path> <form_data>
#   action_path: 例如 "/site?action=AddSite"
#   form_data: curl --data-urlencode 形式的额外参数（多个用换行分隔）
# stdout: 返回 BT API 的 JSON 响应；非 0 退出码表示 curl 失败
_bt_api_post() {
    local action_path="$1"
    local form_data="$2"

    # 惰性解析 API 端点（首次调用时探测；BT_API_BASE 已设置则直接复用）
    _resolve_bt_api_base

    _bt_sign || return 1

    local url="${BT_API_BASE}${action_path}"
    # 默认超时 30s；input_package 等同步执行 install.sh 的端点可临时覆盖 BT_API_TIMEOUT
    local timeout="${BT_API_TIMEOUT:-30}"
    # 安全：-k（跳过 TLS 校验）仅在目标确为本机 loopback 时启用——BT 11.x 自签名证书
    # 走 https 必须跳过校验，但 BT_API_BASE 可被 env 覆盖成任意地址，对非 loopback
    # 主机跳过校验等于放任中间人。故断言 URL 必须以 https://127.0.0.1 开头才加 -k，
    # 其余情况留空 → curl 正常校验 TLS。
    local insecure_flag=""
    case "$url" in
        https://127.0.0.1 | https://127.0.0.1:* | https://127.0.0.1/*)
            insecure_flag="-k"
            ;;
    esac
    # 失败时把 curl stderr 一并输出，帮助诊断协议错配 / SSL 握手 / 连不上等问题
    local resp curl_exit=0
    if [ -n "$form_data" ]; then
        # form_data 可能含多行 --data-urlencode 参数，用 eval 展开
        # 参数已在调用方控制，不引入用户输入
        resp=$(eval curl -s $insecure_flag --show-error --connect-timeout 10 --max-time "$timeout" \
            --data-urlencode "request_token=$BT_REQ_TOKEN" \
            --data-urlencode "request_time=$BT_REQ_TIME" \
            "$form_data" \
            "'$url'" 2>&1) || curl_exit=$?
    else
        resp=$(curl -s $insecure_flag --show-error --connect-timeout 10 --max-time "$timeout" \
            --data-urlencode "request_token=$BT_REQ_TOKEN" \
            --data-urlencode "request_time=$BT_REQ_TIME" \
            "$url" 2>&1) || curl_exit=$?
    fi

    if [ "$curl_exit" -ne 0 ]; then
        log_warning "BT API curl 失败 (exit=$curl_exit) url=$url"
        [ -n "$resp" ] && log_warning "curl 输出: $resp"
        return 1
    fi

    printf '%s' "$resp"
    return 0
}

# 解析 JSON 字段（用 BT 自带 python3，不依赖 jq）
# 用法：_bt_json_get <json> <key>
# 仅支持顶层字段；status 等布尔输出为 "true"/"false"；自动解 \uXXXX 转义
# （BT 11.x 响应里中文都是 \uXXXX 形式，老的 grep 实现 grep "已存在" 永远不会命中）
_bt_json_get() {
    local json="$1"
    local key="$2"
    JSON_KEY="$key" python3 -c "
import sys, json, os
key = os.environ.get('JSON_KEY', '')
try:
    d = json.loads(sys.stdin.read())
    if not isinstance(d, dict):
        sys.exit(0)
    v = d.get(key)
    if v is None:
        sys.exit(0)
    if isinstance(v, bool):
        print('true' if v else 'false')
    elif isinstance(v, (int, float)):
        print(v)
    elif isinstance(v, str):
        print(v)
    else:
        print(json.dumps(v, ensure_ascii=False))
except Exception:
    pass
" 2>/dev/null <<<"$json"
}

# ========================================
# 1. 探测 BT_KEY
# ========================================

# 从面板配置文件探测 API key
# 项目最低要求 BT 11.5+，固定字段 token_crypt（用于 MD5 签名）；不再回落旧版本字段
# 优先级：
#   1. 环境变量 BT_KEY 已设置 → 直接用
#   2. /www/server/panel/config/api.json 的 token_crypt
# 成功：导出 BT_KEY，return 0
# 失败：return 1（不打印 error，由调用方决定是否提示）
bt_resolve_key() {
    if [ -n "$BT_KEY" ]; then
        log_info "BT_KEY 来自环境变量"
        return 0
    fi

    # BT 11.x api.json 字段：{"token_crypt":"<对外签名 key>", ...}
    # 之前误读 "token" 字段会触发"密钥校验失败"累积 → BT "连续 20 次失败禁 1 小时"
    # 用 awk -F'"' 准确字段名匹配，避免 grep "token" 误匹配 token_crypt 之外的字段
    local api_json="/www/server/panel/config/api.json"
    if [ -r "$api_json" ]; then
        local token
        token=$(awk -F'"' '/"token_crypt"/{for(i=1;i<=NF;i++) if($i=="token_crypt"){print $(i+2); exit}}' "$api_json" 2>/dev/null)
        if [ -n "$token" ]; then
            BT_KEY="$token"
            log_info "BT_KEY 来源: $api_json"
            return 0
        fi
    fi

    return 1
}

# 验证 BT API key 是否真实有效（签名能通过 + IP 白名单允许）
# 必须在第一次"重要" API 调用前跑一次预检，避免无效 key 累积到 BT 的"连续 20 次失败禁 1 小时"安全锁
# 用一个无害的 GetSystemTotal 端点测试
# return 0: key 有效；1: key 无效 / API 关闭 / IP 白名单不允许
bt_verify_api_key() {
    if [ -z "$BT_KEY" ]; then
        return 1
    fi
    local resp
    resp=$(_bt_api_post "/system?action=GetSystemTotal" "") || return 1
    # 成功响应：含 memTotal / cpuNum 等字段
    if echo "$resp" | grep -qE '"memTotal"|"cpuNum"'; then
        return 0
    fi
    # 失败响应类型：
    #   {"status":false,"msg":"密钥校验失败"} — token 错 / 字段读错
    #   {"status":false,"msg":"连续20次验证失败,禁止1小时"} — 已被 BT 封锁
    #   {"status":false,"msg":"未开启API接口"} — 用户没开
    #   {"status":false,"msg":"IP不在白名单"} — limit_addr 限制
    local msg
    msg=$(_bt_json_get "$resp" "msg")
    log_warning "BT API key 验证失败: ${msg:-未知错误}"
    if echo "$msg" | grep -qE '禁止1小时|continuous|limit'; then
        log_warning "BT 已触发安全锁（连续 N 次验证失败禁 1 小时）"
        log_info "解封：在测试机执行 /etc/init.d/bt restart"
    elif echo "$msg" | grep -qE '密钥校验失败|invalid'; then
        log_info "可能原因 1: BT API 接口未开启（宝塔面板 → 设置 → API 接口）"
        log_info "可能原因 2: limit_addr IP 白名单不含 127.0.0.1（应改为 [\"127.0.0.1\"]）"
        log_info "可能原因 3: api.json 的 token_crypt 字段缺失或已变更"
    elif echo "$msg" | grep -qE '白名单|allow|deny'; then
        log_info "limit_addr 不允许 127.0.0.1，请到面板 → 设置 → API 接口 → IP 白名单加 127.0.0.1"
    fi
    return 1
}

# ========================================
# 2. 建站
# ========================================

# 调 BT API 建站
# 用法：bt_create_site <domain> <php_version> <root_path>
#   domain: 主域名（如 manager.example.com）
#   php_version: 数字格式（如 83 / 84），自动转换 BT API 期望格式
#   root_path: 网站根目录（如 /www/wwwroot/manager）
#
# 幂等性：BT API 在同名 root 已存在时返回 status:false + msg "网站已存在"，本函数视为成功 skip
# 返回：0 成功；1 API 调用失败；2 已存在（视为成功）
bt_create_site() {
    local domain="$1"
    local php_version="$2"
    local root_path="$3"

    if [ -z "$domain" ] || [ -z "$php_version" ] || [ -z "$root_path" ]; then
        log_error "bt_create_site 参数不全（domain / php_version / root_path）"
        return 1
    fi

    log_step "BT API 建站: $domain"

    # 构造 webname JSON
    # BT AddSite 期望：webname={"domain":"x.com","domainlist":[],"count":0}
    local webname_json
    webname_json="{\"domain\":\"$domain\",\"domainlist\":[],\"count\":0}"

    # 提取目录名（path 末尾段）
    local site_dir_name
    site_dir_name="$(basename "$root_path")"

    # 调用 AddSite
    # 注：path 由 BT 自己拼，传 site_dir_name 作为 ftp 站点路径名；
    # 部分版本要求 path 必填，这里传完整 root_path 更稳。
    local form
    form="--data-urlencode 'webname=$webname_json' \
--data-urlencode 'type=PHP' \
--data-urlencode 'port=80' \
--data-urlencode 'ps=Manager Auto-created' \
--data-urlencode 'path=$root_path' \
--data-urlencode 'type_id=0' \
--data-urlencode 'version=$php_version' \
--data-urlencode 'ftp=false' \
--data-urlencode 'sql=false' \
--data-urlencode 'codeing=utf8'"

    local resp
    resp=$(_bt_api_post "/site?action=AddSite" "$form") || {
        log_warning "BT API 建站请求失败（curl 错误）"
        return 1
    }

    # 解析响应（BT 11.x 与旧版响应格式不同，兼容两种）
    # BT 11.x 成功：{"siteStatus":true,"siteId":N,"ftpStatus":false,...}
    # BT 10.x 成功：{"status":true,"msg":"..."}
    # 失败（任一版本）：{"status":false,"msg":"已存在/..."}
    local status msg site_status
    status="$(_bt_json_get "$resp" "status")"
    msg="$(_bt_json_get "$resp" "msg")"
    site_status="$(_bt_json_get "$resp" "siteStatus")"

    if [ "$status" = "true" ] || [ "$site_status" = "true" ]; then
        log_success "BT API 建站成功: $domain"
        return 0
    fi

    # 幂等：网站已存在
    if echo "$msg" | grep -qE '已存在|exist'; then
        log_warning "网站已存在，跳过建站: $domain"
        return 2
    fi

    log_warning "BT API 建站失败: ${msg:-未知错误}"
    log_info "原始响应: $resp"
    return 1
}

# 查询站点是否存在；存在则 echo 站点 root 路径并返回 0，不存在返回 1
# 用法：if path=$(bt_get_site_path domain.com 2>/dev/null); then ... fi
#
# 端点：data?action=getData&table=sites + search 参数
# 注意：search 是模糊匹配；这里取精确等于 domain 的项
bt_get_site_path() {
    local domain="$1"
    [ -n "$domain" ] || return 1

    local resp
    resp=$(_bt_api_post "/data?action=getData" \
        "--data-urlencode 'table=sites' \
--data-urlencode 'limit=100' \
--data-urlencode 'p=1' \
--data-urlencode 'search=$domain'") || return 1

    # 提取与 domain 精确匹配的项的 path（python3 兜底，BT 自带）
    # domain 经 env 传入、python 从 os.environ 读，不插值到源码（防 python 注入）——与 bt_list_crontab_all 范式一致
    local path
    path=$(printf '%s' "$resp" | MATCH_NAME="$domain" python3 -c "
import sys, json, os
match = os.environ.get('MATCH_NAME', '')
try:
    d = json.load(sys.stdin)
    for s in d.get('data', []) if isinstance(d, dict) else []:
        if s.get('name') == match:
            print(s.get('path', ''))
            sys.exit(0)
except Exception:
    pass
sys.exit(1)
" 2>/dev/null) || return 1

    [ -n "$path" ] || return 1
    printf '%s' "$path"
    return 0
}

# ========================================
# 3. Supervisor（走 BT 面板插件 API；面板可见可管理）
# ========================================
#
# 抓包确认（2026-05 BT 9.x）：
#   - 安装插件: POST /plugin?action=install_plugin
#                form: sName=supervisor, version=3, min_version=0.6
#   - 添加进程: POST /plugin?action=a&name=supervisor&s=AddProcess
#                form: pjname / user / path / command / numprocs / ps
#
# 设计：
#   - bt_ensure_supervisor_plugin: 检测插件，未装则触发 install_plugin + 轮询等待
#   - bt_add_supervisor_process: 调 AddProcess；已存在视为成功
#   - 全部依赖 BT_KEY；缺失由调用方走"手工提示"分支（不再 fallback 写文件）

# 检测 supervisor 插件是否已安装
# 探测策略：
#   - 优先看本地 install_checks 路径 /www/server/panel/plugin/supervisor/info.json（BT 自己也用这个判定）
#   - 兜底调 GetProcessList（BT 11.x 真实端点；已装返回 array、未装返回"找不到方法"）
# return 0 已装；1 未装
_bt_supervisor_plugin_installed() {
    # path-based 探测：与 BT 面板 install_checks 一致；最快且避免 token 防爆锁
    if [ -f /www/server/panel/plugin/supervisor/info.json ]; then
        return 0
    fi
    # 兜底：通过 BT API（适用于脚本不直接落在面板宿主机的远程触发场景）
    local resp
    resp=$(_bt_api_post "/plugin?action=a&name=supervisor&s=GetProcessList" "") || return 1
    # 已装：返回 JSON array（[ 开头）或含 program 字段
    if echo "$resp" | grep -qE '^\[|"program"[[:space:]]*:'; then
        return 0
    fi
    # 未装：BT 通用错误："找不到方法" / "插件未安装" / "不存在"
    return 1
}

# 触发 supervisor 插件安装并等待完成（BT 11.x 两步流程）
#
# 安装流程（与浏览器面板手动操作完全一致）：
#   step 1: POST /plugin?action=install_plugin (sName, version, min_version)
#           面板从远程节点下载 zip 并解包到 /www/server/panel/temp/<name>/，
#           返回 plugin_data_info（含 tmp_path / install_opt / name / versions）
#   step 2: POST /plugin?action=input_package (tmp_path, plugin_name, install_opt)
#           面板把 tmp_path 内容拷到 /www/server/panel/plugin/<name>/ 并执行 install.sh
#           返回 {"status": true, "msg": "安装成功!"}
#
# 注意：step 1 返回的 JSON 没有 status 字段（含 name/tmp_path/install_opt 即视为成功）；
#       step 2 才返回 status=true/false。两步均不需要绑定宝塔账号。
# return 0 成功；1 失败
_bt_supervisor_plugin_install() {
    log_info "调 BT API 安装 supervisor 插件（two-step：install_plugin + input_package）..."

    # === step 1: install_plugin 下载 + 解包 ===
    local resp1
    resp1=$(_bt_api_post "/plugin?action=install_plugin" \
        "--data-urlencode 'sName=supervisor' \
--data-urlencode 'version=3' \
--data-urlencode 'min_version=0.6'") || {
        log_warning "BT API install_plugin 调用失败"
        return 1
    }

    # 显式失败信号：returnMsg 格式 {status:false, msg:"..."}
    local msg1
    msg1="$(_bt_json_get "$resp1" "msg")"
    if echo "$resp1" | grep -qE '"status"[[:space:]]*:[[:space:]]*false'; then
        log_warning "install_plugin 失败：${msg1:-(empty)}"
        log_info "  原始响应（前 200 字节）：$(echo "$resp1" | head -c 200)"
        return 1
    fi

    # 解析 step 1 返回的元信息（必须含 name + tmp_path + install_opt）
    local plugin_name tmp_path install_opt
    plugin_name="$(_bt_json_get "$resp1" "name")"
    tmp_path="$(_bt_json_get "$resp1" "tmp_path")"
    install_opt="$(_bt_json_get "$resp1" "install_opt")"

    if [ -z "$plugin_name" ] || [ -z "$tmp_path" ] || [ -z "$install_opt" ]; then
        log_warning "install_plugin 响应缺字段（name/tmp_path/install_opt）"
        log_info "  原始响应（前 200 字节）：$(echo "$resp1" | head -c 200)"
        return 1
    fi

    log_info "step 1 完成：tmp_path=${tmp_path}, install_opt=${install_opt}"

    # === step 2: input_package 拷贝 + 同步执行 install.sh ===
    # install.sh 编译 supervisor 可能耗时 60~180s，临时把 curl 超时拉到 BT_PLUGIN_INSTALL_TIMEOUT
    log_info "step 2: 调 input_package 同步执行 install.sh（编译 supervisor 可能耗时 60~180s）..."
    local resp2
    resp2=$(BT_API_TIMEOUT="$BT_PLUGIN_INSTALL_TIMEOUT" _bt_api_post "/plugin?action=input_package" \
        "--data-urlencode 'tmp_path=${tmp_path}' \
--data-urlencode 'plugin_name=${plugin_name}' \
--data-urlencode 'install_opt=${install_opt}'") || resp2=""

    local status2 msg2
    status2="$(_bt_json_get "$resp2" "status")"
    msg2="$(_bt_json_get "$resp2" "msg")"

    if [ "$status2" = "true" ]; then
        log_success "supervisor 插件安装完成（${msg2:-OK}）"
        return 0
    fi

    # curl 超时或非标准应答时，BT 后台可能仍在跑 install.sh —— 轮询 GetIndex 兜底确认
    log_info "input_package 应答非 status=true（${msg2:-curl 超时}），轮询 GetIndex 兜底..."
    local elapsed=0
    while [ "$elapsed" -lt "$BT_PLUGIN_INSTALL_TIMEOUT" ]; do
        sleep 5
        elapsed=$((elapsed + 5))
        if _bt_supervisor_plugin_installed; then
            log_success "supervisor 插件安装完成（兜底耗时约 ${elapsed}s）"
            return 0
        fi
    done

    log_warning "input_package 失败：${msg2:-(empty)}"
    log_info "  原始响应（前 200 字节）：$(echo "$resp2" | head -c 200)"
    return 1
}

# supervisor 运行时准备：日志目录 + systemd 服务
# - /var/log/supervisor：BT supervisor 插件默认 logfile 路径；缺目录会让 supervisord 启动报：
#   "The directory named as part of the path /var/log/supervisor/<x>.log does not exist"
# - 启动失败多次后 systemd 进入 failed，需 reset-failed 后才能再 start
_bt_supervisor_ensure_runtime() {
    mkdir -p /var/log/supervisor

    command -v systemctl &>/dev/null || return 0

    # 兼容 supervisord.service / supervisor.service 不同发行版命名
    local svc=""
    for s in supervisord supervisor; do
        if systemctl list-unit-files 2>/dev/null | grep -qE "^$s\.service"; then
            svc="$s"
            break
        fi
    done
    [ -n "$svc" ] || return 0

    if systemctl is-active --quiet "$svc" 2>/dev/null; then
        return 0
    fi

    log_info "$svc 服务未运行，尝试 reset-failed + restart"
    systemctl reset-failed "$svc" 2>/dev/null || true
    systemctl restart "$svc" 2>/dev/null || true
    sleep 2
    if systemctl is-active --quiet "$svc" 2>/dev/null; then
        log_info "$svc 服务已启动"
    else
        log_warning "$svc 服务启动失败，到面板 → SuperVisord 检查日志（常见原因：旧进程 ini 中 logfile 父目录缺失）"
    fi
}

# 确保 supervisor 插件可用：已装直接返回 0，未装尝试安装
# return 0 可用；1 不可用（已 log_warning）
bt_ensure_supervisor_plugin() {
    log_step "检测 BT supervisor 插件"
    if _bt_supervisor_plugin_installed; then
        log_success "supervisor 插件已安装"
        _bt_supervisor_ensure_runtime
        return 0
    fi

    log_info "supervisor 插件未安装，触发自动安装"
    if _bt_supervisor_plugin_install; then
        _bt_supervisor_ensure_runtime
        return 0
    fi

    log_warning "supervisor 插件未就绪，请到宝塔面板 → 软件商店 → 任务管理器(Supervisor) 手工安装"
    return 1
}

# 添加 supervisor 进程（走 BT 插件 API）
# 用法：bt_add_supervisor_process <pjname> <user> <path> <command> [numprocs] [ps]
#   pjname:   程序名（面板显示用；唯一标识）
#   user:     运行用户（www）
#   path:     工作目录（绝对路径，建议结尾 /）
#   command:  完整命令（PHP 必须用绝对路径 /www/server/php/<ver>/bin/php）
#   numprocs: 进程数（默认 1）
#   ps:       备注（默认 = pjname）
#
# 重装语义（覆盖）：先 GetProcessList 检测同名 → 存在则 RemoveProcess 删除 → 再 AddProcess
# 注意 BT 11.x 字段名：列表项含 "program"（非 "name"）；RemoveProcess 入参也是 program=
# return 0 成功；1 失败
bt_add_supervisor_process() {
    local pjname="$1"
    local user="$2"
    local path="$3"
    local command="$4"
    local numprocs="${5:-1}"
    local ps="${6:-$pjname}"

    if [ -z "$pjname" ] || [ -z "$user" ] || [ -z "$path" ] || [ -z "$command" ]; then
        log_error "bt_add_supervisor_process 参数不全（pjname / user / path / command）"
        return 1
    fi

    log_step "BT 添加 supervisor 进程: $pjname"

    # 1. 检测同名进程是否存在；存在则先删（覆盖重装语义）
    # 用 python3 解析 JSON：BT 后端 PHP json_encode 默认无空格，
    # 早期 grep -F '"program": "<name>"' 因带空格永远不命中，导致同名进程无法被覆盖
    # pjname 经 env 传入、python 从 os.environ 读，不插值到源码（防 python 注入）——与 bt_list_crontab_all 范式一致
    local list_resp existing
    list_resp=$(_bt_api_post "/plugin?action=a&name=supervisor&s=GetProcessList" "") || true
    existing=$(echo "$list_resp" | MATCH_NAME="$pjname" python3 -c "
import json, sys, os
match = os.environ.get('MATCH_NAME', '')
try:
    raw = sys.stdin.read()
    data = json.loads(raw)
    # GetProcessList 响应：list 或 {'data':[...]} 或 {'msg':[...]}（BT 不同版本字段差异）
    items = data if isinstance(data, list) else (data.get('data') or data.get('msg') or [])
    if not isinstance(items, list):
        items = []
    for it in items:
        if isinstance(it, dict) and it.get('program') == match:
            print(it.get('program'))
            break
except Exception:
    pass
" 2>/dev/null)
    if [ -n "$existing" ]; then
        log_info "同名进程 $pjname 已存在，先删除（覆盖重装）"
        _bt_api_post "/plugin?action=a&name=supervisor&s=RemoveProcess" \
            "--data-urlencode 'program=$pjname'" >/dev/null 2>&1 || true
        sleep 1
    fi

    # 2. 添加
    local form
    form="--data-urlencode 'pjname=$pjname' \
--data-urlencode 'user=$user' \
--data-urlencode 'path=$path' \
--data-urlencode 'command=$command' \
--data-urlencode 'numprocs=$numprocs' \
--data-urlencode 'ps=$ps'"

    local resp
    resp=$(_bt_api_post "/plugin?action=a&name=supervisor&s=AddProcess" "$form") || {
        log_warning "BT API AddProcess 调用失败"
        return 1
    }

    local status msg
    status="$(_bt_json_get "$resp" "status")"
    msg="$(_bt_json_get "$resp" "msg")"

    if [ "$status" = "true" ]; then
        log_success "supervisor 进程已添加: $pjname"
        return 0
    fi

    # AddProcess 失败重试：GetProcessList 在 supervisor 服务停止时返回空（漏检），
    # BT 数据库中的进程配置记录仍然保留，AddProcess 报"已被使用"。
    # 强制 RemoveProcess（操作 BT 数据库不依赖 supervisor 服务运行）+ 重试 AddProcess。
    if echo "$msg" | grep -qE '已被使用|已存在|exist'; then
        log_info "AddProcess 报名称冲突，强制 RemoveProcess + 重试（supervisor 服务停止时 GetProcessList 漏检）"
        _bt_api_post "/plugin?action=a&name=supervisor&s=RemoveProcess" \
            "--data-urlencode 'program=$pjname'" >/dev/null 2>&1 || true
        sleep 1
        resp=$(_bt_api_post "/plugin?action=a&name=supervisor&s=AddProcess" "$form") || {
            log_warning "BT API AddProcess 重试调用失败"
            return 1
        }
        status="$(_bt_json_get "$resp" "status")"
        msg="$(_bt_json_get "$resp" "msg")"
        if [ "$status" = "true" ]; then
            log_success "supervisor 进程已添加: ${pjname}（重试通过）"
            return 0
        fi
    fi

    log_warning "BT supervisor AddProcess 失败: ${msg:-未知错误}"
    log_info "原始响应: $resp"
    log_info "提示：若 supervisor 服务已停止，请到面板 → 软件商店 → SuperVisord → 启动"
    return 1
}

# ========================================
# 4. Cron（走 BT 面板 crontab API；完整字段）
# ========================================
#
# 抓包确认（2026-05 BT 9.x）：
#   POST /crontab?action=AddCrontab
#   form: name / sType / sBody / sName / backupTo / save / urladdress /
#         save_local / notice / notice_channel / datab_name / tables_name /
#         keyword / flock / version / user / stop_site / type / week / hour /
#         minute / where1 / timeSet / timeType
#
# 当前实现仅支持 minute-n（每 N 分钟）类型；其他周期未来按需扩展
# 不再 fallback 写 crontab（违反"面板可见"要求）；BT_KEY 缺失由调用方走手工提示

# 添加 BT cron 任务
# 用法：bt_add_crontab <name> <type> <where1> <command>
#   name:    任务名（面板显示）
#   type:    周期类型，目前仅支持 'minute-n'
#   where1:  分钟周期 N（每 N 分钟跑一次；type=minute-n 时填整数）
#   command: 完整命令（PHP 必须用绝对路径）
#
# 重装语义（覆盖）：先 GetCrontab 检查同名任务 → 存在则 DelCrontab 删除 → 再 AddCrontab
# return 0 成功；1 失败
bt_add_crontab() {
    local name="$1"
    local type="$2"
    local where1="$3"
    local command="$4"

    if [ -z "$name" ] || [ -z "$type" ] || [ -z "$where1" ] || [ -z "$command" ]; then
        log_error "bt_add_crontab 参数不全（name / type / where1 / command）"
        return 1
    fi

    if [ "$type" != "minute-n" ]; then
        log_error "bt_add_crontab 当前仅支持 type=minute-n，收到: $type"
        return 1
    fi

    log_step "BT 添加 cron 任务: $name"

    # 1. 检测同名任务；存在则先删（覆盖重装语义）
    local list_resp
    list_resp=$(_bt_api_post "/crontab?action=GetCrontab" \
        "--data-urlencode 'p=1' --data-urlencode 'limit=100'") || true
    # 找出同名任务的 id（命中第一个；理论上 BT cron 同名不应允许，但用户可能在面板手工建多个）
    # name 经 env 传入、python 从 os.environ 读，不插值到源码（防 python 注入）——与 bt_list_crontab_all 范式一致
    local existing_id
    existing_id=$(echo "$list_resp" | MATCH_NAME="$name" python3 -c "
import json, sys, os
match = os.environ.get('MATCH_NAME', '')
try:
    raw = sys.stdin.read()
    # BT 11.x GetCrontab 响应：{'data': [{'id':N,'name':'...',...}]} 或直接 list
    data = json.loads(raw)
    items = data.get('data', []) if isinstance(data, dict) else data
    for it in items:
        if it.get('name') == match:
            print(it.get('id'))
            break
except Exception:
    pass
" 2>/dev/null)
    if [ -n "$existing_id" ]; then
        log_info "同名 cron $name (id=$existing_id) 已存在，先删除（覆盖重装）"
        _bt_api_post "/crontab?action=DelCrontab" \
            "--data-urlencode 'id=$existing_id'" >/dev/null 2>&1 || true
        sleep 1
    fi

    # 2. 提交 AddCrontab（22 个字段，对齐截图）
    local form
    form="--data-urlencode 'name=$name' \
--data-urlencode 'sType=toShell' \
--data-urlencode 'sBody=$command' \
--data-urlencode 'sName=' \
--data-urlencode 'backupTo=' \
--data-urlencode 'save=' \
--data-urlencode 'urladdress=' \
--data-urlencode 'save_local=0' \
--data-urlencode 'notice=0' \
--data-urlencode 'notice_channel=' \
--data-urlencode 'datab_name=' \
--data-urlencode 'tables_name=' \
--data-urlencode 'keyword=' \
--data-urlencode 'flock=1' \
--data-urlencode 'version=' \
--data-urlencode 'user=www' \
--data-urlencode 'stop_site=0' \
--data-urlencode 'type=$type' \
--data-urlencode 'week=1' \
--data-urlencode 'hour=1' \
--data-urlencode 'minute=1' \
--data-urlencode 'where1=$where1' \
--data-urlencode 'timeSet=1' \
--data-urlencode 'timeType=sday'"

    local resp
    resp=$(_bt_api_post "/crontab?action=AddCrontab" "$form") || {
        log_warning "BT API AddCrontab 调用失败"
        return 1
    }

    local status msg
    status="$(_bt_json_get "$resp" "status")"
    msg="$(_bt_json_get "$resp" "msg")"

    if [ "$status" = "true" ]; then
        log_success "cron 任务已添加: $name"
        return 0
    fi

    log_warning "BT AddCrontab 失败: ${msg:-未知错误}"
    log_info "原始响应: $resp"
    return 1
}

# ========================================
# 5. 注入 nginx vhost include
# ========================================

# 在 BT 站点 vhost 文件的 server 块**根 root** 下面插入一行 include
# 用法：bt_inject_vhost_include <domain> <include_path> [expected_root]
#   domain: BT 站点主域名（vhost 文件名为 /www/server/panel/vhost/nginx/<domain>.conf）
#   include_path: 要 include 的绝对路径（如 /www/wwwroot/manager/nginx/manager.conf）
#   expected_root: 可选；BT vhost 应有的 server 根 root（通常 = INSTALL_DIR）
#                  传入时校验 vhost 实际 root 与之一致；不一致 -y 模式失败，交互模式询问
#
# 设计要点：
#   1. 直接读写 vhost 文件（BT 面板"配置文件"页签也是读这个文件，自动同步可见）
#      不走 BT API GetFileBody/SaveFileBody，避免多行 JSON 转义解析（_bt_json_get 仅支持单行字符串）
#   2. awk 跟踪 `{`/`}` 计数维护 depth；root 行不含 `{`/`}` → 该行所在深度 = 当前 depth
#      只在 server 块直接子级（depth == 1）的第一个 `^[ \t]*root [^;]+;[ \t]*$` 行后插入，
#      自动跳过 location 子块（depth >= 2）和 if/limit_except 等子块内的 root
#   3. 幂等：先 grep 检测目标 include 已存在则跳过；写入前备份 vhost 到 .manager.bak.<ts>
#   4. nginx -t 失败立即回滚备份，避免 reload 整体崩
#   5. reload 优先走宝塔 nginx 的 -s reload，失败兜底 systemctl reload nginx
#
# 返回：
#   0 成功（已注入或已存在 → 幂等）
#   1 失败（vhost 文件不存在 / awk 没找到根 root / nginx -t 失败已回滚）
bt_inject_vhost_include() {
    local domain="$1"
    local include_path="$2"
    local expected_root="${3:-}"

    if [ -z "$domain" ] || [ -z "$include_path" ]; then
        log_error "bt_inject_vhost_include 参数不全（domain / include_path）"
        return 1
    fi

    local vhost="/www/server/panel/vhost/nginx/${domain}.conf"
    if [ ! -f "$vhost" ]; then
        log_warning "BT vhost 文件不存在: $vhost"
        log_info "请确认 BT 已建站；或手工添加 include 到对应 nginx 配置"
        return 1
    fi

    log_step "注入 nginx vhost include: $domain"

    # ==== root 路径校验 ====
    # 用与注入同源的 awk depth==1 过滤逻辑，提取 server 块直接子级的 root 值
    # 不一致时：-y 模式直接失败；交互模式询问是否继续
    if [ -n "$expected_root" ]; then
        local actual_root
        actual_root=$(awk '
            BEGIN { depth = 0; found = 0 }
            {
                line = $0
                # 仅在 server 块直接子级（depth==1）匹配 root 行
                if (!found && depth == 1) {
                    val = line
                    if (sub(/^[ \t]*root[ \t]+/, "", val)) {
                        sub(/[ \t]*;.*$/, "", val)   # 去掉 ; 及之后空白/注释
                        if (val != "") { print val; found = 1; exit 0 }
                    }
                }
                # 更新 depth（注释 # 后忽略；同主注入逻辑）
                n_open = 0; n_close = 0
                s = line; len = length(s)
                for (i = 1; i <= len; i++) {
                    c = substr(s, i, 1)
                    if (c == "#") break
                    if (c == "{") n_open++
                    else if (c == "}") n_close++
                }
                depth += n_open - n_close
                if (depth < 0) depth = 0
            }
            END { exit (found ? 0 : 1) }
        ' "$vhost")

        if [ -z "$actual_root" ]; then
            log_warning "未在 BT vhost 中找到 server 块根 root（vhost 结构异常？）"
            return 1
        fi

        if [ "$actual_root" != "$expected_root" ]; then
            log_warning "BT vhost root 与 INSTALL_DIR 不一致："
            log_warning "  BT vhost root  = $actual_root"
            log_warning "  INSTALL_DIR    = $expected_root"
            log_warning "继续注入会导致 include 指向 ${expected_root}，但 nginx 仍把 $actual_root 作为站点根目录"
            log_warning "→ SPA 静态资源（admin/user）将 404"
            log_info "修复方法：到宝塔面板 → 网站 → $domain → 修改站点目录为 $expected_root"

            if [ "${AUTO_YES:-}" = "true" ]; then
                log_error "（-y 模式）path 错位，跳过注入；请到面板修正 root 后重跑"
                return 1
            fi

            local confirm=""
            read -r -p "继续注入？(y/N): " confirm </dev/tty
            case "$confirm" in
                y | Y | yes | YES) log_warning "用户确认继续注入（路径错位）" ;;
                *)
                    log_info "已取消注入"
                    return 1
                    ;;
            esac
        else
            log_info "BT vhost root 校验通过: $actual_root"
        fi
    fi

    # 幂等检测：未注释的 include 行已包含目标路径
    # 转义 include_path 中的 | 等用于 grep -E 的特殊字符（路径几乎不会有，但保险）
    local include_path_re
    include_path_re=$(printf '%s' "$include_path" | sed -E 's/[][\.|^$*+?(){}\\]/\\&/g')
    if grep -qE "^[[:space:]]*include[[:space:]]+${include_path_re}[[:space:]]*;" "$vhost"; then
        log_info "vhost 已包含 include ${include_path}，跳过"
        return 0
    fi

    # 备份（带时间戳，可手工回滚）
    local ts backup
    ts=$(date +%Y%m%d-%H%M%S)
    backup="${vhost}.manager.bak.${ts}"
    if ! cp -p "$vhost" "$backup"; then
        log_warning "vhost 备份失败: $backup"
        return 1
    fi
    log_info "已备份 vhost → $backup"

    # awk 注入：跟踪 {} 深度，仅在 server 块直接子级（depth==1）的第一个 root 行后插入
    # - root 行格式：^<spaces>root <path>;<spaces>$ （nginx 标准）
    # - 注释处理：行内遇到 # 后续忽略（nginx 注释规则；# 不会出现在引号内含 {} 的字符串里）
    # - 行起始深度 = 上一行结束 depth；root 行本身不含 {} → line_depth = depth
    local tmp="${vhost}.manager.tmp.${ts}"
    awk -v INC="$include_path" '
        BEGIN { depth = 0; injected = 0 }
        {
            line = $0

            # 在 server 块直接子级遇到根 root 行：先打印原行，再插入 include（保持原缩进）
            if (!injected && depth == 1 && match(line, /^[ \t]*root[ \t]+[^;]+;[ \t]*$/)) {
                # 提取行首缩进
                match(line, /^[ \t]*/)
                indent = substr(line, RSTART, RLENGTH)
                print line
                printf "%sinclude %s;\n", indent, INC
                injected = 1
                next
            }

            print line

            # 更新 depth：扫描行内非注释部分的 {/}（# 后忽略；nginx 不会有引号包裹的 {}）
            n_open = 0
            n_close = 0
            s = line
            len = length(s)
            for (i = 1; i <= len; i++) {
                c = substr(s, i, 1)
                if (c == "#") break
                if (c == "{") n_open++
                else if (c == "}") n_close++
            }
            depth += n_open - n_close
            if (depth < 0) depth = 0   # 健壮性：异常文件不崩
        }
        END { exit (injected ? 0 : 1) }
    ' "$vhost" >"$tmp"

    local awk_rc=$?
    if [ "$awk_rc" != "0" ]; then
        log_warning "未在 server 块直接子级找到根 root 行（vhost 结构异常？）"
        rm -f "$tmp"
        log_info "已保留备份: ${backup}（无修改）"
        return 1
    fi

    # 覆盖原文件（保持 owner/perm；BT vhost 默认 root:root 644）
    if ! cat "$tmp" >"$vhost"; then
        log_warning "vhost 写入失败，回滚备份"
        cp -p "$backup" "$vhost"
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"

    # nginx -t 校验；失败立即回滚
    local nginx_bin=""
    if [ -x "/www/server/nginx/sbin/nginx" ]; then
        nginx_bin="/www/server/nginx/sbin/nginx"
    elif command -v nginx &>/dev/null; then
        nginx_bin="$(command -v nginx)"
    fi

    if [ -n "$nginx_bin" ]; then
        if ! "$nginx_bin" -t 2>/dev/null; then
            log_warning "nginx -t 失败，回滚 vhost"
            cp -p "$backup" "$vhost"
            "$nginx_bin" -t 2>&1 | head -20
            return 1
        fi

        # reload nginx：优先 nginx -s reload，失败兜底 systemctl reload
        if "$nginx_bin" -s reload 2>/dev/null; then
            log_success "nginx 已 reload；vhost 注入 include 完成"
        elif command -v systemctl &>/dev/null && systemctl reload nginx 2>/dev/null; then
            log_success "nginx 已 reload (systemctl)；vhost 注入 include 完成"
        else
            log_warning "无法 reload nginx，请手工执行: $nginx_bin -s reload"
        fi
    else
        log_warning "未找到 nginx 命令，请手工 reload nginx 让 include 生效"
    fi

    log_info "已注入: include $include_path;"
    log_info "如需回滚，恢复备份: cp $backup $vhost && $nginx_bin -s reload"
    return 0
}

# ========================================
# 6. PHP 自动化（安装扩展 / 启用函数 / 重启 PHP-FPM）
# ========================================

# 把 PHP 完整版本号转成宝塔紧凑形式（如 8.3.21 → 83）
_bt_php_ver_compact() {
    local full="$1"
    echo "$full" | awk -F. '{print $1$2}'
}

# 列出指定宝塔 PHP 版本的真实 FPM PID。
# 不使用 pgrep 命令行文本，避免其他 PHP 版本或探测命令自身造成误判。
_bt_php_fpm_process_pids() {
    local php_ver="$1"
    local proc_root="${BT_PROC_ROOT:-/proc}"
    local expected="/www/server/php/$php_ver/sbin/php-fpm"
    local proc_exe resolved pid

    for proc_exe in "$proc_root"/[0-9]*/exe; do
        [ -L "$proc_exe" ] || continue
        resolved=$(readlink "$proc_exe" 2>/dev/null) || continue
        # Linux 在已删除的可执行文件后追加 " (deleted)"。
        resolved="${resolved% (deleted)}"
        [ "$resolved" = "$expected" ] || continue
        pid="${proc_exe%/exe}"
        printf '%s\n' "${pid##*/}"
    done
    return 0
}

# 判定该 PID 是否为 FPM master。
# 不用「parent 不在同版本 PID 集合内」推断：master 死后 worker 被 reparent 到 1，同样满足该条件，
# 会把孤儿 worker 误认成 master（孤儿仍持有继承的 listen fd，能应答健康探活）→ 代际 + 探活双证据
# 同时被绕过、输出假成功。改判 cmdline：master 恒为 "php-fpm: master process (...)"，worker 为
# "php-fpm: pool <name>"。版本归属仍由 exe 锚定（_bt_php_fpm_process_pids），cmdline 只区分角色。
_bt_php_fpm_pid_is_master() {
    local pid="$1"
    local proc_root="${BT_PROC_ROOT:-/proc}"
    # 整块包 2>/dev/null：重定向失败的报错由 shell 自己打印，写成 `tr < f 2>/dev/null` 时
    # `< f` 先于 stderr 重定向生效，进程在 reload 期间不断退出会把裸报错刷进升级日志。
    { tr '\0' ' ' <"$proc_root/$pid/cmdline" | grep -qF 'master process'; } 2>/dev/null
}

# 输出 worker 身份（PID:starttime）。PID 可能复用，加入 /proc/<pid>/stat starttime 才能准确比较代际。
_bt_php_fpm_worker_identities() {
    local php_ver="$1"
    local proc_root="${BT_PROC_ROOT:-/proc}"
    local pids pid stat_line stat_tail start_time
    pids="$(_bt_php_fpm_process_pids "$php_ver")"

    for pid in $pids; do
        _bt_php_fpm_pid_is_master "$pid" && continue
        stat_line=$(cat "$proc_root/$pid/stat" 2>/dev/null) || continue
        # 去掉可能含空格或右括号的 "(comm)"：用 ##（贪婪）匹配到最后一个 ") "，
        # 否则 comm 内含 ") " 时会少剥字段、starttime 错位成 0，代际比较退化为纯 PID 比较。
        # 余下第 20 字段对应原始 stat 第 22 字段 starttime。
        stat_tail="${stat_line##*) }"
        start_time=$(printf '%s\n' "$stat_tail" | awk '{ print $20 }')
        [ -n "$start_time" ] && printf '%s:%s\n' "$pid" "$start_time"
    done
    return 0
}

# 输出同版本 FPM 的 master PID（正常只有一个）。
_bt_php_fpm_master_pids() {
    local php_ver="$1"
    local pid
    for pid in $(_bt_php_fpm_process_pids "$php_ver"); do
        _bt_php_fpm_pid_is_master "$pid" && printf '%s\n' "$pid"
    done
    return 0
}

# 判定一次探活响应是否可信（纯函数，无 IO —— 与 curl 调用分离便于表驱动回归覆盖）。
# 三重收紧，缺一不可：
#   HTTP 码 ∈ {200,503}：503 是 freeze 期健康入口的正常返回，其余码说明没走到应用层；
#   content-type 为 application/json：挡住 Laravel 预渲染维护页 / Nginx 错误页这类 HTML；
#   body 同时含 status/freeze/checks：挡住"是 JSON 但不是本项目"的异站响应（同机多站点时真实存在）。
# 返回：0=可信，1=不可信。
_bt_php_fpm_probe_response_ok() {
    local code="$1" content_type="$2" body="$3"
    case "$code" in
        200 | 503) ;;
        *) return 1 ;;
    esac
    case "$content_type" in
        application/json*) ;;
        *) return 1 ;;
    esac
    printf '%s' "$body" | grep -qF '"status"' || return 1
    printf '%s' "$body" | grep -qF '"freeze"' || return 1
    printf '%s' "$body" | grep -qF '"checks"' || return 1
    return 0
}

# 通过本站 Nginx vhost 请求公开 /api/health。
# 200/503 均可：这里只验证请求确实经过 FPM 并返回本项目 JSON，不把业务健康度当 reload 结果。
_bt_php_fpm_http_probe() {
    local domain="$1"
    [ -n "$domain" ] || return 1
    case "$domain" in
        *[!A-Za-z0-9.-]*) return 1 ;;
    esac
    command -v curl >/dev/null 2>&1 || return 1

    local scheme port result meta body code content_type
    for scheme in https http; do
        if [ "$scheme" = "https" ]; then
            port=443
        else
            port=80
        fi
        result=$(curl -ksS --noproxy '*' \
            --connect-timeout 2 --max-time 5 \
            --resolve "$domain:$port:127.0.0.1" \
            -H 'Accept: application/json' \
            -w $'\n__FPM_PROBE__%{http_code}|%{content_type}' \
            "$scheme://$domain/api/health" 2>/dev/null) || continue
        meta=$(printf '%s\n' "$result" | tail -1)
        body=$(printf '%s\n' "$result" | sed '$d')
        case "$meta" in
            __FPM_PROBE__*) ;;
            *) continue ;;
        esac
        meta="${meta#__FPM_PROBE__}"
        code="${meta%%|*}"
        content_type="${meta#*|}"
        _bt_php_fpm_probe_response_ok "$code" "$content_type" "$body" && return 0
    done
    return 1
}

# 发出一次 PHP-FPM reload。通道优先级：本机 init 脚本 > systemctl > 宝塔 API。
# 结果写入 BT_FPM_RELOAD_CHANNEL（通道名）与 BT_FPM_RELOAD_DETAIL（失败原因 / 宝塔原始响应）。
#
# 为什么宝塔 API 排在最后（实测于宝塔面板 class/system.py::ServiceAdmin）：
#   1. 它执行 `/etc/init.d/php-fpm-XX reload` 后并不看该命令退出码，而是轮询
#      `check_service_status` → `public.is_php_fpm_process_exists`；判否时会**再补发最多 6 次
#      `systemctl reload php-fpm-XX`**，返回失败前还会重复执行一次原命令。即「只发一次 reload」
#      在 API 通道上不成立，额外重载会在等待窗口内反复翻新 worker 代际，干扰代际观测。
#   2. 判否即返回 `{"status": false, "msg": "php-fpm-XX服务启动失败"}`。实测该判活在 reload 期间
#      **间歇性假阴**：php-fpm 以 --daemonize 启动，reload 时 master execvp 后再 fork 脱离、PID 必换，
#      而面板的 psutil 先取 pids() 快照再逐个查 exe，正好可能落在「旧 master 已走、新 master 未进
#      快照」的窗口里。窗口宽度与机器相关（实测某台约半数失败，另一些 10/10 正常）。故不能作为
#      成败权威——它与 reload 是否真的成功没有因果关系。
# 本机 init 脚本则是单次 `kill -USR2 $(cat php-fpm.pid)`，退出码可信，且不需要 BT API key。
_bt_php_fpm_send_reload() {
    local php_ver="$1"
    local init_script="${BT_PHP_FPM_INIT_DIR:-/etc/init.d}/php-fpm-$php_ver"
    local out masters pid signal_failed
    BT_FPM_RELOAD_CHANNEL=""
    BT_FPM_RELOAD_DETAIL=""

    # 首选：对本机已识别出的 master 直接 kill -USR2。这是唯一退出码真正代表「信号已送达目标进程」
    # 的通道——宝塔 init 脚本的 reload 分支以 `echo " done"` 收尾，`kill` 失败（pid 文件残留指向
    # 已消失的进程等）它照样 exit 0，rc 不能证明信号送达；systemctl / API 同理更间接。
    masters="$(_bt_php_fpm_master_pids "$php_ver")"
    if [ -n "$masters" ]; then
        BT_FPM_RELOAD_CHANNEL="signal"
        signal_failed=0
        for pid in $masters; do
            kill -USR2 "$pid" 2>/dev/null || signal_failed=1
        done
        if [ "$signal_failed" -eq 0 ]; then
            BT_FPM_RELOAD_DETAIL="kill -USR2 → master ${masters}"
            return 0
        fi
        BT_FPM_RELOAD_DETAIL="kill -USR2 失败（master=${masters}）"
        return 1
    fi

    if [ -x "$init_script" ]; then
        BT_FPM_RELOAD_CHANNEL="init.d"
        if out=$("$init_script" reload 2>&1); then
            return 0
        fi
        BT_FPM_RELOAD_DETAIL="$init_script reload 失败: $out"
        return 1
    fi

    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "php-fpm-$php_ver" 2>/dev/null; then
        BT_FPM_RELOAD_CHANNEL="systemctl"
        if out=$(systemctl reload "php-fpm-$php_ver" 2>&1); then
            return 0
        fi
        BT_FPM_RELOAD_DETAIL="systemctl reload php-fpm-$php_ver 失败: $out"
        return 1
    fi

    if declare -f _bt_api_post >/dev/null 2>&1; then
        BT_FPM_RELOAD_CHANNEL="bt-api"
        if out=$(_bt_api_post "/system?action=ServiceAdmin" \
            "--data-urlencode 'name=php-fpm-$php_ver' --data-urlencode 'type=reload'"); then
            BT_FPM_RELOAD_DETAIL="宝塔原始响应: $out"
            # 宝塔的 status 不作为成败判据（见上），只要请求送达就交给本机证据判定。
            return 0
        fi
        BT_FPM_RELOAD_DETAIL="BT API ServiceAdmin 调用失败"
        return 1
    fi

    BT_FPM_RELOAD_CHANNEL="none"
    BT_FPM_RELOAD_DETAIL="未找到可用的 reload 通道（无 ${init_script}、无 systemctl 服务、无 BT API）"
    return 1
}

# 重载 PHP-FPM 并以本机可观测证据确认完成
# 用法：bt_reload_php_fpm <php_ver_compact> [site_domain]
# 成功判据（全部由本机直接观测，不采信宝塔自陈）：
#   ① reload 已成功发出（本机命令退出码 0 / API 请求送达）
#   ② reload 前记录的旧 worker 代际全部退出，且 master 存在
#   ③ reload 后本站 /api/health 经 Nginx → FPM → Laravel 返回本项目 JSON
#      —— 仅在能确定站点域名时执行；取不到域名时跳过并降级告警，不据此判失败
# return 0 成功；1 失败
bt_reload_php_fpm() {
    local php_ver="$1"
    local site_domain="${2:-}"

    if [ -z "$php_ver" ]; then
        log_error "bt_reload_php_fpm 缺少 php_ver"
        return 1
    fi

    log_step "重载 PHP-FPM $php_ver"

    # reload 前记录 master 身份。宝塔的 php-fpm 以 --daemonize 启动，reload 时 master 按原始 argv
    # execvp 自身、再 fork 脱离，**master PID 会变**（实测日志：663089 → 663093 → 663096 …）。
    # master 换代只可能由 reload 造成，是比 worker 代际更强的因果证据：它不受 pm.process_idle_timeout
    # 影响，且在 ondemand 空闲池（0 worker、无从建立代际基线）下同样成立。
    # 注：并非所有部署都会换 PID（非 daemonize / systemd 托管时可能原地保留），故只作为充分条件，
    # 不作必要条件——未换代时仍回落 worker 代际判定。
    local old_masters
    old_masters="$(_bt_php_fpm_master_pids "$php_ver")"

    # reload 前记录 worker 代际。完成条件是这些旧 worker 全部退出，而非“进程连续存在 N 秒”。
    local old_workers old_count=0 identity baseline_synthetic=0 baseline_started=0
    old_workers="$(_bt_php_fpm_worker_identities "$php_ver")"
    for identity in $old_workers; do
        old_count=$((old_count + 1))
    done
    # ondemand 空闲池可能没有 worker，无法比较代际。先通过本站健康入口生成一个旧 worker，
    # 再记录其 PID:starttime；reload 后等待这个明确身份退出，避免靠固定时间猜测。
    if [ "$old_count" -eq 0 ] && [ -n "$site_domain" ]; then
        baseline_synthetic=1
        log_info "PHP-FPM $php_ver 为 ondemand 空闲态，调用站点健康入口建立 worker 代际基线"
        if _bt_php_fpm_http_probe "$site_domain"; then
            # 年龄从**探活成功后**起算：合成 worker 诞生于探活末尾，而探活先 https 后 http
            # （各 --max-time 5），站点无 443 时起点前置会白吃掉数秒预算、误报证据不足。
            baseline_started=$SECONDS
            old_workers="$(_bt_php_fpm_worker_identities "$php_ver")"
            old_count=0
            for identity in $old_workers; do
                old_count=$((old_count + 1))
            done
            if [ "$old_count" -gt 0 ]; then
                log_info "PHP-FPM $php_ver 为 ondemand 空闲态，已建立 ${old_count} 个旧 worker 代际基线"
            else
                log_warning "站点健康入口可用，但未观察到 PHP-FPM worker，无法建立代际基线"
            fi
        else
            log_warning "站点健康入口探测失败，无法为 ondemand PHP-FPM 建立 worker 代际基线"
        fi
    fi

    if ! _bt_php_fpm_send_reload "$php_ver"; then
        log_warning "PHP-FPM $php_ver reload 未能发出（通道 ${BT_FPM_RELOAD_CHANNEL:-未知}）"
        [ -n "$BT_FPM_RELOAD_DETAIL" ] && log_info "$BT_FPM_RELOAD_DETAIL"
        return 1
    fi
    log_info "已通过 ${BT_FPM_RELOAD_CHANNEL} 通道发出 PHP-FPM $php_ver reload"
    [ "$BT_FPM_RELOAD_CHANNEL" = "bt-api" ] && [ -n "$BT_FPM_RELOAD_DETAIL" ] && log_info "$BT_FPM_RELOAD_DETAIL"

    # timeout 只是故障上限，不是固定等待时长；一旦本机证据齐备立即返回。
    local timeout="${BT_PHP_FPM_WAIT_TIMEOUT:-30}"
    local interval="${BT_PHP_FPM_WAIT_INTERVAL:-2}"
    case "$timeout" in
        '' | *[!0-9]*) timeout=30 ;;
    esac
    case "$interval" in
        '' | *[!0-9]* | 0) interval=2 ;;
    esac

    # 合成基线的因果窗口：真 reload 立刻杀空闲 worker，远快于 idle 回收。
    local causal_window="${BT_PHP_FPM_CAUSAL_WINDOW:-$interval}"
    case "$causal_window" in
        '' | *[!0-9]*) causal_window="$interval" ;;
    esac
    # 基线年龄上限，取 pm.process_idle_timeout 常见默认值 10 秒的安全余量。
    local baseline_max_age="${BT_PHP_FPM_BASELINE_MAX_AGE:-6}" baseline_age=0
    case "$baseline_max_age" in
        '' | *[!0-9]*) baseline_max_age=6 ;;
    esac

    local elapsed=0
    local current_workers current_count remaining old_identity current_identity found
    local master_state health_state generation_complete current_masters master_changed
    local current_master old_master
    log_info "确认 PHP-FPM $php_ver 完成换代（最长 ${timeout} 秒）"

    while :; do
        current_workers="$(_bt_php_fpm_worker_identities "$php_ver")"
        current_count=0
        for identity in $current_workers; do
            current_count=$((current_count + 1))
        done

        remaining=0
        for old_identity in $old_workers; do
            found=0
            for current_identity in $current_workers; do
                if [ "$old_identity" = "$current_identity" ]; then
                    found=1
                    break
                fi
            done
            if [ "$found" -eq 1 ]; then
                remaining=$((remaining + 1))
            fi
        done

        current_masters="$(_bt_php_fpm_master_pids "$php_ver")"
        # 换代判据必须是「出现了一个不在旧集合里的**新** master」，不能用集合整体不等：
        # 后者把「master 消失」「多 master 收缩」也算成换代——旧集合为空时等待期冒出一个 master
        # （此时必然走 rc 不可信的 init.d/API 通道）、或双 master 退掉一个，都会被记成 reload 成功。
        # 同理要求 old_masters 非空：没有旧身份可比时，"出现 master" 不构成任何因果证据。
        master_changed=0
        if [ -n "$old_masters" ]; then
            for current_master in $current_masters; do
                found=0
                for old_master in $old_masters; do
                    [ "$current_master" = "$old_master" ] && found=1 && break
                done
                [ "$found" -eq 0 ] && master_changed=1 && break
            done
        fi

        master_state="缺失"
        health_state="未检查"
        generation_complete=0
        if [ -n "$current_masters" ]; then
            master_state="正常"
            if [ "$master_changed" -eq 1 ]; then
                # 强因果证据：master 换代只可能由 reload 造成（不受 idle 回收影响），
                # ondemand 空闲池建不起 worker 基线时这也是唯一可用证据。
                generation_complete=1
            elif [ "$old_count" -gt 0 ] && [ "$remaining" -eq 0 ]; then
                generation_complete=1
                # 因果绑定：合成基线（探活刚拉起的空闲 worker）会被 pm.process_idle_timeout（常见
                # 默认 10s）在等待窗口内无条件回收——若只看「旧代际消失」，一次**根本没发生的 reload**
                # 也能靠时间流逝凑齐该条件。真 reload 会立刻杀掉空闲 worker，故对合成基线额外要求
                # 退出发生在 reload 后一个采样间隔内；超出即判定证据不足，不得宣告成功。
                # 两道窗口都要过：`elapsed` 从 reload 起算，而合成 worker 的 idle 计时其实从**基线
                # 探活**那一刻就开始了（探活先 https 后 http，站点无 SSL 时可先耗掉数秒），只看
                # elapsed 会让实际存活时长悄悄逼近 pm.process_idle_timeout。故再加一道基线年龄闸。
                baseline_age=$((SECONDS - baseline_started))
                if [ "$baseline_synthetic" -eq 1 ] &&
                    { [ "$elapsed" -gt "$causal_window" ] || [ "$baseline_age" -gt "$baseline_max_age" ]; }; then
                    log_warning "PHP-FPM $php_ver 合成基线 worker 在 reload 后 ${elapsed} 秒、建立后 ${baseline_age} 秒才退出（窗口 ${causal_window}/${baseline_max_age} 秒），且 master 未换代"
                    log_warning "无法排除空闲回收所致，不能据此确认本次 reload 已生效"
                    [ -n "$BT_FPM_RELOAD_DETAIL" ] && log_info "$BT_FPM_RELOAD_DETAIL"
                    return 1
                fi
            fi
            if [ "$generation_complete" -eq 1 ]; then
                local evidence="旧 worker 代际已退出"
                # master 列表是多行输出，压成单行再进日志，避免升级日志被断行
                [ "$master_changed" -eq 1 ] &&
                    evidence="master 已换代（$(printf '%s' "$old_masters" | tr '\n' ',') → $(printf '%s' "$current_masters" | tr '\n' ',')）"
                # 取不到站点域名时无法做链路二次确认。这是「测不了」而非「测failed」，
                # 不能据此判失败——否则一次真实成功的 reload 会被拖满 timeout 再误报，
                # 而此时站点仍停在维护态。降级为告警放行。
                if [ -z "$site_domain" ]; then
                    health_state="跳过（未取到站点域名）"
                    log_warning "PHP-FPM $php_ver ${evidence}，但未取到站点域名，跳过健康入口二次确认"
                    log_success "PHP-FPM $php_ver 重载完成（通道 ${BT_FPM_RELOAD_CHANNEL}，未做链路二次确认）"
                    return 0
                fi
                if _bt_php_fpm_http_probe "$site_domain"; then
                    health_state="可用"
                    log_success "PHP-FPM $php_ver 重载完成：${evidence}、master 正常、站点健康入口可用"
                    return 0
                fi
                health_state="不可用"
            fi
        fi

        [ "$elapsed" -ge "$timeout" ] && break
        log_info "等待 PHP-FPM $php_ver 完成重载：旧 worker 剩余 ${remaining}/${old_count}，当前 worker ${current_count}，master ${master_state}（换代 ${master_changed}），健康入口 ${health_state}（${elapsed}/${timeout} 秒）"
        sleep "$interval"
        elapsed=$((elapsed + interval))
        [ "$elapsed" -gt "$timeout" ] && elapsed="$timeout"
    done

    log_warning "PHP-FPM $php_ver 在 ${timeout} 秒内未完成重载：仍有 ${remaining} 个旧 worker，master ${master_state}，健康入口 ${health_state}"
    [ -n "$BT_FPM_RELOAD_DETAIL" ] && log_info "$BT_FPM_RELOAD_DETAIL"
    return 1
}

# ========================================
# 7. cron / supervisor 的 PHP 绝对路径扫描与替换
# ========================================

# 列出所有 cron 任务（用于扫描 PHP 路径）
# 输出：每行 JSON 形式 {"id":N,"name":"...","sBody":"...","type":"...","where1":"..."}
# type/where1 给自动修复时保留原频率用（BT 不同版本字段名 type 或 sType 均做兜底）
bt_list_crontab_all() {
    local resp
    resp=$(_bt_api_post "/crontab?action=GetCrontab" \
        "--data-urlencode 'p=1' --data-urlencode 'limit=500'") || return 1

    echo "$resp" | python3 -c "
import json, sys
try:
    d = json.loads(sys.stdin.read())
    items = d.get('data', []) if isinstance(d, dict) else d
    if isinstance(items, dict):
        items = items.get('data', [])
    for it in items:
        print(json.dumps({
            'id': it.get('id'),
            'name': it.get('name', ''),
            'sBody': it.get('sBody', it.get('cmd', '')),
            'type': it.get('type', it.get('sType', '')),
            'where1': it.get('where1', ''),
        }, ensure_ascii=False))
except Exception:
    pass
" 2>/dev/null
}

# 列出 supervisor 进程（队列 worker 检测）
# 输出：每行 JSON 形式 {"program":"...","name":"...","command":"...","user":"...","path":"...","numprocs":N}
# BT 11.x GetProcessList 真实字段是 program（不是 name）；保留 name 兼容老调用方
bt_list_supervisor_all() {
    local resp
    resp=$(_bt_api_post "/plugin?action=a&name=supervisor&s=GetProcessList" "") || return 1

    echo "$resp" | python3 -c "
import json, sys
try:
    d = json.loads(sys.stdin.read())
    items = d if isinstance(d, list) else d.get('data', d.get('message', []))
    if not isinstance(items, list):
        items = []
    for it in items:
        program = it.get('program', it.get('name', ''))
        print(json.dumps({
            'program': program,
            'name': program,
            'command': it.get('command', ''),
            'user': it.get('user', 'www'),
            'path': it.get('path', ''),
            'numprocs': it.get('numprocs', 1),
        }, ensure_ascii=False))
except Exception:
    pass
" 2>/dev/null
}

# ========================================
# 主入口（独立运行时使用；source 时跳过）
# ========================================

# 仅在直接执行时运行（被 source 时不执行）
# shellcheck disable=SC2128
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    if [ -f "$SCRIPT_DIR/common.sh" ]; then
        # shellcheck source=common.sh
        source "$SCRIPT_DIR/common.sh"
    fi

    case "${1:-help}" in
        resolve-key)
            if bt_resolve_key; then
                echo "BT_KEY=$BT_KEY"
            else
                log_error "未能探测 BT_KEY"
                exit 1
            fi
            ;;
        create-site)
            shift
            bt_resolve_key || {
                log_error "需要 BT_KEY"
                exit 1
            }
            bt_create_site "$@"
            ;;
        get-site-path)
            shift
            bt_resolve_key || {
                log_error "需要 BT_KEY"
                exit 1
            }
            bt_get_site_path "$@"
            ;;
        ensure-supervisor)
            shift
            bt_resolve_key || {
                log_error "需要 BT_KEY"
                exit 1
            }
            bt_ensure_supervisor_plugin "$@"
            ;;
        add-supervisor)
            shift
            bt_resolve_key || {
                log_error "需要 BT_KEY"
                exit 1
            }
            bt_add_supervisor_process "$@"
            ;;
        add-crontab)
            shift
            bt_resolve_key || {
                log_error "需要 BT_KEY"
                exit 1
            }
            bt_add_crontab "$@"
            ;;
        inject-vhost)
            shift
            bt_inject_vhost_include "$@"
            ;;
        help | *)
            cat <<EOF
Usage: bt-automate.sh <command> [args...]

Commands:
  resolve-key                                          Detect BT_KEY (env BT_KEY > api.json token_crypt; BT 11.5+)
  create-site <domain> <php_ver> <root_path>           Call BT API to create a site
  get-site-path <domain>                               Echo site root path if exists (exit 1 if not found)
  ensure-supervisor                                    Detect BT supervisor plugin; install via API if missing
  add-supervisor <pjname> <user> <path> <command> [numprocs] [ps]
                                                       Add supervisor process via BT plugin API (panel visible)
  add-crontab <name> <type> <where1> <command>         Add BT cron task via panel API (type=minute-n, where1=N)
  inject-vhost <domain> <include_path> [expected_root]
                                                       Inject "include <path>;" after server-block root in BT vhost
                                                       (validates root matches expected_root if provided)

Source mode (recommended for integration):
  source bt-automate.sh
  bt_resolve_key && bt_create_site domain.com 83 /www/wwwroot/domain.com

Environment variables:
  BT_KEY        Override auto-detected API key
  BT_API_BASE   Override BT API base URL (default: probe https/http on port.pl; fallback https://127.0.0.1:8888)
EOF
            ;;
    esac
fi
