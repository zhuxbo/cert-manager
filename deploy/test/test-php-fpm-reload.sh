#!/usr/bin/env bash
# PHP-FPM 升级尾部重载演练：
# - reload 通道优先本机 init 脚本（单次 kill -USR2、退出码可信、不需要 BT API key），宝塔 API 仅兜底；
# - 成败只认本机证据（旧 worker 代际退出 + master 存在 + 健康入口可用），不采信宝塔自陈的 status；
# - 取不到站点域名时跳过链路二次确认而非空转到超时；
# - master 判定走 cmdline，孤儿 worker 不得被认成 master；
# - 升级保持维护态，先完成权限，再 reload FPM，最后 unfreeze/up。
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
BT_AUTOMATE="$ROOT/deploy/scripts/bt-automate.sh"
PASS=0
FAIL=0

extract_fn() {
    awk -v head="$2() {" '
        $0 == head { p = 1; depth = 0 }
        p {
            print
            line = $0
            opens = gsub(/\{/, "{", line)
            closes = gsub(/\}/, "}", line)
            depth += opens - closes
            if (depth == 0) { exit }
        }
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
API_CALL_FILE="$(mktemp)"
SLEEP_CALL_FILE="$(mktemp)"
WORKER_CALL_FILE="$(mktemp)"
PROBE_CALL_FILE="$(mktemp)"
INITD_CALL_FILE="$(mktemp)"
PROC_ROOT="$(mktemp -d)"
INIT_DIR="$(mktemp -d)"
trap 'rm -f "$LOG_FILE" "$API_CALL_FILE" "$SLEEP_CALL_FILE" "$WORKER_CALL_FILE" "$PROBE_CALL_FILE" "$INITD_CALL_FILE"; rm -rf "$PROC_ROOT" "$INIT_DIR"' EXIT

log_step() { printf 'STEP|%s\n' "$1" >>"$LOG_FILE"; }
log_info() { printf 'INFO|%s\n' "$1" >>"$LOG_FILE"; }
log_success() { printf 'OK|%s\n' "$1" >>"$LOG_FILE"; }
log_warning() { printf 'WARN|%s\n' "$1" >>"$LOG_FILE"; }
log_error() { printf 'ERROR|%s\n' "$1" >>"$LOG_FILE"; }

API_STATUS=false
_bt_api_post() {
    local calls
    calls=$(cat "$API_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$API_CALL_FILE"
    printf '{"status": %s, "msg": "mock"}\n' "$API_STATUS"
}
_bt_json_get() {
    printf '%s\n' "$API_STATUS"
}
sleep() {
    local calls
    calls=$(cat "$SLEEP_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$SLEEP_CALL_FILE"
}

eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_process_pids)"
eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_pid_is_master)"
eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_worker_identities)"
eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_master_pids)"
eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_probe_response_ok)"
eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_http_probe)"
eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_send_reload)"
eval "$(extract_fn "$BT_AUTOMATE" bt_reload_php_fpm)"
eval "$(extract_fn "$UPGRADE" _find_bt_vhost_for_install_dir)"
eval "$(extract_fn "$UPGRADE" _bt_site_domain_from_vhost)"
eval "$(extract_fn "$UPGRADE" _finalize_install_permissions)"
for fn in _bt_php_fpm_process_pids _bt_php_fpm_pid_is_master _bt_php_fpm_worker_identities \
    _bt_php_fpm_master_pids _bt_php_fpm_probe_response_ok \
    _bt_php_fpm_http_probe _bt_php_fpm_send_reload bt_reload_php_fpm \
    _find_bt_vhost_for_install_dir _bt_site_domain_from_vhost _finalize_install_permissions; do
    if ! declare -f "$fn" >/dev/null 2>&1; then
        echo "✗ 抽取失败：缺少函数 $fn"
        exit 1
    fi
done

reset_scenario() {
    : >"$LOG_FILE"
    echo 0 >"$API_CALL_FILE"
    echo 0 >"$SLEEP_CALL_FILE"
    echo 0 >"$WORKER_CALL_FILE"
    echo 0 >"$PROBE_CALL_FILE"
    echo 0 >"$INITD_CALL_FILE"
    API_STATUS=false
    BT_PHP_FPM_WAIT_TIMEOUT=6
    BT_PHP_FPM_WAIT_INTERVAL=2
    BT_PHP_FPM_CAUSAL_WINDOW=""
    BT_PHP_FPM_BASELINE_MAX_AGE=""
    BT_FPM_RELOAD_CHANNEL=""
    BT_FPM_RELOAD_DETAIL=""
    rm -f "$INIT_DIR"/php-fpm-*
}

