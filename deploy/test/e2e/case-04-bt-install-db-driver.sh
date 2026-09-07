#!/usr/bin/env bash
# e2e 场景 4：bt-install.sh mysql 单驱动 + .env 生成契约
#
# 验证：
# - 拒绝 --db-password=xxx 命令行明文（与 admin 密码同安全策略）
# - --db-password-file=PATH 销毁逻辑就位
# - generate_env_file / select_db_driver / collect_db_credentials / run_artisan_install / setup_admin_password 5 函数已加
# - 不残留 web 安装向导（backend/public/install.php + install-assets/ 已删除）

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

E2E_TMPDIR="$(mktemp -d)"
trap e2e_cleanup EXIT

e2e_step "case 04: bt-install.sh mysql 单驱动 + .env 生成 + web 向导删除契约"

BT_INSTALL="$E2E_REPO_ROOT/deploy/scripts/bt-install.sh"

# === 测试 1：web 安装向导彻底删除 ===
e2e_log "1. backend/public/install.php + install-assets/ 已删除"
if [ ! -f "$E2E_REPO_ROOT/backend/public/install.php" ]; then
    e2e_pass "install.php 已删除"
else
    e2e_fail "install.php 仍存在（要求删除）"
fi

if [ ! -d "$E2E_REPO_ROOT/backend/public/install-assets" ]; then
    e2e_pass "install-assets/ 目录已删除"
else
    e2e_fail "install-assets/ 仍存在"
fi

# === 测试 2：bt-install.sh 包含 5 个函数 ===
e2e_log "2. bt-install.sh 含 mysql + artisan 流程函数"
for fn in select_db_driver collect_db_credentials generate_env_file run_artisan_install setup_admin_password _set_env_var; do
    if grep -qE "^${fn}\(\)" "$BT_INSTALL"; then
        e2e_pass "函数 $fn 已定义"
    else
        e2e_fail "函数 $fn 缺失"
    fi
done

# === 测试 3：命令行参数 --db / --db-host / --db-port 等解析 case 完整 ===
e2e_log "3. 命令行参数 case 完整"
for arg in '--db=' '--db-host=' '--db-port=' '--db-database=' '--db-username='; do
    if grep -qF -e "${arg}*" "$BT_INSTALL"; then
        e2e_pass "$arg 参数 case 已实现"
    else
        e2e_fail "$arg 参数 case 缺失"
    fi
done

# === 测试 4：拒绝 --db-password=xxx 明文 ===
e2e_log "4. 拒绝 --db-password 明文"
out=$(bash "$BT_INSTALL" --db-password=secret 2>&1 || echo "EXIT=$?")
if echo "$out" | grep -q "拒绝 --db-password=xxx"; then
    e2e_pass "--db-password=secret 被立即拒绝（同 --admin-password 安全策略）"
else
    e2e_fail "--db-password=secret 未被拒绝；output=$out"
fi

# === 测试 5：--db-password-file= 销毁逻辑 ===
e2e_log "5. --db-password-file 含销毁逻辑"
if grep -qF 'rm -f "$DB_PASSWORD_FILE"' "$BT_INSTALL"; then
    e2e_pass "--db-password-file 含读后 rm 销毁"
else
    e2e_fail "--db-password-file 缺销毁逻辑"
fi

# === 测试 6：mysql 单驱动 ===
e2e_log "6. select_db_driver 强制 mysql"
SELECT_DB_BODY=$(awk '/^select_db_driver\(\) \{/,/^}/' "$BT_INSTALL")
if echo "$SELECT_DB_BODY" | grep -qF 'DB_DRIVER="mysql"'; then
    e2e_pass "select_db_driver 强制 DB_DRIVER=mysql"
else
    e2e_fail "select_db_driver 未强制 mysql"
fi

# === 测试 7：generate_env_file 写入 mysql 5 字段 ===
e2e_log "7. generate_env_file 写入 mysql 5 字段"
GEN_BODY=$(awk '/^generate_env_file\(\) \{/,/^}/' "$BT_INSTALL")
for field in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
    if echo "$GEN_BODY" | grep -qE "_set_env_var.*\"$field\""; then
        e2e_pass "generate_env_file 写入 $field"
    else
        e2e_fail "generate_env_file 缺 $field"
    fi
