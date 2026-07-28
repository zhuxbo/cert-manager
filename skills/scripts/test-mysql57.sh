#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

PROCESSES="${PROCESSES:-4}"
MYSQL57_CONTAINER="ssl-manager-mysql57-test-$$"
TEST_DATABASE="ssl_manager_test"
TEST_COLLATION="utf8mb4_unicode_ci"

if [[ ! "$PROCESSES" =~ ^[1-9][0-9]*$ ]]; then
    echo "错误：PROCESSES 必须是正整数，当前值：$PROCESSES" >&2
    exit 2
fi

cleanup() {
    local status=$?
    docker rm -f "$MYSQL57_CONTAINER" >/dev/null 2>&1 || true
    return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

app_container="$(docker compose ps -q app)"
if [[ -z "$app_container" ]] || [[ "$(docker inspect -f '{{.State.Running}}' "$app_container" 2>/dev/null)" != "true" ]]; then
    echo "错误：Compose app 容器未运行，请先执行 make up" >&2
    exit 1
fi

compose_network="$({
    docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$app_container"
} | head -n 1)"
if [[ -z "$compose_network" ]]; then
    echo "错误：无法确定 Compose app 容器所在网络" >&2
    exit 1
fi

echo "启动隔离 MySQL 5.7：database=$TEST_DATABASE collation=$TEST_COLLATION"
docker run --platform linux/amd64 --rm -d \
    --name "$MYSQL57_CONTAINER" \
    --network "$compose_network" \
    -e MYSQL_ROOT_PASSWORD=password \
    -e MYSQL_DATABASE="$TEST_DATABASE" \
    mysql:5.7 \
    --character-set-server=utf8mb4 \
    --collation-server="$TEST_COLLATION" >/dev/null

ready=false
for _ in $(seq 1 60); do
    if docker exec "$MYSQL57_CONTAINER" mysqladmin ping -h127.0.0.1 -uroot -ppassword --silent >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 2
done

if [[ "$ready" != "true" ]]; then
    echo "错误：MySQL 5.7 在 120 秒内未就绪" >&2
    docker logs --tail 50 "$MYSQL57_CONTAINER" >&2 || true
    exit 1
fi

db_env=(
    -e DB_CONNECTION=mysql
    -e DB_HOST="$MYSQL57_CONTAINER"
    -e DB_PORT=3306
    -e DB_DATABASE="$TEST_DATABASE"
    -e DB_USERNAME=root
    -e DB_PASSWORD=password
    -e DB_COLLATION="$TEST_COLLATION"
)

echo "运行 MySQL 5.7 主迁移..."
docker compose exec -T "${db_env[@]}" app \
    php artisan migrate --force --path=database/migrations

echo "运行 MySQL 5.7 全量测试（processes=${PROCESSES}）..."
docker compose exec -T "${db_env[@]}" app \
    php artisan test --parallel --processes="$PROCESSES"
