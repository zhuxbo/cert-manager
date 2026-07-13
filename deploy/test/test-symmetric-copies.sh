#!/usr/bin/env bash
# 反模式 4 配套：deploy 对称副本等价测试
#
# 本批新增了 4 组"对称副本"，此前仅靠注释"修改时请同步两处"维系，无任何自动校验：
#   ① _php_pretty_version  common.sh ↔ upgrade.sh    （bash↔bash，要求字节一致）
#   ② _probe_any_php       common.sh ↔ upgrade.sh    （bash↔bash，要求字节一致）
#   ③ _read_req_field      common.sh ↔ upgrade.sh    （bash↔bash，要求字节一致）
#   ④ _redis_required_from_env  upgrade.sh(内嵌 PHP) ↔ EnvironmentChecker::isRedisRequiredFromEnv
#      （bash↔PHP，要求对同一批 .env 输入输出一致）
#   ⑤ write_logrotate_conf  bt-install.sh ↔ upgrade.sh （bash↔bash，要求字节一致；
#      P0 批次 M6 引入，写同一 /etc/logrotate.d/ssl-manager，漂移会让新装机与升级机轮转行为分叉）
#
# 副本是有意保留的（upgrade.sh 独立部署不 source common.sh），但 review-checklist 反模式 4 要求
# "保留对称副本必须配 build 时 grep 等价校验 或 运行时输出等价测试，仅靠注释不够"。本脚本即该配套。
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMMON="$ROOT/deploy/scripts/common.sh"
UPGRADE="$ROOT/deploy/upgrade.sh"
ENV_CHECKER="$ROOT/backend/app/Services/Upgrade/EnvironmentChecker.php"
PASS=0
FAIL=0

# 提取顶层函数体：从 `^name() {$` 到该函数顶格闭合 `^}$`（含首尾两行）。
# 顶层函数闭合 } 顶格，函数体内嵌套 } 多有缩进；但两类多行块内也可能出现顶格 }，
# 会被朴素 `$0 == "}"` 误判为函数结束，需按块状态豁免：
#   - 内嵌 PHP（`-r '...'` 多行单引号块）里的顶格 }（如 foreach 闭合）；
#   - heredoc（`<<EOF ... EOF`）里的顶格 }（如 write_logrotate_conf 的 logrotate 条目块闭合）。
extract_fn() {
    awk -v head="$2() {" -v sq="'" '
        $0 == head { p = 1 }
        p { print }
        p && index($0, "<<EOF") > 0 { inh = 1; next }
        p && inh && $0 == "EOF" { inh = 0; next }
        p && index($0, "-r ") > 0 && substr($0, length($0), 1) == sq { inq = 1; next }
        p && inq && substr($0, 1, 1) == sq { inq = 0; next }
        p && inq == 0 && inh == 0 && $0 == "}" { exit }
    ' "$1"
}

# ===== ① / ② / ③ bash↔bash 对称副本字节等价 =====
echo "=== bash↔bash 对称副本字节等价 (common.sh ↔ upgrade.sh) ==="
assert_identical() {
    local fn="$1" a b
    a="$(extract_fn "$COMMON" "$fn")"
    b="$(extract_fn "$UPGRADE" "$fn")"
    if [ -z "$a" ] || [ -z "$b" ]; then
        echo "✗ ${fn}：在 common.sh 或 upgrade.sh 未提取到函数体"
        FAIL=$((FAIL + 1))
        return
    fi
    if [ "$a" = "$b" ]; then
        echo "✓ ${fn}：两副本字节一致"
        PASS=$((PASS + 1))
    else
        echo "✗ ${fn}：两副本已漂移："
        diff <(printf '%s\n' "$a") <(printf '%s\n' "$b") || true
        FAIL=$((FAIL + 1))
    fi
}
assert_identical _php_pretty_version
assert_identical _probe_any_php
assert_identical _read_req_field

