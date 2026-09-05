#!/usr/bin/env bash
# e2e 场景 3：bt-install.sh BT_KEY / SITE_DOMAIN 多来源 + 拒绝命令行明文
#
# 不实际跑宝塔（无环境）；用 grep + 短脚本 source 验证：
#   - 命令行 --bt-key=xxx 必须拒绝（明文进 history）
#   - --bt-key-file=PATH 读取后销毁文件
#   - --site-domain=xxx 解析正常
#   - 自动探测顺序文档化（env BT_KEY > api.json token_crypt；BT 11.5+ 单字段）

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

E2E_TMPDIR="$(mktemp -d)"
trap e2e_cleanup EXIT

e2e_step "case 03: bt-install.sh BT_KEY / SITE_DOMAIN 多来源契约"

BT_INSTALL="$E2E_REPO_ROOT/deploy/scripts/bt-install.sh"
BT_AUTOMATE="$E2E_REPO_ROOT/deploy/scripts/bt-automate.sh"

# === 测试 1：拒绝 --bt-key=xxx 命令行明文 ===
e2e_log "1. 验证拒绝 --bt-key= 明文参数"
# 单引号字符串内 grep 表达式更易读，不引入转义反斜杠地狱
if grep -qF -e '--bt-key=*' "$BT_INSTALL"; then
    e2e_pass "bt-install.sh 含 --bt-key 参数处理 case"
else
    e2e_fail "bt-install.sh 缺 --bt-key 参数 case"
fi

# 实跑：--bt-key=xxx 必须 exit 1（不是 0）
out=$(bash "$BT_INSTALL" --bt-key=secret 2>&1 || echo "EXIT=$?")
if echo "$out" | grep -q "拒绝命令行明文 --bt-key"; then
    e2e_pass "--bt-key=secret 被立即拒绝"
else
    e2e_fail "--bt-key=secret 未被拒绝；output=$out"
fi

# === 测试 2：--bt-key-file=PATH 文件读取后销毁 ===
e2e_log "2. 验证 --bt-key-file= 文件销毁逻辑"
if grep -qE 'BT_KEY_FILE=' "$BT_INSTALL" && grep -qE 'rm -f "\$BT_KEY_FILE"' "$BT_INSTALL"; then
    e2e_pass "--bt-key-file 含读后 rm 销毁逻辑"
else
    e2e_fail "--bt-key-file 缺销毁逻辑（密钥可能落盘）"
fi

# === 测试 3：--site-domain=xxx 正常解析 ===
e2e_log "3. 验证 --site-domain= 解析"
if grep -qE 'SITE_DOMAIN="\$\{1\#\*=\}"' "$BT_INSTALL" || grep -qE '\-\-site-domain=' "$BT_INSTALL"; then
    e2e_pass "--site-domain= 参数 case 已实现"
else
    e2e_fail "--site-domain 参数缺失"
fi

# === 测试 3.5：BT 面板版本预检 ≥11.5（最早阶段）===
e2e_log "3.5 BT 面板版本预检 ≥11.5"
if grep -qE '^_get_bt_panel_version\(\) \{' "$BT_INSTALL"; then
    e2e_pass "_get_bt_panel_version 函数已定义"
else
    e2e_fail "_get_bt_panel_version 函数缺失"
fi

if grep -qE '^_version_ge\(\) \{' "$BT_INSTALL"; then
    e2e_pass "_version_ge 函数已定义（sort -V）"
else
    e2e_fail "_version_ge 函数缺失"
fi

if grep -qE '^BT_MIN_VERSION="11\.5\.0"' "$BT_INSTALL"; then
    e2e_pass "BT_MIN_VERSION=11.5.0"
else
    e2e_fail "BT_MIN_VERSION 不是 11.5.0"
fi

# 校验：版本预检在 check_environment 内部 + 失败 exit 1
CHECK_BODY=$(awk '/^check_environment\(\) \{/,/^}/' "$BT_INSTALL")
if echo "$CHECK_BODY" | grep -qF '_get_bt_panel_version' &&
    echo "$CHECK_BODY" | grep -qF '_version_ge' &&
    echo "$CHECK_BODY" | grep -qE '宝塔面板版本过低.*exit 1|exit 1'; then
    e2e_pass "check_environment 调用版本预检 + 过低时 exit 1"
else
    e2e_fail "check_environment 缺版本预检集成"
fi

# === 测试 4：BT API 预检（detect_bt_key）非交互、无重复 key 输入 ===
e2e_log "4. detect_bt_key 预检函数定义且不交互输入 key"
if grep -qE '^detect_bt_key\(\) \{' "$BT_INSTALL"; then
    e2e_pass "detect_bt_key 函数已定义（依赖检测后预检）"
else
    e2e_fail "detect_bt_key 函数缺失"
fi

# 反向断言：try_bt_automation 不再二次交互输入 BT_KEY（与 detect_bt_key 单一路径承诺一致）
TRY_BODY=$(awk '/^try_bt_automation\(\) \{/,/^}/' "$BT_INSTALL")
if echo "$TRY_BODY" | grep -qF '宝塔 API Token'; then
    e2e_fail "try_bt_automation 仍残留二次输入 BT_KEY 的交互（应由 detect_bt_key 一次预检完成）"
