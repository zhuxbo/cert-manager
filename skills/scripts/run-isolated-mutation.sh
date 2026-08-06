#!/usr/bin/env bash
# 在忽略目录的源码快照和一次性容器中运行 mutation，避免污染工作树或共享 app 容器。

set -euo pipefail
umask 077

PROJECT_ROOT="$(git rev-parse --show-toplevel)"
WORKSPACE_ROOT="$PROJECT_ROOT/.superpowers/mutation-workspaces"
mkdir -p "$WORKSPACE_ROOT"

LIFECYCLE_SMOKE_SECONDS=""
DRY_RUN=0
PLUGIN_WRITE_SMOKE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --lifecycle-smoke-seconds)
            LIFECYCLE_SMOKE_SECONDS="${2:-}"
            if [[ ! "$LIFECYCLE_SMOKE_SECONDS" =~ ^[1-9][0-9]*$ ]]; then
                echo "--lifecycle-smoke-seconds 需要正整数" >&2
                exit 2
            fi
            shift 2
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --plugin-write-smoke)
            PLUGIN_WRITE_SMOKE=1
            shift
            ;;
        *)
            break
            ;;
    esac
done

WORKSPACE="$(mktemp -d "$WORKSPACE_ROOT/run-XXXXXXXX")"
BACKEND_SNAPSHOT="$WORKSPACE/backend"
PLUGINS_SNAPSHOT="$WORKSPACE/plugins"
PEST_TEMP="$WORKSPACE/pest-temp"
PEST_MUTATE_TEMP="$WORKSPACE/pest-mutate-temp"
PEST_MUTATIONS="$PEST_MUTATE_TEMP/mutations"
PEST_MUTATE_CACHE="${MUTATION_PEST_CACHE_DIR:-$PEST_MUTATE_TEMP/pest-mutate-cache}"
SEED_LOG="$WORKSPACE/docker-seed.log"
CONTAINER_NAME="ssl-manager-mutation-$(basename "$WORKSPACE")-$$"
DB_CONTAINER_NAME="${CONTAINER_NAME}-mysql"
SEED_CONTAINER_NAME="${CONTAINER_NAME}-seed"
APP_VOLUME_NAME="${CONTAINER_NAME}-app-data"
PLUGINS_VOLUME_NAME="${CONTAINER_NAME}-plugins-data"
KEEP_WORKSPACE="${MUTATION_KEEP_WORKSPACE:-0}"
DOCKER_CLIENT_PID=""
APP_CONTAINER_STARTED=0
DB_CONTAINER_STARTED=0
SEED_CONTAINER_STARTED=0
APP_VOLUME_CREATED=0
PLUGINS_VOLUME_CREATED=0

is_missing_container_error() {
    local container="$1"
    local output="$2"

    [[ "$output" == *"No such container: $container"* ]] ||
        [[ "$output" == *"no such container: $container"* ]] ||
        [[ "$output" == *"No such object: $container"* ]] ||
        [[ "$output" == *"no such object: $container"* ]]
}

is_missing_volume_error() {
    local volume="$1"
    local output="$2"

    [[ "$output" == *"No such volume: $volume"* ]] ||
        [[ "$output" == *"no such volume: $volume"* ]] ||
        [[ "$output" == *"get $volume: no such volume"* ]]
}

remove_and_confirm_container() {
    local container="$1"
    local attempt
    local inspect_output
    local inspect_rc
    local last_error=""
    local rm_output
    local rm_rc

    for attempt in 1 2 3 4 5; do
        set +e
        rm_output="$(docker rm -f "$container" 2>&1)"
        rm_rc=$?
        set -e
        if [[ "$rm_rc" -ne 0 ]] &&
            ! is_missing_container_error "$container" "$rm_output"; then
            last_error="$rm_output"
        fi

        set +e
        inspect_output="$(docker inspect "$container" 2>&1)"
        inspect_rc=$?
        set -e
        if [[ "$inspect_rc" -ne 0 ]]; then
            if is_missing_container_error "$container" "$inspect_output"; then
                return 0
            fi
            last_error="$inspect_output"
        elif [[ -z "$last_error" ]]; then
            last_error="container 仍然存在"
        fi
        sleep 0.1
    done

    echo "无法确认 container 已删除: $container: ${last_error:-Docker 返回未知错误}" >&2
    return 1
}

