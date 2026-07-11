#!/usr/bin/env bash
# P0-2 包U：upgrade.sh storage 防删守卫演练（验收⑦）
#
# 验收⑦：升级中途 Ctrl-C / SSH 断连 → storage 与 storage/databak 完好、已移出内容被守卫还原。
#
# 框架对齐 deploy/test/test-symmetric-copies.sh：extract_fn 抽顶层函数体（不 source 整脚本避免触发
# main）→ 定义 assert_* + PASS/FAIL 计数 → [ "$FAIL" -eq 0 ] 退出。纯 shell、无需 PHP，任意 runner 可跑。
#
# 分组：
#   A 单元（直接驱动守卫函数）：A1 还原生效 / A2 成功态不误还原 / A3 守卫失败不吞 /
#                               A4 搁浅拦截 / A5 空壳清理 / A6 same-fs 断言
#   B 信号注入（子进程 harness）：B1 SIGINT / B2 SIGTERM / B3 SIGHUP 还原 + B4 SIGKILL 后重跑拦截
#   C 回归守卫：钉死 PRESERVE_DIR 在 $INSTALL_DIR 下（非 /tmp），防改回 tmpfs
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
PASS=0
FAIL=0

# 与 test-symmetric-copies.sh 同款 extract_fn（跟踪内嵌 PHP 单引号块；本包守卫函数无内嵌 PHP）
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

# log_* stub（被抽取的守卫函数依赖；upgrade.sh :38-42 是单行函数，extract_fn 整行等值匹配抽不出）
log_info() { :; }
log_success() { :; }
log_error() { :; }
log_warning() { :; }
log_step() { :; }
_print_recovery_runbook() { :; }

# 抽取守卫函数进当前 shell（A 组直接驱动）
eval "$(extract_fn "$UPGRADE" _fs_device)"
eval "$(extract_fn "$UPGRADE" _assert_storage_same_fs)"
eval "$(extract_fn "$UPGRADE" _check_stranded_preserve)"
eval "$(extract_fn "$UPGRADE" _restore_preserved_storage)"

# 抽取健全性校验：任一函数未抽出即整体失败（防 upgrade.sh 改结构后静默失测）
for fn in _fs_device _assert_storage_same_fs _check_stranded_preserve _restore_preserved_storage; do
    if ! declare -f "$fn" >/dev/null 2>&1; then
        echo "✗ 抽取失败：$fn 未从 $UPGRADE 提取到（函数结构变化？）"
        exit 1
    fi
done

DATABAK_REL="backend/storage/databak/db_20260101_000000.sql.gz"

# ========================================================================
# A. 单元
# ========================================================================
echo "=== A. 单元（直接驱动守卫函数）==="

# A1 还原生效：mv 出后驱动还原，databak 字节一致回原位、preserve/storage 消费、vendor 还原、rc=0
test_a1() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a1"
    local sent="SENTINEL-A1-$$"
    mkdir -p "$INSTALL_DIR/backend/storage/databak" "$INSTALL_DIR/backend/storage/logs" "$INSTALL_DIR/backend/vendor"
    printf '%s' "$sent" >"$INSTALL_DIR/$DATABAK_REL"
    printf 'LOG-SENTINEL' >"$INSTALL_DIR/backend/storage/logs/laravel.log"
    printf 'AUTOLOAD' >"$INSTALL_DIR/backend/vendor/autoload.php"
    # 模拟切代码窗内 mv 出（同 fs）
    mkdir -p "$PRESERVE_DIR"
    mv "$INSTALL_DIR/backend/storage" "$PRESERVE_DIR/storage"
    mv "$INSTALL_DIR/backend/vendor" "$PRESERVE_DIR/vendor"
    local rc
    (_restore_preserved_storage)
    rc=$?
    local ok=1
    [ "$rc" -eq 0 ] || ok=0
    [ "$(cat "$INSTALL_DIR/$DATABAK_REL" 2>/dev/null || true)" = "$sent" ] || ok=0
    [ ! -d "$PRESERVE_DIR/storage" ] || ok=0                  # preserve/storage 已被 mv 消费
    [ -f "$INSTALL_DIR/backend/vendor/autoload.php" ] || ok=0 # vendor 也还原
    if [ "$ok" -eq 1 ]; then
        pass "A1 还原生效：databak 字节一致回原位 + preserve/storage 消费 + vendor 还原 + rc=0"
    else
        fail "A1 还原生效（rc=$rc）"
    fi
    rm -rf "$base"
}

