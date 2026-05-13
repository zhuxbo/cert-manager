#!/usr/bin/env bash
# 资金核心变异测试门禁
# 跑 pest-plugin-mutate 验证测试质量，MSI 不退步则 PASS
# 用法: bash backend/scripts/test-mutate.sh [extra-pest-args...]

set -euo pipefail

cd "$(dirname "$0")/.."

BASELINE_FILE="tests/.mutation-baseline.json"

if [[ ! -f "$BASELINE_FILE" ]]; then
    echo "❌ baseline 文件不存在: $BASELINE_FILE" >&2
    echo "首次跑请先生成 baseline，命令见 skills/backend-dev.md 变异测试章节" >&2
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

# 确保 services cache 存在（paratest 多 worker 偶发清 cache 导致 require 失败）
php artisan package:discover --ansi >/dev/null 2>&1

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
    "$@"