done

# DB_CONNECTION 必写
if echo "$GEN_BODY" | grep -qE '_set_env_var.*"DB_CONNECTION"'; then
    e2e_pass "generate_env_file 写入 DB_CONNECTION"
else
    e2e_fail "generate_env_file 缺 DB_CONNECTION"
fi

# === 测试 8：generate_env_file 生成 APP_KEY + JWT_SECRET ===
# 备份不再加密（明文 .sql.gz），install 期不应残留 BACKUP_ENC_KEY 字样
e2e_log "8. generate_env_file 含 APP_KEY + JWT_SECRET 生成"
if grep -qE 'app_key=.*openssl rand -base64' "$BT_INSTALL" &&
    grep -qE 'jwt_secret=.*openssl rand -base64' "$BT_INSTALL" &&
    grep -qE '_set_env_var.*APP_KEY.*\$app_key' "$BT_INSTALL" &&
    grep -qE '_set_env_var.*JWT_SECRET.*\$jwt_secret' "$BT_INSTALL" &&
    ! grep -qE 'BACKUP_ENC_KEY' "$BT_INSTALL"; then
    e2e_pass "APP_KEY + JWT_SECRET 现场生成 + 写入 .env"
else
    e2e_fail "密钥生成逻辑缺失或仍残留 BACKUP_ENC_KEY"
fi

# === 测试 9：多站点改为分配 Redis DB，不再改 APP_NAME ===
e2e_log "9. generate_env_file 分配独立 Redis DB 对"
if grep -qE '^allocate_redis_databases\(\)' "$BT_INSTALL" &&
    echo "$GEN_BODY" | grep -qF 'allocate_redis_databases "$env_file"' &&
    ! echo "$GEN_BODY" | grep -qE '_set_env_var.*"APP_NAME".*ssl'; then
    e2e_pass "安装时分配 Redis DB 对且保持 APP_NAME"
else
    e2e_fail "Redis DB 分配逻辑缺失或仍通过 APP_NAME 分配隔离"
fi

SET_ENV_BODY=$(awk '/^_set_env_var\(\) \{/,/^}/' "$BT_INSTALL")
READ_REDIS_ENDPOINT_BODY=$(awk '/^_read_env_redis_endpoint\(\) \{/,/^}/' "$BT_INSTALL")
READ_REDIS_DB_BODY=$(awk '/^_read_env_redis_db\(\) \{/,/^}/' "$BT_INSTALL")
NORMALIZE_DECIMAL_BODY=$(awk '/^_normalize_decimal_in_range\(\) \{/,/^}/' "$BT_INSTALL")
ALLOC_BODY=$(awk '/^allocate_redis_databases\(\) \{/,/^}/' "$BT_INSTALL")
eval "$SET_ENV_BODY"
eval "$NORMALIZE_DECIMAL_BODY"
eval "$READ_REDIS_ENDPOINT_BODY"
eval "$READ_REDIS_DB_BODY"
eval "$ALLOC_BODY"
log_error() { printf '%s\n' "$*" >&2; }
MANAGER_SITES_ROOT="$E2E_TMPDIR/sites"
INSTALL_DIR="$MANAGER_SITES_ROOT/new-manager"
mkdir -p "$MANAGER_SITES_ROOT/legacy/backend" "$MANAGER_SITES_ROOT/explicit/backend" "$INSTALL_DIR/backend"
printf 'APP_NAME=ssl\n' >"$MANAGER_SITES_ROOT/legacy/backend/.env"
printf "REDIS_DB = '03'\nREDIS_CACHE_DB = '04'\n" >"$MANAGER_SITES_ROOT/explicit/backend/.env"
REDIS_DB_ALLOCATED=''
REDIS_CACHE_DB_ALLOCATED=''
allocate_redis_databases
if [ "$REDIS_DB_ALLOCATED" = "5" ] && [ "$REDIS_CACHE_DB_ALLOCATED" = "6" ]; then
    e2e_pass "缺省站点占用 1/2、等号前空白与单引号前导零站点占用 3/4 后分配 5/6"