remove_and_confirm_volume() {
    local volume="$1"
    local attempt
    local inspect_output
    local inspect_rc
    local last_error=""
    local rm_output
    local rm_rc

    for attempt in 1 2 3 4 5; do
        set +e
        rm_output="$(docker volume rm -f "$volume" 2>&1)"
        rm_rc=$?
        set -e
        if [[ "$rm_rc" -ne 0 ]] &&
            ! is_missing_volume_error "$volume" "$rm_output"; then
            last_error="$rm_output"
        fi

        set +e
        inspect_output="$(docker volume inspect "$volume" 2>&1)"
        inspect_rc=$?
        set -e
        if [[ "$inspect_rc" -ne 0 ]]; then
            if is_missing_volume_error "$volume" "$inspect_output"; then
                return 0
            fi
            last_error="$inspect_output"
        elif [[ -z "$last_error" ]]; then
            last_error="volume 仍然存在"
        fi
        sleep 0.1
    done

    echo "无法确认 volume 已删除: $volume: ${last_error:-Docker 返回未知错误}" >&2
    return 1
}

cleanup() {
    local rc=$?
    trap - EXIT INT TERM HUP

    # docker compose 客户端被中断时，显式删除具名容器并确认它已退出，
    # 之后外层 finish-check 执行器才会释放 backend-runtime / db 锁。
    if [[ -n "$DOCKER_CLIENT_PID" ]]; then
        kill "$DOCKER_CLIENT_PID" >/dev/null 2>&1 || true
        # 必须先等 docker 客户端完全退出，再删除具名容器。否则 compose run
        # 仍在创建容器时 rm 会先返回“not found”，随后容器才启动并永久残留。
        wait "$DOCKER_CLIENT_PID" >/dev/null 2>&1 || true
        DOCKER_CLIENT_PID=""
    fi
    if [[ "$APP_CONTAINER_STARTED" == "1" ]]; then
        remove_and_confirm_container "$CONTAINER_NAME" || rc=1
    fi
    if [[ "$SEED_CONTAINER_STARTED" == "1" ]]; then
        remove_and_confirm_container "$SEED_CONTAINER_NAME" || rc=1
    fi
    if [[ "$DB_CONTAINER_STARTED" == "1" ]]; then
        remove_and_confirm_container "$DB_CONTAINER_NAME" || rc=1
    fi
    if [[ "$APP_VOLUME_CREATED" == "1" ]]; then
        remove_and_confirm_volume "$APP_VOLUME_NAME" || rc=1
    fi
    if [[ "$PLUGINS_VOLUME_CREATED" == "1" ]]; then
        remove_and_confirm_volume "$PLUGINS_VOLUME_NAME" || rc=1
    fi
    if [[ -d "$PEST_MUTATE_CACHE" ]] &&
        ! chmod -R go-rwx "$PEST_MUTATE_CACHE"; then
        echo "无法收紧 mutation Pest cache 权限: $PEST_MUTATE_CACHE" >&2
        rc=1
    fi

    if [[ "$KEEP_WORKSPACE" != "1" ]]; then
        rm -rf "$WORKSPACE"
    else
        printf 'Mutation workspace retained: %s\n' "$WORKSPACE" >&2
    fi
    exit "$rc"
}
trap cleanup EXIT INT TERM HUP

if [[ -n "${MUTATION_PEST_CACHE_DIR:-}" ]] &&
    [[ "$PEST_MUTATE_CACHE" != "$PROJECT_ROOT/.superpowers/mutation-pest-cache/"* ]]; then
    echo "MUTATION_PEST_CACHE_DIR 必须位于项目 .superpowers/mutation-pest-cache/" >&2
    exit 2
fi

mkdir -p \
    "$BACKEND_SNAPSHOT" \
    "$PLUGINS_SNAPSHOT" \
    "$PEST_TEMP" \
    "$PEST_MUTATIONS" \
    "$PEST_MUTATE_CACHE"
chmod 0700 "$PEST_MUTATE_CACHE"
rsync -a \
    --exclude vendor \
    --exclude storage \
    --exclude 'bootstrap/cache/*.php' \
    "$PROJECT_ROOT/backend/" "$BACKEND_SNAPSHOT/"
while IFS= read -r tracked_gitignore; do
    relative_gitignore="${tracked_gitignore#backend/}"
    snapshot_gitignore="$BACKEND_SNAPSHOT/$relative_gitignore"
    mkdir -p "$(dirname "$snapshot_gitignore")"
    cp "$PROJECT_ROOT/$tracked_gitignore" "$snapshot_gitignore"
