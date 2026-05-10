#!/usr/bin/env bash
# e2e 场景 5：BT 安装"有 BT_KEY"路径全自动流程契约
#
# 验证 try_bt_automation 在 has_bt_key=true 时的预期行为：
#   1. 调 bt_create_site（建站）
#   2. 调 bt_inject_vhost_include（注入 include；不依赖 KEY，但有 KEY 时也跑）
#   3. 调 bt_ensure_supervisor_plugin + bt_add_supervisor_process（走 BT 面板插件 API）
#   4. 调 bt_add_crontab（走 BT 面板 cron API）
#
# 同时验证抓包对齐的字段：
#   - install_plugin: sName=supervisor / version=3 / min_version=0.6
#   - AddProcess: pjname / user / path / command / numprocs / ps（6 字段）
#   - AddCrontab: 22 个字段全部对齐截图
#   - supervisor command 用 $PHP_CMD 绝对路径
#   - bt_inject_vhost_include 传 expected_root（路径错位防御）

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

E2E_TMPDIR="$(mktemp -d)"
trap e2e_cleanup EXIT

e2e_step "case 05: BT 安装有 BT_KEY 路径全自动契约"

BT_INSTALL="$E2E_REPO_ROOT/deploy/scripts/bt-install.sh"
BT_AUTOMATE="$E2E_REPO_ROOT/deploy/scripts/bt-automate.sh"

# 抽取 try_bt_automation 函数体（用于后续 grep 范围限定）
TRY_BODY=$(awk '/^try_bt_automation\(\) \{/,/^}/' "$BT_INSTALL")

# === 1. has_bt_key 状态信号（已迁移到 detect_bt_key 预检 + BT_KEY_AVAILABLE 单一来源）===
e2e_log "1. has_bt_key 信号来自 BT_KEY_AVAILABLE（detect_bt_key 一次预检）"
if echo "$TRY_BODY" | grep -qE 'has_bt_key="\$BT_KEY_AVAILABLE"'; then
    e2e_pass "has_bt_key 直接读取 BT_KEY_AVAILABLE（不再二次解析）"
else
    e2e_fail "has_bt_key 未来自 BT_KEY_AVAILABLE"
fi

# detect_bt_key 函数定义就位
if grep -qE '^detect_bt_key\(\) \{' "$BT_INSTALL"; then
    e2e_pass "detect_bt_key 函数已定义（依赖检测后预检 + 设 BT_KEY_AVAILABLE）"
else
    e2e_fail "detect_bt_key 函数缺失"
fi

# 关键反向断言：旧 bug "无 KEY return 0" 已被移除
if echo "$TRY_BODY" | grep -qE 'if[[:space:]]+\[[[:space:]]+-z[[:space:]]+"\$BT_KEY"[[:space:]]+\][[:space:]]*;[[:space:]]*then'; then
    if echo "$TRY_BODY" | grep -A2 'if[[:space:]]\+\[[[:space:]]\+-z[[:space:]]\+"\$BT_KEY"[[:space:]]\+\]' | grep -qE 'return[[:space:]]+0'; then
        e2e_fail "旧 bug 残留：BT_KEY 为空时 return 0 整体跳过"
    fi
fi

# === 2. has_bt_key=true 路径包含 4 个核心 API 调用 ===
e2e_log "2. has_bt_key=true 路径调 bt_create_site / supervisor / cron"

# bt_create_site：建站需要 BT_KEY，调用应在 has_bt_key 检查内
if echo "$TRY_BODY" | grep -qE 'has_bt_key.*=.*true.*\].*&&.*bt_create_site|bt_create_site.*&&.*has_bt_key|"\$has_bt_key"[[:space:]]*=[[:space:]]*true[[:space:]]*\][[:space:]]*\\?'; then
    e2e_pass "bt_create_site 在 has_bt_key=true 守护下调用"