# 装一个假的 /etc/init.d/php-fpm-XX：记录调用次数，退出码由 $1 决定。
install_fake_init_script() {
    local rc="${1:-0}"
    cat >"$INIT_DIR/php-fpm-84" <<EOF
#!/usr/bin/env bash
calls=\$(cat "$INITD_CALL_FILE")
printf '%s' "\$((calls + 1))" >"$INITD_CALL_FILE"
[ "\$1" = reload ] || { echo "unexpected action: \$1" >&2; exit 2; }
echo "Reload service php-fpm  done"
exit $rc
EOF
    chmod +x "$INIT_DIR/php-fpm-84"
}

# role=master 时写 master 的 cmdline，否则写 worker（pool）cmdline。
write_fake_fpm_process() {
    local pid="$1" ppid="$2" start_time="$3" version="$4" role="${5:-worker}"
    mkdir -p "$PROC_ROOT/$pid"
    ln -sf "/www/server/php/$version/sbin/php-fpm" "$PROC_ROOT/$pid/exe"
    printf 'Name:\tphp-fpm\nPPid:\t%s\n' "$ppid" >"$PROC_ROOT/$pid/status"
    printf '%s (php-fpm) S %s 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 %s 0\n' \
        "$pid" "$ppid" "$start_time" >"$PROC_ROOT/$pid/stat"
    if [ "$role" = "master" ]; then
        printf 'php-fpm: master process (/www/server/php/%s/etc/php-fpm.conf)' "$version" \
            >"$PROC_ROOT/$pid/cmdline"
    else
        printf 'php-fpm: pool www' >"$PROC_ROOT/$pid/cmdline"
    fi
}

echo "=== 场景 1：精确识别目标版本 master 与 worker 代际 ==="
reset_scenario
write_fake_fpm_process 100 1 1000 84 master
write_fake_fpm_process 101 100 1001 84
write_fake_fpm_process 102 100 1002 84
write_fake_fpm_process 201 1 2001 83 master
worker_ids=$(BT_PROC_ROOT="$PROC_ROOT" _bt_php_fpm_worker_identities 84)
if [ "$(BT_PROC_ROOT="$PROC_ROOT" _bt_php_fpm_master_pids 84)" = "100" ] &&
    [ "$worker_ids" = $'101:1001\n102:1002' ] &&
    ! printf '%s\n' "$worker_ids" | grep -qF '201:'; then
    pass "按真实可执行文件、cmdline 角色和启动时刻识别 master/worker"
else
    fail "PHP-FPM master/worker 代际识别不准确（workers=${worker_ids:-空}）"
fi

echo "=== 场景 1b：master 已死、worker 被 reparent 到 1 时不得认成 master ==="
reset_scenario
ORPHAN_ROOT="$(mktemp -d)"
PROC_ROOT_BAK="$PROC_ROOT"
PROC_ROOT="$ORPHAN_ROOT"
write_fake_fpm_process 301 1 3001 84 worker
orphan_workers=$(BT_PROC_ROOT="$ORPHAN_ROOT" _bt_php_fpm_worker_identities 84)
if [ -z "$(BT_PROC_ROOT="$ORPHAN_ROOT" _bt_php_fpm_master_pids 84)" ] &&
    [ "$orphan_workers" = "301:3001" ]; then
    pass "孤儿 worker 不被误判成 master（否则代际+探活双证据可被同时绕过）"
else
    fail "孤儿 worker 被误判成 master（workers=${orphan_workers:-空}）"
fi
PROC_ROOT="$PROC_ROOT_BAK"
rm -rf "$ORPHAN_ROOT"

echo "=== 场景 2：按安装目录反查本站 vhost 与探活域名 ==="
reset_scenario
VHOST_DIR="$PROC_ROOT/vhosts"
mkdir -p "$VHOST_DIR"
cat >"$VHOST_DIR/manager.test.conf" <<VHOST
server {
    server_name *.test.example.com manager.test.example.com;
    root /www/wwwroot/manager.test;
}
VHOST
matched_vhost=$(BT_NGINX_VHOST_DIR="$VHOST_DIR" _find_bt_vhost_for_install_dir /www/wwwroot/manager.test)
matched_domain=$(_bt_site_domain_from_vhost "$matched_vhost")
if [ "$matched_vhost" = "$VHOST_DIR/manager.test.conf" ] &&
    [ "$matched_domain" = "manager.test.example.com" ]; then
    pass "精确反查当前安装目录并选择普通域名用于本机健康探活"
else
    fail "本站 vhost/domain 反查失败（vhost=${matched_vhost:-空} domain=${matched_domain:-空}）"
fi

