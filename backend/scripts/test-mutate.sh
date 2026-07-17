#!/usr/bin/env bash
# 资金核心变异测试门禁
# 跑 pest-plugin-mutate 验证测试质量，MSI 不退步则 PASS
# 用法: bash backend/scripts/test-mutate.sh [extra-pest-args...]

set -euo pipefail

cd "$(dirname "$0")/.."

BASELINE_FILE="tests/.mutation-baseline.json"

if [[ ! -f "$BASELINE_FILE" ]]; then
    echo "❌ baseline 文件不存在: $BASELINE_FILE" >&2
    echo "首次跑请先生成 baseline，命令见 skills/backend/core.md 变异测试章节" >&2
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "❌ 需要 jq (brew install jq)" >&2
    exit 1
fi

MIN_MSI=$(jq -r '.min_msi' "$BASELINE_FILE")

if [[ -z "$MIN_MSI" || "$MIN_MSI" == "null" ]]; then
    echo "❌ baseline 文件缺少 min_msi 字段" >&2
    exit 1
fi

echo "═══ 资金核心变异测试门禁 ═══"
echo "Baseline MSI 门槛: ≥ ${MIN_MSI}%"
echo ""

# 覆盖率驱动：镜像默认 pcov.enabled=0（省普通 `make test` 的每行 hook 开销），变异跑时临时开启。
# 写 conf.d 而非 -d/env：pest --parallel 各 worker 是独立 php 进程，只有 conf.d 能让 worker 也开 pcov。
# （宿主机若用 xdebug，走下方 XDEBUG_MODE=coverage 环境变量，前缀保留以兼容。）
PCOV_INI=""
MUTATE_LOG="$(mktemp)"
trap 'rm -f "$PCOV_INI" "$MUTATE_LOG"' EXIT
if php -m | grep -qiE '^pcov$'; then
    PCOV_INI="${PHP_INI_DIR:-/usr/local/etc/php}/conf.d/zzz-pcov-mutate.ini"
    printf 'pcov.enabled=1\npcov.directory=%s\n' "$(pwd)" >"$PCOV_INI" 2>/dev/null ||
        {
            echo "⚠ 无法写 $PCOV_INI；pcov.enabled=0 时变异无覆盖，请手动启用覆盖率驱动" >&2
            PCOV_INI=""
        }
fi

# bootstrap/cache TOCTOU flake 自愈：cache:clear-all / backup 类命令测试在 --parallel 下清掉所有 worker
# 共享的 bootstrap/cache/{packages,services}.php，覆盖率放大窗口 → baseline 轮偶发 require 失败中断。
# 仅对该签名自动重试（重建 cache + 重跑），不掩盖真实测试失败 / MSI 不达标。
attempt=0
max_attempts=5
while :; do
    attempt=$((attempt + 1))
    set +e
    php artisan package:discover --ansi >/dev/null 2>&1 # 每轮重建 cache；失败也不中断（在 set +e 区），pest 缺 cache 会命中下方 retry 自愈
    XDEBUG_MODE=coverage ./vendor/bin/pest --mutate \
        --class='App\Models\Fund' \
        --class='App\Models\Transaction' \
        --class='App\Services\Acme\Action' \
        --class='App\Services\Order\Action' \
        --class='App\Services\Order\AutoRenewService' \
        --class='App\Services\FundAudit\FundInvariants' \
        --covered-only \
        --parallel \
        --min="$MIN_MSI" \
        "$@" 2>&1 | tee "$MUTATE_LOG"
    rc=${PIPESTATUS[0]}
    set -e
    if [ "$rc" -ne 0 ] && [ "$attempt" -lt "$max_attempts" ] &&
        grep -qE 'bootstrap/cache/(packages|services)\.php.*Failed to open stream' "$MUTATE_LOG"; then
        echo "⚠ bootstrap/cache TOCTOU flake（第 ${attempt}/${max_attempts} 次），重建 cache 后重试…" >&2
        continue
    fi
    break
done
exit "$rc"