# ===== ⑤ write_logrotate_conf bt-install.sh ↔ upgrade.sh 字节等价 =====
echo ""
echo "=== write_logrotate_conf 对称副本字节等价 (bt-install.sh ↔ upgrade.sh) ==="
BT_INSTALL="$ROOT/deploy/scripts/bt-install.sh"
assert_identical_files() {
    local fn="$1" f1="$2" f2="$3" a b
    a="$(extract_fn "$f1" "$fn")"
    b="$(extract_fn "$f2" "$fn")"
    # 提取自愈校验：函数体必须以顶格 } 结尾（防 heredoc/引号状态误判致截断，截断的两侧比较无意义）
    if [ -z "$a" ] || [ -z "$b" ] || [ "${a##*$'\n'}" != "}" ] || [ "${b##*$'\n'}" != "}" ]; then
        echo "✗ ${fn}：函数体提取失败或不完整（未闭合于顶格 }）"
        FAIL=$((FAIL + 1))
        return
    fi
    if [ "$a" = "$b" ]; then
        echo "✓ ${fn}：两副本字节一致"
        PASS=$((PASS + 1))
    else
        echo "✗ ${fn}：两副本已漂移："
        diff <(printf '%s\n' "$a") <(printf '%s\n' "$b") || true
        FAIL=$((FAIL + 1))
    fi
}
assert_identical_files write_logrotate_conf "$BT_INSTALL" "$UPGRADE"

# ===== ④ _redis_required_from_env bash↔PHP 运行时输出等价 =====
echo ""
echo "=== _redis_required_from_env bash↔PHP 运行时输出等价 ==="
PHP_BIN="$(command -v php 2>/dev/null || true)"
if [ -z "$PHP_BIN" ]; then
    for d in /www/server/php/*/bin/php /opt/homebrew/bin/php /usr/local/bin/php; do
        [ -x "$d" ] && PHP_BIN="$d" && break
    done
fi

if [ -z "$PHP_BIN" ] || [ ! -f "$ENV_CHECKER" ]; then
    echo "⚠ 无 PHP CLI 或缺 EnvironmentChecker，跳过 redis 跨实现等价（PHP 侧另有单测覆盖语义）"
else
    # eval 出 upgrade.sh 的 bash 版本（不 source 整个 upgrade.sh，避免触发主流程）
    eval "$(extract_fn "$UPGRADE" _redis_required_from_env)"
    PHP_CMD="$PHP_BIN" # bash 版本依赖此全局

    # PHP 侧：仅 require 类文件即可（无构造依赖、方法纯解析，不需 composer autoload）
    php_redis_required() {
        "$PHP_BIN" -r 'require $argv[1]; $c = new App\Services\Upgrade\EnvironmentChecker(); exit($c->isRedisRequiredFromEnv($argv[2]) ? 0 : 1);' "$ENV_CHECKER" "$1"
    }

    assert_redis_equiv() {
        local label="$1" content="$2"
        local tmp bash_rc php_rc
        tmp="$(mktemp -d)"
        mkdir -p "$tmp/backend"
        printf '%s' "$content" >"$tmp/backend/.env"
        INSTALL_DIR="$tmp" _redis_required_from_env
        bash_rc=$?
        php_redis_required "$tmp/backend/.env"
        php_rc=$?
        rm -rf "$tmp"
        # rc=0 表示"需要 redis"，rc!=0 表示"不需要"
        if [ "$bash_rc" -eq 0 ] && [ "$php_rc" -eq 0 ]; then
            echo "✓ [$label] 一致（都判定需要 redis）"
            PASS=$((PASS + 1))
        elif [ "$bash_rc" -ne 0 ] && [ "$php_rc" -ne 0 ]; then
            echo "✓ [$label] 一致（都判定不需要）"
            PASS=$((PASS + 1))
        else
            echo "✗ [$label] 不一致：bash_rc=$bash_rc php_rc=$php_rc"
            FAIL=$((FAIL + 1))
        fi
    }

    assert_redis_equiv "裸 redis" $'CACHE_DRIVER=redis\n'
    assert_redis_equiv "双引号 redis" $'QUEUE_CONNECTION="redis"\n'
    assert_redis_equiv "单引号 redis" $'CACHE_STORE=\x27redis\x27\n'
    assert_redis_equiv "行内注释 redis" $'CACHE_STORE=redis # 用 redis\n'
    assert_redis_equiv "CRLF redis" $'CACHE_DRIVER=redis\r\n'
    assert_redis_equiv "尾随空白 redis" $'QUEUE_CONNECTION=redis   \n'
    assert_redis_equiv "非 redis 值" $'CACHE_DRIVER=file\nQUEUE_CONNECTION=database\n'
    assert_redis_equiv "redis 子串不误判" $'CACHE_DRIVER=redisson\n'
    assert_redis_equiv "前导空白 + redis" $'  CACHE_DRIVER = redis\n'
    assert_redis_equiv "空文件" ''
    assert_redis_equiv "注释行不算" $'# CACHE_DRIVER=redis\n'
fi

echo ""
echo "================================"
echo "PASS=$PASS  FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