echo "=== 场景 2b：reload 通道优先本机 init 脚本，不触碰 BT API ==="
reset_scenario
# 识别不到 master（非宝塔进程布局）时才回落 init.d；signal 通道另在场景 2e 覆盖。
_bt_php_fpm_master_pids() { return 0; }
install_fake_init_script 0
if BT_PHP_FPM_INIT_DIR="$INIT_DIR" _bt_php_fpm_send_reload 84 &&
    [ "$BT_FPM_RELOAD_CHANNEL" = "init.d" ] &&
    [ "$(cat "$INITD_CALL_FILE")" -eq 1 ] &&
    [ "$(cat "$API_CALL_FILE")" -eq 0 ]; then
    pass "本机 init 脚本可用时只发一次本地 reload，不经宝塔 API"
else
    fail "reload 通道未优先本机 init 脚本（channel=${BT_FPM_RELOAD_CHANNEL:-空}）"
fi

echo "=== 场景 2c：本机 init 脚本失败时如实失败，不静默回落 ==="
reset_scenario
install_fake_init_script 1
if ! BT_PHP_FPM_INIT_DIR="$INIT_DIR" _bt_php_fpm_send_reload 84 &&
    [ "$BT_FPM_RELOAD_CHANNEL" = "init.d" ] &&
    [ "$(cat "$API_CALL_FILE")" -eq 0 ] &&
    printf '%s' "$BT_FPM_RELOAD_DETAIL" | grep -qF "reload 失败"; then
    pass "本机 reload 命令非零退出即如实失败，不掩盖为其他通道成功"
else
    fail "本机 reload 失败被静默吞掉（channel=${BT_FPM_RELOAD_CHANNEL:-空}）"
fi

echo "=== 场景 2e：识别到 master 时直接 kill -USR2，退出码才真代表信号送达 ==="
reset_scenario
SIGNAL_TARGET_FILE="$(mktemp)"
_bt_php_fpm_master_pids() { printf '4242\n'; }
kill() {
    printf '%s\n' "$*" >>"$SIGNAL_TARGET_FILE"
    [ "$1" = "-USR2" ] && [ "$2" = "4242" ]
}
install_fake_init_script 0
if BT_PHP_FPM_INIT_DIR="$INIT_DIR" _bt_php_fpm_send_reload 84 &&
    [ "$BT_FPM_RELOAD_CHANNEL" = "signal" ] &&
    [ "$(cat "$SIGNAL_TARGET_FILE")" = "-USR2 4242" ] &&
    [ "$(cat "$INITD_CALL_FILE")" -eq 0 ] &&
    [ "$(cat "$API_CALL_FILE")" -eq 0 ]; then
    pass "优先对已识别 master 直接发 USR2（init 脚本 rc=0 不证明信号送达，故不作首选）"
else
    fail "未优先使用可信的 signal 通道（channel=${BT_FPM_RELOAD_CHANNEL:-空}）"
fi
: >"$SIGNAL_TARGET_FILE"
kill() {
    printf '%s\n' "$*" >>"$SIGNAL_TARGET_FILE"
    return 1
}
if ! BT_PHP_FPM_INIT_DIR="$INIT_DIR" _bt_php_fpm_send_reload 84 &&
    [ "$BT_FPM_RELOAD_CHANNEL" = "signal" ] &&
    [ "$(cat "$INITD_CALL_FILE")" -eq 0 ]; then
    pass "kill -USR2 失败即如实失败，不静默回落到退出码不可信的通道"
else
    fail "signal 通道失败被静默掩盖"
fi
unset -f kill
rm -f "$SIGNAL_TARGET_FILE"

