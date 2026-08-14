#!/usr/bin/env bash
# P0-2 包U：upgrade.sh storage/vendor/适配器 防删守卫 + 信号还原演练
#
# 验收⑦：升级中途 Ctrl-C / SSH 断连 → storage 与 storage/databak 完好、已移出内容被守卫还原。
# vendor 唯一副本中断丢失 → composer 强制重装兜底（不因 hash 相等跳过致砖机）+ vendor-only 残留回迁。
# cleanup 删 preserve 前先还原 api_adapters/frontend_config 唯一在线副本（不静默销毁）。
# B 组信号注入用 set -m 真正投递 SIGINT（非睡满 EXIT 假覆盖）+ 睡满哨兵证伪。
#
# 框架对齐 deploy/test/test-symmetric-copies.sh：extract_fn 抽顶层函数体（不 source 整脚本避免触发
# main）→ 定义 assert_* + PASS/FAIL 计数 → [ "$FAIL" -eq 0 ] 退出。纯 shell、无需 PHP，任意 runner 可跑。
#
# 分组：
#   A 单元（直接驱动守卫函数）：A1 还原生效 / A2 成功态不误还原 / A3 守卫失败不吞 / A4 搁浅拦截 /
#     A5 空壳清理 / A6 same-fs 断言 / A7 vendor-only 回迁(⑧) / A8 extras 还原(⑨) /
#     A9 composer 判定(⑧) / A10 cleanup 还原 extras 集成(⑨) / A11 正常恢复消费 extras /
#     A12 consume 清理失败不中止升级
#   B 信号注入（子进程 harness）：B1 SIGINT / B2 SIGTERM / B3 SIGHUP 还原（set -m 真投递 + 睡满哨兵，⑫）
#     + B4 SIGKILL 后重跑拦截
#   C 回归守卫：PRESERVE_DIR 钉在 $INSTALL_DIR 下 / 生产信号 trap 装配行 / composer vendor 缺失兜底(⑧) /
#     cleanup 删 preserve 前还原 extras(⑨) / platform-config 不再 preserve 且升级包必须携带
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE="$ROOT/deploy/upgrade.sh"
BUILD_CONFIG="$ROOT/build/config.json"
CONTAINER_BUILD="$ROOT/build/scripts/container-build.sh"
COLLECT_ARTIFACTS="$ROOT/build/scripts/collect-artifacts.sh"
PACKAGE_SCRIPT="$ROOT/build/scripts/package.sh"
PACKAGE_AUDITOR="$ROOT/build/scripts/audit-package.sh"
BT_INSTALL="$ROOT/deploy/scripts/bt-install.sh"
PACKAGE_EXTRACTOR="$ROOT/backend/app/Services/Upgrade/PackageExtractor.php"
PASS=0
FAIL=0

# 与 test-symmetric-copies.sh 同款 extract_fn（跟踪内嵌 PHP 单引号块；本包守卫函数无内嵌 PHP）
extract_fn() {
    awk -v head="$2() {" -v sq="'" '
        $0 == head { p = 1 }
        p { print }
        p && index($0, "-r ") > 0 && substr($0, length($0), 1) == sq { inq = 1; next }
        p && inq && substr($0, 1, 1) == sq { inq = 0; next }
        p && inq == 0 && $0 == "}" { exit }
    ' "$1"
}

# 抽取 perform_upgrade 中第 N 个运行目录失败闸，测试真实调用位点是否仍以 exit 终止。
extract_runtime_guard() {
    awk -v target="$2" '
        /^[[:space:]]*if ! _ensure_runtime_directories; then$/ {
            seen++
            if (seen == target) {
                printing = 1
            }
        }
        printing { print }
        printing && /^[[:space:]]*fi$/ { exit }
    ' "$1"
}

extract_shell_runtime_dirs() {
    awk '
        /^[[:space:]]*(local -a )?runtime_rel_dirs=\($/ { printing = 1; next }
        printing && /^[[:space:]]*\)$/ { exit }
        printing {
            line = $0
            gsub(/^[[:space:]]*"|"[[:space:]]*$/, "", line)
            if (line != "") print line
        }
    ' "$1"
}

extract_php_runtime_dirs() {
    awk '
        /private const RUNTIME_RELATIVE_DIRECTORIES = \[/ { printing = 1; next }
        printing && /^[[:space:]]*\];$/ { exit }
        printing {
            line = $0
            gsub(/^[[:space:]]*\047|\047,[[:space:]]*$/, "", line)
            if (line != "") print line
        }
    ' "$1"
}

pass() {
    echo "✓ $1"
    PASS=$((PASS + 1))
}
fail() {
    echo "✗ $1"
    FAIL=$((FAIL + 1))
}

# log_* stub（被抽取的守卫函数依赖；upgrade.sh :38-42 是单行函数，extract_fn 整行等值匹配抽不出）
log_info() { :; }
log_success() { :; }
log_error() { :; }
log_warning() { :; }
log_step() { :; }
_print_recovery_runbook() { :; }
file_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

# 抽取守卫函数进当前 shell（A 组直接驱动）
eval "$(extract_fn "$UPGRADE" _fs_device)"
eval "$(extract_fn "$UPGRADE" _assert_storage_same_fs)"
eval "$(extract_fn "$UPGRADE" _check_stranded_preserve)"
eval "$(extract_fn "$UPGRADE" _restore_preserved_storage)"
eval "$(extract_fn "$UPGRADE" _restore_preserved_extras)"
eval "$(extract_fn "$UPGRADE" _need_composer_install)"
eval "$(extract_fn "$UPGRADE" _bundled_vendor_matches_lock)"
eval "$(extract_fn "$UPGRADE" _vendor_dir_matches_lock)"
eval "$(extract_fn "$UPGRADE" _write_composer_lock_marker)"
eval "$(extract_fn "$BT_INSTALL" write_composer_lock_marker)"
eval "$(extract_fn "$UPGRADE" _stage_bundled_vendor)"
eval "$(extract_fn "$UPGRADE" _ensure_runtime_directories)"
eval "$(extract_fn "$UPGRADE" _print_recovery_runbook)"

# 抽取健全性校验：任一函数未抽出即整体失败（防 upgrade.sh 改结构后静默失测）
for fn in _fs_device _assert_storage_same_fs _check_stranded_preserve _restore_preserved_storage \
    _restore_preserved_extras _need_composer_install _bundled_vendor_matches_lock _vendor_dir_matches_lock \
    _write_composer_lock_marker write_composer_lock_marker \
    _stage_bundled_vendor _ensure_runtime_directories; do
    if ! declare -f "$fn" >/dev/null 2>&1; then
        echo "✗ 抽取失败：$fn 未从 $UPGRADE 提取到（函数结构变化？）"
        exit 1
    fi
done

DATABAK_REL="backend/storage/databak/db_20260101_000000.sql.gz"

# ========================================================================
# A. 单元
# ========================================================================
echo "=== A. 单元（直接驱动守卫函数）==="

