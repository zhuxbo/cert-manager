#!/usr/bin/env bash
# watchdog 互杀修复配套：upgrade.sh 入口残留升级状态处置（_handle_stale_upgrade_status）演练
#
# 框架对齐 test-cron-manage.sh：extract_fn 抽顶层函数体（不 source 整脚本避免触发 main）
# → stub log_* → 沙箱 INSTALL_DIR 驱动断言。
#
# A 组（纯 shell，PHP_CMD 用 stub 控制 verdict，全平台恒跑）：shell 分支全覆盖
#   A1 缺 status.json → rc=0 不动
#   A2 verdict=other（终态/损坏）→ 文件原样保留、rc=0
#   A3 verdict=running_dead → 归档 .stale.*、原文件消失、rc=0
#   A4 verdict=running_alive → rc=1 中止
#   A5 UPGRADE_IGNORE_RUNNING=1 → rc=0 放行且不归档（不调 php）
#   A6 php 探测失败（stub rc=1）→ verdict 回落 other、文件原样、rc=0
#   A7 归档 mv 失败（目录只读）→ best-effort 忽略、原文件保留、rc=0
# B 组（真 php；CI setup-php 恒有，本地无 php 时显式提示跳过）：php verdict 判定正确性
#   B1 running + 死 pid → 归档
#   B2 running + 活 pid（本测试进程）→ rc=1
#   B3 completed → 原样
#   B4 损坏 JSON → 原样
#   B5 running + 活 pid 但 pid_starttime 不符（PID 复用）→ Linux 判死归档 / 非 Linux 保守中止
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
PASS=0
FAIL=0

# 与 test-upgrade-preserve-guard.sh 同款 extract_fn（跟踪内嵌 PHP 单引号块）
extract_fn() {
    awk -v head="$2() {" -v sq="'" '
        $0 == head { p = 1 }
        p { print }
        p && index($0, "-r ") > 0 && substr($0, length($0), 1) == sq { inq = 1; next }
        p && inq && substr($0, 1, 1) == sq { inq = 0; next }
        p && inq == 0 && $0 == "}" { exit }
    ' "$1"
}

pass() {
    echo "✓ $1"
    PASS=$((PASS + 1))
}
fail() {
    echo "✗ $1"
    FAIL=$((FAIL + 1))
}

FN_SRC="$(extract_fn "$UPGRADE" _handle_stale_upgrade_status)"
if [ -z "$FN_SRC" ]; then
    fail "extract_fn 未能从 upgrade.sh 抽出 _handle_stale_upgrade_status"
    echo "结果: $PASS 通过 / $FAIL 失败"
    exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# stub php：忽略实参，按 STUB_VERDICT / STUB_RC 输出（模拟 verdict 通道）
STUB_PHP="$TMP/php-stub"
cat >"$STUB_PHP" <<'EOF'
#!/usr/bin/env bash
printf '%s' "${STUB_VERDICT:-other}"
exit "${STUB_RC:-0}"
EOF
chmod +x "$STUB_PHP"

# 驱动器：子 shell 内注入依赖 + eval 函数体 + 调用；返回函数 rc（exit 1 被子 shell 捕获）
run_guard() {
    local install_dir="$1" php_cmd="$2" ignore="${3:-0}" verdict="${4:-other}" stub_rc="${5:-0}"
    (
        set +e
        INSTALL_DIR="$install_dir"
        PHP_CMD="$php_cmd"
        UPGRADE_IGNORE_RUNNING="$ignore"
        STUB_VERDICT="$verdict"
        STUB_RC="$stub_rc"
        export STUB_VERDICT STUB_RC
        log_info() { :; }
        log_success() { :; }
        log_error() { :; }
        log_warning() { :; }
        log_step() { :; }
        eval "$FN_SRC"
        _handle_stale_upgrade_status
    )
}

fresh_sandbox() {
    local dir="$TMP/install-$1"
    rm -rf "$dir"
    mkdir -p "$dir/backend/storage/upgrades"
    echo "$dir"
}

status_of() { echo "$1/backend/storage/upgrades/status.json"; }

# ---------- A 组：纯 shell 分支 ----------

# A1 缺文件
SB="$(fresh_sandbox a1)"
if run_guard "$SB" "$STUB_PHP" 0 running_dead; then
    pass "A1 缺 status.json → rc=0 不动"
else
    fail "A1 缺 status.json 应 rc=0"
fi

# A2 verdict=other
SB="$(fresh_sandbox a2)"
echo '{"status":"completed"}' >"$(status_of "$SB")"
if run_guard "$SB" "$STUB_PHP" 0 other && [ -f "$(status_of "$SB")" ]; then
    pass "A2 verdict=other → 原样保留"
else
    fail "A2 verdict=other 应保留原文件且 rc=0"
fi

# A3 verdict=running_dead → 归档
SB="$(fresh_sandbox a3)"
echo '{"status":"running","pid":1}' >"$(status_of "$SB")"
if run_guard "$SB" "$STUB_PHP" 0 running_dead &&
    [ ! -f "$(status_of "$SB")" ] &&
    ls "$(status_of "$SB")".stale.* >/dev/null 2>&1; then
    pass "A3 running_dead → 归档 .stale.* 且原文件消失"
else
    fail "A3 running_dead 应归档"
fi

# A4 verdict=running_alive → 中止
SB="$(fresh_sandbox a4)"
echo '{"status":"running","pid":1}' >"$(status_of "$SB")"
if run_guard "$SB" "$STUB_PHP" 0 running_alive; then
    fail "A4 running_alive 应 rc=1 中止"