else
    e2e_pass "try_bt_automation 不再二次询问 BT_KEY（信任 BT_KEY_AVAILABLE 单一路径）"
fi

if echo "$TRY_BODY" | grep -qF 'BT_KEY_AVAILABLE'; then
    e2e_pass "try_bt_automation 据 BT_KEY_AVAILABLE 决定 has_bt_key 状态"
else
    e2e_fail "try_bt_automation 未引用 BT_KEY_AVAILABLE"
fi

# === 测试 5：select_install_dir 根据 BT_KEY_AVAILABLE 单一路径（不再三选项菜单）===
e2e_log "5. select_install_dir 根据 BT_KEY_AVAILABLE 单一路径"
SELECT_BODY=$(awk '/^select_install_dir\(\) \{/,/^}/' "$BT_INSTALL")

# 路径 A：BT 可用 → 仅询问站点域名 + 站点存在询问复用
if echo "$SELECT_BODY" | grep -qE 'BT_KEY_AVAILABLE.*=.*true' &&
    echo "$SELECT_BODY" | grep -qF '请输入站点域名（如 manager.example.com）'; then
    e2e_pass "BT_KEY 可用路径：仅询问站点域名"
else
    e2e_fail "BT_KEY 可用路径文案不对"
fi

if echo "$SELECT_BODY" | grep -qF 'bt_get_site_path'; then
    e2e_pass "站点存在性预检调 bt_get_site_path"
else
    e2e_fail "缺站点存在性预检"
fi

if echo "$SELECT_BODY" | grep -qE 'confirm.*是否复用该站点.*"y"'; then
    e2e_pass "已存在站点询问复用（默认 y）"
else
    e2e_fail "复用询问/默认值不对"
fi

# 复用站点 = 同意覆盖目录；step 5 跳过二次询问
if echo "$SELECT_BODY" | grep -qF 'SITE_REUSE_CONFIRMED=true' &&
    echo "$SELECT_BODY" | grep -qE 'SITE_REUSE_CONFIRMED.*!=.*"true"'; then
    e2e_pass "复用站点后跳过 step 5 目录覆盖二次询问"
else
    e2e_fail "复用站点未跳过目录覆盖二次询问（用户被问两次）"
fi

# 路径 B：BT 不可用 → 仅询问安装目录绝对路径
if echo "$SELECT_BODY" | grep -qF '请输入安装目录（绝对路径）'; then
    e2e_pass "BT_KEY 不可用路径：仅询问安装目录绝对路径"
else
    e2e_fail "BT_KEY 不可用路径文案不对"
fi

# 反向断言：旧三选项菜单（1/2/3）已移除
if echo "$SELECT_BODY" | grep -qE '请选择 \(1/2/3\)'; then
    e2e_fail "select_install_dir 仍残留三选项菜单（应单一路径）"
else
    e2e_pass "三选项菜单已移除（单一路径生效）"
fi

# === 测试 6：bt-automate.sh BT_KEY 2 级探测（BT 11.5+ 单字段 token_crypt）===
e2e_log "6. 验证 bt-automate.sh BT_KEY 2 级探测"
if grep -qE 'if \[ -n "\$BT_KEY" \]' "$BT_AUTOMATE"; then
    e2e_pass "优先级 1: env BT_KEY"
else
    e2e_fail "优先级 1 (env) 缺失"
fi

if grep -qE 'token_crypt' "$BT_AUTOMATE" && grep -qE '/www/server/panel/config/api\.json' "$BT_AUTOMATE"; then
    e2e_pass "优先级 2: api.json 的 token_crypt 字段（BT 11.5+）"
else
    e2e_fail "优先级 2 (api.json token_crypt) 缺失"
fi

# 反向断言：旧版兼容路径已移除（BT 11.5+ 不再回落）
if grep -qE '/www/server/panel/data/userInfo\.json' "$BT_AUTOMATE"; then
    e2e_fail "残留旧版 userInfo.json 路径（BT 11.5+ 应已移除）"
else
    e2e_pass "userInfo.json 旧版路径已移除"
fi

if grep -qE 'bt default' "$BT_AUTOMATE"; then
    e2e_fail "残留 bt default 命令兜底路径（BT 11.5+ 应已移除）"
else
    e2e_pass "bt default 命令兜底已移除"
fi

# 反向断言：函数体代码（剥离注释）不含 fallback "token" 字段读取
# 关键设计：先 `grep -v '^[[:space:]]*#'` 剥离整行注释（注释里出现 "token" 是历史教训文档化，
# 不应触发失败），再 grep -F '"token"' fixed-string 匹配 awk 字段读取字面量；
# 绝不会误匹配 "token_crypt"（完整字面量中间是 _，不构成 "token" 子串）。
# 比"if 结构匹配"鲁棒：fallback 不论怎么写（紧凑/分块/单行），读 "token" 字段都被捕获
RESOLVE_BODY=$(awk '/^bt_resolve_key\(\) \{/,/^}/' "$BT_AUTOMATE")
if echo "$RESOLVE_BODY" | grep -v '^[[:space:]]*#' | grep -qF '"token"'; then
    e2e_fail "bt_resolve_key 残留 \"token\" 字段读取（BT 11.5+ 应仅读 token_crypt）"