echo "=== 场景 2d：探活响应校验逐项证伪（表驱动，无外部依赖）==="
reset_scenario
PROJECT_JSON='{"status":"ok","freeze":false,"checks":{"db":{"ok":true}}}'
MAINTENANCE_HTML='<!DOCTYPE html><html><body>503 Service Unavailable</body></html>'
FOREIGN_JSON='{"message":"Not Found"}'
# 缺 checks：同项目形状但不是 /api/health 的响应（如某些错误 JSON）
PARTIAL_JSON='{"status":"error","freeze":true}'
# 判别性样本：只缺 status / 只缺 freeze。没有这两条时，那两个校验会被 "checks" 完全遮蔽——
# 删掉它们套件也不会变红，用例名声称的「逐项可证伪」就不成立。
NO_STATUS_JSON='{"freeze":false,"checks":{}}'
NO_FREEZE_JSON='{"status":"ok","checks":{}}'
probe_cases_ok=1
# 应放行：freeze 期 503 + 正常 200
_bt_php_fpm_probe_response_ok 503 "application/json" "$PROJECT_JSON" || probe_cases_ok=0
_bt_php_fpm_probe_response_ok 200 "application/json; charset=UTF-8" "$PROJECT_JSON" || probe_cases_ok=0
# 应拒绝：HTML 维护页 / 异站 JSON / 缺关键字段 / 非白名单状态码 / content-type 伪装
! _bt_php_fpm_probe_response_ok 503 "text/html; charset=UTF-8" "$MAINTENANCE_HTML" || probe_cases_ok=0
! _bt_php_fpm_probe_response_ok 200 "application/json" "$FOREIGN_JSON" || probe_cases_ok=0
! _bt_php_fpm_probe_response_ok 200 "application/json" "$PARTIAL_JSON" || probe_cases_ok=0
! _bt_php_fpm_probe_response_ok 200 "application/json" "$NO_STATUS_JSON" || probe_cases_ok=0
! _bt_php_fpm_probe_response_ok 200 "application/json" "$NO_FREEZE_JSON" || probe_cases_ok=0
! _bt_php_fpm_probe_response_ok 500 "application/json" "$PROJECT_JSON" || probe_cases_ok=0
! _bt_php_fpm_probe_response_ok 502 "application/json" "$PROJECT_JSON" || probe_cases_ok=0
! _bt_php_fpm_probe_response_ok 200 "text/html" "$PROJECT_JSON" || probe_cases_ok=0
if [ "$probe_cases_ok" -eq 1 ]; then
    pass "探活响应校验：状态码/content-type/本项目关键字段三重收紧，逐项可证伪"
else
    fail "探活响应校验存在放行漏洞（HTML 维护页或异站 JSON 被当成 FPM 链路可用）"
fi

echo "=== 场景 2f：探活 IO 层直驱——域名白名单是唯一的 --resolve 注入闸 ==="
reset_scenario
CURL_CALL_FILE="$(mktemp)"
echo 0 >"$CURL_CALL_FILE"
CURL_BODY='{"status":"ok","freeze":false,"checks":{}}'
CURL_META='__FPM_PROBE__200|application/json'
curl() {
    local calls
    calls=$(cat "$CURL_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$CURL_CALL_FILE"
    printf '%s\n%s' "$CURL_BODY" "$CURL_META"
}
inject_ok=1
for bad in 'a;touch /tmp/pwned' 'x$(id)' 'a b.com' '' '127.0.0.1:80:evil.com'; do
    _bt_php_fpm_http_probe "$bad" && inject_ok=0
done
[ "$(cat "$CURL_CALL_FILE")" -eq 0 ] || inject_ok=0
if [ "$inject_ok" -eq 1 ]; then
    pass "含 shell 元字符/空格/冒号/空域名一律在发 curl 之前被拒（--resolve 注入闸生效）"
else
    fail "非法域名穿透到 curl --resolve（注入面暴露）"
fi

echo "=== 场景 2g：探活 IO 层直驱——https 失败回落 http，哨兵缺失即拒 ==="
reset_scenario
echo 0 >"$CURL_CALL_FILE"
# 第 1 次（https）返回维护页 HTML，第 2 次（http）返回本项目 JSON → 必须回落后放行
curl() {
    local calls
    calls=$(cat "$CURL_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$CURL_CALL_FILE"
    if [ "$calls" -eq 1 ]; then
        printf '<html>503</html>\n__FPM_PROBE__503|text/html'
    else
        printf '{"status":"ok","freeze":true,"checks":{}}\n__FPM_PROBE__503|application/json'
    fi
}
probe_io_ok=1
_bt_php_fpm_http_probe test.example.com || probe_io_ok=0
[ "$(cat "$CURL_CALL_FILE")" -eq 2 ] || probe_io_ok=0
# 哨兵缺失（curl 被截断 / -w 失效）绝不能按「body 含关键字」放行
echo 0 >"$CURL_CALL_FILE"
curl() {
    local calls
    calls=$(cat "$CURL_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$CURL_CALL_FILE"
    printf '{"status":"ok","freeze":false,"checks":{}}\n'
}
_bt_php_fpm_http_probe test.example.com && probe_io_ok=0
if [ "$probe_io_ok" -eq 1 ]; then
    pass "https→http 回落生效；哨兵缺失时拒绝，不退化成裸 body 匹配"
else
    fail "探活 IO 层回落或哨兵校验失效"
fi
unset -f curl
rm -f "$CURL_CALL_FILE"

echo "=== 场景 3：reload 发出后等待旧 worker 全部退出 ==="
reset_scenario
_bt_php_fpm_send_reload() {
    BT_FPM_RELOAD_CHANNEL="init.d"
    BT_FPM_RELOAD_DETAIL=""
    return 0
}
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_http_probe() {
    local calls
    calls=$(cat "$PROBE_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$PROBE_CALL_FILE"
    [ "$1" = "test.example.com" ]
}
_bt_php_fpm_worker_identities() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    case "$calls" in
        1 | 2) printf '101:1001\n102:1002\n' ;;
        3) printf '102:1002\n201:2001\n' ;;
        *) printf '201:2001\n202:2002\n' ;;
    esac
}
if bt_reload_php_fpm 84 test.example.com &&
    [ "$(cat "$PROBE_CALL_FILE")" -eq 1 ] &&
    [ "$(cat "$SLEEP_CALL_FILE")" -eq 2 ] &&
    grep -qF "INFO|等待 PHP-FPM 84 完成重载：旧 worker 剩余 2/2" "$LOG_FILE" &&
    grep -qF "INFO|等待 PHP-FPM 84 完成重载：旧 worker 剩余 1/2" "$LOG_FILE" &&
    grep -qF "OK|PHP-FPM 84 重载完成：旧 worker 代际已退出、master 正常、站点健康入口可用" "$LOG_FILE"; then
    pass "旧代际退出 + master 正常 + reload 后健康探活三项同时满足才判成功"
