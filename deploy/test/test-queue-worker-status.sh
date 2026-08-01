#!/usr/bin/env bash
# upgrade.sh queue worker 状态检测 mock 演练：
# - 只认本站 artisan queue:work 真实进程，不受其他 Supervisor 进程干扰；
# - 进程重建窗口内允许短暂重试；
# - 无法读取进程表时不谎报 worker 未运行。
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
PASS=0
FAIL=0

extract_fn() {
    awk -v head="$2() {" '
        $0 == head { p = 1 }
        p { print }
        p && $0 == "}" { exit }
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

LOG_FILE="$(mktemp)"
PS_CALL_FILE="$(mktemp)"
trap 'rm -f "$LOG_FILE" "$PS_CALL_FILE"' EXIT

log_info() { printf 'INFO|%s\n' "$1" >>"$LOG_FILE"; }
log_success() { printf 'OK|%s\n' "$1" >>"$LOG_FILE"; }
log_warning() { printf 'WARN|%s\n' "$1" >>"$LOG_FILE"; }

INSTALL_DIR="/www/wwwroot/ssl.iuip.com"
PS_MODE=""

ps() {
    local calls
    [ "$*" = "-eww -o pid=,args=" ] || return 2
    calls=$(cat "$PS_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$PS_CALL_FILE"

    case "$PS_MODE" in
        target-running)
            echo "123 /www/server/php/84/bin/php $INSTALL_DIR/backend/artisan queue:work --queue tasks,notifications"
            ;;
        unrelated-running)
            echo "456 /usr/bin/python /srv/other-worker.py RUNNING"
            ;;
        target-on-retry)
            if [ "$calls" -ge 2 ]; then
                echo "123 /www/server/php/84/bin/php $INSTALL_DIR/backend/artisan queue:work --queue tasks,notifications"
            fi
            ;;
        relative-target)
            echo "789 /www/server/php/84/bin/php artisan queue:work --queue tasks,notifications"
            ;;
        unavailable)
            return 1
            ;;
    esac
}

readlink() {
    [ "$1" = "/proc/789/cwd" ] && echo "$INSTALL_DIR/backend"
}

sleep() { :; }

eval "$(extract_fn "$UPGRADE" check_queue_worker_status)"
if ! declare -f check_queue_worker_status >/dev/null 2>&1; then
    echo "✗ 抽取失败：check_queue_worker_status"
    exit 1
fi

reset_scenario() {
    : >"$LOG_FILE"
    echo 0 >"$PS_CALL_FILE"
}

echo "=== 场景 1：本站 worker 正常 ==="
reset_scenario
PS_MODE="target-running"
check_queue_worker_status
if grep -qF "OK|queue worker 正在运行" "$LOG_FILE" &&
    ! grep -qF "WARN|" "$LOG_FILE"; then
    pass "本站 worker 正常时不误报警"
else
    fail "本站 worker 正常时仍产生警告"
fi

echo "=== 场景 2：只有无关进程 ==="
reset_scenario
PS_MODE="unrelated-running"
check_queue_worker_status
if grep -qF "WARN|queue worker 未运行，请到宝塔面板检查 Supervisor" "$LOG_FILE"; then
    pass "无关 RUNNING 进程不能冒充本站 worker"
else
    fail "未识别本站 worker 缺失"
fi

echo "=== 场景 3：重建窗口内第二次出现 ==="
reset_scenario
PS_MODE="target-on-retry"
check_queue_worker_status
if grep -qF "OK|queue worker 正在运行" "$LOG_FILE" &&
    [ "$(cat "$PS_CALL_FILE")" -eq 2 ] &&
    ! grep -qF "WARN|" "$LOG_FILE"; then
    pass "Supervisor 重建窗口内会重试"
else
    fail "未覆盖 Supervisor 重建窗口"
fi

echo "=== 场景 4：进程表不可读 ==="
reset_scenario
PS_MODE="unavailable"
check_queue_worker_status
if grep -qF "INFO|未能读取进程列表，跳过 queue worker 状态检测" "$LOG_FILE" &&
    ! grep -qF "WARN|" "$LOG_FILE"; then
    pass "检测不可用时不谎报 worker 未运行"
else
    fail "检测不可用时产生了误报警"
fi

echo "=== 场景 5：存量相对 artisan 命令 ==="
reset_scenario
PS_MODE="relative-target"
check_queue_worker_status
if grep -qF "OK|queue worker 正在运行" "$LOG_FILE" &&
    ! grep -qF "WARN|" "$LOG_FILE"; then
    pass "相对 artisan 命令通过本站 cwd 精确识别"
else
    fail "未兼容本站存量相对 artisan 命令"
fi

echo
echo "==================== 结果 ===================="
echo "PASS: $PASS   FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