else
    e2e_fail "Redis DB 行为分配错误：runtime=$REDIS_DB_ALLOCATED cache=$REDIS_CACHE_DB_ALLOCATED"
fi

mkdir -p "$MANAGER_SITES_ROOT/dotenv-variants/backend"
printf " export 'REDIS_DB' = '0005' # runtime\nREDIS_CACHE_DB=\"0006\" # cache\n" >"$MANAGER_SITES_ROOT/dotenv-variants/backend/.env"
allocate_redis_databases
if [ "$REDIS_DB_ALLOCATED" = "7" ] && [ "$REDIS_CACHE_DB_ALLOCATED" = "8" ]; then
    e2e_pass "phpdotenv 的 export、引号键、行尾注释变体占用 5/6 后分配 7/8"
else
    e2e_fail "phpdotenv 变体分配错误：runtime=$REDIS_DB_ALLOCATED cache=$REDIS_CACHE_DB_ALLOCATED"
fi

mkdir -p "$MANAGER_SITES_ROOT/duplicate-keys/backend"
printf 'REDIS_DB=11\nREDIS_DB=7\nREDIS_CACHE_DB=12\nREDIS_CACHE_DB=8\n' >"$MANAGER_SITES_ROOT/duplicate-keys/backend/.env"
allocate_redis_databases
if [ "$REDIS_DB_ALLOCATED" = "9" ] && [ "$REDIS_CACHE_DB_ALLOCATED" = "10" ]; then
    e2e_pass "重复 dotenv 键按最后值占用 7/8 后分配 9/10"
else
    e2e_fail "重复 dotenv 键覆盖语义错误：runtime=$REDIS_DB_ALLOCATED cache=$REDIS_CACHE_DB_ALLOCATED"
fi

BOUNDARY_SITES_ROOT="$E2E_TMPDIR/boundary-sites"
BOUNDARY_INSTALL_DIR="$BOUNDARY_SITES_ROOT/new-manager"
mkdir -p "$BOUNDARY_SITES_ROOT/numeric-boundaries/backend" "$BOUNDARY_INSTALL_DIR/backend"
printf 'REDIS_DB=00\nREDIS_CACHE_DB=00016\n' >"$BOUNDARY_SITES_ROOT/numeric-boundaries/backend/.env"
zero_db=$(_read_env_redis_db "$BOUNDARY_SITES_ROOT/numeric-boundaries/backend/.env" "REDIS_DB")
high_db_status=0
_read_env_redis_db "$BOUNDARY_SITES_ROOT/numeric-boundaries/backend/.env" "REDIS_CACHE_DB" >/dev/null || high_db_status=$?
if [ "$zero_db" = "0" ] && [ "$high_db_status" -eq 2 ]; then
    e2e_pass "纯数字 Redis DB 规范化为十进制字符串，且超过 15 时直接拒绝"
else
    e2e_fail "Redis DB 数字边界规范化错误：zero=$zero_db high_status=$high_db_status"
fi
if (MANAGER_SITES_ROOT="$BOUNDARY_SITES_ROOT" INSTALL_DIR="$BOUNDARY_INSTALL_DIR" allocate_redis_databases >/dev/null 2>&1); then
    e2e_fail "超出 Redis logical DB 0-15 范围的现有配置仍继续分配"
else
    e2e_pass "超出 Redis logical DB 0-15 范围的现有配置 fail-closed"
fi

mkdir -p "$BOUNDARY_SITES_ROOT/huge-db/backend" "$BOUNDARY_SITES_ROOT/huge-port/backend"
printf 'REDIS_DB=999999999999999999999999999999999999\nREDIS_CACHE_DB=2\n' >"$BOUNDARY_SITES_ROOT/huge-db/backend/.env"
printf 'REDIS_PORT=999999999999999999999999999999999999\nREDIS_DB=1\nREDIS_CACHE_DB=2\n' >"$BOUNDARY_SITES_ROOT/huge-port/backend/.env"
if _read_env_redis_db "$BOUNDARY_SITES_ROOT/huge-db/backend/.env" "REDIS_DB" >/dev/null 2>&1; then
    e2e_fail "超长 Redis DB 数字绕过了范围校验"