else
    fail "未完成三项本机证据检查"
fi

echo "=== 场景 4：识别不到 master 时不得凭宝塔 API 自陈宣告成功 ==="
reset_scenario
API_STATUS=true
# 恢复真实实现：识别不到 master → 无 init 脚本 → 落到 API 兜底通道。
# 此时本机既无 master 也无代际可比，宝塔哪怕回 true 也不能作为成功依据。
eval "$(extract_fn "$BT_AUTOMATE" _bt_php_fpm_send_reload)"
_bt_php_fpm_master_pids() { return 0; }
_bt_php_fpm_http_probe() {
    local calls
    calls=$(cat "$PROBE_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$PROBE_CALL_FILE"
    return 0
}
_bt_php_fpm_worker_identities() { return 0; }
if ! bt_reload_php_fpm 84 test.example.com &&
    [ "$(cat "$API_CALL_FILE")" -eq 1 ] &&
    ! grep -qF "OK|PHP-FPM 84 重载完成" "$LOG_FILE" &&
    grep -qF "WARN|PHP-FPM 84 在 6 秒内未完成重载" "$LOG_FILE"; then
    pass "宝塔 status=true 也不能替代本机证据（面板判活与 reload 结果无因果关系）"
else
    fail "宝塔自陈被当成了成功依据"
fi
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_send_reload() {
    BT_FPM_RELOAD_CHANNEL="init.d"
    BT_FPM_RELOAD_DETAIL=""
    return 0
}

echo "=== 场景 5：旧 worker 未退出则等待超时 ==="
reset_scenario
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_worker_identities() { printf '101:1001\n'; }
if ! bt_reload_php_fpm 84 test.example.com &&
    [ "$(cat "$SLEEP_CALL_FILE")" -eq 3 ] &&
    grep -qF "WARN|PHP-FPM 84 在 6 秒内未完成重载：仍有 1 个旧 worker" "$LOG_FILE"; then
    pass "旧 worker 未退出时持续等待，超时仅作为故障上限"
else
    fail "旧 worker 未退出却被固定时间或进程存在误判成功"
fi

echo "=== 场景 5b：取不到站点域名时跳过链路确认，不空转到超时 ==="
reset_scenario
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_http_probe() {
    local calls
    calls=$(cat "$PROBE_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$PROBE_CALL_FILE"
    return 1
}
_bt_php_fpm_worker_identities() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    if [ "$calls" -eq 1 ]; then
        printf '101:1001\n'
    else
        printf '201:2001\n'
    fi
}
if bt_reload_php_fpm 84 &&
    [ "$(cat "$SLEEP_CALL_FILE")" -eq 0 ] &&
    [ "$(cat "$PROBE_CALL_FILE")" -eq 0 ] &&
    grep -qF "WARN|PHP-FPM 84 旧 worker 代际已退出，但未取到站点域名，跳过健康入口二次确认" "$LOG_FILE" &&
    grep -qF "OK|PHP-FPM 84 重载完成（通道 init.d，未做链路二次确认）" "$LOG_FILE"; then
    pass "无站点域名时立即结束并降级告警，不在维护态内空转满 timeout"
else
    fail "无站点域名的成功 reload 被拖到超时误报失败（站点仍停在维护态）"
fi

echo "=== 场景 6：ondemand 0/0 在 reload 前主动建立 worker 基线 ==="
reset_scenario
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_worker_identities() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    case "$calls" in
        1) return 0 ;;
        2) printf '101:1001\n' ;;
        *) printf '201:2001\n' ;;
    esac
}
_bt_php_fpm_http_probe() {
    local calls
    calls=$(cat "$PROBE_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$PROBE_CALL_FILE"
    [ "$1" = "test.example.com" ]
}
if bt_reload_php_fpm 84 test.example.com &&
    [ "$(cat "$PROBE_CALL_FILE")" -eq 2 ] &&
    [ "$(cat "$SLEEP_CALL_FILE")" -eq 0 ] &&
    grep -qF "INFO|PHP-FPM 84 为 ondemand 空闲态，已建立 1 个旧 worker 代际基线" "$LOG_FILE" &&
    grep -qF "OK|PHP-FPM 84 重载完成：旧 worker 代际已退出、master 正常、站点健康入口可用" "$LOG_FILE"; then
    pass "reload 前建立旧基线，reload 后再次探活验证新请求链"
else
    fail "ondemand 0/0 未在 reload 前建立可验证的旧 worker 基线"
fi

echo "=== 场景 6c：合成基线在因果窗口外才退出且 master 未换代 → 不得判成功 ==="
reset_scenario
BT_PHP_FPM_CAUSAL_WINDOW=2
# master 全程不变（模拟 reload 根本没发生），合成基线 worker 直到第 3 次采样（elapsed=4s）
# 才因 pm.process_idle_timeout 自然回收——只看「旧代际消失」会被时间流逝骗过。
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_worker_identities() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    case "$calls" in
        1) return 0 ;;
        2 | 3 | 4) printf '101:1001\n' ;;
        *) return 0 ;;
    esac
}
_bt_php_fpm_http_probe() {
    local calls
    calls=$(cat "$PROBE_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$PROBE_CALL_FILE"
    return 0
}
if ! bt_reload_php_fpm 84 test.example.com &&
    ! grep -qF "OK|PHP-FPM 84 重载完成" "$LOG_FILE" &&
    grep -qE "合成基线 worker 在 reload 后 [0-9]+ 秒、建立后 [0-9]+ 秒才退出（窗口 2/6 秒），且 master 未换代" "$LOG_FILE"; then
    pass "空闲回收造成的代际消失不被当成 reload 生效（reload 未发生时不产出假成功）"
else
    fail "一次根本没发生的 reload 靠时间流逝凑齐代际证据被判成功"
fi
BT_PHP_FPM_CAUSAL_WINDOW=""

echo "=== 场景 6d：master 换代是强因果证据，ondemand 无基线也能确认 ==="
reset_scenario
# 实测宝塔 php-fpm 以 --daemonize 启动，reload 时 master execvp 后再 fork 脱离，PID 必变。
_bt_php_fpm_master_pids() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    if [ "$calls" -le 1 ]; then printf '663089\n'; else printf '663093\n'; fi
}
_bt_php_fpm_worker_identities() { return 0; }
_bt_php_fpm_http_probe() {
    local calls
    calls=$(cat "$PROBE_CALL_FILE")
    printf '%s' "$((calls + 1))" >"$PROBE_CALL_FILE"
    return 1
}
if bt_reload_php_fpm 84 &&
    [ "$(cat "$SLEEP_CALL_FILE")" -eq 0 ] &&
    grep -qF "OK|PHP-FPM 84 重载完成（通道 init.d，未做链路二次确认）" "$LOG_FILE" &&
    grep -qF "master 已换代（663089 → 663093）" "$LOG_FILE"; then
    pass "master PID 换代即确认 reload 生效，不依赖 worker 代际，也不受 idle 回收干扰"
