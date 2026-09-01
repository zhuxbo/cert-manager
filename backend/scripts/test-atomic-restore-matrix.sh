#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

series_list=(5.7 8.0 8.4)
if [[ -n "${MYSQL_SERIES:-}" ]]; then
    read -r -a series_list <<<"$MYSQL_SERIES"
fi

for command_name in docker gzip; do
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
containers=()
cleanup() {
    local status=$?
    for container in "${containers[@]}"; do
        docker rm -f "$container" >/dev/null 2>&1 || true
    done
    docker exec "$app_container" rm -f /tmp/atomic-matrix-schema.json >/dev/null 2>&1 || true
    rm -rf "$work_dir"
    return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

fixture_sql="$work_dir/legacy-mariadb-client.sql"
fixture_schema="$work_dir/fixture.schema.json"
docker compose exec -T app php -r \
    'require "vendor/autoload.php"; echo (new Tests\Support\Backup\SqlDumpFixtureBuilder)->dump(128);' \
    >"$fixture_sql"
docker compose exec -T app php -r \
    'require "vendor/autoload.php"; echo json_encode((new Tests\Support\Backup\SqlDumpFixtureBuilder)->schema("8.0", false, "mariadb"), JSON_THROW_ON_ERROR);' \
    >"$fixture_schema"
docker cp "$fixture_schema" "$app_container:/tmp/atomic-matrix-schema.json"

run_mysql() {
    local container="$1"
    shift
    docker exec -e MYSQL_PWD=matrix-password "$container" mysql -uroot "$@"
}

prepare_retained_and_runtime() {
    local container="$1" database="$2" token="$3"
    run_mysql "$container" "$database" -e "
CREATE TABLE activity_logs (id bigint unsigned NOT NULL, user_id bigint unsigned NULL, message varchar(64) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB;
CREATE TABLE easy_logs LIKE activity_logs;
CREATE TABLE cloud_deploy_logs LIKE activity_logs;
INSERT INTO activity_logs VALUES (1,999,'core retained');
INSERT INTO easy_logs VALUES (2,999,'easy retained');
INSERT INTO cloud_deploy_logs VALUES (3,999,'cloud retained');
CREATE TABLE __rst_${token}_jobs (id bigint unsigned NOT NULL AUTO_INCREMENT, payload longtext NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB;
CREATE TABLE __rst_${token}_admin_refresh_tokens (id bigint unsigned NOT NULL AUTO_INCREMENT, token varchar(255) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB;
CREATE TABLE __rst_${token}_user_refresh_tokens (id bigint unsigned NOT NULL AUTO_INCREMENT, token varchar(255) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB;"
}

for series in "${series_list[@]}"; do
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
            echo "错误：仅支持矩阵系列 5.7、8.0、8.4，收到 $series" >&2
            exit 2
            ;;
    esac

    safe_series="${series/./}"
    container="ssl-manager-atomic-matrix-${safe_series}-$$"
    containers+=("$container")
    echo "═══ MySQL $series ═══"
    if [[ -n "$platform_name" ]]; then
        docker run --platform "$platform_name" --rm -d --name "$container" \
            -e MYSQL_ROOT_PASSWORD=matrix-password \
            "$image" --character-set-server=utf8mb4 >/dev/null
    else
        docker run --rm -d --name "$container" \
            -e MYSQL_ROOT_PASSWORD=matrix-password \
            "$image" --character-set-server=utf8mb4 >/dev/null
    fi

    ready=false
    for _ in $(seq 1 90); do
        if docker exec -e MYSQL_PWD=matrix-password "$container" \
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
    run_mysql "$container" -Nse "SELECT VERSION(), @@version_comment"

    database="atomic_restore_matrix"
    run_mysql "$container" -e "CREATE DATABASE $database CHARACTER SET utf8mb4"
    token="abcdef123456"
    prepare_retained_and_runtime "$container" "$database" "$token"
    checksum_before="$(run_mysql "$container" "$database" -Nse "
SELECT SUM(v) FROM (
 SELECT CRC32(CONCAT_WS('#',id,user_id,message)) v FROM activity_logs
 UNION ALL SELECT CRC32(CONCAT_WS('#',id,user_id,message)) FROM easy_logs
 UNION ALL SELECT CRC32(CONCAT_WS('#',id,user_id,message)) FROM cloud_deploy_logs
) retained")"

    docker compose exec -T app php scripts/atomic-restore-rewrite.php \
        /tmp/atomic-matrix-schema.json "$token" <"$fixture_sql" |
        docker exec -i -e MYSQL_PWD=matrix-password "$container" mysql -uroot "$database"

    run_mysql "$container" "$database" -e "
ALTER TABLE __rst_${token}_task12_children
  ADD CONSTRAINT task12_children_parent_fk
  FOREIGN KEY (parent_id) REFERENCES __rst_${token}_task12_parents (id)
  ON DELETE RESTRICT ON UPDATE CASCADE;
RENAME TABLE
  __rst_${token}_admins TO admins,
  __rst_${token}_users TO users,
  __rst_${token}_transactions TO transactions,
  __rst_${token}_task12_parents TO task12_parents,
  __rst_${token}_task12_children TO task12_children,
  __rst_${token}_agisos TO agisos,
  __rst_${token}_jobs TO jobs,
  __rst_${token}_admin_refresh_tokens TO admin_refresh_tokens,
  __rst_${token}_user_refresh_tokens TO user_refresh_tokens;"

    checksum_after="$(run_mysql "$container" "$database" -Nse "
SELECT SUM(v) FROM (
 SELECT CRC32(CONCAT_WS('#',id,user_id,message)) v FROM activity_logs
 UNION ALL SELECT CRC32(CONCAT_WS('#',id,user_id,message)) FROM easy_logs
 UNION ALL SELECT CRC32(CONCAT_WS('#',id,user_id,message)) FROM cloud_deploy_logs
) retained")"
    [[ "$checksum_before" == "$checksum_after" ]] || {
        echo "错误：保留日志校验和变化" >&2
        exit 1
    }
    [[ "$(run_mysql "$container" "$database" -Nse 'SELECT COUNT(*) FROM jobs')" == 0 ]] || exit 1
    [[ "$(run_mysql "$container" "$database" -Nse 'SELECT COUNT(*) FROM admin_refresh_tokens')" == 0 ]] || exit 1
    [[ "$(run_mysql "$container" "$database" -Nse 'SELECT COUNT(*) FROM user_refresh_tokens')" == 0 ]] || exit 1
    [[ "$(run_mysql "$container" "$database" -Nse 'SELECT id FROM users ORDER BY id LIMIT 1')" == 718793000000000000 ]] || exit 1
    [[ "$(run_mysql "$container" "$database" -Nse 'SELECT HEX(payload) FROM agisos WHERE id=1')" == 000102FFCAFE ]] || exit 1
    [[ "$(run_mysql "$container" "$database" -Nse "SELECT AUTO_INCREMENT >= 42 FROM information_schema.TABLES WHERE TABLE_SCHEMA='$database' AND TABLE_NAME='admins'")" == 1 ]] || exit 1
    fk_facts="$(run_mysql "$container" "$database" -Nse "SELECT CONCAT(CONSTRAINT_NAME,':',REFERENCED_TABLE_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$database' AND TABLE_NAME='task12_children' AND REFERENCED_TABLE_NAME IS NOT NULL")"
    [[ "$fk_facts" == task12_children_parent_fk:task12_parents ]] || {
        echo "错误：外键事实异常：$fk_facts" >&2
        exit 1
    }

    official_dump="$work_dir/mysql-${safe_series}-official.sql.gz"
    dump_options=(
        --single-transaction --quick --skip-lock-tables --no-tablespaces
        --set-gtid-purged=OFF --default-character-set=utf8mb4 --hex-blob --add-drop-table
    )
    if [[ "$series" != 5.7 ]]; then
        dump_options+=(--column-statistics=0)
    fi
    docker exec -e MYSQL_PWD=matrix-password "$container" mysqldump -uroot \
        "${dump_options[@]}" \
        --ignore-table="$database.activity_logs" \
        --ignore-table="$database.easy_logs" \
        --ignore-table="$database.cloud_deploy_logs" \
        --ignore-table="$database.jobs" \
        --ignore-table="$database.admin_refresh_tokens" \
        --ignore-table="$database.user_refresh_tokens" \
        "$database" | gzip -1 -c >"$official_dump"

    restore_db="atomic_restore_official"
    run_mysql "$container" -e "CREATE DATABASE $restore_db CHARACTER SET utf8mb4"
    official_token="654321abcdef"
    gzip -dc "$official_dump" |
        docker compose exec -T app php scripts/atomic-restore-rewrite.php \
            /tmp/atomic-matrix-schema.json "$official_token" |
        docker exec -i -e MYSQL_PWD=matrix-password "$container" mysql -uroot "$restore_db"
    [[ "$(run_mysql "$container" "$restore_db" -Nse "SELECT COUNT(*) FROM __rst_${official_token}_users")" == 128 ]] || exit 1
    [[ "$(run_mysql "$container" "$restore_db" -Nse "SELECT COUNT(*) FROM __rst_${official_token}_transactions")" == 3 ]] || exit 1

    rm -f "$official_dump"
    docker rm -f "$container" >/dev/null
    echo "PASS MySQL ${series}：官方同系列备份/恢复与历史 MariaDB-client 文件输入"
done
