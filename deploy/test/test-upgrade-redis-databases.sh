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

# 原编号一致时无须 Redis 服务；启用隔离实例后增加真实迁移及同库分配。
cases=('0 1 0 1' '5 6 5 6' '7 8 7 8')
if [ -n "${REDIS_UPGRADE_TEST_PORT:-}" ]; then
    [ "$REDIS_UPGRADE_TEST_PORT" != 6379 ]
    cases+=('3 4 1 2' '0 1 3 3' '0 0 0 0')
fi
for pair in "${cases[@]}"; do
    read -r runtime cache target_runtime target_cache <<<"$pair"
    INSTALL_DIR="$FIXTURE_DIR/site-$runtime-$cache-$target_runtime-$target_cache"
    MANAGER_SITES_ROOT="$INSTALL_DIR"
    "$TEST_PHP_BIN" -r '
$backend = $argv[1]."/backend";
mkdir($backend."/bootstrap", 0755, true);
if ($argv[3] !== "7") symlink($argv[2], $backend."/vendor");
file_put_contents($backend."/.env", "APP_NAME=original_manager\nREDIS_DB={$argv[5]}\nREDIS_CACHE_DB=14\nREDIS_CACHE_DB={$argv[6]}\n");
$config = [
    "cache" => ["default" => "redis", "prefix" => "cache_", "stores" => ["redis" => ["driver" => "redis", "connection" => "cache"]]],
    "queue" => ["default" => "database"],
    "database" => ["redis" => ["client" => "phpredis", "options" => ["prefix" => "manager_"],
        "default" => ["host" => "redis", "port" => (int) $argv[7], "database" => $argv[3]],
        "cache" => ["host" => "redis", "port" => (int) $argv[7], "database" => $argv[4]]]],
];
if ($argv[7] !== "0") {
    $redis = new Redis;
    $redis->connect("redis", (int) $argv[7]);
    $redis->flushAll();
    $redis->select((int) $argv[3]);
    $redis->rPush("manager_queues:tasks", "job");
    $redis->select((int) $argv[4]);
    $redis->set("manager_cache_value", "cached", ["px" => 60000]);
}
$bootstrap = <<<'"'"'PHP'"'"'
<?php
$app = new Illuminate\Foundation\Application(dirname(__DIR__));
$app->instance("files", new Illuminate\Filesystem\Filesystem);
$app->instance("config", new Illuminate\Config\Repository(CONFIG_PLACEHOLDER));
$app->instance(Illuminate\Contracts\Console\Kernel::class, new class {
    public function bootstrap(): void {}
    public function call($command, $arguments = []): int { return 0; }
});
$app->instance(Illuminate\Contracts\Foundation\MaintenanceMode::class, new class implements Illuminate\Contracts\Foundation\MaintenanceMode {
    public function activate(array $payload): void {}
    public function deactivate(): void {}
    public function active(): bool { return true; }
    public function data(): array { return []; }
});
$app->register(Illuminate\Cache\CacheServiceProvider::class);
$app->register(Illuminate\Redis\RedisServiceProvider::class);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
return $app;
PHP;
file_put_contents($backend."/bootstrap/app.php", str_replace("CONFIG_PLACEHOLDER", var_export($config, true), $bootstrap));
' "$INSTALL_DIR" "$ROOT/backend/vendor" "$runtime" "$cache" "$target_runtime" "$target_cache" "${REDIS_UPGRADE_TEST_PORT:-0}"

    BUNDLED_VENDOR_STAGE="$ROOT/backend/vendor"
    if ! _preserve_redis_databases "$ROOT" >"$WRAPPER_DIR/output" 2>&1; then
        cat "$WRAPPER_DIR/output"
        exit 1
    fi
    grep -qx "REDIS_DB=$target_runtime" "$INSTALL_DIR/backend/.env"
    [ "$(grep -c '^REDIS_DB=' "$INSTALL_DIR/backend/.env")" -eq 1 ]
    [ "$(grep -c '^REDIS_CACHE_DB=' "$INSTALL_DIR/backend/.env")" -eq 1 ]
    grep -qx 'APP_NAME=original_manager' "$INSTALL_DIR/backend/.env"
    grep -Fq "升级使用 REDIS_DB=$target_runtime" "$WRAPPER_DIR/output"
    if [ "$target_runtime" != "$target_cache" ]; then
        grep -qx "REDIS_CACHE_DB=$target_cache" "$INSTALL_DIR/backend/.env"
    fi
    if [ -n "${REDIS_UPGRADE_TEST_PORT:-}" ]; then
        "$TEST_PHP_BIN" -r '
require $argv[2]."/autoload.php";
$env = Dotenv\Dotenv::parse(file_get_contents($argv[1]."/backend/.env"));
if ($env["REDIS_DB"] === $env["REDIS_CACHE_DB"]) exit(1);
$r = new Redis;
$r->connect("redis", (int) $argv[3]);
$r->select((int) $env["REDIS_DB"]);
if ($r->lRange("manager_queues:tasks", 0, -1) !== ["job"]) exit(2);
$r->select((int) $env["REDIS_CACHE_DB"]);
if ($r->get("manager_cache_value") !== "cached" || $r->pttl("manager_cache_value") <= 0) exit(3);
' "$INSTALL_DIR" "$ROOT/backend/vendor" "$REDIS_UPGRADE_TEST_PORT"
    fi
    echo "PASS: 脚本 Redis ${runtime}/${cache} → env ${target_runtime}/${target_cache}，显式值优先、自动分库和去重"
done

# 迁移必须在 down/freeze 与启动独占锁之后、旧代码覆盖之前。
awk '
    /^    _acquire_bootstrap_lock$/ { lock=NR }
    /artisan down --retry/ { down=NR }
    /^    FREEZE_FIRED=1$/ { freeze=NR }
    /if ! _preserve_redis_databases/ { migration=NR }
    /# 6. 提取需要保留的文件/ { copy=NR }
    copy { exit !(lock && down && freeze && migration && lock < migration && down < migration && freeze < migration && migration < copy) }
' "$ROOT/deploy/upgrade.sh"