else
    if [ -f "$(status_of "$SB")" ]; then
        pass "A4 running_alive → rc=1 且未动文件"
    else
        fail "A4 running_alive 中止时不应动文件"
    fi
fi

# A5 逃生门：UPGRADE_IGNORE_RUNNING=1
SB="$(fresh_sandbox a5)"
echo '{"status":"running","pid":1}' >"$(status_of "$SB")"
if run_guard "$SB" "$STUB_PHP" 1 running_alive && [ -f "$(status_of "$SB")" ]; then
    pass "A5 UPGRADE_IGNORE_RUNNING=1 → 放行且不归档"
else
    fail "A5 逃生门应 rc=0 且不动文件"
fi

# A6 php 探测失败 → 回落 other
SB="$(fresh_sandbox a6)"
echo '{"status":"running","pid":1}' >"$(status_of "$SB")"
if run_guard "$SB" "$STUB_PHP" 0 running_dead 1 && [ -f "$(status_of "$SB")" ]; then
    pass "A6 php rc=1 → verdict 回落 other、原样保留"
else
    fail "A6 php 失败应回落 other 且 rc=0"
fi

# A7 归档 mv 失败 → best-effort 忽略（目录只读制造 mv 失败，仅对非 root 生效；
# CI runner 与本地开发机均非 root 恒执行，容器 root 场景明示跳过）
if [ "$(id -u)" != "0" ]; then
    SB="$(fresh_sandbox a7)"
    echo '{"status":"running","pid":1}' >"$(status_of "$SB")"
    chmod 555 "$SB/backend/storage/upgrades"
    if run_guard "$SB" "$STUB_PHP" 0 running_dead && [ -f "$(status_of "$SB")" ]; then
        pass "A7 归档失败 → 忽略（rc=0、原文件保留）"
    else
        fail "A7 归档失败应 best-effort 忽略"
    fi
    chmod 755 "$SB/backend/storage/upgrades"
else
    echo "! A7 跳过：root 不受目录只读约束，无法制造 mv 失败（CI runner 非 root 恒执行）"
fi

# ---------- B 组：真 php verdict 判定 ----------

if command -v php >/dev/null 2>&1; then
    REAL_PHP="$(command -v php)"

    # B1 running + 死 pid
    (exit 0) &
    DEAD_PID=$!
    wait "$DEAD_PID" 2>/dev/null || true
    SB="$(fresh_sandbox b1)"
    printf '{"status":"running","pid":%d}' "$DEAD_PID" >"$(status_of "$SB")"
    if run_guard "$SB" "$REAL_PHP" &&
        [ ! -f "$(status_of "$SB")" ] &&
        ls "$(status_of "$SB")".stale.* >/dev/null 2>&1; then
        pass "B1 真 php：running + 死 pid → 归档"
    else
        fail "B1 真 php 应判 running_dead 并归档"
    fi

    # B2 running + 活 pid（本测试脚本进程）
    SB="$(fresh_sandbox b2)"
    printf '{"status":"running","pid":%d}' "$$" >"$(status_of "$SB")"
    if run_guard "$SB" "$REAL_PHP"; then
        fail "B2 真 php：活 pid 应 rc=1 中止"
    else
        pass "B2 真 php：running + 活 pid → rc=1 中止"
    fi

    # B3 completed → 原样
    SB="$(fresh_sandbox b3)"
    echo '{"status":"completed","pid":1}' >"$(status_of "$SB")"
    if run_guard "$SB" "$REAL_PHP" && [ -f "$(status_of "$SB")" ]; then
        pass "B3 真 php：completed → 原样"
    else
        fail "B3 真 php：completed 应原样保留"
    fi

    # B4 损坏 JSON → 原样
    SB="$(fresh_sandbox b4)"
    echo 'not-json{{' >"$(status_of "$SB")"
    if run_guard "$SB" "$REAL_PHP" && [ -f "$(status_of "$SB")" ]; then
        pass "B4 真 php：损坏 JSON → 原样"
    else
        fail "B4 真 php：损坏 JSON 应原样保留"
    fi

    # B5 running + 活 pid 但 pid_starttime 不符（PID 复用）
    #   Linux(/proc)：starttime 校验判死 → 归档；非 Linux：无 /proc 不校验，保守判活 → rc=1 中止
    SB="$(fresh_sandbox b5)"
    printf '{"status":"running","pid":%d,"pid_starttime":"1"}' "$$" >"$(status_of "$SB")"
    if [ -d /proc ]; then
        if run_guard "$SB" "$REAL_PHP" &&
            [ ! -f "$(status_of "$SB")" ] &&
            ls "$(status_of "$SB")".stale.* >/dev/null 2>&1; then
            pass "B5 真 php：活 pid 但 starttime 不符（PID 复用）→ 判死归档"
        else
            fail "B5 真 php：PID 复用应判 running_dead 并归档"
        fi
    else
        if run_guard "$SB" "$REAL_PHP"; then
            fail "B5 真 php：非 Linux 无 starttime 校验，活 pid 应 rc=1 中止"
        else
            pass "B5 真 php：非 Linux 回落保守判活 → rc=1 中止"
        fi
    fi
else
    echo "! B 组跳过：PATH 无 php（CI 由 setup-php 保证执行；本地可在带 php 的环境重跑）"
fi

echo ""
echo "结果: $PASS 通过 / $FAIL 失败"
[ "$FAIL" -eq 0 ]