done < <(git -C "$PROJECT_ROOT" ls-files 'backend/storage/**/.gitignore')
rsync -a \
    --exclude node_modules \
    --exclude vendor \
    --exclude dist \
    --exclude temp \
    "$PROJECT_ROOT/plugins/" "$PLUGINS_SNAPSHOT/"

# 正式分片只运行其已指纹化的精确测试集。Pest 4 的 mutant 超时预算由覆盖
# 基线总时长推导；窄测试集会把预算压到数秒并制造假 timeout。这个不覆盖业务
# 代码的固定基线测试只保留与全树基线相当的保守预算，正式汇总仍强制 timeout=0。
if [[ "${MUTATION_FORMAL_SCOPE:-0}" == "1" ]]; then
    TIMEOUT_BUDGET_TEST="$BACKEND_SNAPSHOT/tests/Unit/MutationTimeoutBudgetTest.php"
    MUTATION_PHPUNIT_CONFIG="$BACKEND_SNAPSHOT/phpunit.mutation.xml"
    printf '%s\n' \
        '<?php' \
        '' \
        "it('reserves a conservative mutation timeout budget', function (): void {" \
        '    sleep(90);' \
        '    expect(true)->toBeTrue();' \
        '});' >"$TIMEOUT_BUDGET_TEST"
    python3 - "$BACKEND_SNAPSHOT/phpunit.xml" "$MUTATION_PHPUNIT_CONFIG" "$@" <<'PY'
import sys
import xml.etree.ElementTree as ET

source, destination, *test_paths = sys.argv[1:]
if not test_paths:
    raise SystemExit("正式 mutation 测试范围为空")
tree = ET.parse(source)
root = tree.getroot()
testsuites = root.find("testsuites")
if testsuites is None:
    raise SystemExit("phpunit.xml 缺少 testsuites")
testsuites.clear()
suite = ET.SubElement(testsuites, "testsuite", {"name": "MutationScope"})
for path in [*test_paths, "tests/Unit/MutationTimeoutBudgetTest.php"]:
    if not path.startswith("tests/") or not path.endswith("Test.php"):
        raise SystemExit(f"无效正式 mutation 测试路径: {path}")
    ET.SubElement(suite, "file").text = path
tree.write(destination, encoding="UTF-8", xml_declaration=True)
PY
    set -- --configuration=phpunit.mutation.xml --testsuite=MutationScope --profile
fi

mkdir -p \
    "$BACKEND_SNAPSHOT/bootstrap/cache" \
    "$BACKEND_SNAPSHOT/storage/app/private" \
    "$BACKEND_SNAPSHOT/storage/app/public" \
    "$BACKEND_SNAPSHOT/storage/framework/cache/data" \
    "$BACKEND_SNAPSHOT/storage/framework/sessions" \
    "$BACKEND_SNAPSHOT/storage/framework/testing" \
    "$BACKEND_SNAPSHOT/storage/framework/views" \
    "$BACKEND_SNAPSHOT/storage/logs" \
    "$BACKEND_SNAPSHOT/storage/pay"

# macOS 的 bind mount 适合只做一次输入。先把冻结快照复制到 Docker 原生
# volume，正式 mutation 期间不再跨宿主文件系统读取大量 PHP/vendor 小文件。
touch "$SEED_LOG"
chmod 0600 "$SEED_LOG"
APP_VOLUME_CREATED=1
docker volume create "$APP_VOLUME_NAME" >/dev/null
PLUGINS_VOLUME_CREATED=1
docker volume create "$PLUGINS_VOLUME_NAME" >/dev/null
SEED_CONTAINER_STARTED=1
DB_CONTAINER_STARTED=1
docker compose \
    --project-directory "$PROJECT_ROOT" \
    --profile tools \
    run --rm --no-deps \
    --name "$SEED_CONTAINER_NAME" \
    --entrypoint /bin/bash \
    -v "$BACKEND_SNAPSHOT:/source:ro" \
    -v "$PROJECT_ROOT/backend/vendor:/source-vendor:ro" \
    -v "$PLUGINS_SNAPSHOT:/source-plugins:ro" \
    -v "$APP_VOLUME_NAME:/native-app" \
    -v "$PLUGINS_VOLUME_NAME:/native-plugins" \
    mutation-app \
    -lc 'set -euo pipefail