# A2 成功态不误还原：preserve 无 storage、原位 storage 在 → no-op、原位未动、rc=0
test_a2() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a2"
    local sent="SENTINEL-A2-$$"
    mkdir -p "$INSTALL_DIR/backend/storage/databak"
    printf '%s' "$sent" >"$INSTALL_DIR/$DATABAK_REL"
    mkdir -p "$PRESERVE_DIR" # preserve 存在但无 storage（模拟窗关后）
    local rc
    (_restore_preserved_storage)
    rc=$?
    local ok=1
    [ "$rc" -eq 0 ] || ok=0
    [ "$(cat "$INSTALL_DIR/$DATABAK_REL" 2>/dev/null || true)" = "$sent" ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "A2 成功态不误还原：no-op、原位 storage 未被动、rc=0"
    else
        fail "A2 成功态不误还原（rc=$rc）"
    fi
    rm -rf "$base"
}

# A3 守卫失败不吞：mv 失败 stub → 返回 1、PRESERVE_DIR/storage 仍在（未被删）
test_a3() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a3"
    local sent="SENTINEL-A3-$$"
    mkdir -p "$PRESERVE_DIR/storage/databak" "$INSTALL_DIR/backend"
    printf '%s' "$sent" >"$PRESERVE_DIR/storage/databak/db_20260101_000000.sql.gz"
    local rc
    (
        mv() { return 1; } # 注入还原 mv 失败（subshell 隔离，不污染后续测试）
        _restore_preserved_storage
    )
    rc=$?
    local ok=1
    [ "$rc" -eq 1 ] || ok=0                # 守卫返回 1
    [ -d "$PRESERVE_DIR/storage" ] || ok=0 # 唯一副本仍在
    [ "$(cat "$PRESERVE_DIR/storage/databak/db_20260101_000000.sql.gz" 2>/dev/null || true)" = "$sent" ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "A3 守卫失败不吞：返回 1 + PRESERVE_DIR/storage 保留（数据可手工恢复）"
    else
        fail "A3 守卫失败不吞（rc=$rc）"
    fi
    rm -rf "$base"
}

# A4 残留检测·搁浅拦截：.upgrade-preserve-*/storage 存在且 backend/storage 缺失 → 中止、数据未动、不造空 storage
test_a4() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    local sent="SENTINEL-A4-$$"
    mkdir -p "$INSTALL_DIR/.upgrade-preserve-99999/storage/databak" "$INSTALL_DIR/backend"
    printf '%s' "$sent" >"$INSTALL_DIR/.upgrade-preserve-99999/storage/databak/db.sql.gz"
    local rc
    (_check_stranded_preserve)
    rc=$?
    local ok=1
    [ "$rc" -ne 0 ] || ok=0                                                         # 被拦截（exit 1）
    [ -f "$INSTALL_DIR/.upgrade-preserve-99999/storage/databak/db.sql.gz" ] || ok=0 # 搁浅数据未动
    [ "$(cat "$INSTALL_DIR/.upgrade-preserve-99999/storage/databak/db.sql.gz" 2>/dev/null || true)" = "$sent" ] || ok=0
    [ ! -d "$INSTALL_DIR/backend/storage" ] || ok=0 # 未静默造空 storage
    if [ "$ok" -eq 1 ]; then
        pass "A4 搁浅拦截：rc≠0 中止 + 搁浅 storage 未动 + backend/storage 未被创建"
    else
        fail "A4 搁浅拦截（rc=$rc）"
    fi
    rm -rf "$base"
}

# A5 残留检测·空壳清理：无 storage 的空壳（.env / api_adapters）→ 放行 rc=0、空壳被清
test_a5() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    mkdir -p "$INSTALL_DIR/.upgrade-preserve-99999/api_adapters" "$INSTALL_DIR/backend"
    printf 'x' >"$INSTALL_DIR/.upgrade-preserve-99999/.env"
    local rc
    (_check_stranded_preserve)
    rc=$?
    local ok=1
    [ "$rc" -eq 0 ] || ok=0                                 # 放行
    [ ! -d "$INSTALL_DIR/.upgrade-preserve-99999" ] || ok=0 # 空壳被清
    if [ "$ok" -eq 1 ]; then
        pass "A5 空壳清理：rc=0 放行 + 空壳被清（防堆积）"
    else
        fail "A5 空壳清理（rc=$rc）"
    fi
    rm -rf "$base"
}