# A1 还原生效：mv 出后驱动还原，databak 字节一致回原位、preserve/storage 消费、vendor 还原、rc=0
test_a1() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a1"
    local sent="SENTINEL-A1-$$"
    mkdir -p "$INSTALL_DIR/backend/storage/databak" "$INSTALL_DIR/backend/storage/logs" "$INSTALL_DIR/backend/vendor"
    printf '%s' "$sent" >"$INSTALL_DIR/$DATABAK_REL"
    printf 'LOG-SENTINEL' >"$INSTALL_DIR/backend/storage/logs/laravel.log"
    printf 'AUTOLOAD' >"$INSTALL_DIR/backend/vendor/autoload.php"
    # 模拟切代码窗内 mv 出（同 fs）
    mkdir -p "$PRESERVE_DIR"
    mv "$INSTALL_DIR/backend/storage" "$PRESERVE_DIR/storage"
    mv "$INSTALL_DIR/backend/vendor" "$PRESERVE_DIR/vendor"
    local rc
    (_restore_preserved_storage)
    rc=$?
    local ok=1
    [ "$rc" -eq 0 ] || ok=0
    [ "$(cat "$INSTALL_DIR/$DATABAK_REL" 2>/dev/null || true)" = "$sent" ] || ok=0
    [ ! -d "$PRESERVE_DIR/storage" ] || ok=0                  # preserve/storage 已被 mv 消费
    [ -f "$INSTALL_DIR/backend/vendor/autoload.php" ] || ok=0 # vendor 也还原
    if [ "$ok" -eq 1 ]; then
        pass "A1 还原生效：databak 字节一致回原位 + preserve/storage 消费 + vendor 还原 + rc=0"
    else
        fail "A1 还原生效（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A2 成功态不误还原：preserve 无 storage、原位 storage 在 → no-op、原位未动、rc=0
test_a2() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a2"
    local sent="SENTINEL-A2-$$"
    mkdir -p "$INSTALL_DIR/backend/storage/databak"
    printf '%s' "$sent" >"$INSTALL_DIR/$DATABAK_REL"
    mkdir -p "$PRESERVE_DIR" # preserve 存在但无 storage（模拟窗关后）
    local rc
    (_restore_preserved_storage)
    rc=$?
    local ok=1
    [ "$rc" -eq 0 ] || ok=0
    [ "$(cat "$INSTALL_DIR/$DATABAK_REL" 2>/dev/null || true)" = "$sent" ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "A2 成功态不误还原：no-op、原位 storage 未被动、rc=0"
    else
        fail "A2 成功态不误还原（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A3 守卫失败不吞：mv 失败 stub → 返回 1、PRESERVE_DIR/storage 仍在（未被删）
test_a3() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a3"
    local sent="SENTINEL-A3-$$"
    mkdir -p "$PRESERVE_DIR/storage/databak" "$INSTALL_DIR/backend"
    printf '%s' "$sent" >"$PRESERVE_DIR/storage/databak/db_20260101_000000.sql.gz"
    local rc
    (
        mv() { return 1; } # 注入还原 mv 失败（subshell 隔离，不污染后续测试）
        _restore_preserved_storage
    )
    rc=$?
    local ok=1
    [ "$rc" -eq 1 ] || ok=0                # 守卫返回 1
    [ -d "$PRESERVE_DIR/storage" ] || ok=0 # 唯一副本仍在
    [ "$(cat "$PRESERVE_DIR/storage/databak/db_20260101_000000.sql.gz" 2>/dev/null || true)" = "$sent" ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "A3 守卫失败不吞：返回 1 + PRESERVE_DIR/storage 保留（数据可手工恢复）"
    else
        fail "A3 守卫失败不吞（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A4 残留检测·搁浅拦截：.upgrade-preserve-*/storage 存在且 backend/storage 缺失 → 中止、数据未动、不造空 storage
test_a4() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    local sent="SENTINEL-A4-$$"
    mkdir -p "$INSTALL_DIR/.upgrade-preserve-99999/storage/databak" "$INSTALL_DIR/backend"
    printf '%s' "$sent" >"$INSTALL_DIR/.upgrade-preserve-99999/storage/databak/db.sql.gz"
    local rc
    (_check_stranded_preserve)
    rc=$?
    local ok=1
    [ "$rc" -ne 0 ] || ok=0                                                         # 被拦截（exit 1）
    [ -f "$INSTALL_DIR/.upgrade-preserve-99999/storage/databak/db.sql.gz" ] || ok=0 # 搁浅数据未动
    [ "$(cat "$INSTALL_DIR/.upgrade-preserve-99999/storage/databak/db.sql.gz" 2>/dev/null || true)" = "$sent" ] || ok=0
    [ ! -d "$INSTALL_DIR/backend/storage" ] || ok=0 # 未静默造空 storage
    if [ "$ok" -eq 1 ]; then
        pass "A4 搁浅拦截：rc≠0 中止 + 搁浅 storage 未动 + backend/storage 未被创建"
    else
        fail "A4 搁浅拦截（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A5 残留检测·空壳清理：无 storage 的空壳（.env / api_adapters）→ 放行 rc=0、空壳被清
test_a5() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    mkdir -p "$INSTALL_DIR/.upgrade-preserve-99999/api_adapters" "$INSTALL_DIR/backend"
    printf 'x' >"$INSTALL_DIR/.upgrade-preserve-99999/.env"
    local rc
    (_check_stranded_preserve)
    rc=$?
    local ok=1
    [ "$rc" -eq 0 ] || ok=0                                 # 放行
    [ ! -d "$INSTALL_DIR/.upgrade-preserve-99999" ] || ok=0 # 空壳被清
    if [ "$ok" -eq 1 ]; then
        pass "A5 空壳清理：rc=0 放行 + 空壳被清（防堆积）"
    else
        fail "A5 空壳清理（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A6 same-fs 断言：不同设备号 → 中止；相同设备号 → 放行；真实 stat 正例（同 fs 必过，macOS 顺带覆盖 -f 回落）
test_a6() {
    local base stubdir
    base="$(mktemp -d)"
    stubdir="$(mktemp -d)"
    INSTALL_DIR="$base"
    mkdir -p "$INSTALL_DIR/backend/storage"

    # 6a. stat stub 对 storage 返回不同设备号 → 断言中止
    cat >"$stubdir/stat" <<'STATEOF'
#!/usr/bin/env bash
p=""
for p; do :; done
case "$p" in
    *storage*) echo 222 ;;
    *) echo 111 ;;
esac
STATEOF
    chmod +x "$stubdir/stat"
    local rc_diff
    (
        PATH="$stubdir:$PATH"
        _assert_storage_same_fs
    )
    rc_diff=$?

    # 6b. stat stub 全返回相同设备号 → 放行
    cat >"$stubdir/stat" <<'STATEOF'
#!/usr/bin/env bash
echo 111
STATEOF
    chmod +x "$stubdir/stat"
    local rc_same
    (
        PATH="$stubdir:$PATH"
        _assert_storage_same_fs
    )
    rc_same=$?

    # 6c. 真实 stat 正例：同 fs 临时目录必过（不 stub PATH）
    local rc_real
    (_assert_storage_same_fs)
    rc_real=$?

    local ok=1
    [ "$rc_diff" -ne 0 ] || ok=0 # 跨设备中止
    [ "$rc_same" -eq 0 ] || ok=0 # 同设备放行
    [ "$rc_real" -eq 0 ] || ok=0 # 真实同 fs 放行
    if [ "$ok" -eq 1 ]; then
        pass "A6 same-fs 断言：跨设备中止(rc=$rc_diff) + 同设备放行 + 真实同 fs 放行"
    else
        fail "A6 same-fs 断言（diff=${rc_diff} same=${rc_same} real=${rc_real}）"
    fi
    rm -rf "$base" "$stubdir"
}