else
    fail "master 换代这一强因果证据未被采纳（ondemand 空闲池将无从确认）"
fi

echo "=== 场景 6e：旧集合为空时「冒出 master」不算换代 ==="
reset_scenario
# old_masters 为空意味着 reload 只能走 rc 不可信的 init.d/API 通道；此时等待期内出现一个 master
# 完全可能是别的东西把 FPM 拉起来，绝不能记成本次 reload 成功。
_bt_php_fpm_master_pids() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    [ "$calls" -eq 1 ] && return 0
    printf '777\n'
}
_bt_php_fpm_worker_identities() { return 0; }
# 探活必须放行：若 stub 成失败，三条断言会被「探活失败必失败」过度决定，
# 换代判据被整块回退时用例也不会变红（伪绿）。
_bt_php_fpm_http_probe() { return 0; }
if ! bt_reload_php_fpm 84 test.example.com &&
    ! grep -qF "OK|PHP-FPM 84 重载完成" "$LOG_FILE" &&
    ! grep -qF "master 已换代" "$LOG_FILE" &&
    grep -qF "（换代 0）" "$LOG_FILE"; then
    pass "旧 master 集合为空时不承认换代证据（无旧身份可比 = 无因果）"
else
    fail "「凭空出现 master」被当成 reload 成功"