cp -a /source/. /native-app/
mkdir -p /native-app/vendor
cp -a /source-vendor/. /native-app/vendor/
cp -a /source-plugins/. /native-plugins/
rm -rf \
    /native-app/vendor/pestphp/pest/.temp \
    /native-app/vendor/pestphp/pest-plugin-mutate/.temp
mkdir -p \
    /native-app/vendor/pestphp/pest/.temp \
    /native-app/vendor/pestphp/pest-plugin-mutate/.temp/mutations \
    /native-app/vendor/pestphp/pest-plugin-mutate/.temp/pest-mutate-cache' \
    >"$SEED_LOG" 2>&1 &
DOCKER_CLIENT_PID=$!
set +e
wait "$DOCKER_CLIENT_PID"
seed_rc=$?
set -e
DOCKER_CLIENT_PID=""
if [[ "$seed_rc" -ne 0 ]]; then
    cat "$SEED_LOG" >&2
    echo "mutation 原生 volume 初始化失败" >&2
    exit "$seed_rc"
fi
remove_and_confirm_container "$SEED_CONTAINER_NAME"
SEED_CONTAINER_STARTED=0

# mutation 不共用开发 MySQL：每次启动一个数据目录在 tmpfs 的一次性实例。
# 它仍使用 MySQL 8.4/InnoDB 及完整事务/外键/唯一索引语义，仅放宽崩溃耐久性。
docker compose \
    --project-directory "$PROJECT_ROOT" \
    --profile tools \
    run --rm --no-deps -d \
    --name "$DB_CONTAINER_NAME" \
    mutation-mysql >/dev/null

db_ready=0
for _ in $(seq 1 90); do
    if docker exec "$DB_CONTAINER_NAME" \
        mysqladmin ping -h 127.0.0.1 -uroot -ppassword --silent \
        >/dev/null 2>&1; then
        db_ready=1
        break
    fi
    if ! docker inspect "$DB_CONTAINER_NAME" >/dev/null 2>&1; then
        echo "mutation MySQL 容器在就绪前退出" >&2
        exit 1
    fi
    sleep 1
done
if [[ "$db_ready" != "1" ]]; then
    echo "mutation MySQL 90 秒内未就绪" >&2
    exit 1
fi

docker_args=(
    compose
    --project-directory "$PROJECT_ROOT"
    run --rm --no-deps
    --name "$CONTAINER_NAME"
    --entrypoint /bin/bash
    -e "DB_HOST=$DB_CONTAINER_NAME"
    -e DB_PORT=3306
    -e DB_DATABASE=ssl_manager_test
    -e DB_USERNAME=root
    -e DB_PASSWORD=password
    -e MUTATE_MIN_MSI=0
    -e "MUTATE_TARGET_CLASSES=${MUTATE_TARGET_CLASSES:-}"
    -e "MUTATE_TARGET_PATHS=${MUTATE_TARGET_PATHS:-}"
    -e "MUTATION_FORMAL_SCOPE=${MUTATION_FORMAL_SCOPE:-0}"
    -e COLUMNS=200
    -e "MUTATE_DRY_RUN=$DRY_RUN"
    -v "$APP_VOLUME_NAME:/var/www"
    -v "$PLUGINS_VOLUME_NAME:/var/plugins"
)
if [[ -n "${MUTATION_PEST_CACHE_DIR:-}" ]]; then
    docker_args+=(
        -v "$PEST_MUTATE_CACHE:/var/www/vendor/pestphp/pest-plugin-mutate/.temp/pest-mutate-cache"
    )
fi
docker_args+=(mutation-app)

APP_CONTAINER_STARTED=1
if [[ -n "$LIFECYCLE_SMOKE_SECONDS" ]]; then
    docker "${docker_args[@]}" \
        -lc 'exec sleep "$1"' bash "$LIFECYCLE_SMOKE_SECONDS" &
elif [[ "$PLUGIN_WRITE_SMOKE" == "1" ]]; then
    docker "${docker_args[@]}" \
        -lc 'exec php artisan test tests/Feature/Services/PluginManagerMigrationRollbackTest.php' &
else
    docker "${docker_args[@]}" \
        -lc 'umask 077
php artisan migrate --force --no-interaction
exec bash scripts/test-mutate.sh "$@"' bash "$@" &
fi
DOCKER_CLIENT_PID=$!

set +e
wait "$DOCKER_CLIENT_PID"
rc=$?
set -e
DOCKER_CLIENT_PID=""
exit "$rc"