# A7 vendor-only 残留回迁（⑧ Fix#2）：preserve 仅剩 vendor（无 storage）、backend/vendor 缺失 →
# 回迁到原位 + 置 NEED_COMPOSER_FORCE=1 + 清空壳；不当空壳静默 rm 掉唯一 vendor 副本致砖机
test_a7() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    mkdir -p "$INSTALL_DIR/backend"
    mkdir -p "$INSTALL_DIR/.upgrade-preserve-77777/vendor"
    printf 'AUTOLOAD' >"$INSTALL_DIR/.upgrade-preserve-77777/vendor/autoload.php"
    NEED_COMPOSER_FORCE=0
    # subshell 隔离潜在 exit；经文件回传 NEED_COMPOSER_FORCE（子进程改的全局不回传父 shell）
    (
        _check_stranded_preserve
        printf '%s' "$NEED_COMPOSER_FORCE" >"$base/flag"
    )
    local flag
    flag=$(cat "$base/flag" 2>/dev/null || true)
    local ok=1
    [ -f "$INSTALL_DIR/backend/vendor/autoload.php" ] || ok=0 # vendor 已回迁原位
    [ "$(cat "$INSTALL_DIR/backend/vendor/autoload.php" 2>/dev/null || true)" = "AUTOLOAD" ] || ok=0
    [ "$flag" = "1" ] || ok=0                               # 置强制重装标志
    [ ! -d "$INSTALL_DIR/.upgrade-preserve-77777" ] || ok=0 # 空壳被清
    if [ "$ok" -eq 1 ]; then
        pass "A7 vendor-only 回迁：唯一副本移回原位 + NEED_COMPOSER_FORCE=1 + 空壳清理"
    else
        fail "A7 vendor-only 回迁（vendor=$([ -f "$INSTALL_DIR/backend/vendor/autoload.php" ] && echo 在 || echo 缺) flag=${flag}）"
    fi
    rm -rf "$base"
}

# A8 extras 还原单元：preserve 有 api_adapters(order+acme) + frontend_config 副本、原位缺失 → 还原到位、rc=0
test_a8() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a8"
    mkdir -p "$PRESERVE_DIR/api_adapters/order" "$PRESERVE_DIR/api_adapters/acme" "$PRESERVE_DIR/frontend_config"
    printf 'ORDER-ADAPTER' >"$PRESERVE_DIR/api_adapters/order/MyOrderApi.php"
    printf 'ACME-ADAPTER' >"$PRESERVE_DIR/api_adapters/acme/MyAcmeApi.php"
    printf 'LOGO' >"$PRESERVE_DIR/frontend_config/user_logo.svg"
    printf 'PNG-QR' >"$PRESERVE_DIR/frontend_config/user_qrcode.png"
    printf 'LOGIN' >"$PRESERVE_DIR/frontend_config/user_login.svg"
    local rc
    (_restore_preserved_extras)
    rc=$?
    local ok=1
    [ "$rc" -eq 0 ] || ok=0
    [ "$(cat "$INSTALL_DIR/backend/app/Services/Order/Api/MyOrderApi.php" 2>/dev/null || true)" = "ORDER-ADAPTER" ] || ok=0
    [ "$(cat "$INSTALL_DIR/backend/app/Services/Acme/Api/MyAcmeApi.php" 2>/dev/null || true)" = "ACME-ADAPTER" ] || ok=0
    [ "$(cat "$INSTALL_DIR/frontend/user/logo.svg" 2>/dev/null || true)" = "LOGO" ] || ok=0
    [ "$(cat "$INSTALL_DIR/frontend/user/qrcode.png" 2>/dev/null || true)" = "PNG-QR" ] || ok=0
    [ "$(cat "$INSTALL_DIR/frontend/user/login.svg" 2>/dev/null || true)" = "LOGIN" ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "A8 extras 还原：api_adapters(order+acme) + frontend_config 还原到位、rc=0"
    else
        fail "A8 extras 还原（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A9 composer 判定（⑧ Fix#1）：vendor 缺失 / 回迁强制 / hash 变化 → 需要安装(0)；present+hash 相等+无强制 → 跳过(1)
test_a9() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    mkdir -p "$INSTALL_DIR/backend/vendor"
    local ok=1 r9a r9b r9c r9d

    # 9a. vendor/autoload.php 缺失 + hash 相等 → 需要安装（根治砖机的核心断言）
    NEED_COMPOSER_FORCE=0
    rm -f "$INSTALL_DIR/backend/vendor/autoload.php"
    _need_composer_install AAA AAA BBB BBB
    r9a=$?
    [ "$r9a" -eq 0 ] || ok=0

    # 9b. vendor present + hash 相等 + 无强制 → 跳过
    printf 'AUTOLOAD' >"$INSTALL_DIR/backend/vendor/autoload.php"
    NEED_COMPOSER_FORCE=0
    _need_composer_install AAA AAA BBB BBB
    r9b=$?
    [ "$r9b" -eq 1 ] || ok=0

    # 9c. vendor present + NEED_COMPOSER_FORCE=1 → 需要安装（回迁后对齐新 lock）
    NEED_COMPOSER_FORCE=1
    _need_composer_install AAA AAA BBB BBB
    r9c=$?
    [ "$r9c" -eq 0 ] || ok=0

    # 9d. vendor present + composer.json hash 变化 → 需要安装（常规依赖变更）
    NEED_COMPOSER_FORCE=0
    _need_composer_install AAA CCC BBB BBB
    r9d=$?
    [ "$r9d" -eq 0 ] || ok=0

    if [ "$ok" -eq 1 ]; then
        pass "A9 composer 判定：vendor 缺失/回迁强制/hash 变化→装(0)，present+相等+无强制→跳过(1)"
    else
        fail "A9 composer 判定（9a=$r9a 9b=$r9b 9c=$r9c 9d=${r9d}，期望 0/1/0/0）"
    fi
    rm -rf "$base"
}

test_a9b() {
    local base expected ok=1
    base="$(mktemp -d)"
    mkdir -p "$base/vendor/composer"
    printf 'LOCK' >"$base/composer.lock"
    printf 'AUTOLOAD' >"$base/vendor/autoload.php"
    expected="$(file_sha256 "$base/composer.lock")"
    printf '%s\n' "$expected" >"$base/vendor/composer/.ssl-manager-lock.sha256"

    _bundled_vendor_matches_lock "$base" || ok=0
    printf 'BROKEN' >"$base/vendor/composer/.ssl-manager-lock.sha256"
    if _bundled_vendor_matches_lock "$base"; then ok=0; fi

    if [ "$ok" -eq 1 ]; then
        pass "A9b 包内 vendor 仅在 autoload 与 lock SHA-256 标记对齐时通过"
    else
        fail "A9b 包内 vendor 完整性校验"
    fi
    rm -rf "$base"
}

test_a9c() {
    local base expected ok=1
    base="$(mktemp -d)"
    INSTALL_DIR="$base/install"
    BUNDLED_VENDOR_STAGE=""
    mkdir -p "$INSTALL_DIR/backend" "$base/source/vendor/composer"
    printf 'LOCK' >"$base/source/composer.lock"
    printf 'AUTOLOAD' >"$base/source/vendor/autoload.php"
    printf 'PACKAGE' >"$base/source/vendor/package.php"
    expected="$(file_sha256 "$base/source/composer.lock")"
    printf '%s\n' "$expected" >"$base/source/vendor/composer/.ssl-manager-lock.sha256"

    _stage_bundled_vendor "$base/source" || ok=0
    [ -d "$BUNDLED_VENDOR_STAGE" ] || ok=0
    [ -f "$BUNDLED_VENDOR_STAGE/composer/.ssl-manager-lock.sha256" ] || ok=0
    [ ! -d "$base/source/vendor" ] || ok=0
    _vendor_dir_matches_lock "$BUNDLED_VENDOR_STAGE" "$base/source/composer.lock" || ok=0

    if [ "$ok" -eq 1 ]; then
        pass "A9c 新 vendor 在安装盘完整预拷贝并校验后，源 vendor 才被隔离"
    else
        fail "A9c 新 vendor 原子启用预备"
    fi
    rm -rf "$base"
    BUNDLED_VENDOR_STAGE=""
}