fi

echo "=== 场景 6f：多 master 收缩不算换代 ==="
reset_scenario
# 集合整体不等会把「一个 master 消失」也算成换代——必须按「出现新成员」判定。
_bt_php_fpm_master_pids() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    if [ "$calls" -eq 1 ]; then printf '100\n200\n'; else printf '100\n'; fi
}
_bt_php_fpm_worker_identities() { return 0; }
# 同 6e：探活放行，让断言只由换代判据决定。
_bt_php_fpm_http_probe() { return 0; }
if ! bt_reload_php_fpm 84 test.example.com &&
    ! grep -qF "OK|PHP-FPM 84 重载完成" "$LOG_FILE" &&
    ! grep -qF "master 已换代" "$LOG_FILE" &&
    grep -qF "（换代 0）" "$LOG_FILE"; then
    pass "master 集合收缩不被当成换代（判据是出现新成员，不是集合整体不等）"
else
    fail "master 消失被误判成换代成功"
fi

echo "=== 场景 6g：基线年龄闸独立生效（reload 后窗口内，但基线已太老）==="
reset_scenario
# 只放宽 reload 侧窗口、卡死基线年龄：确认两道窗口是「同时要求」而非其中一道兜底。
# SECONDS 是墙钟，演练里 sleep 被 stub，故用 `command sleep` 制造真实年龄。
BT_PHP_FPM_CAUSAL_WINDOW=10
BT_PHP_FPM_BASELINE_MAX_AGE=0
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_worker_identities() {
    local calls
    calls=$(cat "$WORKER_CALL_FILE")
    calls=$((calls + 1))
    printf '%s' "$calls" >"$WORKER_CALL_FILE"
    case "$calls" in
        1) return 0 ;;
        2) printf '101:1001\n' ;;
        *)
            command sleep 1
            return 0
            ;;
    esac
}
_bt_php_fpm_http_probe() { return 0; }
if ! bt_reload_php_fpm 84 test.example.com &&
    ! grep -qF "OK|PHP-FPM 84 重载完成" "$LOG_FILE" &&
    grep -qE "建立后 [1-9][0-9]* 秒才退出（窗口 10/0 秒）" "$LOG_FILE"; then
    pass "基线年龄闸独立拦截：reload 后窗口内退出但基线已老，仍判证据不足"
else
    fail "基线年龄闸未生效（idle 计时从探活起算的那段预算无人守）"
fi

echo "=== 场景 6b：基线与 master 换代都拿不到时不宣告成功 ==="
reset_scenario
_bt_php_fpm_master_pids() { printf '100\n'; }
_bt_php_fpm_worker_identities() { return 0; }
_bt_php_fpm_http_probe() { return 1; }
if ! bt_reload_php_fpm 84 test.example.com &&
    ! grep -qF "OK|PHP-FPM 84 重载完成" "$LOG_FILE" &&
    grep -qF "WARN|PHP-FPM 84 在 6 秒内未完成重载" "$LOG_FILE"; then
    pass "既无代际基线也无 master 换代时如实失败交人工，绝不宣告成功"
else
    fail "无任何因果证据仍宣称重载成功"
fi

# 取文件权限位（GNU 在前、BSD 兜底）
# 顺序不能反：GNU 的 `stat -f` 是 --file-system、不接受格式串，`-f '%Lp'` 会把 '%Lp' 当成文件
# 操作数，**先把文件系统报告打到 stdout** 再以 rc=1 退出，`||` 随后把权限位追加在报告后面，
# 返回值成了一坨垃圾、比较恒假（CI 的 ubuntu runner 上整条断言会静默失效）。
# BSD 的 `stat -c` 则是干净失败：报错走 stderr、stdout 无输出、rc≠0，可安全回落。
file_mode() {
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1" 2>/dev/null
}

echo "=== 场景 7：可选权限文件不存在时仍成功 ==="
reset_scenario
PERMISSION_ROOT="$(mktemp -d)"
INSTALL_DIR="$PERMISSION_ROOT"
mkdir -p "$INSTALL_DIR/backend/storage" "$INSTALL_DIR/backups"
if _finalize_install_permissions; then
    pass "version.json 和根 .env 不存在时权限步骤仍返回成功"
else
    fail "可选文件不存在被误判为权限步骤失败"
fi
rm -rf "$PERMISSION_ROOT"