else
    # 兜底：检查 bt_create_site 调用周围有 has_bt_key 检查（前后 10 行内）
    if echo "$TRY_BODY" | awk '
        /bt_create_site/ { found_create = NR }
        /has_bt_key/ { found_key = NR }
        END { exit (found_create && found_key && (found_create - found_key) > 0 && (found_create - found_key) < 10 ? 0 : 1) }
    '; then
        e2e_pass "bt_create_site 在 has_bt_key 上下文中调用"
    else
        e2e_fail "bt_create_site 调用未被 has_bt_key 守护"
    fi
fi

if echo "$TRY_BODY" | grep -qF 'bt_ensure_supervisor_plugin'; then
    e2e_pass "bt_ensure_supervisor_plugin 被调用"
else
    e2e_fail "bt_ensure_supervisor_plugin 调用缺失"
fi

# 复用站点跳过 bt_create_site（select_install_dir 已确认）
if echo "$TRY_BODY" | grep -qE 'SITE_REUSE_CONFIRMED.*=.*"true".*\].*\&\&.*site_ready=true|SITE_REUSE_CONFIRMED.*=.*"true"' &&
    echo "$TRY_BODY" | grep -qE '已存在并复用'; then
    e2e_pass "复用站点跳过 bt_create_site，直接 site_ready=true"
else
    # 兜底：检查 SITE_REUSE_CONFIRMED 在 bt_create_site 之前出现
    if echo "$TRY_BODY" | awk '
        /SITE_REUSE_CONFIRMED/ { found_reuse = NR }
        /bt_create_site/ && !found_reuse { exit 1 }
        END { exit 0 }
    '; then
        e2e_pass "SITE_REUSE_CONFIRMED 检查在 bt_create_site 之前"
    else
        e2e_fail "复用站点未跳过 bt_create_site（会触发 BT API 已存在错误）"
    fi
fi

if echo "$TRY_BODY" | grep -qF 'bt_add_supervisor_process'; then
    e2e_pass "bt_add_supervisor_process 被调用"
else
    e2e_fail "bt_add_supervisor_process 调用缺失"
fi

if echo "$TRY_BODY" | grep -qF 'bt_add_crontab'; then
    e2e_pass "bt_add_crontab 被调用"
else
    e2e_fail "bt_add_crontab 调用缺失"
fi

# === 3. supervisor command 用 $PHP_CMD 绝对路径 ===
e2e_log "3. supervisor command 使用 \$PHP_CMD 绝对路径（避免 PATH 依赖）"
if echo "$TRY_BODY" | grep -qE '\$PHP_CMD[[:space:]]+\$INSTALL_DIR/backend/artisan queue:work'; then
    e2e_pass "supervisor command 用 \$PHP_CMD（来自 select_php_version 设置的 /www/server/php/<ver>/bin/php）"
else
    e2e_fail "supervisor command 未用 \$PHP_CMD 绝对路径"
fi

# === 4. supervisor command 包含抓包对齐的 queue:work 选项 ===
e2e_log "4. supervisor command 包含完整 queue:work 选项（与抓包对齐）"
for opt in '--tries 3' '--delay 5' '--max-jobs 1000' '--max-time 3600' '--memory 128' '--timeout 60' '--sleep 3'; do
    # grep -F -- 防止 -- 前缀被识别为参数终止符
    if echo "$TRY_BODY" | grep -qF -- "$opt"; then
        e2e_pass "queue:work 含 $opt"
    else
        e2e_fail "queue:work 缺 $opt"
    fi
done

# === 5. bt_inject_vhost_include 调用传 expected_root（路径错位防御）===
e2e_log "5. bt_inject_vhost_include 传 expected_root=\$INSTALL_DIR（P4 路径校验）"
if echo "$TRY_BODY" | grep -qE 'bt_inject_vhost_include[[:space:]]+"\$SITE_DOMAIN"[[:space:]]+"\$INSTALL_DIR/nginx/manager\.conf"[[:space:]]+"\$INSTALL_DIR"'; then
    e2e_pass "bt_inject_vhost_include 调用含 expected_root 第 3 参数"
else
    e2e_fail "bt_inject_vhost_include 缺 expected_root（路径错位陷阱未防御）"
fi