else
    e2e_pass "超长 Redis DB 数字在整数比较前 fail-closed"
fi
if _read_env_redis_endpoint "$BOUNDARY_SITES_ROOT/huge-port/backend/.env" >/dev/null 2>&1; then
    e2e_fail "超长 Redis port 数字绕过了范围校验"
else
    e2e_pass "超长 Redis port 数字在整数比较前 fail-closed"
fi

INSTANCE_SITES_ROOT="$E2E_TMPDIR/instance-sites"
INSTANCE_INSTALL_DIR="$INSTANCE_SITES_ROOT/new-manager"
mkdir -p "$INSTANCE_INSTALL_DIR/backend" "$INSTANCE_SITES_ROOT/local-manager/backend" "$INSTANCE_SITES_ROOT/remote-host/backend" "$INSTANCE_SITES_ROOT/remote-port/backend"
cp "$E2E_REPO_ROOT/backend/.env.example" "$INSTANCE_INSTALL_DIR/backend/.env"
printf 'REDIS_HOST=LOCALHOST\nREDIS_PORT=06379\nREDIS_DB=3\nREDIS_CACHE_DB=4\n' >"$INSTANCE_SITES_ROOT/local-manager/backend/.env"
printf 'REDIS_HOST=redis.example.com\nREDIS_PORT=6379\nREDIS_DB=${REMOTE_RUNTIME_DB}\nREDIS_CACHE_DB=16\n' >"$INSTANCE_SITES_ROOT/remote-host/backend/.env"
printf 'REDIS_HOST=127.0.0.1\nREDIS_PORT=6380\nREDIS_DB=${REMOTE_RUNTIME_DB}\nREDIS_CACHE_DB=16\n' >"$INSTANCE_SITES_ROOT/remote-port/backend/.env"
instance_status=0
(MANAGER_SITES_ROOT="$INSTANCE_SITES_ROOT" INSTALL_DIR="$INSTANCE_INSTALL_DIR" allocate_redis_databases "$INSTANCE_INSTALL_DIR/backend/.env") || instance_status=1
instance_runtime=$(_read_env_redis_db "$INSTANCE_INSTALL_DIR/backend/.env" "REDIS_DB" 2>/dev/null || true)
instance_cache=$(_read_env_redis_db "$INSTANCE_INSTALL_DIR/backend/.env" "REDIS_CACHE_DB" 2>/dev/null || true)
if [ "$instance_status" -eq 0 ] && [ "$instance_runtime" = "1" ] && [ "$instance_cache" = "2" ]; then
    e2e_pass "只统计同一 Redis host/port，localhost 与默认地址归一且忽略远端实例的 DB 配置"
else
    e2e_fail "Redis 实例识别错误：runtime=$instance_runtime cache=$instance_cache"
fi

URL_SITES_ROOT="$E2E_TMPDIR/url-sites"
URL_INSTALL_DIR="$URL_SITES_ROOT/new-manager"
URL_ENV="$URL_SITES_ROOT/legacy/backend/.env"
mkdir -p "$(dirname "$URL_ENV")" "$URL_INSTALL_DIR/backend"
cp "$E2E_REPO_ROOT/backend/.env.example" "$URL_INSTALL_DIR/backend/.env"
for url_line in \
    'REDIS_URL=redis://127.0.0.1:6379/1' \
    ' export "REDIS_URL" = "redis://127.0.0.1:6379/1" # legacy'; do
    printf '%s\nREDIS_HOST=redis.example.com\nREDIS_DB=7\nREDIS_CACHE_DB=8\n' "$url_line" >"$URL_ENV"
    if (MANAGER_SITES_ROOT="$URL_SITES_ROOT" INSTALL_DIR="$URL_INSTALL_DIR" allocate_redis_databases "$URL_INSTALL_DIR/backend/.env" >/dev/null 2>&1); then
        e2e_fail "旧站点 REDIS_URL 覆盖 host/DB 时仍自动分配"
    elif cmp -s "$E2E_REPO_ROOT/backend/.env.example" "$URL_INSTALL_DIR/backend/.env"; then
        e2e_pass "拒绝旧站点 REDIS_URL 且不写入新站点 DB"
    else
        e2e_fail "拒绝旧站点 REDIS_URL 时仍修改了新站点配置"
    fi
