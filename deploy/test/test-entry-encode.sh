#!/bin/bash
# 测试 deploy/upgrade.sh::_entry_encode / _entry_decode 的往返一致性（Bug #6 回归）
#
# 历史 bug：update_jobs_php_path 把 cron/supervisor 的 body/command 直接拼进
# "|" 分隔的数组 entry，后续用 `IFS='|' read ... <<<"$entry"` 解析。
# 当 body 含多行（here-string 只取首行，后续行丢失）或 body 字段不在 entry 末位而含 "|"
# （被切进相邻字段）时被截断，DelCrontab 删掉原任务后写入残缺命令却报成功，
# 回滚也用截断值 → schedule:run 静默停摆。
#
# 修复：拼 entry 前 _entry_encode（单行 base64），解析出 entry 后 _entry_decode 还原。
# 本测试验证含多行 / 含 "|" / 含空格 / 含中文 / 空串 的 body 编码→入 entry→read→解码后字节一致。
#
# 用法：bash test-entry-encode.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
UPGRADE_SH="$DEPLOY_DIR/upgrade.sh"

# 提取 _entry_encode / _entry_decode 函数体 — 避免 source 整个 upgrade.sh 触发 main
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

awk '
    /^_entry_encode\(\) \{/ { in_fn = 1; print; next }
    /^_entry_decode\(\) \{/ { in_fn = 1; print; next }
    in_fn && /^\}$/          { print; in_fn = 0; next }
    in_fn                    { print }
' "$UPGRADE_SH" >"$TMP"

if ! grep -q '^_entry_encode()' "$TMP" || ! grep -q '^_entry_decode()' "$TMP"; then
    echo "❌ 无法从 $UPGRADE_SH 提取 _entry_encode / _entry_decode 函数体"
    exit 1
fi

# shellcheck source=/dev/null
. "$TMP"

PASS=0
FAIL=0

# 验证：原始 body → _entry_encode → 塞进 | 分隔 entry → IFS='|' read 解出末字段 → _entry_decode → 与原始字节一致
assert_roundtrip() {
    local desc="$1" orig="$2"
    local enc entry id name paths body_enc decoded

    enc=$(_entry_encode "$orig")

    # 编码结果不得含 | 或换行（否则仍会破坏 | 分隔字段 / here-string 取首行）
    if printf '%s' "$enc" | grep -q '|'; then
        echo "✗ [$desc] 编码结果含 '|'，会破坏分隔字段"
        FAIL=$((FAIL + 1))
        return
    fi
    if [ "$(printf '%s' "$enc" | wc -l | tr -d ' ')" != "0" ]; then
        echo "✗ [$desc] 编码结果含换行，here-string 会截断"
        FAIL=$((FAIL + 1))
        return
    fi

    # 模拟真实链路：构造一条 cron entry，末字段是编码后的 body
    entry="42|测试任务|/www/server/php/83/bin/php|$enc"
    IFS='|' read -r id name paths body_enc <<<"$entry"
    decoded=$(_entry_decode "$body_enc")

    if [ "$decoded" = "$orig" ]; then
        echo "✓ [$desc] 往返字节一致（id=$id name=$name 解析未受 body 污染）"
        PASS=$((PASS + 1))
    else
        echo "✗ [$desc] 往返不一致"
        echo "    原始: $(printf '%s' "$orig" | od -c | head -3)"
        echo "    还原: $(printf '%s' "$decoded" | od -c | head -3)"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== Bug #6 触发场景（旧实现会被截断）==="
assert_roundtrip "多行 body" "$(printf 'cd /www/wwwroot/site\n/www/server/php/83/bin/php artisan schedule:run\necho done')"
assert_roundtrip "含 | 管道的命令" '/www/server/php/83/bin/php artisan schedule:run | tee -a /tmp/cron.log'
assert_roundtrip "多行且含 |" "$(printf 'php artisan schedule:run | grep ok\ncd /tmp && ls | wc -l')"

echo ""
echo "=== 常规字节场景 ==="
assert_roundtrip "纯单行命令" '/www/server/php/83/bin/php /www/wwwroot/site/backend/artisan schedule:run'
assert_roundtrip "含空格与制表符" "$(printf 'echo  a\tb   c')"
assert_roundtrip "含中文" 'php artisan 调度:执行 # 计划任务'
assert_roundtrip "含双引号与单引号" "php -r 'echo \"hi\";'"
assert_roundtrip "含反引号与 \$ 变量字面量" 'echo `date` $HOME ${PATH}'

echo ""
echo "=== 边界场景 ==="
assert_roundtrip "空串" ""
assert_roundtrip "单字符" "x"
assert_roundtrip "仅一个管道符" "|"
assert_roundtrip "仅换行" "$(printf '\n')"

echo ""
echo "=== 反证：旧实现（直接拼裸多行 body）确实会被截断 ==="
# 不经编码直接拼，多行 body 经 here-string 只喂首行 → IFS read 拿到的末字段缺后续行，
# 证明 bug 真实存在、编码修复有效。
# （注：单行含 "|" 因末变量吸收剩余分隔符不会丢内容；真正的截断来自多行 here-string 只取首行，
#  以及 body 字段若不在末位时被 "|" 切断 — 故必须编码。这里用多行做最直接的反证。）
naive_orig="$(printf 'php artisan schedule:run\ncd /tmp && rm -rf old')"
naive_entry="42|name|paths|$naive_orig"
IFS='|' read -r _ _ _ naive_body <<<"$naive_entry"
if [ "$naive_body" != "$naive_orig" ]; then
    echo "✓ 裸拼接多行确被截断：得到 '$naive_body'（仅首行，丢失 'cd /tmp && rm -rf old'），印证编码修复的必要性"
    PASS=$((PASS + 1))
else
    echo "✗ 预期裸拼接被截断，但未截断（环境差异，测试假设失效）"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "=== 结果汇总 ==="
echo "PASS: $PASS"
echo "FAIL: $FAIL"
[ "$FAIL" = "0" ]