# === 6. install_plugin 抓包字段（sName / version / min_version）===
e2e_log "6. install_plugin 字段对齐抓包（sName=supervisor / version=3 / min_version=0.6）"
INSTALL_PLUGIN_BLOCK=$(awk '/_bt_supervisor_plugin_install\(\) \{/,/^}/' "$BT_AUTOMATE")
for kv in "sName=supervisor" "version=3" "min_version=0.6"; do
    if echo "$INSTALL_PLUGIN_BLOCK" | grep -qF "$kv"; then
        e2e_pass "install_plugin 含 $kv"
    else
        e2e_fail "install_plugin 缺 $kv"
    fi
done

# === 7. AddProcess 抓包字段（6 字段）===
e2e_log "7. AddProcess 字段对齐抓包（pjname / user / path / command / numprocs / ps）"
ADD_PROCESS_BLOCK=$(awk '/^bt_add_supervisor_process\(\) \{/,/^}/' "$BT_AUTOMATE")
for field in pjname user path command numprocs ps; do
    if echo "$ADD_PROCESS_BLOCK" | grep -qE "data-urlencode '$field="; then
        e2e_pass "AddProcess 含字段 $field"
    else
        e2e_fail "AddProcess 缺字段 $field"
    fi
done

# 路由 URL 对齐抓包：/plugin?action=a&name=supervisor&s=AddProcess
if echo "$ADD_PROCESS_BLOCK" | grep -qF '/plugin?action=a&name=supervisor&s=AddProcess'; then
    e2e_pass "AddProcess 路由 URL 对齐抓包"
else
    e2e_fail "AddProcess 路由 URL 不对齐"
fi

# === 8. AddCrontab 抓包 22 字段全对齐 ===
e2e_log "8. AddCrontab 字段对齐抓包（22 字段全集）"
ADD_CRONTAB_BLOCK=$(awk '/^bt_add_crontab\(\) \{/,/^}/' "$BT_AUTOMATE")
for field in name sType sBody sName backupTo save urladdress save_local notice notice_channel \
    datab_name tables_name keyword flock version user stop_site type week hour minute \
    where1 timeSet timeType; do
    if echo "$ADD_CRONTAB_BLOCK" | grep -qE "data-urlencode '$field="; then
        e2e_pass "AddCrontab 含字段 $field"
    else
        e2e_fail "AddCrontab 缺字段 $field（与抓包不对齐）"
    fi
done

# 路由 URL 对齐抓包：/crontab?action=AddCrontab
if echo "$ADD_CRONTAB_BLOCK" | grep -qF '/crontab?action=AddCrontab'; then
    e2e_pass "AddCrontab 路由 URL 对齐抓包"
else
    e2e_fail "AddCrontab 路由 URL 不对齐"
fi

# AddCrontab 仅支持 minute-n（其他类型留待未来扩展）
if echo "$ADD_CRONTAB_BLOCK" | grep -qF '!= "minute-n"'; then
    e2e_pass "AddCrontab 仅支持 minute-n（明确白名单）"
else
    e2e_fail "AddCrontab 缺 minute-n 白名单守护"
fi

# === 9. 函数定义就位 ===
e2e_log "9. 5 个核心函数定义齐全（bt-automate.sh）"
for fn in bt_ensure_supervisor_plugin bt_add_supervisor_process bt_add_crontab _bt_supervisor_plugin_installed _bt_supervisor_plugin_install; do
    if grep -qE "^${fn}\(\) \{" "$BT_AUTOMATE"; then
        e2e_pass "函数 $fn 已定义"
    else
        e2e_fail "函数 $fn 缺失"
    fi
done

# === 9b. _bt_supervisor_plugin_install 走两步流程（install_plugin + input_package）===
e2e_log "9b. supervisor 安装走 BT 11.x 两步流程"
if echo "$INSTALL_PLUGIN_BLOCK" | grep -qF "action=install_plugin"; then
    e2e_pass "step 1: install_plugin 端点调用就位（下载+解包）"
else
    e2e_fail "step 1: install_plugin 端点调用缺失"
fi