done

printf 'REDIS_URL=redis://127.0.0.1:6379/1\nREDIS_URL="" # disabled\nREDIS_DB=7\nREDIS_CACHE_DB=8\n' >"$URL_ENV"
if (MANAGER_SITES_ROOT="$URL_SITES_ROOT" INSTALL_DIR="$URL_INSTALL_DIR" allocate_redis_databases "$URL_INSTALL_DIR/backend/.env" >/dev/null 2>&1); then
    e2e_pass "最后一个 REDIS_URL 为空时允许按显式连接分配"
else
    e2e_fail "空 REDIS_URL 被误拒绝"
fi
printf 'REDIS_URL=redis://127.0.0.1:6379/1\n' >"$URL_INSTALL_DIR/backend/.env"
if (MANAGER_SITES_ROOT="$URL_SITES_ROOT" INSTALL_DIR="$URL_INSTALL_DIR" allocate_redis_databases "$URL_INSTALL_DIR/backend/.env" >/dev/null 2>&1); then
    e2e_fail "目标站点 REDIS_URL 非空时仍自动分配"
else
    e2e_pass "目标站点 REDIS_URL 非空时拒绝分配"
fi

for runtime_db in 1 3 5 7 9 11 13; do
    site_dir="$MANAGER_SITES_ROOT/exhausted-$runtime_db/backend"
    mkdir -p "$site_dir"
    printf 'REDIS_DB=%s\nREDIS_CACHE_DB=%s\n' "$runtime_db" "$((runtime_db + 1))" >"$site_dir/.env"
done
if (allocate_redis_databases >/dev/null 2>&1); then
    e2e_fail "Redis logical DB 1-14 耗尽时仍继续分配"
else
    e2e_pass "Redis logical DB 1-14 耗尽时拒绝安装"
fi

SEQUENTIAL_SITES_ROOT="$E2E_TMPDIR/sequential-sites"
SEQUENTIAL_A="$SEQUENTIAL_SITES_ROOT/manager-a"
SEQUENTIAL_B="$SEQUENTIAL_SITES_ROOT/manager-b"
mkdir -p "$SEQUENTIAL_A/backend" "$SEQUENTIAL_B/backend"
cp "$E2E_REPO_ROOT/backend/.env.example" "$SEQUENTIAL_A/backend/.env"
cp "$E2E_REPO_ROOT/backend/.env.example" "$SEQUENTIAL_B/backend/.env"
sequential_status=0
for install_dir in "$SEQUENTIAL_A" "$SEQUENTIAL_B"; do
    (MANAGER_SITES_ROOT="$SEQUENTIAL_SITES_ROOT" INSTALL_DIR="$install_dir" allocate_redis_databases "$install_dir/backend/.env") || sequential_status=1
done
sequential_a_runtime=$(_read_env_redis_db "$SEQUENTIAL_A/backend/.env" "REDIS_DB" 2>/dev/null || true)
sequential_a_cache=$(_read_env_redis_db "$SEQUENTIAL_A/backend/.env" "REDIS_CACHE_DB" 2>/dev/null || true)
sequential_b_runtime=$(_read_env_redis_db "$SEQUENTIAL_B/backend/.env" "REDIS_DB" 2>/dev/null || true)
sequential_b_cache=$(_read_env_redis_db "$SEQUENTIAL_B/backend/.env" "REDIS_CACHE_DB" 2>/dev/null || true)
if [ "$sequential_status" -eq 0 ] &&
    [ -n "$sequential_a_runtime" ] && [ -n "$sequential_a_cache" ] &&
    [ -n "$sequential_b_runtime" ] && [ -n "$sequential_b_cache" ] &&
    [ "$sequential_a_runtime" != "$sequential_a_cache" ] &&
    [ "$sequential_a_runtime" != "$sequential_b_runtime" ] &&
    [ "$sequential_a_runtime" != "$sequential_b_cache" ] &&
    [ "$sequential_a_cache" != "$sequential_b_runtime" ] &&
    [ "$sequential_a_cache" != "$sequential_b_cache" ] &&
    [ "$sequential_b_runtime" != "$sequential_b_cache" ]; then
    e2e_pass "顺序安装分配且持久化两组互不重叠的 Redis DB"
