#!/usr/bin/env bash
# e2e 场景 6：BT 安装"无 BT_KEY"路径解耦契约
#
# 验证 try_bt_automation 在 has_bt_key=false 时的预期行为：
#   1. 跳过 bt_create_site（建站需 BT_KEY）
#   2. **仍然**调 bt_inject_vhost_include（关键 — 旧 bug：BT_KEY 缺失整体 return 0）
#   3. 跳过 bt_ensure_supervisor_plugin / bt_add_supervisor_process
#   4. 跳过 bt_add_crontab
#   5. 打印 supervisor / cron 手工提示函数（show_manual_*_hint）
#
# 关键反向断言（防 regression）：
#   - 旧 bug 残留：`if [ -z "$BT_KEY" ]; then ... return 0; fi` 已被移除
#   - 旧的 bt_setup_supervisor / bt_setup_cron / _bt_setup_cron_direct 已删除
#   - 旧的 BT_SUPERVISOR_CONF_DIRS 数组已删除（不再直接写文件）

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

E2E_TMPDIR="$(mktemp -d)"
trap e2e_cleanup EXIT

e2e_step "case 06: BT 安装无 BT_KEY 路径解耦契约 + 旧 bug regression 防御"

BT_INSTALL="$E2E_REPO_ROOT/deploy/scripts/bt-install.sh"
BT_AUTOMATE="$E2E_REPO_ROOT/deploy/scripts/bt-automate.sh"

TRY_BODY=$(awk '/^try_bt_automation\(\) \{/,/^}/' "$BT_INSTALL")

# === 1. vhost 注入不依赖 BT_KEY（在 has_bt_key 守护块外）===
e2e_log "1. bt_inject_vhost_include 不被 has_bt_key 守护（独立可跑）"
# 用 awk 检查：bt_inject_vhost_include 调用周围是否有 has_bt_key 守护
# 策略：找 bt_inject_vhost_include 调用行号，找 has_bt_key 守护块的行号范围
# 如果调用行不在任何 has_bt_key=true 块内 → PASS
INJECT_LINE_IN_TRY=$(echo "$TRY_BODY" | grep -n 'bt_inject_vhost_include' | grep -v '^[[:space:]]*#' | head -1 | cut -d: -f1)
if [ -z "$INJECT_LINE_IN_TRY" ]; then
    e2e_fail "try_bt_automation 中未找到 bt_inject_vhost_include 调用"
else
    # 简单契约：注入调用前 5 行内不应出现 'has_bt_key" = true' 的 if 守护
    PRE_LINES=$(echo "$TRY_BODY" | sed -n "$((INJECT_LINE_IN_TRY > 5 ? INJECT_LINE_IN_TRY - 5 : 1)),${INJECT_LINE_IN_TRY}p")
    if echo "$PRE_LINES" | grep -qE 'if \[.*has_bt_key.*=.*true.*\][[:space:]]*;[[:space:]]*then'; then
        e2e_fail "bt_inject_vhost_include 调用被 has_bt_key=true 守护（应独立可跑）"
    else
        e2e_pass "bt_inject_vhost_include 不依赖 BT_KEY（在守护块外）"
    fi
fi

# 反向断言：注入调用前应有 SITE_DOMAIN 检查（独立守护）
PRE_LINES=$(echo "$TRY_BODY" | sed -n "$((INJECT_LINE_IN_TRY > 5 ? INJECT_LINE_IN_TRY - 5 : 1)),${INJECT_LINE_IN_TRY}p")
if echo "$PRE_LINES" | grep -qE 'if \[.*-n.*"\$\{?SITE_DOMAIN'; then
    e2e_pass "bt_inject_vhost_include 调用前用 SITE_DOMAIN 守护（独立条件）"
else
    e2e_fail "bt_inject_vhost_include 调用前缺 SITE_DOMAIN 守护"
fi

# === 2. supervisor / cron 仍由 has_bt_key 守护 ===
e2e_log "2. supervisor / cron 仍由 has_bt_key=true 守护（无 KEY 时跳过）"
# 找 bt_add_supervisor_process 调用的前文，应该有 has_bt_key=true if
SUP_LINE=$(echo "$TRY_BODY" | grep -n 'bt_add_supervisor_process' | head -1 | cut -d: -f1)
if [ -z "$SUP_LINE" ]; then
    e2e_fail "未找到 bt_add_supervisor_process 调用"
