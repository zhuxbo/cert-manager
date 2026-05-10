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

# === 测试 9：run_artisan_install + setup_admin_password 链路 ===
e2e_log "9. artisan migrate + db:seed + admin:reset-password 链路"
if grep -qF "artisan migrate --force" "$BT_INSTALL" &&
    grep -qF "artisan db:seed --force" "$BT_INSTALL" &&
    grep -qF "artisan admin:reset-password" "$BT_INSTALL"; then
    e2e_pass "三步 artisan 链路完整"
else
    e2e_fail "artisan 链路不完整"
fi

# === 测试 10：main() 调用顺序 ===
e2e_log "10. main() 函数调用顺序"
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
