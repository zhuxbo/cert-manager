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
eval "$(awk '/^_separate_redis_cache_database\(\) \{/{p=1} p{print} p && /^}/{exit}' "$ROOT/deploy/upgrade.sh")"

for pair in '0 1' '5 6' '0 0' '1 1' '7 8'; do
    read -r runtime cache <<<"$pair"
    INSTALL_DIR="$FIXTURE_DIR/site-$runtime-$cache"
    "$TEST_PHP_BIN" -r '
$backend = $argv[1]."/backend";
mkdir($backend."/bootstrap", 0755, true);
if ($argv[3] !== "7") {
    symlink($argv[2], $backend."/vendor");
}
file_put_contents($backend."/.env", "APP_NAME=original_manager\nREDIS_CACHE_DB=14\nREDIS_CACHE_DB=15\n");
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
    [ "$(grep -c '^REDIS_CACHE_DB=' "$INSTALL_DIR/backend/.env")" -eq 1 ]
    grep -Fq 'REDIS_CACHE_DB 的 .env 值（15）与已加载配置不同' "$WRAPPER_DIR/output"
    grep -Fq "升级前保留 REDIS_DB=$runtime" "$WRAPPER_DIR/output"
    grep -Fq "升级前保留 REDIS_CACHE_DB=$cache" "$WRAPPER_DIR/output"
    grep -Fq $'\033[0;34m[INFO]' "$WRAPPER_DIR/output"
    grep -Fq $'\033[0;33m[WARN]' "$WRAPPER_DIR/output"
    if [ "$runtime" = "$cache" ]; then
        grep -Fq '会话迁移完成后自动分库，请以升级末尾的最终编号为准' "$WRAPPER_DIR/output"
    fi
    _preserve_redis_databases "$ROOT"
    [ "$(grep -c '^REDIS_DB=' "$INSTALL_DIR/backend/.env")" -eq 1 ]
    grep -qx 'APP_NAME=original_manager' "$INSTALL_DIR/backend/.env"
    echo "PASS: 保留旧编号 ${runtime}/${cache}（迁移前保留同库、重复幂等、APP_NAME 保持）"

    if [ "$runtime" != "$cache" ] && [ "$runtime" != "7" ]; then
        "$TEST_PHP_BIN" -r '
$dir = $argv[1]."/backend/app/Services/Upgrade";
mkdir($dir, 0755, true);
touch($dir."/RedisDatabaseConfig.php");
' "$INSTALL_DIR"
        _separate_redis_cache_database >"$WRAPPER_DIR/output" 2>&1
        grep -Fq $'\033[0;34m[INFO]' "$WRAPPER_DIR/output"
        grep -Fq "Redis 最终编号（已分离，保持原样）：REDIS_DB=${runtime}，REDIS_CACHE_DB=$cache" "$WRAPPER_DIR/output"
    fi
done

# 真正调用分库实现，模拟 DB 2 非空、DB 3 空闲，验证最终提示与写入一致。
INSTALL_DIR="$FIXTURE_DIR/site-1-1"
"$TEST_PHP_BIN" -r '
$backend = $argv[1]."/backend";
mkdir($backend."/app/Services/Upgrade", 0755, true);
touch($backend."/app/Services/Upgrade/RedisDatabaseConfig.php");
$setup = <<<'"'"'PHP'"'"'
$probe = Mockery::mock();
Illuminate\Support\Facades\Cache::swap(Mockery::mock());
Illuminate\Support\Facades\Artisan::swap(Mockery::mock());
Illuminate\Support\Facades\Artisan::shouldReceive("bootstrap")->once();
$probe->shouldReceive("select")->with(2)->once()->andReturn(true);
$probe->shouldReceive("select")->with(3)->once()->andReturn(true);
$probe->shouldReceive("dbsize")->twice()->andReturn(1, 0);
$probe->shouldReceive("client")->once()->andReturnSelf();
$probe->shouldReceive("close")->once();
Illuminate\Support\Facades\Redis::shouldReceive("resolve")->with("cache")->once()->andReturn($probe);
Illuminate\Support\Facades\Cache::shouldReceive("store")->once()->andReturn(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore));
Illuminate\Support\Facades\Artisan::shouldReceive("call")->with("config:clear")->once()->andReturn(0);
PHP;
$path = $backend."/bootstrap/app.php";
file_put_contents($path, str_replace("return \$app;", $setup."\nreturn \$app;", file_get_contents($path)));
' "$INSTALL_DIR"
MANAGER_SITES_ROOT="$INSTALL_DIR"
if ! _separate_redis_cache_database >"$WRAPPER_DIR/output" 2>&1; then
    cat "$WRAPPER_DIR/output"
    exit 1
fi
grep -Fq $'\033[0;34m[INFO] Redis 最终编号：REDIS_DB=1，REDIS_CACHE_DB=3（缓存库由 1 自动调整为 3）\033[0m' "$WRAPPER_DIR/output"
grep -qx 'REDIS_CACHE_DB=3' "$INSTALL_DIR/backend/.env"
echo 'PASS: 自动分库后的彩色最终提示与实际编号 1/3 一致'

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