else
    e2e_pass "bt_resolve_key 仅读 token_crypt 单字段"
fi

# 对称性反向断言：bt-deps.sh::_resolve_bt_api_key 是独立实现，要与 bt-automate.sh 对齐
BT_DEPS="$E2E_REPO_ROOT/deploy/scripts/bt-deps.sh"
DEPS_RESOLVE_BODY=$(awk '/^_resolve_bt_api_key\(\) \{/,/^}/' "$BT_DEPS")
if echo "$DEPS_RESOLVE_BODY" | grep -v '^[[:space:]]*#' | grep -qF '"token"'; then
    e2e_fail "bt-deps.sh::_resolve_bt_api_key 残留 \"token\" 字段读取（应只读 token_crypt 与 bt-automate.sh 对齐）"
else
    e2e_pass "bt-deps.sh::_resolve_bt_api_key 仅读 token_crypt 单字段（与 bt-automate.sh 对齐）"
fi

if echo "$DEPS_RESOLVE_BODY" | grep -qF 'token_crypt'; then
    e2e_pass "bt-deps.sh::_resolve_bt_api_key 含 token_crypt 主路径"
else
    e2e_fail "bt-deps.sh::_resolve_bt_api_key 缺 token_crypt 主路径"
fi

# === 测试 7：bt_resolve_key env 路径实跑 ===
e2e_log "7. bt_resolve_key env 路径实跑"
unset BT_KEY 2>/dev/null || true
out=$(BT_KEY=test_env_key bash -c "source '$BT_AUTOMATE' && bt_resolve_key && echo \"\$BT_KEY\"" 2>/dev/null || echo "")
if [ "$out" = "test_env_key" ] || echo "$out" | grep -q "test_env_key"; then
    e2e_pass "bt_resolve_key env 优先级实跑命中"
else
    e2e_fail "bt_resolve_key env 路径未命中: $out"
fi

# === 测试 8：bt_resolve_key 无来源时 return 1 ===
e2e_log "8. bt_resolve_key 无任何来源 → return 1"
out=$(
    env -i bash -c "unset BT_KEY; source '$BT_AUTOMATE' && bt_resolve_key" 2>/dev/null
    echo "RC=$?"
)
if echo "$out" | grep -qE 'RC=[1-9]'; then
    e2e_pass "无来源时 bt_resolve_key 返回非 0"
else
    e2e_fail "无来源应当 return 非 0，实际: $out"
fi

# === 测试 9：核心运行目录必须在 Composer 前创建并以 www 验写 ===
e2e_log "9. 核心运行目录在 Composer 前就绪"
PERMISSIONS_BODY=$(awk '/^set_permissions\(\) \{/,/^}/' "$BT_INSTALL")
runtime_dirs_ok=true
for required_dir in \
    backend/bootstrap/cache \
    backend/storage \
    backend/storage/logs \
    backend/storage/framework \
    backend/storage/framework/cache/data \
    backend/storage/framework/runtime-cache/data \
    backend/storage/framework/sessions \
    backend/storage/framework/views \
    backend/storage/app/public \
    backend/storage/app/private \
    backups/upgrades; do
    if ! echo "$PERMISSIONS_BODY" | grep -qF "\"$required_dir\""; then
        runtime_dirs_ok=false
        e2e_fail "set_permissions 缺少核心运行目录: $required_dir"
    fi
done

if [ "$runtime_dirs_ok" = true ] &&
    echo "$PERMISSIONS_BODY" | grep -qF 'mkdir -p "$abs_path"' &&
    echo "$PERMISSIONS_BODY" | grep -qF 'sudo -u "$WWW_USER" test -w "$abs_path"'; then
    e2e_pass "set_permissions 创建并以实际 Web 用户验写全部核心目录"
else
    [ "$runtime_dirs_ok" = false ] || e2e_fail "set_permissions 缺少统一创建或 www 可写检查"
fi

set_permissions_line=$(grep -nE '^[[:space:]]+set_permissions$' "$BT_INSTALL" | tail -1 | cut -d: -f1 || true)
composer_install_line=$(grep -nE '^[[:space:]]+run_composer_install$' "$BT_INSTALL" | tail -1 | cut -d: -f1 || true)
if [ -n "$set_permissions_line" ] && [ -n "$composer_install_line" ] &&
    [ "$set_permissions_line" -lt "$composer_install_line" ]; then
    e2e_pass "set_permissions 严格早于 run_composer_install"
else
    e2e_fail "安装流程未保证核心运行目录在 Composer 前就绪"
fi

echo
echo "结果: ${E2E_PASS:-0} passed / ${E2E_FAIL:-0} failed"
exit "${E2E_FAIL:-0}"
