#!/usr/bin/env bash
# e2e 测试入口：跑所有 case-*.sh
#
# 用法：
#   bash deploy/test/e2e/run.sh             # 跑全部
#   bash deploy/test/e2e/run.sh case-01     # 跑指定 case
#
# 退出码：0=全过；非 0=至少一个 case 失败

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 颜色
COLOR_GREEN='\033[0;32m'
COLOR_RED='\033[0;31m'
COLOR_BLUE='\033[0;34m'
COLOR_RESET='\033[0m'

filter="${1:-}"
total=0
failed=0
failed_cases=()

echo
echo "========================================"
echo "  Manager e2e 测试套件"
echo "========================================"
echo

for case_file in "$SCRIPT_DIR"/case-*.sh; do
    [ -f "$case_file" ] || continue
    case_name="$(basename "$case_file" .sh)"

    if [ -n "$filter" ] && [[ "$case_name" != *"$filter"* ]]; then
        continue
    fi

    total=$((total + 1))
    echo
    printf "${COLOR_BLUE}>>>${COLOR_RESET} %s\n" "$case_name"
    echo "----------------------------------------"

    if bash "$case_file"; then
        printf "${COLOR_GREEN}<<< %s OK${COLOR_RESET}\n" "$case_name"
    else
        rc=$?
        printf "${COLOR_RED}<<< %s FAILED (rc=%d)${COLOR_RESET}\n" "$case_name" "$rc"
        failed=$((failed + 1))
        failed_cases+=("$case_name")
    fi
done

echo
echo "========================================"
if [ "$total" = "0" ]; then
    echo "  没有匹配的 case（filter='$filter'）"
    exit 1
elif [ "$failed" = "0" ]; then
    printf "${COLOR_GREEN}  汇总：全部通过 (%d/%d)${COLOR_RESET}\n" "$total" "$total"
    echo "========================================"
    exit 0
else
    printf "${COLOR_RED}  汇总：%d/%d 失败${COLOR_RESET}\n" "$failed" "$total"
    for c in "${failed_cases[@]}"; do
        echo "    - $c"
    done
    echo "========================================"
    exit 1
fi
