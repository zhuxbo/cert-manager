#!/usr/bin/env bash
# 使用真实 PHP 合并版本配置，并注入生产收尾路径的失败，避免触碰真实安装。
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
source "$ROOT/deploy/test/php-test-runner.sh"
WRAPPERS=$(mktemp -d)
TEST_DIR=""
trap 'rm -rf "$WRAPPERS"; [ -z "$TEST_DIR" ] || rm -rf "$TEST_DIR"' EXIT
test_php_init "$ROOT" "$WRAPPERS"
TEST_DIR=$(test_php_mktemp_dir upgrade-version)

extract_fn() {
    awk -v head="$1() {" -v sq="'" '
        $0 == head { p = 1 }
        p { print }
        p && index($0, "-r ") > 0 && substr($0, length($0), 1) == sq { inq = 1; next }
        p && inq && substr($0, 1, 1) == sq { inq = 0; next }
        p && inq == 0 && $0 == "}" { exit }
    ' "$UPGRADE"
}
eval "$(extract_fn _publish_upgrade_version)"
# 从生产函数截取最后恢复服务至返回的代码，失败必须真正阻止版本发布。
TAIL=$(extract_fn perform_upgrade | sed -n '/^    # unfreeze 必须严格先于 artisan up/,$p' | sed '$d')
[ -n "$TAIL" ]
# 生产函数只有最后一个发布入口，不允许重新引入 apply 阶段复制版本文件。
BODY=$(extract_fn perform_upgrade)
[ "$(printf '%s\n' "$BODY" | grep -c '_publish_upgrade_version ')" -eq 1 ]
if printf '%s\n' "$BODY" | grep -E 'cp .*version.json'; then
    echo '升级中途仍在复制版本文件' >&2
    exit 1
fi
log_step() { :; }
log_info() { :; }
log_success() { :; }
log_warning() { :; }
log_error() { :; }
update_jobs_php_path() { :; }
_separate_redis_cache_database() { :; }
check_queue_worker_status() { :; }
chown() { [ "$FAIL_AT" != permissions ]; }
nginx() { :; }
php_dispatch() {
    if [ "$1" = artisan ]; then
        if [ "$2" = config:cache ]; then
            [ "$FAIL_AT" != cache ] || return 17
            return 0
        fi
        # 版本发布前的 Artisan 收尾步骤都必须仍能读到旧版本。
        cmp -s "$INSTALL_DIR/version.json" "$TEST_DIR/original.json" || return 98
        if [ "$FAIL_AT" = "$2" ]; then return 17; fi
        return 0
    fi
    "$TEST_PHP_BIN" "$@"
}
run_tail() { eval "$TAIL"; }
PHP_CMD=php_dispatch
php_ver_compact=84
site_domain=test.local
bt_reload_php_fpm() {
    if [ "$1" = 84 ] && [ "$2" = test.local ] &&
        "$TEST_PHP_BIN" -r 'exit(json_decode(file_get_contents($argv[1]), true)["version"] === "0.6.9-beta.19" ? 0 : 1);' "$INSTALL_DIR/version.json"; then
        echo pass >>"$INSTALL_DIR/reload-result"
    else
        echo fail >>"$INSTALL_DIR/reload-result"
    fi
}

for FAIL_AT in up migrate config:clear permissions invalid cache success; do
    INSTALL_DIR="$TEST_DIR/$FAIL_AT"
    src_dir="$INSTALL_DIR/package"
    mkdir -p "$INSTALL_DIR/backend/database/migrations" "$src_dir"
    touch "$INSTALL_DIR/backend/database/migrations/2026_09_04_000001_invalidate_sessions_for_runtime_cache_cutover.php"
    printf '%s\n' '{"version":"0.6.9-beta.18","release_url":"https://custom.example/a?x=1&y=2","network":"cn"}' >"$TEST_DIR/original.json"
    cp "$TEST_DIR/original.json" "$INSTALL_DIR/version.json"
    printf '%s\n' '{"version":"0.6.9-beta.19","release_url":"https://default.example","network":"intl","build_time":"new-build"}' >"$src_dir/version.json"
    [ "$FAIL_AT" != invalid ] || printf 'invalid json' >"$src_dir/version.json"
    target_version=0.6.9-beta.19
    backup_path=unused
    UPGRADE_DONE=0
    set +e
    (
        set -e
        run_tail
    )
    rc=$?
    set -e
    if [ "$FAIL_AT" = success ] || [ "$FAIL_AT" = cache ]; then
        [ "$rc" -eq 0 ]
        [ "$(cat "$INSTALL_DIR/reload-result")" = pass ]
        "$TEST_PHP_BIN" -r '
$d = json_decode(file_get_contents($argv[1]), true);
exit($d["version"] === "0.6.9-beta.19" && $d["network"] === "cn"
    && $d["release_url"] === "https://custom.example/a?x=1&y=2"
    && $d["build_time"] === "new-build" ? 0 : 1);
' "$INSTALL_DIR/version.json"
    else
        case "$FAIL_AT" in
            up | migrate | config:clear) [ "$rc" -eq 17 ] ;;
            *) [ "$rc" -eq 1 ] ;;
        esac
        cmp "$INSTALL_DIR/version.json" "$TEST_DIR/original.json"
    fi
    [ -z "$(find "$INSTALL_DIR" -name '.version-next.*' -print)" ]
    echo "✓ 版本发布: $FAIL_AT"
done
