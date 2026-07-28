#!/usr/bin/env bash
# upgrade.sh cron 管理段（update_jobs_php_path）mock 演练：
# - schedule:run 仅修正 PHP 路径，不迁移日志重定向；
# - 新安装命令不重定向，由宝塔保存任务日志。
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
BTINSTALL="$ROOT/deploy/scripts/bt-install.sh"
PASS=0
FAIL=0

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

log_info() { :; }
log_success() { :; }
log_error() { :; }
log_warning() { :; }
log_step() { :; }

_json_field() {
    local field="$1" line
    IFS= read -r line
    echo "$line" | sed -n "s/.*\"${field}\":\"\([^\"]*\)\".*/\1/p"
}
_entry_encode() { printf '%s' "$1"; }
_entry_decode() { printf '%s' "$1"; }

BT_KEY="fake-key"
bt_resolve_key() { return 0; }
bt_verify_api_key() { return 0; }
bt_list_supervisor_all() { :; }

CALL_LOG="$(mktemp)"
MOCK_CRON_FILE="$(mktemp)"
BT_ADD_RC=0

bt_list_crontab_all() { cat "$MOCK_CRON_FILE" 2>/dev/null; }
bt_add_crontab() {
    echo "ADD|name=$1|type=$2|where1=$3|body=$4" >>"$CALL_LOG"
    return "$BT_ADD_RC"
}
_bt_api_post() {
    echo "POST|$1|$2" >>"$CALL_LOG"
    return 0
}

INSTALL_DIR="/www/wwwroot/site"
PHP_CMD="/www/server/php/84/bin/php"
target_php="$PHP_CMD"
SCRIPT_DIR="$(mktemp -d)"
: >"$SCRIPT_DIR/bt-automate.sh"

eval "$(extract_fn "$UPGRADE" _fix_installer_cron)"
eval "$(extract_fn "$UPGRADE" update_jobs_php_path)"

for fn in _fix_installer_cron update_jobs_php_path; do
    declare -f "$fn" >/dev/null 2>&1 || {
        echo "✗ 抽取失败：$fn"
        exit 1
    }
done

cron_line() {
    printf '{"id":"%s","name":"%s","type":"%s","where1":"%s","sBody":"%s"}\n' "$1" "$2" "$3" "$4" "$5"
}
SCHED_OLD="/www/server/php/83/bin/php /www/wwwroot/site/backend/artisan schedule:run >> /dev/null 2>&1"
SCHED_PLAIN="/www/server/php/84/bin/php /www/wwwroot/site/backend/artisan schedule:run"

reset_scenario() {
    : >"$CALL_LOG"
    : >"$MOCK_CRON_FILE"
    BT_ADD_RC=0
}
has() { grep -qF "$1" "$CALL_LOG"; }

echo "=== 场景 1：旧 PHP schedule ==="
reset_scenario
cron_line 1 site minute-n 1 "$SCHED_OLD" >"$MOCK_CRON_FILE"
update_jobs_php_path >/dev/null 2>&1
has "ADD|name=site|type=minute-n|where1=1|body=/www/server/php/84/bin/php" &&
    has ">> /dev/null 2>&1" &&
    pass "schedule 仅修 PHP 路径并保留原重定向" ||
    fail "schedule 修复意外改变了日志策略"

echo "=== 场景 2：干净 schedule ==="
reset_scenario
cron_line 1 site minute-n 1 "$SCHED_PLAIN" >"$MOCK_CRON_FILE"
update_jobs_php_path >/dev/null 2>&1
[ ! -s "$CALL_LOG" ] && pass "全干净时零改动" || fail "全干净时发生了操作"

echo "=== 场景 3：_fix_installer_cron no-op 守卫 ==="
reset_scenario
_fix_installer_cron "9|site|-|minute-n|1|$SCHED_PLAIN"
rc=$?
[ ! -s "$CALL_LOG" ] && [ "$rc" -ne 0 ] && pass "正确 body 不删除重建" || fail "no-op 守卫失效"

echo "=== 场景 4：空列表 ==="
reset_scenario
update_jobs_php_path >/dev/null 2>&1
[ ! -s "$CALL_LOG" ] && pass "空列表不误操作" || fail "空列表触发了操作"

echo "=== 场景 5：新安装命令契约 ==="
grep -qF 'echo " 脚本内容: $PHP_CMD $INSTALL_DIR/backend/artisan schedule:run"' "$BTINSTALL" &&
    grep -qF '"$PHP_CMD $INSTALL_DIR/backend/artisan schedule:run"; then' "$BTINSTALL" &&
    ! grep -qF 'schedule:run >>' "$BTINSTALL" &&
    ! grep -qF 'monitor:probe' "$BTINSTALL" &&
    ! grep -qF 'write_logrotate_conf' "$BTINSTALL" &&
    pass "新安装由宝塔记录 schedule 日志且不创建 probe/logrotate" ||
    fail "新安装 cron 契约不正确"

rm -rf "$CALL_LOG" "$MOCK_CRON_FILE" "$SCRIPT_DIR"

echo ""
echo "==================== 结果 ===================="
echo "PASS: $PASS   FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
