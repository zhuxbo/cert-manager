#!/usr/bin/env bash
# M3/M6 §1.5：upgrade.sh cron 管理段（update_jobs_php_path）mock 演练
#
# 框架对齐 test-upgrade-preserve-guard.sh：extract_fn 抽顶层函数体（不 source 整脚本避免触发
# main）→ stub BT/_json_field/log_* → 驱动 update_jobs_php_path，捕获 bt_add_crontab/_bt_api_post
# 调用断言。纯 shell、无需 PHP。
#
# 场景：
#   1 仅 schedule 需修（PHP 旧 + /dev/null）+ probe 已存在正确 → schedule 自动修 PHP + /dev/null→schedule.log 迁移，probe/ensure 不动
#   2 schedule + probe 双行均需修 → 两组各自自动修（组内唯一互不拆台），ensure 不触发
#   3a schedule 需修 + probe 缺失 → ensure 新增 probe
#   3b 干净机变体：schedule 完全干净（total_mismatch=0）+ probe 缺失 → ensure 仍执行（前移至早返之前）
#   3c schedule + probe 均干净 → 零改动、ensure 不触发（probe 已存在不重复新增）
#   4 no-op 守卫：_fix_installer_cron 对已正确 body 直接 skip（不 Del/Add）
#   5 负向：bt_list_crontab_all 空列表 → schedule_marker_seen=false → ensure skip（防瞬时失败误新增）
#   6 grep：bt-install 两处重定向 + 拨测 cron + 两脚本 write_logrotate_conf
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
BTINSTALL="$ROOT/deploy/scripts/bt-install.sh"
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

# ---- stub 被抽取函数的依赖 ----
log_info() { :; }
log_success() { :; }
log_error() { :; }
log_warning() { :; }
log_step() { :; }

# _json_field：从 stdin 一行 mock JSON 提取 "field":"value"（value 无内嵌双引号）
_json_field() {
    local field="$1" line
    IFS= read -r line
    echo "$line" | sed -n "s/.*\"${field}\":\"\([^\"]*\)\".*/\1/p"
}
# body 无 | 字符，编解码取恒等
_entry_encode() { printf '%s' "$1"; }
_entry_decode() { printf '%s' "$1"; }

# BT key 边界：非空 + verify 成功，绕过 bt_resolve_key
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
    echo "POST|$1" >>"$CALL_LOG"
    return 0
}

# 全局：被抽取函数读取（update_jobs_php_path 内 local target_php 会遮蔽此全局；
# 全局 target_php 仅供场景 4 直接调用 _fix_installer_cron 时提供 —— 依赖 bash 动态作用域）
INSTALL_DIR="/www/wwwroot/site"
PHP_CMD="/www/server/php/84/bin/php"
target_php="$PHP_CMD"
SCRIPT_DIR="$(mktemp -d)"
: >"$SCRIPT_DIR/bt-automate.sh" # 存在即可（bt_list_crontab_all 已定义，不会真 source）

# ---- 抽取被测函数 ----
eval "$(extract_fn "$UPGRADE" _fix_installer_cron)"
eval "$(extract_fn "$UPGRADE" update_jobs_php_path)"

for fn in _fix_installer_cron update_jobs_php_path; do
    if ! declare -f "$fn" >/dev/null 2>&1; then
        echo "✗ 抽取失败：$fn 未从 $UPGRADE 提取到（函数结构变化？）"
        exit 1
    fi
done

# mock cron 行构造器
cron_line() { # id name type where1 body
    printf '{"id":"%s","name":"%s","type":"%s","where1":"%s","sBody":"%s"}\n' "$1" "$2" "$3" "$4" "$5"
}
SCHED_OLD="/www/server/php/83/bin/php /www/wwwroot/site/backend/artisan schedule:run >> /dev/null 2>&1"
SCHED_NEW="/www/server/php/84/bin/php /www/wwwroot/site/backend/artisan schedule:run >> /www/wwwroot/site/backend/storage/logs/schedule.log 2>&1"
PROBE_OLD="/www/server/php/83/bin/php /www/wwwroot/site/backend/artisan monitor:probe >> /www/wwwroot/site/backend/storage/logs/probe.log 2>&1"
PROBE_NEW="/www/server/php/84/bin/php /www/wwwroot/site/backend/artisan monitor:probe >> /www/wwwroot/site/backend/storage/logs/probe.log 2>&1"

reset_scenario() {
    : >"$CALL_LOG"
    : >"$MOCK_CRON_FILE"
    BT_ADD_RC=0
}

has() { grep -qF "$1" "$CALL_LOG"; }
count() { grep -cF "$1" "$CALL_LOG"; }

echo "=== 场景 1：仅 schedule 需修 + probe 已正确 → 修 schedule + 迁移，probe/ensure 不动 ==="
reset_scenario
{
    cron_line 1 site minute-n 1 "$SCHED_OLD"
    cron_line 2 site-probe minute-n 5 "$PROBE_NEW"
} >"$MOCK_CRON_FILE"
update_jobs_php_path >/dev/null 2>&1
has "POST|" && has "schedule.log 2>&1" && has "/www/server/php/84/bin/php /www/wwwroot/site/backend/artisan schedule:run" &&
    ! has "/dev/null" &&
    pass "场景1：schedule 修 PHP 84 + /dev/null→schedule.log 迁移" ||
    fail "场景1：schedule 未正确修复/迁移"
[ "$(count 'monitor:probe')" -eq 0 ] &&
    pass "场景1：probe 已正确 + probe_cron_exists=true → 不 Del/Add、ensure 不触发" ||
    fail "场景1：probe 被误动（应零操作）"