echo "=== 场景 7b：权限函数真的改了权限位（不只是返回 0）==="
reset_scenario
PERMISSION_ROOT="$(mktemp -d)"
INSTALL_DIR="$PERMISSION_ROOT"
mkdir -p "$INSTALL_DIR/backend/storage/logs" "$INSTALL_DIR/backups"
: >"$INSTALL_DIR/version.json"
: >"$INSTALL_DIR/backend/.env"
: >"$INSTALL_DIR/.env"
chmod 700 "$INSTALL_DIR/backend/storage" "$INSTALL_DIR/backend/storage/logs" "$INSTALL_DIR/backups"
chmod 777 "$INSTALL_DIR/version.json" "$INSTALL_DIR/backend/.env" "$INSTALL_DIR/.env"
# chown www:www 在非 root / 无 www 用户的环境（本机、CI runner）注定失败且被 `|| true` 吞掉，
# 无法断言；这里断言的是同一函数中可观测、且真正决定站点能否写入的 chmod 结果。
perm_ok=1
_finalize_install_permissions || perm_ok=0
[ "$(file_mode "$INSTALL_DIR/backend/storage")" = "775" ] || perm_ok=0
[ "$(file_mode "$INSTALL_DIR/backend/storage/logs")" = "775" ] || perm_ok=0
[ "$(file_mode "$INSTALL_DIR/backups")" = "775" ] || perm_ok=0
[ "$(file_mode "$INSTALL_DIR/version.json")" = "664" ] || perm_ok=0
[ "$(file_mode "$INSTALL_DIR/backend/.env")" = "600" ] || perm_ok=0
[ "$(file_mode "$INSTALL_DIR/.env")" = "640" ] || perm_ok=0
if [ "$perm_ok" -eq 1 ]; then
    pass "storage/backups 递归 775、version.json 664、backend/.env 600、根 .env 640 均实际生效"
else
    fail "权限函数返回 0 但权限位未生效（函数体被掏空也不会被发现）"
fi
rm -rf "$PERMISSION_ROOT"

echo "=== 场景 8：升级尾部顺序保持维护态 ==="
perform_body="$(extract_fn "$UPGRADE" perform_upgrade)"
permissions_line=$(printf '%s\n' "$perform_body" | grep -nF '_finalize_install_permissions' | head -1 | cut -d: -f1)
reload_line=$(printf '%s\n' "$perform_body" | grep -nE '^[[:space:]]+bt_reload_php_fpm ' | head -1 | cut -d: -f1)
unfreeze_line=$(printf '%s\n' "$perform_body" | grep -nF 'artisan upgrade:unfreeze' | tail -1 | cut -d: -f1)
up_line=$(printf '%s\n' "$perform_body" | grep -nE 'artisan up([[:space:]]|$)' | tail -1 | cut -d: -f1)
queue_line=$(printf '%s\n' "$perform_body" | grep -nF 'artisan queue:restart' | tail -1 | cut -d: -f1)
storage_chown_line=$(printf '%s\n' "$perform_body" | grep -nE 'chown -R www:www "\$INSTALL_DIR/backend/storage" "\$INSTALL_DIR/backend/bootstrap/cache"' | tail -1 | cut -d: -f1)
# 内层断言必须并入同一个 if：写成 `grep -q ... && pass` 会在 grep 失败时既不 pass 也不 fail，
# 套件仍 exit 0——那正是 reload 丢掉 $site_domain 这类真实回归在 CI 里静默的口子。
if [ -n "$permissions_line" ] && [ -n "$reload_line" ] && [ -n "$storage_chown_line" ] &&
    [ "$permissions_line" -lt "$reload_line" ] &&
    [ "$reload_line" -lt "$unfreeze_line" ] &&
    [ "$unfreeze_line" -lt "$up_line" ] &&
    [ "$up_line" -lt "$queue_line" ] &&
    [ "$queue_line" -lt "$storage_chown_line" ] &&
    printf '%s\n' "$perform_body" | grep -qE 'bt_reload_php_fpm "\$php_ver_compact" "\$site_domain"'; then
    pass "权限 → FPM reload（带站点域名）→ unfreeze → up → queue:restart → storage 收尾 chown 顺序正确"
else
    fail "升级尾部未按维护态安全顺序执行（storage 收尾 chown 必须在 queue:restart 之后）"
fi

echo "=== 场景 8b：reload 不得被 BT API key 门控 ==="
if printf '%s\n' "$perform_body" | grep -qF 'bt_verify_api_key'; then
    fail "reload 仍被 BT API key 门控（无 key 的机器将完全不 reload，opcache 常驻旧代码）"
else
    pass "reload 不依赖 BT API key，本机 init 通道始终可用"
fi

echo
echo "==================== 结果 ===================="
echo "PASS: $PASS   FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
