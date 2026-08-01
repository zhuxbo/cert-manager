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

MIN_MSI="${MUTATE_MIN_MSI:-$(jq -r '.min_msi' "$BASELINE_FILE")}"

if [[ -z "$MIN_MSI" || "$MIN_MSI" == "null" ]]; then
    echo "❌ baseline 文件缺少 min_msi 字段" >&2
    exit 1
fi

echo "═══ 资金核心变异测试门禁 ═══"
echo "Baseline MSI 门槛: ≥ ${MIN_MSI}%"
echo ""

DEFAULT_MUTATE_CLASSES=(
    'App\Models\Fund'
    'App\Models\Transaction'
    'App\Services\Acme\Action'
    'App\Services\Order\Action'
    'App\Services\Order\AutoRenewService'
    'App\Services\FundAudit\FundInvariants'
)
DEFAULT_MUTATE_PATHS=(
    'app/Models/Fund.php'
    'app/Models/Transaction.php'
    'app/Services/Acme/Action.php'
    'app/Services/Order/Action.php'
    'app/Services/Order/AutoRenewService.php'
    'app/Services/FundAudit/FundInvariants.php'
)
MUTATE_CLASSES=()
MUTATE_PATHS=()
if [[ -n "${MUTATE_TARGET_CLASSES:-}" ]]; then
    IFS=',' read -r -a REQUESTED_CLASSES <<<"$MUTATE_TARGET_CLASSES"
    for class in "${REQUESTED_CLASSES[@]}"; do
        class="${class#"${class%%[![:space:]]*}"}"
        class="${class%"${class##*[![:space:]]}"}"
        if [[ ! "$class" =~ ^[A-Z][A-Za-z0-9_]*(\\[A-Z][A-Za-z0-9_]*)+$ ]]; then
            echo "❌ 无效 mutation class: $class" >&2
            exit 2
        fi
        MUTATE_CLASSES+=("$class")
    done
    if [[ -n "${MUTATE_TARGET_PATHS:-}" ]]; then
        IFS=',' read -r -a REQUESTED_PATHS <<<"$MUTATE_TARGET_PATHS"
        for path in "${REQUESTED_PATHS[@]}"; do
            path="${path#"${path%%[![:space:]]*}"}"
            path="${path%"${path##*[![:space:]]}"}"
            MUTATE_PATHS+=("$path")
        done
    else
        for class in "${MUTATE_CLASSES[@]}"; do
            relative_class="${class#App\\}"
            MUTATE_PATHS+=("app/$(printf '%s' "$relative_class" | tr '\\' '/').php")
        done
    fi
else
    MUTATE_CLASSES=("${DEFAULT_MUTATE_CLASSES[@]}")
    MUTATE_PATHS=("${DEFAULT_MUTATE_PATHS[@]}")
fi

if [[ "${#MUTATE_CLASSES[@]}" -ne "${#MUTATE_PATHS[@]}" ]]; then
    echo "❌ mutation class 与 path 数量不一致" >&2
    exit 2
fi
for path in "${MUTATE_PATHS[@]}"; do
    if [[ ! "$path" =~ ^app/([A-Za-z0-9_]+/)*[A-Za-z0-9_]+\.php$ ]] ||
        [[ ! -f "$path" ]]; then
        echo "❌ 无效 mutation path: $path" >&2
        exit 2
    fi
done

MUTATE_CLASS_FILTER="$(
    IFS=,
    echo "${MUTATE_CLASSES[*]}"
)"
MUTATE_PATH_FILTER="$(
    IFS=,
    echo "${MUTATE_PATHS[*]}"
)"
printf 'Mutation targets:\n'
for index in "${!MUTATE_CLASSES[@]}"; do
    printf '  - %s (%s)\n' "${MUTATE_CLASSES[$index]}" "${MUTATE_PATHS[$index]}"
done
printf 'Mutation class filter: %s\n' "$MUTATE_CLASS_FILTER"
printf 'Mutation path filter: %s\n' "$MUTATE_PATH_FILTER"
echo ""

if [[ "${MUTATE_DRY_RUN:-0}" == "1" ]]; then
    exit 0
fi

# 覆盖率驱动：镜像默认 pcov.enabled=0（省普通 `make test` 的每行 hook 开销），变异跑时临时开启。
# 写独立临时扫描目录而非全局 conf.d：pest --parallel 各 worker 是独立 PHP
# 进程，PHP_INI_SCAN_DIR 会被继承；即使外层进程被强杀，残留文件也不会污染普通测试。
# （宿主机若用 xdebug，走下方 XDEBUG_MODE=coverage 环境变量，前缀保留以兼容。）
PCOV_INI=""
PCOV_INI_DIR=""
MUTATE_LOG="$(mktemp)"
DEFAULT_SCAN_DIR="${PHP_INI_DIR:-/usr/local/etc/php}/conf.d"
MUTATE_SCAN_DIR="${PHP_INI_SCAN_DIR:-$DEFAULT_SCAN_DIR}"
cleanup_mutate() {
    rm -f "$PCOV_INI" "$MUTATE_LOG"
    if [[ -n "$PCOV_INI_DIR" ]]; then
        rmdir "$PCOV_INI_DIR" 2>/dev/null || true
    fi
}
trap cleanup_mutate EXIT
# pipefail 下不能用 grep -q：命中后提前关闭管道会让 php -m 偶发 SIGPIPE，
# 从而把已安装的 pcov 误判为缺失。
if php -m | grep -iE '^pcov$' >/dev/null; then
    PCOV_INI_DIR="$(mktemp -d)"
    PCOV_INI="$PCOV_INI_DIR/zzz-pcov-mutate.ini"
    printf 'pcov.enabled=1\npcov.directory=%s\n' "$(pwd)" >"$PCOV_INI" 2>/dev/null ||
        {
            echo "⚠ 无法写 ${PCOV_INI}；pcov.enabled=0 时变异无覆盖，请手动启用覆盖率驱动" >&2
            PCOV_INI=""
        }
    if [[ -n "$PCOV_INI" ]]; then
        MUTATE_SCAN_DIR="${MUTATE_SCAN_DIR}:$PCOV_INI_DIR"
    fi
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
    PHP_INI_SCAN_DIR="$MUTATE_SCAN_DIR" XDEBUG_MODE=coverage ./vendor/bin/pest --mutate \
        --class="$MUTATE_CLASS_FILTER" \
        --path="$MUTATE_PATH_FILTER" \
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