# A6 same-fs 断言：不同设备号 → 中止；相同设备号 → 放行；真实 stat 正例（同 fs 必过，macOS 顺带覆盖 -f 回落）
test_a6() {
    local base stubdir
    base="$(mktemp -d)"
    stubdir="$(mktemp -d)"
    INSTALL_DIR="$base"
    mkdir -p "$INSTALL_DIR/backend/storage"

    # 6a. stat stub 对 storage 返回不同设备号 → 断言中止
    cat >"$stubdir/stat" <<'STATEOF'
#!/usr/bin/env bash
p=""
for p; do :; done
case "$p" in
    *storage*) echo 222 ;;
    *) echo 111 ;;
esac
STATEOF
    chmod +x "$stubdir/stat"
    local rc_diff
    (
        PATH="$stubdir:$PATH"
        _assert_storage_same_fs
    )
    rc_diff=$?

    # 6b. stat stub 全返回相同设备号 → 放行
    cat >"$stubdir/stat" <<'STATEOF'
#!/usr/bin/env bash
echo 111
STATEOF
    chmod +x "$stubdir/stat"
    local rc_same
    (
        PATH="$stubdir:$PATH"
        _assert_storage_same_fs
    )
    rc_same=$?

    # 6c. 真实 stat 正例：同 fs 临时目录必过（不 stub PATH）
    local rc_real
    (_assert_storage_same_fs)
    rc_real=$?

    local ok=1
    [ "$rc_diff" -ne 0 ] || ok=0 # 跨设备中止
    [ "$rc_same" -eq 0 ] || ok=0 # 同设备放行
    [ "$rc_real" -eq 0 ] || ok=0 # 真实同 fs 放行
    if [ "$ok" -eq 1 ]; then
        pass "A6 same-fs 断言：跨设备中止(rc=$rc_diff) + 同设备放行 + 真实同 fs 放行"
    else
        fail "A6 same-fs 断言（diff=$rc_diff same=$rc_same real=$rc_real）"
    fi
    rm -rf "$base" "$stubdir"
}

test_a1
test_a2
test_a3
test_a4
test_a5
test_a6

# ========================================================================
# B. 信号注入（子进程 harness，直接复现验收⑦）
# ========================================================================
echo ""
echo "=== B. 信号注入（子进程 harness）==="

# 生成最小 harness：抽取的全局 + _restore_preserved_storage + cleanup + trap 装配 + mv 出 → sleep
# PRESERVE_DIR 命名为 $INSTALL_DIR/.upgrade-preserve-harness（B4 靠此被 _check_stranded_preserve 扫到）
write_harness() {
    local hf="$1" inst="$2" ready="$3" sent="$4"
    cat >"$hf" <<HDR
#!/usr/bin/env bash
set -e
log_info() { :; }
log_success() { :; }
log_error() { :; }
log_warning() { :; }
log_step() { :; }
_print_recovery_runbook() { :; }
INSTALL_DIR="$inst"
TEMP_DIR="$inst/tmp"
PRESERVE_DIR="$inst/.upgrade-preserve-harness"
FREEZE_FIRED=0
UPGRADE_DONE=0
PHP_CMD=php
HDR
    extract_fn "$UPGRADE" _restore_preserved_storage >>"$hf"
    extract_fn "$UPGRADE" cleanup >>"$hf"
    cat >>"$hf" <<MAIN
trap cleanup EXIT
trap cleanup INT TERM HUP
mkdir -p "\$INSTALL_DIR/backend/storage/databak"
printf '%s' "$sent" > "\$INSTALL_DIR/$DATABAK_REL"
mkdir -p "\$PRESERVE_DIR"
mv "\$INSTALL_DIR/backend/storage" "\$PRESERVE_DIR/storage"
touch "$ready"
sleep 3
MAIN
}

# 等 ready 标志出现（harness 完成 mv 出、进入 sleep），避免信号早于 mv 的竞态
wait_ready() {
    local ready="$1" i
    for i in $(seq 1 100); do
        [ -f "$ready" ] && return 0
        sleep 0.1
    done
    return 1
}

