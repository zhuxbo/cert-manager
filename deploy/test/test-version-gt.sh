#!/bin/bash
# 测试 deploy/upgrade.sh::version_gt 的 SemVer 比较行为
#
# 历史 bug：原实现走 GNU sort -V，对 SemVer 预发布处理有以下错误（实测 coreutils 8.32）：
#   1. 0.5.2 < 0.5.2-beta.10（实际应正式版 > 预发布）
#   2. dev 排在 beta 之后（实际应 dev < alpha < beta < rc）
# 因此 `bash upgrade.sh 0.5.2`（当前 0.5.2-beta.10）会被误判"不允许降级"。
#
# 修复：纯 bash 实现 SemVer，对齐后端 PHP version_compare 与前端 compareVersions。
#
# 用法：bash test-version-gt.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT="$(cd "$DEPLOY_DIR/.." && pwd)"
UPGRADE_SH="$DEPLOY_DIR/upgrade.sh"
source "$SCRIPT_DIR/php-test-runner.sh"

# 提取 version_gt 函数体（'version_gt() {' 到 '^}$'）— 避免 source 整个 upgrade.sh 触发 main
TEST_TMP=$(mktemp -d)
TMP="$TEST_TMP/version-gt.sh"
trap 'rm -rf "$TEST_TMP"' EXIT

awk '
    /^version_gt\(\) \{/ { in_fn = 1; print; next }
    in_fn && /^\}$/      { print; in_fn = 0; exit }
    in_fn                { print }
' "$UPGRADE_SH" >"$TMP"

if ! grep -q '^version_gt()' "$TMP"; then
    echo "❌ 无法从 $UPGRADE_SH 提取 version_gt 函数体"
    exit 1
fi

# shellcheck source=/dev/null
. "$TMP"

PASS=0
FAIL=0

assert_gt() {
    local v1="$1" v2="$2" desc="$3"
    if version_gt "$v1" "$v2"; then
        echo "✓ version_gt($v1, $v2) — $desc"
        PASS=$((PASS + 1))
    else
        echo "✗ version_gt($v1, $v2) returned false (expected: $v1 > $v2) — $desc"
        FAIL=$((FAIL + 1))
    fi
}

assert_not_gt() {
    local v1="$1" v2="$2" desc="$3"
    if version_gt "$v1" "$v2"; then
        echo "✗ version_gt($v1, $v2) returned true (expected: $v1 <= $v2) — $desc"
        FAIL=$((FAIL + 1))
    else
        echo "✓ !version_gt($v1, $v2) — $desc"
        PASS=$((PASS + 1))
    fi
}

echo "=== 数字段递增（用户报告核心场景）==="
assert_gt "0.5.2-beta.10" "0.5.2-beta.9" "beta.10 > beta.9"
assert_not_gt "0.5.2-beta.9" "0.5.2-beta.10" "beta.9 <= beta.10"
assert_gt "1.0.0-rc.11" "1.0.0-rc.2" "rc.11 > rc.2（两位 vs 个位字典序陷阱）"
assert_gt "1.0.0-alpha.100" "1.0.0-alpha.99" "三位 vs 两位"

echo ""
echo "=== 正式版 > 预发布（sort -V 误判场景）==="
assert_gt "0.5.2" "0.5.2-beta.10" "0.5.2 正式 > beta.10"
assert_not_gt "0.5.2-beta.10" "0.5.2" "beta.10 预发布 <= 正式"
assert_gt "1.0.0" "1.0.0-rc.99" "正式 > rc"
assert_gt "1.0.0" "1.0.0-dev" "正式 > dev"

echo ""
echo "=== 关键字优先级（sort -V dev 位置错场景）==="
assert_gt "1.0.0-rc.1" "1.0.0-beta.99" "rc > beta"
assert_gt "1.0.0-beta.1" "1.0.0-alpha.99" "beta > alpha"
assert_gt "1.0.0-alpha.1" "1.0.0-dev.99" "alpha > dev"
assert_not_gt "1.0.0-dev.99" "1.0.0-alpha.1" "dev <= alpha"

echo ""
echo "=== 主版本号 ==="
assert_gt "0.5.3" "0.5.2-beta.10" "patch 递增 > 任何预发布"
assert_gt "1.0.0" "0.99.99" "major 跨越"
assert_not_gt "1.0.0" "1.0.0" "完全相等返 false（v1 > v2 不成立）"
assert_not_gt "1.0.0-beta.10" "1.0.0-beta.10" "预发布相等返 false"