test_a9d() {
    local base ok=1 rc
    base="$(mktemp -d)"
    INSTALL_DIR="$base/install"
    BUNDLED_VENDOR_STAGE=""
    mkdir -p "$INSTALL_DIR/backend/vendor" "$base/source/vendor/composer"
    printf 'OLD-AUTOLOAD' >"$INSTALL_DIR/backend/vendor/autoload.php"
    printf 'LOCK' >"$base/source/composer.lock"
    printf 'NEW-AUTOLOAD' >"$base/source/vendor/autoload.php"
    printf 'BROKEN\n' >"$base/source/vendor/composer/.ssl-manager-lock.sha256"

    _stage_bundled_vendor "$base/source"
    rc=$?
    [ "$rc" -ne 0 ] || ok=0
    [ "$(cat "$INSTALL_DIR/backend/vendor/autoload.php" 2>/dev/null || true)" = "OLD-AUTOLOAD" ] || ok=0
    [ -d "$base/source/vendor" ] || ok=0
    [ -z "$BUNDLED_VENDOR_STAGE" ] || ok=0
    if find "$INSTALL_DIR/backend" -maxdepth 1 -name '.vendor-next-*' -print -quit | grep -q .; then
        ok=0
    fi

    if [ "$ok" -eq 1 ]; then
        pass "A9d 新 vendor 校验失败时，旧 vendor 未触碰且临时预拷贝已清理"
    else
        fail "A9d 新 vendor 校验失败的覆盖前保护（rc=${rc}）"
    fi
    rm -rf "$base"
    BUNDLED_VENDOR_STAGE=""
}