else
    # 调用前 8 行内应有 has_bt_key=true 守护
    PRE_SUP=$(echo "$TRY_BODY" | sed -n "$((SUP_LINE > 8 ? SUP_LINE - 8 : 1)),${SUP_LINE}p")
    if echo "$PRE_SUP" | grep -qE 'if \[.*has_bt_key.*=.*true.*\][[:space:]]*;[[:space:]]*then'; then
        e2e_pass "supervisor 块由 has_bt_key=true 守护"
    else
        e2e_fail "supervisor 块缺 has_bt_key 守护（无 KEY 时仍会调 BT API）"
    fi
fi

CRON_LINE=$(echo "$TRY_BODY" | grep -n 'bt_add_crontab' | head -1 | cut -d: -f1)
if [ -z "$CRON_LINE" ]; then
    e2e_fail "未找到 bt_add_crontab 调用"
else
    PRE_CRON=$(echo "$TRY_BODY" | sed -n "$((CRON_LINE > 8 ? CRON_LINE - 8 : 1)),${CRON_LINE}p")
    if echo "$PRE_CRON" | grep -qE 'if \[.*has_bt_key.*=.*true.*\][[:space:]]*;[[:space:]]*then'; then
        e2e_pass "cron 块由 has_bt_key=true 守护"
    else
        e2e_fail "cron 块缺 has_bt_key 守护"
    fi
fi

# === 3. 手工提示函数定义齐全 ===
e2e_log "3. 手工提示函数定义齐全（拆分为 supervisor / cron / vhost 三段）"
for fn in show_manual_supervisor_hint show_manual_cron_hint show_manual_vhost_hint; do
    if grep -qE "^${fn}\(\) \{" "$BT_INSTALL"; then
        e2e_pass "函数 $fn 已定义"
    else
        e2e_fail "函数 $fn 缺失"
    fi
done

# 反向：旧的笼统函数 show_manual_bt_setup_hint 是否仍存在
# 它已经被三个独立函数取代，旧函数定义可以保留作为 fallback，但 try_bt_automation 内不应再调用
if echo "$TRY_BODY" | grep -qE '^[[:space:]]+show_manual_bt_setup_hint[[:space:]]*$'; then
    e2e_warn "旧 show_manual_bt_setup_hint 仍被 try_bt_automation 调用（建议改为 supervisor/cron/vhost 三段）"
fi

# === 4. 手工提示按需调用（supervisor_ok / cron_ok / include_injected 不为 true 时）===
e2e_log "4. 手工提示按需调用（与 *_ok 标志位联动）"
if echo "$TRY_BODY" | grep -qE 'supervisor_ok.*!=.*true.*&&.*show_manual_supervisor_hint|"\$supervisor_ok"[[:space:]]*!=[[:space:]]*true.*\][[:space:]]*&&'; then
    e2e_pass "supervisor_ok != true 时调 show_manual_supervisor_hint"
else
    # 兜底：函数被在 if/&& 链中调用即可
    if echo "$TRY_BODY" | grep -qF 'show_manual_supervisor_hint'; then
        e2e_pass "show_manual_supervisor_hint 在 try_bt_automation 中被调用"
    else
        e2e_fail "show_manual_supervisor_hint 未在 try_bt_automation 中调用"
    fi
fi

if echo "$TRY_BODY" | grep -qF 'show_manual_cron_hint'; then
    e2e_pass "show_manual_cron_hint 在 try_bt_automation 中被调用"
else
    e2e_fail "show_manual_cron_hint 未在 try_bt_automation 中调用"
fi

if echo "$TRY_BODY" | grep -qF 'show_manual_vhost_hint'; then
    e2e_pass "show_manual_vhost_hint 在 try_bt_automation 中被调用"
else
    e2e_fail "show_manual_vhost_hint 未在 try_bt_automation 中调用"
fi

# === 5. 旧 bug regression 防御：BT_KEY 为空时整体 return 0 ===
e2e_log "5. 旧 bug regression 防御（BT_KEY 缺失整体跳过已移除）"
# 旧代码：
#   if [ -z "$BT_KEY" ]; then
#       log_info "无可用 BT_KEY，跳过自动化（请手工配置）"
#       show_manual_bt_setup_hint
#       return 0
#   fi
# 新代码用 has_bt_key 标志位 + 各步骤独立守护，不能再有这种"整体 return"
if echo "$TRY_BODY" | grep -B1 'return 0' | grep -qF '"$BT_KEY"'; then
    e2e_fail "旧 bug 残留：检测到 'if [ -z \"\$BT_KEY\" ]; then ... return 0' 模式"