echo ""
echo "=== v 前缀 ==="
assert_not_gt "v1.0.0" "1.0.0" "v 前缀去除后相等"
assert_gt "v2.0.0" "v1.0.0" "v 前缀正常比较"
assert_gt "v0.5.2-beta.10" "v0.5.2-beta.9" "v 前缀 + 预发布"

echo ""
echo "=== 边界 ==="
assert_gt "1.0.10" "1.0.9" "三段主版本数字递增"
assert_gt "10.0.0" "9.99.99" "major 两位"

echo ""
echo "=== 大写 V 前缀（M1 修复回归）==="
assert_not_gt "V1.0.0" "1.0.0" "大写 V 前缀去除后相等"
assert_gt "V2.0.0" "V1.0.0" "大写 V 前缀正常比较"
assert_gt "V0.5.2-beta.10" "V0.5.2-beta.9" "大写 V 前缀 + 预发布数字段"

echo ""
echo "=== 跨实现等价（bash version_gt vs PHP version_compare）==="
echo "（反模式 4 配套：4 处独立实现必须语义一致，本段验证 bash 与 PHP 输出对齐）"

if ! test_php_init "$ROOT" "$TEST_TMP"; then
    echo "✗ Docker app PHP 与宿主 PHP 均不可用，禁止跳过跨实现等价测试"
    FAIL=$((FAIL + 1))
else
    PHP_BIN="$TEST_PHP_BIN"
    echo "PHP CLI: $TEST_PHP_RUNTIME ($(test_php_version))"

    # 调 PHP 的 version_compare —— 用与 VersionManager::compareVersions 相同的预处理
    # (strtolower + ltrim v/V)，反映生产代码行为。M2 修复后 VersionManager 入口已统一 strtolower，
    # 这里保持同样预处理保证 cross-impl 等价测试覆盖生产路径。
    php_version_compare() {
        # 返回 "gt" / "eq" / "lt"（对齐 bash version_gt 的 true/false 语义）
        local v1="$1" v2="$2"
        local v1l v2l
        v1l=$(printf '%s' "${v1#v}" | sed 's/^V//' | tr '[:upper:]' '[:lower:]')
        v2l=$(printf '%s' "${v2#v}" | sed 's/^V//' | tr '[:upper:]' '[:lower:]')
        V1="$v1l" V2="$v2l" "$PHP_BIN" -r '
            $r = version_compare(getenv("V1"), getenv("V2"));
            echo $r > 0 ? "gt" : ($r < 0 ? "lt" : "eq");
        '
    }

    assert_equiv() {
        local v1="$1" v2="$2"
        local bash_result php_result
        if version_gt "$v1" "$v2"; then
            bash_result="gt"
        elif version_gt "$v2" "$v1"; then
            bash_result="lt"
        else
            bash_result="eq"
        fi
        php_result=$(php_version_compare "$v1" "$v2")
        if [ "$bash_result" = "$php_result" ]; then
            echo "✓ ($v1 vs $v2) bash=$bash_result, PHP=$php_result"
            PASS=$((PASS + 1))
        else
            echo "✗ ($v1 vs $v2) 不一致：bash=$bash_result, PHP=$php_result"
            FAIL=$((FAIL + 1))
        fi
    }

    # 反模式 4 配套：reviewer 指定的 7 个关键场景 + 边界
    assert_equiv "0.5.2-beta.10" "0.5.2-beta.9"
    assert_equiv "0.5.2" "0.5.2-beta.10"
    assert_equiv "1.0.0-rc.1" "1.0.0-beta.99"
    assert_equiv "1.0.0-alpha.1" "1.0.0-dev.99"
    assert_equiv "1.0.0" "1.0.0"
    assert_equiv "1.0.0-rc.11" "1.0.0-rc.2"
    assert_equiv "0.5.3" "0.5.2-beta.10"
    assert_equiv "v1.0.0" "1.0.0"
    assert_equiv "V0.5.2-beta.10" "v0.5.2-beta.9"
    assert_equiv "1.0.0-alpha.100" "1.0.0-alpha.99"

    # M2 修复：大写关键字（Beta / RC 等）经 strtolower 标准化后应与小写等价
    assert_equiv "1.0.0-Beta.10" "1.0.0-beta.9"
    assert_equiv "1.0.0-BETA" "1.0.0-beta"
    assert_equiv "1.0.0-RC.1" "1.0.0-beta.99"
fi

echo ""
echo "=== 结果汇总 ==="
echo "PASS: $PASS"
echo "FAIL: $FAIL"
[ "$FAIL" = "0" ]