run_signal_test() {
    local sig="$1" label="$2"
    local base ready harness sent pid
    base="$(mktemp -d)"
    ready="$base/ready"
    harness="$base/harness.sh"
    sent="SENTINEL-$sig-$$"
    write_harness "$harness" "$base" "$ready" "$sent"
    bash "$harness" &
    pid=$!
    wait_ready "$ready" || true
    kill -"$sig" "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    local target="$base/$DATABAK_REL"
    local ok=1
    [ -f "$target" ] || ok=0                                     # storage 已还原回原位
    [ "$(cat "$target" 2>/dev/null || true)" = "$sent" ] || ok=0 # databak 字节一致（同一份非新建空）
    if [ "$ok" -eq 1 ]; then
        pass "$label：信号后 storage/databak 字节完好还原（已移出内容被守卫还原）"
    else
        fail "$label：storage=$([ -f "$target" ] && echo 存在 || echo 缺失) 内容匹配=$([ "$(cat "$target" 2>/dev/null || true)" = "$sent" ] && echo 是 || echo 否)"
    fi
    rm -rf "$base"
}

run_signal_test INT "B1 SIGINT(Ctrl-C)"
run_signal_test TERM "B2 SIGTERM"
run_signal_test HUP "B3 SIGHUP(SSH 断连)"

# B4 SIGKILL + 重跑拦截：kill -KILL（trap 不跑）→ 数据存活于 .upgrade-preserve-*/storage；
# 随后模拟重跑入口 _check_stranded_preserve → 必须被拦截、不造空 storage、搁浅数据未动
test_b4() {
    local base ready harness sent pid
    base="$(mktemp -d)"
    ready="$base/ready"
    harness="$base/harness.sh"
    sent="SENTINEL-KILL-$$"
    write_harness "$harness" "$base" "$ready" "$sent"
    bash "$harness" &
    pid=$!
    wait_ready "$ready" || true
    kill -KILL "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true

    local preserved="$base/.upgrade-preserve-harness/storage/databak/db_20260101_000000.sql.gz"
    local ok=1
    [ -f "$preserved" ] || ok=0 # U1 持久 fs backstop：数据存活
    [ "$(cat "$preserved" 2>/dev/null || true)" = "$sent" ] || ok=0
    [ ! -d "$base/backend/storage" ] || ok=0 # trap 未跑：尚未还原
    if [ "$ok" -eq 1 ]; then
        pass "B4a SIGKILL 后数据存活于 .upgrade-preserve-*/storage（U1 持久 fs backstop）"
    else
        fail "B4a SIGKILL 数据存活"
    fi

    # 模拟重跑升级入口
    INSTALL_DIR="$base"
    local rc
    (_check_stranded_preserve)
    rc=$?
    local ok2=1
    [ "$rc" -ne 0 ] || ok2=0                  # 被拦截
    [ ! -d "$base/backend/storage" ] || ok2=0 # 未静默造空 storage
    [ -f "$preserved" ] || ok2=0              # 搁浅数据未动
    if [ "$ok2" -eq 1 ]; then
        pass "B4b SIGKILL 后重跑被 _check_stranded_preserve 拦截、真数据未被埋（Important-2 闭环）"
    else
        fail "B4b 重跑拦截（rc=$rc）"
    fi
    rm -rf "$base"
}

test_b4

# ========================================================================
# C. 回归守卫
# ========================================================================
echo ""
echo "=== C. 回归守卫 ==="

# 钉死 PRESERVE_DIR 展开在 $INSTALL_DIR 下（非 /tmp），防未来改回 tmpfs 复制窗
if grep -qF 'PRESERVE_DIR="$INSTALL_DIR/.upgrade-preserve-$$"' "$UPGRADE"; then
    pass "C PRESERVE_DIR 钉死在 \$INSTALL_DIR 下（非 /tmp）"
else
    fail "C PRESERVE_DIR 改址被回退（应为 \$INSTALL_DIR/.upgrade-preserve-\$\$）"
fi

# 钉死生产信号 trap 装配行：B 组 harness 自装配 trap，删掉 upgrade.sh 生产行 B 组仍绿；且信号 trap 与
# EXIT trap 同调 cleanup、副作用一致（bash 3.2/5.x 实测：删信号 trap 后 EXIT trap 仍还原），黑盒断言
# 无法区分二者，故 trap 装配的回归守卫只能靠此源码 grep（非 reached_end 之类的运行时哨兵）。
if grep -qE 'trap cleanup INT TERM HUP' "$UPGRADE"; then
    pass "C 生产信号 trap 装配行钉死（trap cleanup INT TERM HUP）"
else
    fail "C 生产信号 trap 装配行缺失（应有 trap cleanup INT TERM HUP）"
fi

echo ""
echo "================================"
echo "PASS=$PASS  FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
