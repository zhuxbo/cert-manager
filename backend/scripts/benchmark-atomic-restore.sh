#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 || $# -gt 3 ]]; then
    echo "用法: $0 <backup.sql.gz> <backup.schema.json> [5.7|8.0|8.4]" >&2
    exit 2
fi

dump_path="$1"
schema_path="$2"
series="${3:-8.4}"
if [[ ! -f "$dump_path" || ! -f "$schema_path" ]]; then
    echo "错误：备份 SQL 或 Schema 文件不存在" >&2
    exit 2
fi
dump_path="$(cd "$(dirname "$dump_path")" && pwd)/$(basename "$dump_path")"
schema_path="$(cd "$(dirname "$schema_path")" && pwd)/$(basename "$schema_path")"

case "$series" in
    5.7)
        image="mysql:5.7"
        platform_name="linux/amd64"
        ;;
    8.0)
        image="mysql:8.0"
        platform_name=""
        ;;
    8.4)
        image="mysql:8.4"
        platform_name=""
        ;;
    *)
        echo "错误：系列必须是 5.7、8.0 或 8.4" >&2
        exit 2
        ;;
esac

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"
for command_name in docker gzip perl awk; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "错误：缺少命令 $command_name" >&2
        exit 1
    fi
done

app_container="$(docker compose ps -q app)"
if [[ -z "$app_container" ]] || [[ "$(docker inspect -f '{{.State.Running}}' "$app_container" 2>/dev/null)" != "true" ]]; then
    echo "错误：Compose app 容器未运行，请先执行 make up" >&2
    exit 1
fi

work_dir="$(mktemp -d)"
container="ssl-manager-atomic-benchmark-${series/./}-$$"
container_schema="/tmp/atomic-benchmark-schema-$$.json"
metrics_name="atomic-benchmark-metrics-$$.json"
container_metrics="/tmp/$metrics_name"
cleanup() {
    local status=$?
    docker rm -f "$container" >/dev/null 2>&1 || true
    docker exec "$app_container" rm -f "$container_schema" "$container_metrics" >/dev/null 2>&1 || true
    rm -rf "$work_dir"
    return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

docker cp "$schema_path" "$app_container:$container_schema"

echo "生成流式改写后的压缩基准输入（不落完整解压 SQL）..."
transformed="$work_dir/transformed.sql.gz"
gzip -dc "$dump_path" |
    docker compose exec -T -e ATOMIC_RESTORE_METRICS_FILE="$container_metrics" \
        app php scripts/atomic-restore-rewrite.php "$container_schema" abcdef123456 |
    gzip -1 -c >"$transformed"
docker cp "$app_container:$container_metrics" "$work_dir/prepare-metrics.json" >/dev/null

if [[ -n "$platform_name" ]]; then
    docker run --platform "$platform_name" --rm -d --name "$container" \
        -e MYSQL_ROOT_PASSWORD=benchmark-password \
        "$image" --character-set-server=utf8mb4 >/dev/null
else
    docker run --rm -d --name "$container" \
        -e MYSQL_ROOT_PASSWORD=benchmark-password \
        "$image" --character-set-server=utf8mb4 >/dev/null
fi
ready=false
for _ in $(seq 1 90); do
    if docker exec -e MYSQL_PWD=benchmark-password "$container" \
        mysqladmin ping -h127.0.0.1 -uroot --silent >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 2
done
if [[ "$ready" != true ]]; then
    docker logs --tail 80 "$container" >&2 || true
    echo "错误：MySQL $series 未就绪" >&2
    exit 1
fi

docker exec "$container" mysql --version
docker exec "$container" mysqldump --version
docker exec -e MYSQL_PWD=benchmark-password "$container" mysql -uroot -Nse \
    'SELECT VERSION(), @@version_comment'
docker exec -e MYSQL_PWD=benchmark-password "$container" mysql -uroot -e \
    'CREATE DATABASE atomic_benchmark_raw CHARACTER SET utf8mb4; CREATE DATABASE atomic_benchmark_rewrite CHARACTER SET utf8mb4;'

now_seconds() {
    perl -MTime::HiRes=time -e 'printf "%.9f", time'
}

raw_start="$(now_seconds)"
gzip -dc "$transformed" |
    docker exec -i -e MYSQL_PWD=benchmark-password "$container" \
        mysql -uroot atomic_benchmark_raw
raw_end="$(now_seconds)"

rewrite_start="$(now_seconds)"
gzip -dc "$dump_path" |
    docker compose exec -T -e ATOMIC_RESTORE_METRICS_FILE="$container_metrics" \
        app php scripts/atomic-restore-rewrite.php "$container_schema" abcdef123456 |
    docker exec -i -e MYSQL_PWD=benchmark-password "$container" \
        mysql -uroot atomic_benchmark_rewrite
rewrite_end="$(now_seconds)"
docker cp "$app_container:$container_metrics" "$work_dir/rewrite-metrics.json" >/dev/null

read -r input_bytes output_bytes peak_memory_bytes < <(
    docker compose exec -T app php -r '
        $m = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        echo $m["input_bytes"], " ", $m["output_bytes"], " ", $m["peak_memory_bytes"], "\n";
    ' "$container_metrics"
)
raw_seconds="$(awk -v start="$raw_start" -v end="$raw_end" 'BEGIN { printf "%.6f", end-start }')"
rewrite_seconds="$(awk -v start="$rewrite_start" -v end="$rewrite_end" 'BEGIN { printf "%.6f", end-start }')"
raw_mib_s="$(awk -v bytes="$output_bytes" -v seconds="$raw_seconds" 'BEGIN { printf "%.2f", bytes/1048576/seconds }')"
rewrite_mib_s="$(awk -v bytes="$input_bytes" -v seconds="$rewrite_seconds" 'BEGIN { printf "%.2f", bytes/1048576/seconds }')"
ratio="$(awk -v raw="$raw_mib_s" -v rewrite="$rewrite_mib_s" 'BEGIN { printf "%.4f", rewrite/raw }')"
peak_mib="$(awk -v bytes="$peak_memory_bytes" 'BEGIN { printf "%.2f", bytes/1048576 }')"

printf 'raw:      %s s, %s MiB/s\n' "$raw_seconds" "$raw_mib_s"
printf 'rewriter: %s s, %s MiB/s\n' "$rewrite_seconds" "$rewrite_mib_s"
printf 'ratio:    %s%%（门槛 >= 70%%）\n' "$(awk -v ratio="$ratio" 'BEGIN { printf "%.2f", ratio*100 }')"
printf 'PHP peak: %s MiB（相对 raw 的附加进程内存门槛 <= 64 MiB）\n' "$peak_mib"

if ! awk -v ratio="$ratio" 'BEGIN { exit !(ratio >= 0.70) }'; then
    echo "FAIL：改写吞吐低于 raw 的 70%" >&2
    exit 1
fi
if ! awk -v bytes="$peak_memory_bytes" 'BEGIN { exit !(bytes <= 67108864) }'; then
    echo "FAIL：PHP 峰值内存超过 64 MiB" >&2
    exit 1
fi

echo "PASS：原始导入与流式改写导入性能门槛通过"