else
    e2e_pass "无"BT_KEY 缺失整体 return 0"模式"
fi

# 验证关键日志：旧版"跳过自动化"已被新版"将走手工提示"取代
if echo "$TRY_BODY" | grep -qF '将走手工提示'; then
    e2e_pass "无 KEY 时打印"将走手工提示"提示文案"
else
    e2e_fail "缺无 KEY 提示文案"
fi

# === 6. 旧函数已删除（不再直接写文件）===
e2e_log "6. 旧 bt_setup_supervisor / bt_setup_cron / _bt_setup_cron_direct 已删除"
for old_fn in bt_setup_supervisor bt_setup_cron _bt_setup_cron_direct; do
    if grep -qE "^${old_fn}\(\) \{" "$BT_AUTOMATE"; then
        e2e_fail "旧函数 $old_fn 仍存在（应删除，被新 BT 面板 API 函数取代）"
    else
        e2e_pass "旧函数 $old_fn 已删除"
    fi
done

# === 7. 旧 BT_SUPERVISOR_CONF_DIRS 数组已删除（不再直接写文件）===
e2e_log "7. 旧 BT_SUPERVISOR_CONF_DIRS 数组已删除"
if grep -qE '^BT_SUPERVISOR_CONF_DIRS=' "$BT_AUTOMATE"; then
    e2e_fail "旧 BT_SUPERVISOR_CONF_DIRS 数组仍存在（应删除，不再直接写 supervisor conf 文件）"
else
    e2e_pass "BT_SUPERVISOR_CONF_DIRS 数组已删除"
fi

# === 8. BT_KEY 预检前置到 detect_bt_key（一次预检 → BT_KEY_AVAILABLE）===
e2e_log "8. BT_KEY 状态走 detect_bt_key 预检 + BT_KEY_AVAILABLE 单一来源"
if grep -qE '^detect_bt_key\(\) \{' "$BT_INSTALL"; then
    e2e_pass "detect_bt_key 函数已定义（依赖检测后预检）"
else
    e2e_fail "detect_bt_key 函数缺失"
fi

# 反向断言：try_bt_automation 不再二次交互输入 BT_KEY（旧文案 '宝塔 API Token' 已移除）
if echo "$TRY_BODY" | grep -qF '宝塔 API Token'; then
    e2e_fail "try_bt_automation 仍残留二次输入 BT_KEY 的交互（应已被 detect_bt_key 取代）"
else
    e2e_pass "try_bt_automation 不再二次询问 BT_KEY（信任 BT_KEY_AVAILABLE）"
fi

# === 9. 汇总段打印 4 个步骤状态（site / vhost / supervisor / cron）===
e2e_log "9. 汇总段打印每步骤状态（用户能一眼看出哪些自动完成）"
for label in site_ready include_injected supervisor_ok cron_ok; do
    if echo "$TRY_BODY" | grep -qF "$label"; then
        e2e_pass "汇总用 $label 标志位"
    else
        e2e_fail "缺 $label 标志位"
    fi
done

# === 10. CLI ensure-supervisor / add-supervisor / add-crontab 都需要 BT_KEY ===
e2e_log "10. CLI 命令对 BT_KEY 的硬性依赖"
# 抓 case 块中三个新命令的 bt_resolve_key 失败处理
for cmd_block_marker in "ensure-supervisor)" "add-supervisor)" "add-crontab)"; do
    block=$(awk -v marker="$cmd_block_marker" '
        $0 ~ marker { found = 1 }
        found { print }
        found && /;;/ { exit }
    ' "$BT_AUTOMATE")
    if echo "$block" | grep -qF 'bt_resolve_key'; then
        if echo "$block" | grep -qE 'log_error.*BT_KEY'; then
            e2e_pass "CLI 命令 $cmd_block_marker 缺 KEY 时 exit 1"
        else
            e2e_fail "CLI 命令 $cmd_block_marker 未对 KEY 缺失硬性退出"
        fi
    else
        e2e_fail "CLI 命令 $cmd_block_marker 未调 bt_resolve_key"
    fi
done

echo
echo "结果: ${E2E_PASS:-0} passed / ${E2E_FAIL:-0} failed"
exit "${E2E_FAIL:-0}"
