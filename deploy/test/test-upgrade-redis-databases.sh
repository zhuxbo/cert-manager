#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
source "$ROOT/deploy/test/php-test-runner.sh"
WRAPPER_DIR="$(mktemp -d)"
FIXTURE_DIR=""
cleanup() {
    local status=$?
    rm -rf "$WRAPPER_DIR"
    [ -z "$FIXTURE_DIR" ] || rm -rf "$FIXTURE_DIR"
    exit "$status"
}
trap cleanup EXIT
test_php_init "$ROOT" "$WRAPPER_DIR"
FIXTURE_DIR="$(test_php_mktemp_dir redis-upgrade)"
PHP_CMD="$TEST_PHP_BIN"

eval "$(awk '/^_preserve_redis_databases\(\) \{/{p=1} p{print} p && /^}/{exit}' "$ROOT/deploy/upgrade.sh")"

for pair in '0 1' '5 6' '0 0' '7 8'; do
    read -r runtime cache <<<"$pair"
    INSTALL_DIR="$FIXTURE_DIR/site-$runtime-$cache"
    "$TEST_PHP_BIN" -r '
$backend = $argv[1]."/backend";
mkdir($backend."/bootstrap", 0755, true);
if ($argv[3] !== "7") {
    symlink($argv[2], $backend."/vendor");
}
file_put_contents($backend."/.env", "APP_NAME=original_manager\n");
$config = [
    "cache" => ["default" => "redis"],
    "queue" => ["default" => "database"],
    "database" => ["redis" => ["default" => ["database" => $argv[3]], "cache" => ["database" => $argv[4]]]],
];
$bootstrap = "<?php\n"
    ."\$app = new Illuminate\\Foundation\\Application(dirname(__DIR__));\n"
    ."\$app->instance(\"files\", new Illuminate\\Filesystem\\Filesystem);\n"
    ."\$app->instance(\"config\", new Illuminate\\Config\\Repository(CONFIG_PLACEHOLDER));\n"
    ."\$app->instance(Illuminate\\Contracts\\Console\\Kernel::class, new class { public function bootstrap(): void {} });\n"
    ."Illuminate\\Support\\Facades\\Facade::setFacadeApplication(\$app);\nreturn \$app;\n";
file_put_contents($backend."/bootstrap/app.php", str_replace("CONFIG_PLACEHOLDER", var_export($config, true), $bootstrap));
' "$INSTALL_DIR" "$ROOT/backend/vendor" "$runtime" "$cache"

    BUNDLED_VENDOR_STAGE="$ROOT/backend/vendor"
    status=0
    _preserve_redis_databases "$ROOT" >"$WRAPPER_DIR/output" 2>&1 || status=$?
    if [ "$status" -ne 0 ]; then
        cat "$WRAPPER_DIR/output"
        exit 1
    fi
    grep -qx "REDIS_DB=$runtime" "$INSTALL_DIR/backend/.env"
    grep -qx "REDIS_CACHE_DB=$cache" "$INSTALL_DIR/backend/.env"
    _preserve_redis_databases "$ROOT"
    [ "$(grep -c '^REDIS_DB=' "$INSTALL_DIR/backend/.env")" -eq 1 ]
    grep -qx 'APP_NAME=original_manager' "$INSTALL_DIR/backend/.env"
    echo "PASS: 保留旧编号 ${runtime}/${cache}（迁移前保留同库、重复幂等、APP_NAME 保持）"
done

# 固定真实调用位置：兼容失败必须在旧代码覆盖前退出。
awk '
    /if ! _preserve_redis_databases/ { guard=NR; next }
    guard && !stops && /exit 1/ { stops=NR }
    /# 6. 提取需要保留的文件/ { copy=NR }
    END { exit !(guard && stops && copy && guard < stops && stops < copy) }
' "$ROOT/deploy/upgrade.sh"

# 不能提前切 cache DB，否则现有 JWT 迁移将读不到旧黑名单。
awk '
    /artisan migrate --path=/ { migration=NR }
    /^    _separate_redis_cache_database$/ { split_line=NR }
    /artisan config:clear/ { clear=NR }
    /if .*artisan queue:restart/ { restart=NR }
    END { exit !(migration && split_line && clear && migration < split_line && split_line < clear && split_line < restart) }
' "$ROOT/deploy/upgrade.sh"
