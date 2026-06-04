#!/bin/bash
# 测试 bt-deps.sh 子命令分发是否把命令行参数正确转发给函数
#
# 历史 bug：auto_install_ext) 分支只写了 `auto_install_ext` 不带参数也不 shift，
# 导致 upgrade.sh 传入的 extra 扩展（如 .env 动态判定出的 redis）在调度层丢失，
# 函数内 $@ 为空，bt-deps.sh 只跑 php-requirements.json 里的 required 列表。
#
# 两层守护，职责互不重叠：
#   1. smoke 层（真实脚本执行）：dispatcher 是否进入正确分支
#      - 反向断言：输出不含"未知子命令"（bt-deps.sh 的 *) 兜底）
#      - 不验证参数转发（不依赖具体 log 文案，与 common.sh 解耦）
#   2. 静态扫描层（grep case 块）：dispatcher 分支体是否正确转发参数
#      - 每个子命令分支必须有 `shift`（消费子命令名）
#      - 每个子命令分支必须至少引用一次 $@ 或 $*（消费 extra 参数）
#      - 剥注释行后再 grep，防"注释提到 $@ 但代码漏"的假阳性
#
# 用法：bash test-bt-deps-dispatch.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SCRIPT="$DEPLOY_DIR/scripts/bt-deps.sh"

PASS=0
FAIL=0

# smoke 层定位：验证 dispatcher 进入了某个具体子命令分支，**不**验证参数转发
# （参数转发由下方静态扫描层守护，二层职责互不重叠）
#
# 反向断言：bt-deps.sh 的 `*)` 兜底分支输出 "未知子命令: $1"
#   - 该字符串是给开发者的错误提示，比正常 log 稳定（改它意味着接口契约变更）
#   - dispatcher 进入正确分支时绝不会触发它
# 之前用正向 grep 具体 log 字符串（"未找到符合要求的 PHP 版本"等）依赖 common.sh 内部文案，
# reviewer 抓到这是脆弱设计 — 改成反向断言切断对内部 log 文案的耦合。
assert_dispatch_reached() {
    local label="$1"
    shift
    local out exit_code
    # `|| true` 防 set -e 在 bt-deps.sh 退出非 0 时让函数早返回；exit_code 通过 PIPESTATUS 捕获
    out=$(bash "$SCRIPT" "$@" 2>&1 && echo "__OK_EXIT__=0" || echo "__OK_EXIT__=$?")
    exit_code=$(echo "$out" | grep -oE '__OK_EXIT__=[0-9]+' | tail -1 | cut -d= -f2)
    out=$(echo "$out" | grep -v '^__OK_EXIT__=')

    if echo "$out" | grep -q "未知子命令"; then
        echo "FAIL  [$label] dispatcher 退化为 *) 兜底分支 (exit=$exit_code)"
        echo "  output: $(echo "$out" | head -3)"
        FAIL=$((FAIL + 1))
        return
    fi

    # bash 语法/解析错误退出码通常是 2 或负数；进入分支后即便 detect_php_version 失败也是 exit 1
    # 这里只关心 dispatcher 行为，进入分支即视为 smoke 通过
    echo "PASS  [$label] (exit=$exit_code)"
    PASS=$((PASS + 1))
}

echo "===== 真实脚本 smoke：dispatcher 进入正确分支 ====="
assert_dispatch_reached "auto_install_ext + 1 extra" auto_install_ext redis
assert_dispatch_reached "auto_install_ext + 2 extra" auto_install_ext redis igbinary
assert_dispatch_reached "auto_install_ext 无 extra" auto_install_ext
assert_dispatch_reached "enable_functions + 1 fn" enable_functions proc_open

echo ""
echo "===== 静态扫描：守护 case 分支不退化 ====="
# 通用断言（覆盖两种 dispatch pattern）：
#   pattern A: 调用同名函数 — auto_install_ext "$@"
#   pattern B: 内联展开 $* / $@ — enable_functions 走 functions_str="$*" 模式
# 两种 pattern 必须满足两个条件才能正确转发参数：
#   ① 分支体内有 `shift`（把子命令名移出位置参数）
#   ② 分支体内至少出现一处 $@ 或 $* 引用（真正消费 extra 参数）
# 任一缺失都意味着调用者传的 extra 参数在分支内被吞掉 — 这正是历史 bug 的形态。
#
# 上一版只检查 pattern A（"调同名函数必传 \"\$@\""），对 enable_functions 这种内联 pattern
# 命中 0 行恒 PASS，是空洞守护。reviewer 抓到后改成本版本通用断言。
for sub in auto_install_ext enable_functions; do
    # 切 case 分支体（从 "$sub)" 行到下一个 ";;"，含两端）
    block=$(sed -n "/^[[:space:]]*${sub})/,/^[[:space:]]*;;[[:space:]]*$/p" "$SCRIPT")

    has_shift=no
    consumes_args=no
    # 先剥掉注释行（^空白* # ...），避免注释里写"# 把 $@ 转发出去"造成的假阳性
    # 例：reviewer 抓到过 — 代码漏 "$@" 但注释提到 $@ → 守护误判 PASS → 等于没守
    code_only=$(echo "$block" | grep -vE '^[[:space:]]*#')
    # shift 必须独立成行（不命中行内 "shift" 单词如变量名等）
    if echo "$code_only" | grep -qE "^[[:space:]]*shift([[:space:]]|$)"; then
        has_shift=yes
    fi
    # $@ 或 $* 任一引用即可（带引号或裸露都算消费）
    if echo "$code_only" | grep -qE '\$@|\$\*'; then
        consumes_args=yes
    fi

    if [ "$has_shift" = yes ] && [ "$consumes_args" = yes ]; then
        echo "PASS  [case $sub) 有 shift + 消费 \$@/\$*]"
        PASS=$((PASS + 1))
    else
        echo "FAIL  [case $sub) shift=$has_shift, consumes_args=$consumes_args]"
        echo "  说明：每个子命令分支必须 shift 子命令名 + 至少引用一次 \$@ 或 \$*"
        echo "  否则 upgrade.sh 传入的 extra 参数会在调度层被丢弃（历史 bug 形态）"
        FAIL=$((FAIL + 1))
    fi
done

echo ""
echo "===== 总计 ====="
echo "$PASS passed, $FAIL failed"
[ $FAIL -eq 0 ]