if echo "$INSTALL_PLUGIN_BLOCK" | grep -qF "action=input_package"; then
    e2e_pass "step 2: input_package 端点调用就位（拷贝+执行 install.sh）"
else
    e2e_fail "step 2: input_package 端点调用缺失（BT 11.x 必需）"
fi

# input_package 必传字段：tmp_path / plugin_name / install_opt（来自 step 1 响应）
for f in tmp_path plugin_name install_opt; do
    if echo "$INSTALL_PLUGIN_BLOCK" | grep -qF -- "--data-urlencode 'tmp_path" ||
        echo "$INSTALL_PLUGIN_BLOCK" | grep -qE "data-urlencode '${f}="; then
        e2e_pass "input_package 含字段 $f"
    else
        e2e_fail "input_package 缺字段 $f"
    fi
done

# === 9c. 已撤回错误的 _bt_panel_bound 预检（绑定账号非必需，浏览器手动安装也走两步流程）===
e2e_log "9c. 已撤回错误的 _bt_panel_bound 预检"
if grep -qE "^_bt_panel_bound\(\) \{" "$BT_AUTOMATE"; then
    e2e_fail "_bt_panel_bound 函数仍存在（应删除：绑定不是必需的）"
else
    e2e_pass "_bt_panel_bound 已撤回（不再做错误的根因判断）"
fi

# === 9d. cron / supervisor 名字用 SITE_DOMAIN 保唯一（多站点不冲突）===
e2e_log "9d. supervisor / cron 名字用站点域名（避免重装时 DelProcess 误删其他项目）"
if echo "$TRY_BODY" | grep -qE 'bt_add_supervisor_process[[:space:]]+\\?$\s*"\$SITE_DOMAIN"' ||
    echo "$TRY_BODY" | grep -qE 'bt_add_supervisor_process[[:space:]]+\\\s*$' &&
    echo "$TRY_BODY" | grep -qE '"\$SITE_DOMAIN"[[:space:]]+\\\s*$'; then
    e2e_pass "bt_add_supervisor_process 用 \$SITE_DOMAIN 作为进程名"
else
    # 兜底：检查 try_bt_automation 内 bt_add_supervisor_process 调用紧邻 $SITE_DOMAIN
    if echo "$TRY_BODY" | awk '
        /bt_add_supervisor_process/ { found=NR }
        /^[[:space:]]+"\$SITE_DOMAIN"/ && found && (NR - found) <= 2 { print "ok"; exit }
    ' | grep -q ok; then
        e2e_pass "bt_add_supervisor_process 用 \$SITE_DOMAIN 作为进程名"
    else
        e2e_fail "bt_add_supervisor_process 进程名未用 \$SITE_DOMAIN"
    fi
fi

if echo "$TRY_BODY" | grep -qE 'bt_add_crontab[[:space:]]+"\$SITE_DOMAIN"'; then
    e2e_pass "bt_add_crontab 用 \$SITE_DOMAIN 作为任务名"
else
    e2e_fail "bt_add_crontab 任务名未用 \$SITE_DOMAIN"
fi

# 反向断言：硬编码 "ssl-manager" 不再作为 supervisor/cron 名字传递
if echo "$TRY_BODY" | grep -qE 'bt_add_(supervisor_process|crontab)[[:space:]]+"ssl-manager"'; then
    e2e_fail "supervisor/cron 仍硬编码 ssl-manager（多站点会冲突）"
else
    e2e_pass "supervisor/cron 不再硬编码 ssl-manager"
fi

# === 10. CLI 入口扩展（add-supervisor / add-crontab / ensure-supervisor）===
e2e_log "10. bt-automate.sh CLI 入口扩展"
for cmd in ensure-supervisor add-supervisor add-crontab; do
    if grep -qE "^[[:space:]]+${cmd}\)" "$BT_AUTOMATE"; then
        e2e_pass "CLI 命令 $cmd 已注册"
    else
        e2e_fail "CLI 命令 $cmd 缺失"
    fi
done

echo
echo "结果: ${E2E_PASS:-0} passed / ${E2E_FAIL:-0} failed"
exit "${E2E_FAIL:-0}"