echo "=== 场景 2：schedule + probe 双行均需修 → 两组各自修，ensure 不触发 ==="
reset_scenario
{
    cron_line 1 site minute-n 1 "$SCHED_OLD"
    cron_line 2 site-probe minute-n 5 "$PROBE_OLD"
} >"$MOCK_CRON_FILE"
update_jobs_php_path >/dev/null 2>&1
[ "$(count 'POST|')" -eq 2 ] &&
    pass "场景2：两组各一次 Del（组内唯一互不拆台）" ||
    fail "场景2：Del 次数非 2（得 $(count 'POST|')）"
has "schedule:run >> /www/wwwroot/site/backend/storage/logs/schedule.log" &&
    has "monitor:probe >> /www/wwwroot/site/backend/storage/logs/probe.log" &&
    ! has "/www/server/php/83" &&
    pass "场景2：schedule + probe 均修至 PHP 84（probe 免 /dev/null 迁移）" ||
    fail "场景2：双组修复不完整"

echo "=== 场景 3a：schedule 需修 + probe 缺失 → ensure 新增 probe ==="
reset_scenario
cron_line 1 site minute-n 1 "$SCHED_OLD" >"$MOCK_CRON_FILE"
update_jobs_php_path >/dev/null 2>&1
has "name=site-probe" && has "monitor:probe" &&
    pass "场景3a：probe 缺失 → ensure 新增 site-probe" ||
    fail "场景3a：ensure 未新增 probe"
has "schedule:run >> /www/wwwroot/site/backend/storage/logs/schedule.log" &&
    pass "场景3a：schedule 同时被修复迁移" ||
    fail "场景3a：schedule 未修"

echo "=== 场景 3b：干净机变体（schedule 全对 total_mismatch=0）+ probe 缺失 → ensure 仍执行 ==="
reset_scenario
cron_line 1 site minute-n 1 "$SCHED_NEW" >"$MOCK_CRON_FILE"
update_jobs_php_path >/dev/null 2>&1
has "name=site-probe" && has "monitor:probe" &&
    pass "场景3b：total_mismatch=0 早返前 ensure 已执行（I3 前移正确）" ||
    fail "场景3b：干净机 probe 缺失未补（ensure 被早返吞）"
[ "$(count 'schedule:run')" -eq 0 ] &&
    pass "场景3b：schedule 干净未被 Del/Add" ||
    fail "场景3b：干净 schedule 被误动"

echo "=== 场景 3c：schedule + probe 均干净 → 零改动、ensure 不触发 ==="
reset_scenario
{
    cron_line 1 site minute-n 1 "$SCHED_NEW"
    cron_line 2 site-probe minute-n 5 "$PROBE_NEW"
} >"$MOCK_CRON_FILE"
update_jobs_php_path >/dev/null 2>&1
[ ! -s "$CALL_LOG" ] &&
    pass "场景3c：全干净 → 无任何 Del/Add（probe 已存在不重复新增）" ||
    fail "场景3c：不该有操作却发生（$(cat "$CALL_LOG")）"

echo "=== 场景 4：no-op 守卫 —— _fix_installer_cron 对已正确 body 直接 skip ==="
reset_scenario
_fix_installer_cron "9|site|-|minute-n|1|$SCHED_NEW" schedule
rc=$?
[ ! -s "$CALL_LOG" ] && [ "$rc" -ne 0 ] &&
    pass "场景4：new_body==原 body → skip（无 Del/Add，返回非 0）" ||
    fail "场景4：no-op 守卫未生效（rc=$rc，log=$(cat "$CALL_LOG")）"

echo "=== 场景 5（负向）：bt_list_crontab_all 空列表 → ensure skip，不误新增 ==="
reset_scenario
# MOCK_CRON_FILE 已空
update_jobs_php_path >/dev/null 2>&1
[ ! -s "$CALL_LOG" ] &&
    pass "场景5：空列表 → schedule_marker_seen=false → ensure skip（防瞬时失败堆双 probe）" ||
    fail "场景5：空列表误触发操作（$(cat "$CALL_LOG")）"

echo "=== 场景 6（grep）：bt-install 重定向 + 拨测 cron + 两脚本 logrotate 函数 ==="
sched_redir=$(grep -cF 'schedule:run >> $INSTALL_DIR/backend/storage/logs/schedule.log 2>&1' "$BTINSTALL")
[ "$sched_redir" -ge 2 ] &&
    pass "场景6：bt-install 两处 schedule.log 重定向（cron 注册 + 手工提示）" ||
    fail "场景6：bt-install schedule.log 重定向不足 2 处（得 $sched_redir）"
grep -qF 'monitor:probe >> $INSTALL_DIR/backend/storage/logs/probe.log 2>&1' "$BTINSTALL" &&
    pass "场景6：bt-install 含拨测 cron probe.log 重定向" ||
    fail "场景6：bt-install 缺拨测 cron"
grep -qF 'write_logrotate_conf()' "$BTINSTALL" && grep -qF 'write_logrotate_conf()' "$UPGRADE" &&
    pass "场景6：bt-install + upgrade.sh 均含 write_logrotate_conf 函数" ||
    fail "场景6：write_logrotate_conf 函数缺失"

# 清理
rm -rf "$CALL_LOG" "$MOCK_CRON_FILE" "$SCRIPT_DIR"

echo ""
echo "==================== 结果 ===================="
echo "PASS: $PASS   FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