test_a9e() {
    local base expected ok=1 rc
    base="$(mktemp -d)"
    mkdir -p "$base/vendor/composer"
    printf 'LOCK-A' >"$base/composer.lock"
    printf 'AUTOLOAD' >"$base/vendor/autoload.php"
    printf 'STALE' >"$base/vendor/composer/.ssl-manager-lock.sha256"

    _write_composer_lock_marker "$base" || ok=0
    expected="$(file_sha256 "$base/composer.lock")"
    [ "$(tr -d '[:space:]' <"$base/vendor/composer/.ssl-manager-lock.sha256")" = "$expected" ] || ok=0

    printf 'LOCK-B' >"$base/composer.lock"
    write_composer_lock_marker "$base" || ok=0
    expected="$(file_sha256 "$base/composer.lock")"
    [ "$(tr -d '[:space:]' <"$base/vendor/composer/.ssl-manager-lock.sha256")" = "$expected" ] || ok=0
    if find "$base/vendor/composer" -name '.ssl-manager-lock.sha256.tmp.*' -print -quit | grep -q .; then
        ok=0
    fi

    rm -f "$base/vendor/autoload.php"
    _write_composer_lock_marker "$base"
    rc=$?
    [ "$rc" -ne 0 ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "A9e Shell 安装与升级在 Composer 成功后原子刷新 lock 标记，前置缺失时失败关闭"
    else
        fail "A9e Shell Composer lock 标记刷新（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A10 cleanup 删 preserve 前还原 extras（⑨ 集成）：模拟中断在「rm 原件 ~ step9 还原」窗内，
# preserve 存自定义适配器唯一副本、原件已删 → cleanup 触发后适配器还原到位 + preserve 清理（不静默销毁）
test_a10() {
    local base harness
    base="$(mktemp -d)"
    harness="$base/h.sh"
    cat >"$harness" <<HDR
#!/usr/bin/env bash
set -e
log_info() { :; }
log_success() { :; }
log_error() { :; }
log_warning() { :; }
log_step() { :; }
_print_recovery_runbook() { :; }
INSTALL_DIR="$base"
TEMP_DIR="$base/tmp"
PRESERVE_DIR="$base/.upgrade-preserve-clx"
FREEZE_FIRED=0
UPGRADE_DONE=1
BOOTSTRAP_LOCK_HELD=0
HDR
    extract_fn "$UPGRADE" _restore_preserved_storage >>"$harness"
    extract_fn "$UPGRADE" _restore_preserved_extras >>"$harness"
    extract_fn "$UPGRADE" _release_bootstrap_lock >>"$harness"
    extract_fn "$UPGRADE" cleanup >>"$harness"
    cat >>"$harness" <<'MAIN'
trap cleanup EXIT
# 模拟：原件已删（backend/app 不存在），适配器唯一副本在 preserve；无 storage
mkdir -p "$PRESERVE_DIR/api_adapters/order"
printf 'CUSTOM-ADAPTER' > "$PRESERVE_DIR/api_adapters/order/CustomApi.php"
exit 0 # 触发 EXIT trap → cleanup
MAIN
    bash "$harness" >/dev/null 2>&1 || true
    local restored="$base/backend/app/Services/Order/Api/CustomApi.php"
    local ok=1
    [ "$(cat "$restored" 2>/dev/null || true)" = "CUSTOM-ADAPTER" ] || ok=0 # 适配器已还原
    [ ! -d "$base/.upgrade-preserve-clx" ] || ok=0                          # 还原成功 → preserve 清理
    if [ "$ok" -eq 1 ]; then
        pass "A10 cleanup 还原 extras：适配器唯一副本还原到位后再清 preserve（⑨ 不再静默销毁）"
    else
        fail "A10 cleanup 还原 extras（restored=$([ -f "$restored" ] && echo 在 || echo 缺) preserve=$([ -d "$base/.upgrade-preserve-clx" ] && echo 残留 || echo 清理)）"
    fi
    rm -rf "$base"
}

# A11 正常成功路径消费 extras：步骤 9 还原后必须删掉已成功使用的 preserve 副本；
# 随后的 EXIT cleanup 再调用守卫时应 no-op，不能覆盖步骤 9 之后的原位文件或打印中断还原。
test_a11() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a11"
    mkdir -p "$PRESERVE_DIR/api_adapters/order" "$PRESERVE_DIR/frontend_config"
    printf 'ORDER-ORIGINAL' >"$PRESERVE_DIR/api_adapters/order/CustomApi.php"
    printf 'LOGO-ORIGINAL' >"$PRESERVE_DIR/frontend_config/user_logo.svg"

    local rc_first rc_guard ok=1
    _restore_preserved_extras consume
    rc_first=$?
    printf 'ORDER-AFTER-STEP9' >"$INSTALL_DIR/backend/app/Services/Order/Api/CustomApi.php"
    printf 'LOGO-AFTER-STEP9' >"$INSTALL_DIR/frontend/user/logo.svg"
    _restore_preserved_extras
    rc_guard=$?

    [ "$rc_first" -eq 0 ] || ok=0
    [ "$rc_guard" -eq 0 ] || ok=0
    [ ! -d "$PRESERVE_DIR/api_adapters/order" ] || ok=0
    [ ! -f "$PRESERVE_DIR/frontend_config/user_logo.svg" ] || ok=0
    [ "$(cat "$INSTALL_DIR/backend/app/Services/Order/Api/CustomApi.php")" = "ORDER-AFTER-STEP9" ] || ok=0
    [ "$(cat "$INSTALL_DIR/frontend/user/logo.svg")" = "LOGO-AFTER-STEP9" ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "A11 正常恢复消费 extras：cleanup no-op，不再重复覆盖或误报中断还原"
    else
        fail "A11 正常恢复未消费 extras（first=${rc_first} guard=${rc_guard}）"
    fi
    rm -rf "$base"
}

# A12 consume 模式下「cp 成功但 rm 清理失败」必须不影响返回码：此时数据面已正确，
# 纯清理动作无权把一次正确的升级打断在步骤 9（那会触发恢复 runbook 并让站点滞留维护态）。
test_a12() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"
    PRESERVE_DIR="$base/.upgrade-preserve-a12"
    mkdir -p "$PRESERVE_DIR/api_adapters/order" "$PRESERVE_DIR/frontend_config"
    printf 'ORDER-ORIGINAL' >"$PRESERVE_DIR/api_adapters/order/CustomApi.php"
    printf 'LOGO-ORIGINAL' >"$PRESERVE_DIR/frontend_config/user_logo.svg"
    # 只读父目录 → 其中的删除操作失败，但读取/复制不受影响
    chmod 500 "$PRESERVE_DIR/api_adapters" "$PRESERVE_DIR/frontend_config"

    local rc ok=1
    _restore_preserved_extras consume
    rc=$?

    [ "$rc" -eq 0 ] || ok=0
    [ "$(cat "$INSTALL_DIR/backend/app/Services/Order/Api/CustomApi.php" 2>/dev/null)" = "ORDER-ORIGINAL" ] || ok=0
    [ "$(cat "$INSTALL_DIR/frontend/user/logo.svg" 2>/dev/null)" = "LOGO-ORIGINAL" ] || ok=0
    chmod 700 "$PRESERVE_DIR/api_adapters" "$PRESERVE_DIR/frontend_config" 2>/dev/null || true
    if [ "$ok" -eq 1 ]; then
        pass "A12 consume 清理失败不参与返回码：内容已落位，升级不被纯清理动作打断"
    else
        fail "A12 consume 清理失败仍中止升级（rc=${rc}）"
    fi
    rm -rf "$base"
}

# A13 核心运行目录：完整清单可创建/验写，任一权限步骤失败立即返回非零。
test_a13() {
    local base
    base="$(mktemp -d)"
    INSTALL_DIR="$base"

    local rc_success rc_failure
    (
        chown() { return 0; }
        chmod() { return 0; }
        sudo() {
            local last=""
            for last in "$@"; do :; done
            test -w "$last"
        }
        _ensure_runtime_directories
    )
    rc_success=$?

    local -a expected=(
        backend/bootstrap/cache
        backend/storage
        backend/storage/logs
        backend/storage/framework
        backend/storage/framework/cache/data
        backend/storage/framework/sessions
        backend/storage/framework/views
        backend/storage/app/public
        backend/storage/app/private
        backups/upgrades
    )
    local rel ok=1
    [ "$rc_success" -eq 0 ] || ok=0
    for rel in "${expected[@]}"; do
        [ -d "$INSTALL_DIR/$rel" ] || ok=0
    done

    (
        chown() { return 0; }
        chmod() {
            [ "${2:-}" = "$INSTALL_DIR/backend/storage/app/private" ] && return 1
            return 0
        }
        sudo() { return 0; }
        _ensure_runtime_directories
    )
    rc_failure=$?
    [ "$rc_failure" -ne 0 ] || ok=0

    if [ "$ok" -eq 1 ]; then
        pass "A13 核心运行目录清单完整，权限步骤失败即停"
    else
        fail "A13 核心运行目录创建/失败语义（success=${rc_success}, failure=${rc_failure}）"
    fi
    rm -rf "$base"
}

# A14 两个生产调用位点失败都必须真正退出：首次不能进入备份/维护，第二次由 EXIT
# 清理路径输出恢复指引且不能继续 Composer/unfreeze/up。
test_a14() {
    local first_guard second_guard first_marker second_marker runbook_marker
    first_guard="$(extract_runtime_guard "$UPGRADE" 1)"
    second_guard="$(extract_runtime_guard "$UPGRADE" 2)"
    first_marker="$(mktemp)"
    second_marker="$(mktemp)"
    runbook_marker="$(mktemp)"

    (
        _ensure_runtime_directories() { return 1; }
        eval "$first_guard"
        printf '%s\n' create_backup artisan_down upgrade_freeze >"$first_marker"
    )
    local first_rc=$?

    (
        _ensure_runtime_directories() { return 1; }
        log_error() { printf '%s\n' "$*" >>"$runbook_marker"; }
        INSTALL_DIR="/tmp/runtime-directory-probe"
        PHP_CMD="php"
        UPGRADE_DONE=0
        FREEZE_FIRED=1
        cleanup_runtime_probe() {
            if [ "$UPGRADE_DONE" -eq 0 ] && [ "$FREEZE_FIRED" -eq 1 ]; then
                _print_recovery_runbook
            fi
        }
        trap cleanup_runtime_probe EXIT

        eval "$second_guard"
        printf '%s\n' composer artisan_unfreeze artisan_up >"$second_marker"
    )
    local second_rc=$?

    if [ -n "$first_guard" ] && [ -n "$second_guard" ] &&
        [ "$first_rc" -ne 0 ] && [ ! -s "$first_marker" ] &&
        [ "$second_rc" -ne 0 ] && [ ! -s "$second_marker" ] &&
        grep -qF '升级未完成，系统仍处于 freeze + 维护模式' "$runbook_marker" &&
        grep -qF 'artisan upgrade:unfreeze' "$runbook_marker"; then
        pass "A14 两阶段运行目录失败均在真实调用闸终止并保持既定恢复语义"
    else
        fail "A14 两阶段调用位点失败语义（first=${first_rc}, second=${second_rc}）"
    fi

    rm -f "$first_marker" "$second_marker" "$runbook_marker"
}

test_a1
test_a2
test_a3
test_a4
test_a5
test_a6
test_a7
test_a8
test_a9
test_a9b
test_a9c
test_a9d
test_a9e
test_a10
test_a11
test_a12
test_a13
test_a14

# ========================================================================
# B. 信号注入（子进程 harness，直接复现验收⑦）
# ========================================================================
echo ""
echo "=== B. 信号注入（子进程 harness）==="

# 生成最小 harness：抽取的全局 + _restore_preserved_storage + cleanup + trap 装配 + mv 出 → sleep
# PRESERVE_DIR 命名为 $INSTALL_DIR/.upgrade-preserve-harness（B4 靠此被 _check_stranded_preserve 扫到）
write_harness() {
    local hf="$1" inst="$2" ready="$3" sent="$4" slept="$5"
    cat >"$hf" <<HDR
#!/usr/bin/env bash
set -e
log_info() { :; }
log_success() { :; }
log_error() { :; }
log_warning() { :; }
log_step() { :; }
_print_recovery_runbook() { :; }
INSTALL_DIR="$inst"
TEMP_DIR="$inst/tmp"
PRESERVE_DIR="$inst/.upgrade-preserve-harness"
FREEZE_FIRED=0
UPGRADE_DONE=0
BOOTSTRAP_LOCK_HELD=0
PHP_CMD=php
HDR
    extract_fn "$UPGRADE" _restore_preserved_storage >>"$hf"
    extract_fn "$UPGRADE" _restore_preserved_extras >>"$hf"
    extract_fn "$UPGRADE" _release_bootstrap_lock >>"$hf"
    extract_fn "$UPGRADE" cleanup >>"$hf"
    cat >>"$hf" <<MAIN
trap cleanup EXIT
trap cleanup INT TERM HUP
mkdir -p "\$INSTALL_DIR/backend/storage/databak"
printf '%s' "$sent" > "\$INSTALL_DIR/$DATABAK_REL"
mkdir -p "\$PRESERVE_DIR"
mv "\$INSTALL_DIR/backend/storage" "\$PRESERVE_DIR/storage"
touch "$ready"
sleep 3
touch "$slept" # 睡满哨兵：仅当无信号中断（trap 未装/未触发）才写；信号真触发时 cleanup exit 抢在此行前
MAIN
}

# 等 ready 标志出现（harness 完成 mv 出、进入 sleep），避免信号早于 mv 的竞态
wait_ready() {
    local ready="$1" i
    for i in $(seq 1 100); do
        [ -f "$ready" ] && return 0
        sleep 0.1
    done
    return 1
}

run_signal_test() {
    local sig="$1" label="$2"
    local base ready harness sent slept pid
    base="$(mktemp -d)"
    ready="$base/ready"
    slept="$base/slept"
    harness="$base/harness.sh"
    sent="SENTINEL-$sig-$$"
    write_harness "$harness" "$base" "$ready" "$sent" "$slept"
    # set -m 启用 job control（⑫ 修复）：否则非交互父 shell 的后台子进程会继承 SIGINT=SIG_IGN，
    # 而 POSIX 规定已忽略信号无法再 trap → harness 的 `trap cleanup INT` 根本没装上、kill -INT 被内核丢弃，
    # B1 睡满 3s 走 EXIT trap 路径（对 SIGINT 的假覆盖，与 A1/EXIT 重复）。job control 让后台子进程获得
    # 默认(可 trap)信号处置，SIGINT 真正命中 trap。macOS 无 setsid，set -m 是跨 bash 3.2/5.x 的可移植解。
    set -m
    bash "$harness" &
    pid=$!
    set +m
    wait_ready "$ready" || true
    kill -"$sig" "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    local target="$base/$DATABAK_REL"
    local ok=1
    [ -f "$target" ] || ok=0                                     # storage 已还原回原位
    [ "$(cat "$target" 2>/dev/null || true)" = "$sent" ] || ok=0 # databak 字节一致（同一份非新建空）
    # 哨兵：信号必须真触发 trap → cleanup 的 exit 抢在 sleep 之后的 `touch slept` 之前 → slept 标志不出现。
    # 未装/未触发 trap 的假覆盖会睡满 → touch slept → 此断言失败，正是当年 B1 假绿的证伪点。
    [ ! -f "$slept" ] || ok=0
    if [ "$ok" -eq 1 ]; then
        pass "${label}：信号真触发 trap 还原 storage/databak（未睡满，非 EXIT 假覆盖）"
    else
        fail "${label}：storage=$([ -f "$target" ] && echo 存在 || echo 缺失) 内容=$([ "$(cat "$target" 2>/dev/null || true)" = "$sent" ] && echo 一致 || echo 否) 睡满=$([ -f "$slept" ] && echo 是-假覆盖 || echo 否)"
    fi
    rm -rf "$base"
}

run_signal_test INT "B1 SIGINT(Ctrl-C)"
run_signal_test TERM "B2 SIGTERM"
run_signal_test HUP "B3 SIGHUP(SSH 断连)"

# B4 SIGKILL + 重跑拦截：kill -KILL（trap 不跑）→ 数据存活于 .upgrade-preserve-*/storage；
# 随后模拟重跑入口 _check_stranded_preserve → 必须被拦截、不造空 storage、搁浅数据未动
test_b4() {
    local base ready harness sent pid
    base="$(mktemp -d)"
    ready="$base/ready"
    harness="$base/harness.sh"
    sent="SENTINEL-KILL-$$"
    write_harness "$harness" "$base" "$ready" "$sent" "$base/slept" # slept 参数补齐；SIGKILL 不 trap 故不断言
    bash "$harness" &
    pid=$!
    wait_ready "$ready" || true
    kill -KILL "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true

    local preserved="$base/.upgrade-preserve-harness/storage/databak/db_20260101_000000.sql.gz"
    local ok=1
    [ -f "$preserved" ] || ok=0 # U1 持久 fs backstop：数据存活
    [ "$(cat "$preserved" 2>/dev/null || true)" = "$sent" ] || ok=0
    [ ! -d "$base/backend/storage" ] || ok=0 # trap 未跑：尚未还原
    if [ "$ok" -eq 1 ]; then
        pass "B4a SIGKILL 后数据存活于 .upgrade-preserve-*/storage（U1 持久 fs backstop）"
    else
        fail "B4a SIGKILL 数据存活"
    fi

    # 模拟重跑升级入口
    INSTALL_DIR="$base"
    local rc
    (_check_stranded_preserve)
    rc=$?
    local ok2=1
    [ "$rc" -ne 0 ] || ok2=0                  # 被拦截
    [ ! -d "$base/backend/storage" ] || ok2=0 # 未静默造空 storage
    [ -f "$preserved" ] || ok2=0              # 搁浅数据未动
    if [ "$ok2" -eq 1 ]; then
        pass "B4b SIGKILL 后重跑被 _check_stranded_preserve 拦截、真数据未被埋（Important-2 闭环）"
    else
        fail "B4b 重跑拦截（rc=${rc}）"
    fi
    rm -rf "$base"
}

test_b4

# ========================================================================
# C. 回归守卫
# ========================================================================
echo ""
echo "=== C. 回归守卫 ==="

# 钉死 PRESERVE_DIR 展开在 $INSTALL_DIR 下（非 /tmp），防未来改回 tmpfs 复制窗
if grep -qF 'PRESERVE_DIR="$INSTALL_DIR/.upgrade-preserve-$$"' "$UPGRADE"; then
    pass "C PRESERVE_DIR 钉死在 \$INSTALL_DIR 下（非 /tmp）"
else
    fail "C PRESERVE_DIR 改址被回退（应为 \$INSTALL_DIR/.upgrade-preserve-\$\$）"
fi

# 钉死生产信号 trap 装配行：B 组 harness 自装配 trap，删掉 upgrade.sh 生产行 B 组仍绿；且信号 trap 与
# EXIT trap 同调 cleanup、副作用一致（bash 3.2/5.x 实测：删信号 trap 后 EXIT trap 仍还原），黑盒断言
# 无法区分二者，故 trap 装配的回归守卫只能靠此源码 grep（非 reached_end 之类的运行时哨兵）。
if grep -qE 'trap cleanup INT TERM HUP' "$UPGRADE"; then
    pass "C 生产信号 trap 装配行钉死（trap cleanup INT TERM HUP）"
else
    fail "C 生产信号 trap 装配行缺失（应有 trap cleanup INT TERM HUP）"
fi

lock_acquire_line=$(grep -nE '^[[:space:]]*_acquire_bootstrap_lock$' "$UPGRADE" | head -1 | cut -d: -f1 || true)
legacy_prepare_line=$(grep -nE '^[[:space:]]*_prepare_legacy_bootstrap_entry ' "$UPGRADE" | head -1 | cut -d: -f1 || true)
vendor_preserve_line=$(grep -nF 'mv "$INSTALL_DIR/backend/vendor" "$PRESERVE_DIR/"' "$UPGRADE" | head -1 | cut -d: -f1 || true)
lock_release_line=$(grep -nE '^[[:space:]]*_release_bootstrap_lock$' "$UPGRADE" | tail -1 | cut -d: -f1 || true)
if grep -qF 'local lock_file="$INSTALL_DIR/backend/.upgrade-bootstrap.lock"' "$UPGRADE" &&
    grep -qF 'UPGRADE_LEGACY_REQUEST_DRAIN_TIMEOUT' "$UPGRADE" &&
    grep -qF 'ApplicationBootstrapLock::prepareLegacyHttpEntry' "$UPGRADE" &&
    [ -n "$legacy_prepare_line" ] && [ -n "$lock_acquire_line" ] && [ -n "$vendor_preserve_line" ] && [ -n "$lock_release_line" ] &&
    [ "$legacy_prepare_line" -lt "$lock_acquire_line" ] && [ "$lock_acquire_line" -lt "$vendor_preserve_line" ] &&
    [ "$vendor_preserve_line" -lt "$lock_release_line" ]; then
    pass "C Shell 首次升级先排空旧请求，随后目录替换全程持应用启动独占锁"
else
    fail "C Shell 首次升级排空或启动锁未覆盖 vendor/代码切换窗口"
fi

if ! grep -qF 'rm -rf "$INSTALL_DIR/backend"' "$UPGRADE" &&
    grep -qF '! -name index.php' "$UPGRADE"; then
    pass "C Shell 回滚保留 backend 锁 inode 与 public/index.php 稳定入口"
else
    fail "C Shell 回滚仍可能替换 backend 锁 inode 或共享锁入口"
fi

if grep -qF 'scripts/write-composer-lock-marker.php' "$ROOT/backend/composer.json" &&
    grep -qF '@php ../../../backend/scripts/write-composer-lock-marker.php .' "$ROOT/plugins/cloud-deploy/backend/composer.json" &&
    [ -f "$ROOT/backend/scripts/write-composer-lock-marker.php" ] &&
    [ ! -e "$ROOT/plugins/cloud-deploy/backend/scripts/write-composer-lock-marker.php" ]; then
    pass "C 主系统与云部署插件的 Composer install/update/dump 共享唯一 marker 入口"
else
    fail "C 主系统或云部署插件未共享唯一 Composer marker 入口"
fi

if grep -qF '_vendor_dir_matches_lock "$INSTALL_DIR/backend/vendor" "$src_dir/backend/composer.lock"' "$UPGRADE" &&
    grep -qF 'BUNDLED_VENDOR_REUSED=1' "$UPGRADE" &&
    grep -qF '[ "$BUNDLED_VENDOR_REUSED" -eq 0 ] && [ -d "$INSTALL_DIR/backend/vendor" ]' "$UPGRADE"; then
    pass "C Shell 目标 lock 未变化时原地复用在线 vendor"
else
    fail "C Shell 未在目标 lock 相同时避免 vendor 暂存与切换"
fi

if ! grep -qF 'rm -rf "$INSTALL_DIR/backend/public"' "$UPGRADE" &&
    grep -qF '! -name index.php' "$UPGRADE"; then
    pass "C Shell 切换窗保留 public/index.php 作为共享锁稳定入口"
else
    fail "C Shell 仍会在独占锁期间删除 public/index.php"
fi

rollback_start_line=$(grep -nE '^rollback\(\)' "$UPGRADE" | head -1 | cut -d: -f1 || true)
rollback_lock_line=$(grep -nE '^[[:space:]]*_acquire_bootstrap_lock$' "$UPGRADE" | tail -1 | cut -d: -f1 || true)
rollback_restore_line=$(grep -nF 'rm -rf "$INSTALL_DIR/backend/$dir"' "$UPGRADE" | tail -1 | cut -d: -f1 || true)
rollback_unlock_line=$(grep -nE '^[[:space:]]*_release_bootstrap_lock$' "$UPGRADE" | tail -1 | cut -d: -f1 || true)
rollback_up_line=$(grep -nF 'artisan upgrade:unfreeze' "$UPGRADE" | tail -1 | cut -d: -f1 || true)
if [ -n "$rollback_start_line" ] && [ -n "$rollback_lock_line" ] && [ -n "$rollback_restore_line" ] &&
    [ -n "$rollback_unlock_line" ] && [ -n "$rollback_up_line" ] &&
    [ "$rollback_start_line" -lt "$rollback_lock_line" ] &&
    [ "$rollback_lock_line" -lt "$rollback_restore_line" ] &&
    [ "$rollback_restore_line" -lt "$rollback_unlock_line" ] &&
    [ "$rollback_unlock_line" -lt "$rollback_up_line" ]; then
    pass "C Shell 回滚文件替换同样受应用启动独占锁保护"
else
    fail "C Shell 回滚未完整覆盖启动锁或释放顺序错误"
fi

# ⑧ 钉死 composer 触发的 vendor 缺失兜底：删掉它 → 中断丢 vendor 重跑因 hash 相等跳过 composer → 砖机。
# 用 _need_composer_install 内唯一告警文案定位（`if [ ! -f ...autoload.php ]` 另在 completeness 校验处出现、不特异）
if grep -qF '强制重装 composer 依赖（防中断升级后 hash 相等跳过致砖机）' "$UPGRADE"; then
    pass "C composer vendor 缺失兜底钉死（防中断丢 vendor 后 hash 相等跳过致砖机，⑧）"
else
    fail "C composer vendor 缺失兜底缺失（_need_composer_install 应有 vendor/autoload.php 缺失强制重装）"
fi

# ⑨ 钉死 cleanup 删 preserve 前先还原 extras：防回退成「还原 storage 后直接 rm preserve 连适配器一起销毁」
if grep -qE '_restore_preserved_extras; then' "$UPGRADE"; then
    pass "C cleanup 删 preserve 前还原 extras 钉死（api_adapters/frontend_config 不静默销毁，⑨）"
else
    fail "C cleanup 未在删 preserve 前还原 extras（应 if _restore_preserved_extras; then ... rm PRESERVE_DIR）"
fi

# 正常步骤 9 必须以 consume 模式恢复并消费副本；否则成功 EXIT cleanup 会重复还原并误报“中断升级遗留”。
if grep -qE '_restore_preserved_extras[[:space:]]+consume' "$UPGRADE"; then
    pass "C 正常恢复消费 extras，成功 cleanup 不再重复还原"
else
    fail "C 正常步骤 9 未消费 extras（成功退出仍会误报中断还原）"
fi

if grep -qE 'for file in .*platform-config\.json|frontend_(config|assets)/(admin|user)_platform-config' "$UPGRADE"; then
    fail "C upgrade.sh 不应再把 platform-config.json 纳入 preserve/restore 前端回写"
else
    pass "C upgrade.sh 不再把 platform-config.json 纳入 preserve/restore 前端回写"
fi

# 存量导入链：替换前端前必须把旧 platform-config.json 暂存到 storage 供 Seeder 导入，
# seed 成功后才清理暂存，失败必须中止并保留数据供重跑。
seed_line=$(grep -nF 'artisan db:seed --force' "$UPGRADE" | head -1 | cut -d: -f1 || true)
cleanup_line=$(grep -nE 'rm -rf .*legacy-platform-config' "$UPGRADE" | head -1 | cut -d: -f1 || true)
if grep -qE 'legacy-platform-config/\$side\.json' "$UPGRADE" &&
    [ -n "$seed_line" ] && [ -n "$cleanup_line" ] && [ "$seed_line" -lt "$cleanup_line" ] &&
    ! grep -qE 'artisan db:seed --force[[:space:]]*\|\|[[:space:]]*true' "$UPGRADE"; then
    pass "C upgrade.sh 存量 platform-config 暂存 + seed 成功后清理链完整"
else
    fail "C upgrade.sh 缺少存量 platform-config 暂存或 seed 成功后清理（Seeder 导入链断裂）"
fi

# 中断重跑守卫：暂存必须"已存在不覆盖"（重跑时前端已是新包配置，无守卫 cp 会冲掉旧值暂存）
if grep -qE '\[ -f "\$INSTALL_DIR/backend/storage/app/legacy-platform-config/\$side\.json" \] && continue' "$UPGRADE"; then
    pass "C upgrade.sh 暂存带已存在不覆盖守卫（中断重跑安全）"
else
    fail "C upgrade.sh 暂存缺少已存在不覆盖守卫（中断重跑会用新包配置冲掉旧值）"
fi

# 按键判定：仅含迁移键（Title/Beian/Brands）的旧配置才暂存，新版配置不再逐次产生暂存
if grep -qE 'grep -qE .+(Title\|Beian\|Brands).+platform-config\.json" \|\| continue' "$UPGRADE"; then
    pass "C upgrade.sh 暂存按迁移键判定（新版配置不再暂存）"
else
    fail "C upgrade.sh 暂存缺少迁移键判定（每次升级都会产生无用暂存）"
fi

if grep -qF 'platform-config.json' "$BUILD_CONFIG"; then
    fail "C upgrade 包不应再排除 platform-config.json"
else
    pass "C upgrade 包携带新版 platform-config.json"
fi

if grep -qE 'frontend_config/admin_logo|frontend/admin/logo\.svg' "$UPGRADE" "$BUILD_CONFIG"; then
    fail "C admin logo 不应再进入升级保护或排除清单"
else
    pass "C admin logo 不再进入升级保护或排除清单"
fi

if [ -e "$ROOT/build/web/public/favicon.ico" ] ||
    grep -qF 'favicon.ico' "$COLLECT_ARTIFACTS"; then
    fail "C 系统默认 favicon 不应进入 Web 静态产物"
else
    pass "C Web 静态产物不再携带系统默认 favicon"
fi

if grep -qF '"storage/app/"' "$BUILD_CONFIG" &&
    grep -qF '"storage/databak/"' "$BUILD_CONFIG" &&
    grep -qF '"storage/pay/"' "$BUILD_CONFIG" &&
    grep -qF '"storage/framework/views/"' "$BUILD_CONFIG" &&
    grep -qF "jq -r '.exclude_patterns.backend[]'" "$CONTAINER_BUILD" "$COLLECT_ARTIFACTS" &&
    grep -qF 'rm -rf "$WORKSPACE_DIR/backend/storage" "$WORKSPACE_DIR/backend/bootstrap/cache"' "$CONTAINER_BUILD" &&
    grep -qF 'rm -rf "$PRODUCTION_DIR/backend/storage" "$PRODUCTION_DIR/backend/bootstrap/cache"' "$COLLECT_ARTIFACTS" &&
    grep -qF '"$SCRIPT_DIR/audit-package.sh"' "$PACKAGE_SCRIPT"; then
    pass "C 构建工作区、产物汇总、打包与内容审计均阻断运行数据"
else
    fail "C 运行数据缺少构建清理、统一排除或发布包内容审计"
fi

ensure_calls=$(grep -nE '^[[:space:]]*if ! _ensure_runtime_directories; then' "$UPGRADE" || true)
ensure_count=$(printf '%s\n' "$ensure_calls" | grep -c . || true)
first_ensure_line=$(printf '%s\n' "$ensure_calls" | sed -n '1s/:.*//p')
second_ensure_line=$(printf '%s\n' "$ensure_calls" | sed -n '2s/:.*//p')
stranded_line=$(grep -nE '^[[:space:]]*_check_stranded_preserve$' "$UPGRADE" | head -1 | cut -d: -f1 || true)
backup_line=$(grep -nF 'local backup_path=$(create_backup)' "$UPGRADE" | head -1 | cut -d: -f1 || true)
down_line=$(grep -nF 'artisan down --retry=60' "$UPGRADE" | head -1 | cut -d: -f1 || true)
composer_line=$(grep -nF 'log_step "检测依赖变化..."' "$UPGRADE" | head -1 | cut -d: -f1 || true)

if grep -qF 'mkdir -p "$UPGRADE_DIR/backend/bootstrap/cache"' "$PACKAGE_SCRIPT" &&
    [ "$ensure_count" -eq 2 ] &&
    [ -n "$stranded_line" ] && [ -n "$first_ensure_line" ] && [ -n "$backup_line" ] && [ -n "$down_line" ] &&
    [ "$stranded_line" -lt "$first_ensure_line" ] && [ "$first_ensure_line" -lt "$backup_line" ] &&
    [ "$first_ensure_line" -lt "$down_line" ] &&
    [ -n "$second_ensure_line" ] && [ -n "$composer_line" ] && [ "$second_ensure_line" -lt "$composer_line" ] &&
    grep -qF 'backend/storage/framework/cache/data' "$UPGRADE" &&
    grep -qF 'backend/storage/app/private' "$UPGRADE" &&
    grep -qF 'sudo -u www test -w "$abs_path"' "$UPGRADE"; then
    pass "C upgrade 包保留 cache 空目录，Shell 两阶段补齐并以 www 验写核心目录"
else
    fail "C upgrade 包或 upgrade.sh 核心运行目录清单、两阶段顺序或 www 验写缺失"
fi

runtime_contract_tmp="$(mktemp -d)"
extract_shell_runtime_dirs "$UPGRADE" | sort -u >"$runtime_contract_tmp/upgrade"
extract_shell_runtime_dirs "$BT_INSTALL" | sort -u >"$runtime_contract_tmp/install"
extract_shell_runtime_dirs "$PACKAGE_SCRIPT" | sort -u >"$runtime_contract_tmp/package"
extract_php_runtime_dirs "$PACKAGE_EXTRACTOR" | sort -u >"$runtime_contract_tmp/backend"
runtime_contract_ok=1
cmp -s "$runtime_contract_tmp/upgrade" "$runtime_contract_tmp/install" || runtime_contract_ok=0
cmp -s "$runtime_contract_tmp/upgrade" "$runtime_contract_tmp/package" || runtime_contract_ok=0
cmp -s "$runtime_contract_tmp/upgrade" "$runtime_contract_tmp/backend" || runtime_contract_ok=0
while IFS= read -r rel_path; do
    grep -qF "full/$rel_path/" "$PACKAGE_AUDITOR" || runtime_contract_ok=0
done <"$runtime_contract_tmp/upgrade"

if [ "$runtime_contract_ok" -eq 1 ] && [ "$(wc -l <"$runtime_contract_tmp/upgrade" | tr -d ' ')" -eq 10 ]; then
    pass "C 安装、Shell 升级、后台升级、完整包与审计的核心运行目录集合等价"
else
    fail "C 核心运行目录跨生产路径或制包审计发生漂移"
    diff -u "$runtime_contract_tmp/upgrade" "$runtime_contract_tmp/install" || true
    diff -u "$runtime_contract_tmp/upgrade" "$runtime_contract_tmp/package" || true
    diff -u "$runtime_contract_tmp/upgrade" "$runtime_contract_tmp/backend" || true
fi
rm -rf "$runtime_contract_tmp"

QRCODE_PNG="$ROOT/frontend/user/public/qrcode.png"
QRCODE_PNG_HEADER="$(od -An -tx1 -N24 "$QRCODE_PNG" 2>/dev/null | tr -d ' \n')"
if [ -f "$ROOT/frontend/user/public/logo.svg" ] &&
    [ -f "$QRCODE_PNG" ] &&
    [ "$QRCODE_PNG_HEADER" = "89504e470d0a1a0a0000000d494844520000019000000190" ] &&
    [ ! -e "$ROOT/frontend/user/public/qrcode.svg" ] &&
    ! grep -qF '"frontend/user/qrcode.svg"' "$BUILD_CONFIG" &&
    grep -qF '"frontend/user/qrcode.png"' "$BUILD_CONFIG" &&
    grep -qF '$CUSTOM_DIR/qrcode.png' "$CONTAINER_BUILD" &&
    grep -qF '$WORKSPACE_DIR/frontend/user/public/qrcode.png' "$CONTAINER_BUILD" &&
    ! grep -qF 'qrcode.svg' "$CONTAINER_BUILD"; then
    pass "C 完整包和定制构建使用 400x400 PNG，升级包不交付二维码并仅保护 PNG"
else
    fail "C 默认/定制构建 PNG 错误、仍携带 SVG，或升级包未排除 PNG"
fi

LOGIN_SVG="$ROOT/frontend/user/public/login.svg"
if [ -f "$LOGIN_SVG" ] &&
    [ "$(wc -c <"$LOGIN_SVG")" -le 2048 ] &&
    ! grep -qF '"frontend/user/login.svg"' "$BUILD_CONFIG" &&
    grep -qF 'login.svg' "$ROOT/deploy/upgrade.sh"; then
    pass "C 登录配图随完整包与升级包交付，升级流程保留安装目录已有 login.svg"
else
    fail "C 默认 login.svg 缺失/过大、被升级包错误排除，或 upgrade.sh 未保留 login.svg"
fi

echo ""
echo "================================"
echo "PASS=$PASS  FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