else
    e2e_fail "顺序 Redis DB 分配冲突：A=$sequential_a_runtime/$sequential_a_cache B=$sequential_b_runtime/$sequential_b_cache"
fi

mkdir -p "$MANAGER_SITES_ROOT/unsupported/backend"
printf 'REDIS_DB=${RUNTIME_DB}\nREDIS_CACHE_DB=15\n' >"$MANAGER_SITES_ROOT/unsupported/backend/.env"
if (allocate_redis_databases >/dev/null 2>&1); then
    e2e_fail "无法静态解析的 Redis DB 值仍继续分配"
else
    e2e_pass "无法静态解析的 Redis DB 值 fail-closed，拒绝冒险重占"
fi

# === 测试 10：run_artisan_install + setup_admin_password 链路 ===
e2e_log "10. artisan migrate + db:seed + admin:reset-password 链路"
if grep -qF "artisan migrate --force" "$BT_INSTALL" &&
    grep -qF "artisan db:seed --force" "$BT_INSTALL" &&
    grep -qF "artisan admin:reset-password" "$BT_INSTALL"; then
    e2e_pass "三步 artisan 链路完整"
else
    e2e_fail "artisan 链路不完整"
fi

# === 测试 11：main() 调用顺序 ===
e2e_log "11. main() 函数调用顺序"
main_body=$(awk '/^main\(\) \{/,/^}/' "$BT_INSTALL")

pos_select=$(echo "$main_body" | grep -n "^[[:space:]]*select_db_driver" | head -1 | cut -d: -f1)
pos_genenv=$(echo "$main_body" | grep -n "^[[:space:]]*generate_env_file" | head -1 | cut -d: -f1)
pos_artisan=$(echo "$main_body" | grep -n "^[[:space:]]*run_artisan_install" | head -1 | cut -d: -f1)
pos_apply=$(echo "$main_body" | grep -n "^[[:space:]]*setup_admin_password" | head -1 | cut -d: -f1)

if [ -n "$pos_select" ] && [ -n "$pos_genenv" ] && [ "$pos_select" -lt "$pos_genenv" ]; then
    e2e_pass "select_db_driver 在 generate_env_file 之前"
else
    e2e_fail "顺序错: select_db_driver=$pos_select genenv=$pos_genenv"
fi

if [ -n "$pos_genenv" ] && [ -n "$pos_artisan" ] && [ "$pos_genenv" -lt "$pos_artisan" ]; then
    e2e_pass "generate_env_file 在 run_artisan_install 之前"
else
    e2e_fail "顺序错: genenv=$pos_genenv artisan=$pos_artisan"
fi

if [ -n "$pos_artisan" ] && [ -n "$pos_apply" ] && [ "$pos_artisan" -lt "$pos_apply" ]; then
    e2e_pass "run_artisan_install 在 setup_admin_password 之前"
else
    e2e_fail "顺序错: artisan=$pos_artisan apply=$pos_apply"
fi

# === 测试 11：_set_env_var 跨平台 sed（macOS / Linux 兼容）===
e2e_log "11. _set_env_var 跨平台 sed 兼容"
if grep -qF 'sed -i.bak' "$BT_INSTALL"; then
    e2e_pass "_set_env_var 用 -i.bak + rm（macOS / Linux 兼容）"
else
    e2e_fail "_set_env_var sed 可能不兼容 macOS"
fi

echo
echo "结果: ${E2E_PASS:-0} passed / ${E2E_FAIL:-0} failed"
exit "${E2E_FAIL:-0}"
