#!/usr/bin/env bash
# derive-scope.sh — 从 git diff 提取 skills/finish-check.md §1 的候选影响面
# 取代纯自报枚举制：路径行 glob 推导、内容行只扫 + 新增行、安全面/删除审核保持人工（仅给关键词提示）
#
# 路径/关键词规则索引（实际 gate 由 finish-check.md 按模式和行为确定）：
#   [路径] 行1  backend/app/Services/Acme               ^backend/app/Services/Acme(/|\.php$)
#   [路径] 行2  backend/app/Services/Order              ^backend/app/Services/Order(/|\.php$)
#   [路径] 行3  Models/Fund|Transaction（资金路径）     ^backend/app/Models/(Fund|Transaction)\.php$
#   [路径] 行4  backend/database/migrations             ^backend/database/migrations/
#   [内容] 行5  原生 SQL                                DB::raw|whereRaw|DB::statement
#   [路径] 行6  AppServiceProvider 连接/时区注入        ^backend/app/Providers/AppServiceProvider\.php$
#   [路径] 行7  frontend/shared                         ^frontend/shared/
#   [路径] 行8  plugins/                                ^plugins/
#   [路径] 行9  deploy/ 与后台升级服务                   ^deploy/|^backend/app/Services/Upgrade/
#   [路径] 行10 tests/ 文件本身                         ^backend/tests/|^plugins/.*/tests/
#   [人工] 行11 安全面（鉴权/下载/解压/CORS/公开端点）  仅提示：routes/|Middleware/|Controller 改动 + 新增 public function
#   [内容] 行12 外部命令调用                            exec\(|proc_open|shell_exec
#   [内容] 行13 节流/防重/并发事务                      Cache/命名 store/RuntimeCache/MutexLock、行锁、TaskJob
#   [内容] 行14 catch ApiResponseException              ApiResponseException
#   [内容] 行15 通知模板                                NotificationTemplate|NotificationCenter
#   [人工] 行16 删除审核（§1.5）                        仅提示：- 删除行含 class/function/Schema::drop 或 config 键
#   [路径] 行17 build/ 打包脚本 / .github/workflows     ^build/|^\.github/workflows/   （plan B1 新增行）
#
# 内容规则只扫 .php 文件的 + 新增行（避免文档/skill 提及关键字、或触碰含关键字的大文件即误报）；
# 默认模式额外把 untracked .php 文件按全文新增计入。
# 命中项按实际 diff 核对；注释/同名等误报说明依据后排除，漏报的真实风险必须补入。
# mutation 目标仍是独立门禁要求，不因候选影响面排除而被豁免。
#
# 用法：bash skills/scripts/derive-scope.sh [--base <ref>] [--mutation-target-class <FQCN>]...
#   默认                            git diff HEAD + git diff --cached + untracked 合并去重
#   --base <ref>                    git diff <ref>（对历史 ref 推导/自测，如 --base origin/main）
#   --mutation-target-class <FQCN>  plan 明确要求的额外 mutation 目标，可重复
set -uo pipefail

FILES_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/finish-check-files.py"

usage() {
    cat <<'EOF'
用法: bash skills/scripts/derive-scope.sh [--base <ref>] [--mutation-target-class <FQCN>]...
  默认                            diff 范围 = git diff HEAD + git diff --cached + untracked 合并去重
  --base <ref>                    diff 范围 = git diff <ref>（如 --base origin/main、--base HEAD~5）
  --mutation-target-class <FQCN>  plan 明确要求的额外 mutation 目标，可重复
EOF
}

BASE_REF=""
MUTATION_PLAN_TARGETS=()
MUTATION_CLASS_RE='^[A-Z][A-Za-z0-9_]*(\\[A-Z][A-Za-z0-9_]*)+$'
while [[ $# -gt 0 ]]; do
    case "$1" in
        --base)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "错误: --base 需要一个 ref 参数" >&2
                exit 1
            fi
            BASE_REF="$2"
            shift 2
            ;;
        --mutation-target-class)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "错误: --mutation-target-class 需要一个 FQCN 参数" >&2
                exit 1
            fi
            if [[ ! "$2" =~ $MUTATION_CLASS_RE ]]; then
                echo "错误: 无效 mutation FQCN '$2'" >&2
                exit 1
            fi
            MUTATION_PLAN_TARGETS+=("$2")
            shift 2
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            echo "错误: 未知参数 '$1'" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if ! git rev-parse --show-toplevel >/dev/null 2>&1; then
    echo "错误: 当前目录不是 git 仓库，无法推导范围" >&2
    exit 1
fi
REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

if ! git rev-parse --verify --quiet HEAD >/dev/null 2>&1; then
    echo "错误: 仓库无 HEAD 提交（空仓库），无法 diff" >&2
    exit 1
fi

if [[ -n "$BASE_REF" ]]; then
    if ! git rev-parse --verify --quiet "$BASE_REF^{commit}" >/dev/null 2>&1; then
        echo "错误: base ref '$BASE_REF' 无法解析为 commit" >&2
        exit 1
    fi
fi

# ---------- 收集 diff ----------
if [[ -n "$BASE_REF" ]]; then
    CHANGED_FILES="$(python3 "$FILES_SCRIPT" list --base "$BASE_REF")" || exit 1
    RAW_DIFF="$(git diff "$BASE_REF" -U0 --no-color)"
    DIFF_DESC="git diff $BASE_REF"
else
    CHANGED_FILES="$(python3 "$FILES_SCRIPT" list)" || exit 1
    RAW_DIFF="$(
        git diff HEAD -U0 --no-color
        git diff --cached -U0 --no-color
    )"
    DIFF_DESC="git diff HEAD + --cached + untracked"
fi

if [[ -n "$CHANGED_FILES" ]]; then
    FILE_COUNT="$(wc -l <<<"$CHANGED_FILES" | tr -d ' ')"
else
    FILE_COUNT=0
fi

# ---------- mutation 目标判定 ----------
# 普通 finish-check 只运行本次实际变化/plan 声明的目标；main 正式发布不传
# MUTATE_TARGET_CLASSES，固定运行 test-mutate.sh 内的全部核心默认类。
MUTATION_CORE_MAPPINGS=(
    'backend/app/Models/Fund.php|App\Models\Fund'
    'backend/app/Models/Transaction.php|App\Models\Transaction'
    'backend/app/Services/Acme/Action.php|App\Services\Acme\Action'
    'backend/app/Services/Order/Action.php|App\Services\Order\Action'
    'backend/app/Services/Order/AutoRenewService.php|App\Services\Order\AutoRenewService'
    'backend/app/Services/FundAudit/FundInvariants.php|App\Services\FundAudit\FundInvariants'
)
MUTATION_AUTO_TARGETS=()
for mapping in "${MUTATION_CORE_MAPPINGS[@]}"; do
    file="${mapping%%|*}"
    class="${mapping#*|}"
    if grep -Fxq "$file" <<<"$CHANGED_FILES"; then
        MUTATION_AUTO_TARGETS+=("$class")
    fi
done

MUTATION_EFFECTIVE_TARGETS=()
append_unique_mutation_target() {
    local candidate="$1"
    local existing
    for existing in ${MUTATION_EFFECTIVE_TARGETS[@]+"${MUTATION_EFFECTIVE_TARGETS[@]}"}; do
        [[ "$existing" == "$candidate" ]] && return
    done
    MUTATION_EFFECTIVE_TARGETS+=("$candidate")
}
for class in ${MUTATION_AUTO_TARGETS[@]+"${MUTATION_AUTO_TARGETS[@]}"}; do
    [[ -n "$class" ]] && append_unique_mutation_target "$class"
done
for class in ${MUTATION_PLAN_TARGETS[@]+"${MUTATION_PLAN_TARGETS[@]}"}; do
    [[ -n "$class" ]] && append_unique_mutation_target "$class"
done

join_mutation_targets() {
    local joined=""
    local value
    for value in "$@"; do
        [[ -n "$joined" ]] && joined+=","
        joined+="$value"
    done
    printf '%s' "$joined"
}

MUTATION_AUTO_CSV="$(
    join_mutation_targets ${MUTATION_AUTO_TARGETS[@]+"${MUTATION_AUTO_TARGETS[@]}"}
)"
MUTATION_PLAN_CSV="$(
    join_mutation_targets ${MUTATION_PLAN_TARGETS[@]+"${MUTATION_PLAN_TARGETS[@]}"}
)"
MUTATION_EFFECTIVE_CSV="$(
    join_mutation_targets ${MUTATION_EFFECTIVE_TARGETS[@]+"${MUTATION_EFFECTIVE_TARGETS[@]}"}
)"

# + 新增行（仅 .php），输出格式: 文件路径<TAB>行内容
ADDED_PHP="$(awk '
    /^\+\+\+ b\//          { f = substr($0, 7); next }
    /^\+\+\+ \/dev\/null/  { f = ""; next }
    /^\+/ && !/^\+\+\+ / && f ~ /\.php$/ { print f "\t" substr($0, 2) }
' <<<"$RAW_DIFF")"

# 默认模式：untracked .php 文件全文按新增行计入
if [[ -z "$BASE_REF" ]]; then
    while IFS= read -r uf; do
        [[ -z "$uf" ]] && continue
        [[ "$uf" != *.php || ! -f "$uf" ]] && continue
        ADDED_PHP="$ADDED_PHP"$'\n'"$(awk -v f="$uf" '{ print f "\t" $0 }' "$uf")"
    done <<<"$(git ls-files --others --exclude-standard)"
fi

# - 删除行（全部文件），输出格式: 文件路径<TAB>行内容
DELETED_LINES="$(awk '
    /^--- a\//          { f = substr($0, 7); next }
    /^--- \/dev\/null/  { f = ""; next }
    /^-/ && !/^--- / && f != "" { print f "\t" substr($0, 2) }
' <<<"$RAW_DIFF")"

# ---------- 规则定义（与头部映射注释一一对应，行号 = 数组下标 + 1） ----------
ROW_DIM=(
    "backend/app/Services/Acme"
    "backend/app/Services/Order"
    "backend/app/Models/Fund / Transaction / 资金路径"
    "backend/database/migrations"
    "原生 SQL（DB::raw / whereRaw / DB::statement）"
    "AppServiceProvider 连接/时区注入"
    "frontend/shared"
    "plugins/"
    "deploy/ 升级脚本 / 后台升级目录同步"
    "tests/ 文件本身（新增/修改测试）"
    "鉴权 / 下载 / 解压 / CORS / 通知 / 公开端点（安全面）"
    "外部命令调用（exec / proc_open / 二进制探测）"
    "节流 / 防重 / 并发事务（Cache::add / 锁 / TaskJob / 死锁）"
    "catch 自定义异常后日志/落库（ApiResponseException）"
    "通知模板 / NotificationTemplate / NotificationCenter"
    "删除了类/配置/命令/表/字段/函数"
    "build/ 打包脚本 / .github/workflows"
)
ROW_KIND=(
    path path path path content path path path path path
    manual_security content content content content manual_delete path
)
ROW_PAT=(
    '^backend/app/Services/Acme(/|\.php$)'
    '^backend/app/Services/Order(/|\.php$)'
    '^backend/app/Models/(Fund|Transaction)\.php$'
    '^backend/database/migrations/'
    'DB::raw|whereRaw|DB::statement'
    '^backend/app/Providers/AppServiceProvider\.php$'
    '^frontend/shared/'
    '^plugins/'
    '^deploy/|^backend/app/Services/Upgrade/'
    '^backend/tests/|^plugins/.*/tests/'
    ''
    'exec\(|proc_open|shell_exec'
    'Cache::add|lockForUpdate|TaskJob::dispatch|Cache::lock|Cache::store\([^)]*\)[[:space:]]*->(add|lock)\(|RuntimeCache::lock|MutexLock::'
    'ApiResponseException'
    'NotificationTemplate|NotificationCenter'
    ''
    '^build/|^\.github/workflows/'
)
ROW_TRIG=(
    "ACME 相关测试；完整后端检查由 make-test 覆盖"
    "Order 相关测试；完整后端检查由 make-test 覆盖"
    "资金行为变化：§2.6 资金证据 + §2.4 mysql 5.7"
    "结构变化：§2.4、migrate + db:structure --check、迁移最终态（反模式 21）"
    "SQL 行为变化：§2.4 mysql 5.7"
    "连接/时区行为变化：§2.4 mysql 5.7"
    "shared 行为变化：相关测试及受影响端构建；纯文案/样式按快检"
    "§4 对应插件检查；共享加载/生命周期变化再扩大"
    "部署行为变化：deploy/test/test-*.sh；反模式 7/21 和外部值安全"
    "相关测试；核对反模式 14（flaky）/15（伪绿），不自动全量 capture"
    "安全边界变化：§7 及反模式 17/18/19；核对真实入口绕过证据"
    "反模式 12（BinaryLocator + 开发机/生产环境差异）"
    "并发行为变化：反模式 10（锁顺序）/20（原子化、占位回滚、死锁处理）"
    "反模式 16（消息走 getApiResponse()['msg']，getMessage() 恒空）"
    "模板变化：渲染单测；新增模板需说明部署时 seeder 要求"
    "§1.5 删除审核：确认失效引用及对称入口"
    "产物/CI 执行链变化：反模式 2/21；仅影响打包时运行构建验包"
)

COVERED=""
mark_covered() {
    COVERED="$COVERED"$'\n'"$1"
}

# 截断到 80 字节并丢弃被切断的多字节 UTF-8 残片（iconv -c 清理非法序列）
truncate_utf8() {
    cut -c1-80 | iconv -f UTF-8 -t UTF-8 -c 2>/dev/null
}

YES_COUNT=0
NO_COUNT=0
MANUAL_COUNT=0
TABLE_ROWS=""
DETAIL_LINES=""

n=${#ROW_DIM[@]}
for ((i = 0; i < n; i++)); do
    dim="${ROW_DIM[$i]}"
    kind="${ROW_KIND[$i]}"
    pat="${ROW_PAT[$i]}"
    trig="${ROW_TRIG[$i]}"
    verdict="否"
    detail=""

    case "$kind" in
        path)
            hits=""
            [[ -n "$CHANGED_FILES" ]] && hits="$(grep -E "$pat" <<<"$CHANGED_FILES" || true)"
            if [[ -n "$hits" ]]; then
                verdict="是"
                cnt="$(wc -l <<<"$hits" | tr -d ' ')"
                sample="$(head -3 <<<"$hits" | tr '\n' ' ')"
                [[ "$cnt" -gt 3 ]] && sample="${sample}…共 $cnt 个文件"
                detail="$sample"
                while IFS= read -r f; do mark_covered "$f"; done <<<"$hits"
            fi
            ;;
        content)
            hits=""
            [[ -n "$ADDED_PHP" ]] && hits="$(grep -E "$pat" <<<"$ADDED_PHP" || true)"
            if [[ -n "$hits" ]]; then
                verdict="是"
                hit_files="$(cut -f1 <<<"$hits" | sort -u)"
                lcnt="$(wc -l <<<"$hits" | tr -d ' ')"
                fcnt="$(wc -l <<<"$hit_files" | tr -d ' ')"
                first_file="$(head -1 <<<"$hits" | cut -f1)"
                first_line="$(head -1 <<<"$hits" | cut -f2- | sed 's/^[[:space:]]*//' | truncate_utf8)"
                detail="$first_file: \`$first_line\`（新增行命中 $lcnt 行 / $fcnt 文件）"
                while IFS= read -r f; do mark_covered "$f"; done <<<"$hit_files"
            fi
            ;;
        manual_security)
            verdict="人工"
            MANUAL_COUNT=$((MANUAL_COUNT + 1))
            sec_files=""
            [[ -n "$CHANGED_FILES" ]] && sec_files="$(grep -E '(^|/)routes/|/Middleware/|Controller\.php$' <<<"$CHANGED_FILES" || true)"
            new_pubfn=""
            [[ -n "$ADDED_PHP" ]] && new_pubfn="$(grep -E 'public[[:space:]]+function' <<<"$ADDED_PHP" || true)"
            if [[ -n "$sec_files" && -n "$new_pubfn" ]]; then
                verdict="人工(提示命中)"
                scnt="$(wc -l <<<"$sec_files" | tr -d ' ')"
                sample="$(head -3 <<<"$sec_files" | tr '\n' ' ')"
                [[ "$scnt" -gt 3 ]] && sample="${sample}…共 $scnt 个文件"
                detail="routes/Middleware/Controller 改动（${sample}）且新增 public function — 倾向判是，须人工确认"
                while IFS= read -r f; do mark_covered "$f"; done <<<"$sec_files"
            else
                detail="无关键词提示，仍须人工给一句是/否判定"
            fi
            ;;
        manual_delete)
            verdict="人工"
            MANUAL_COUNT=$((MANUAL_COUNT + 1))
            del_hits=""
            if [[ -n "$DELETED_LINES" ]]; then
                del_hits="$(
                    {
                        awk -F'\t' '$1 ~ /\.(php|sh)$/' <<<"$DELETED_LINES" |
                            grep -E 'class[[:space:]]+[A-Za-z_]|function[[:space:]]+[A-Za-z_]|Schema::drop' || true
                        awk -F'\t' '$1 ~ /^backend\/config\//' <<<"$DELETED_LINES" |
                            grep -E '=>' || true
                    } | sort -u
                )"
            fi
            if [[ -n "$del_hits" ]]; then
                verdict="人工(提示命中)"
                dcnt="$(wc -l <<<"$del_hits" | tr -d ' ')"
                first_file="$(head -1 <<<"$del_hits" | cut -f1)"
                first_line="$(head -1 <<<"$del_hits" | cut -f2- | sed 's/^[[:space:]]*//' | truncate_utf8)"
                detail="删除行含类/函数/Schema::drop/config 键（如 $first_file: \`$first_line\`，共 $dcnt 行）— §1.5 删除审核需人工执行"
                while IFS= read -r f; do mark_covered "$f"; done <<<"$(cut -f1 <<<"$del_hits" | sort -u)"
            else
                detail="无删除关键词提示，仍须人工给一句是/否判定"
            fi
            ;;
    esac

    case "$verdict" in
        是) YES_COUNT=$((YES_COUNT + 1)) ;;
        否) NO_COUNT=$((NO_COUNT + 1)) ;;
    esac

    TABLE_ROWS="$TABLE_ROWS| $dim | $verdict | $trig |"$'\n'
    if [[ -n "$detail" ]]; then
        DETAIL_LINES="$DETAIL_LINES- 行$((i + 1)) $dim → $detail"$'\n'
    fi
done

# ---------- 残差路径 ----------
RESIDUAL=""
RESIDUAL_COUNT=0
if [[ -n "$CHANGED_FILES" ]]; then
    while IFS= read -r f; do
        [[ -z "$f" ]] && continue
        if ! grep -Fxq "$f" <<<"$COVERED"; then
            RESIDUAL="$RESIDUAL- $f"$'\n'
            RESIDUAL_COUNT=$((RESIDUAL_COUNT + 1))
        fi
    done <<<"$CHANGED_FILES"
fi

# ---------- 输出 ----------
echo "## §1 候选影响面（skills/scripts/derive-scope.sh 推导）"
echo ""
echo "tree: $(git rev-parse --short HEAD) dirty: $(git status --porcelain | wc -l | tr -d ' ') files"
echo "diff 基准: ${DIFF_DESC}；变更文件 ${FILE_COUNT} 个"
if [[ "$FILE_COUNT" -gt 0 ]] && ! grep -qvE '\.md$' <<<"$CHANGED_FILES"; then
    echo "DOCS_ONLY=yes"
else
    echo "DOCS_ONLY=no"
fi
if [[ "$FILE_COUNT" -eq 0 ]]; then
    echo "注意: diff 为空（无改动文件），全部判否属预期"
fi
echo ""
echo "| 维度 | 是/否/人工 | 候选检查（先核对行为） |"
echo "|------|------------|------|"
printf '%s' "$TABLE_ROWS"
echo ""
if [[ -n "$DETAIL_LINES" ]]; then
    echo "命中明细："
    printf '%s' "$DETAIL_LINES"
    echo ""
fi
echo "**残差路径**（未匹配规则的文件，按实际行为归类；同类文档可合并说明）："
if [[ "$RESIDUAL_COUNT" -gt 0 ]]; then
    printf '%s' "$RESIDUAL"
else
    echo "（无）"
fi
echo ""
echo "范围规则：表中触发是候选检查；按 skills/finish-check.md 的模式及真实行为选择。误报排除须说明依据，真实漏报须补入；mutation 要求保持独立。"
echo ""
echo "## Mutation 判定"
echo ""
if [[ -n "$MUTATION_EFFECTIVE_CSV" ]]; then
    echo "MUTATION_REQUIRED=yes"
else
    echo "MUTATION_REQUIRED=no"
fi
echo "MUTATION_AUTO_TARGETS=${MUTATION_AUTO_CSV:-（无）}"
echo "MUTATION_PLAN_TARGETS=${MUTATION_PLAN_CSV:-（无）}"
echo "MUTATION_EFFECTIVE_TARGETS=${MUTATION_EFFECTIVE_CSV:-（无）}"
if [[ -n "$MUTATION_EFFECTIVE_CSV" ]]; then
    echo "MUTATION_RUN=python3 skills/scripts/finish-check-exec.py run --run-dir \"\$FINISH_RUN\" --gate mutation --env 'MUTATE_TARGET_CLASSES=$MUTATION_EFFECTIVE_CSV'"
    echo "MUTATION_VERIFY_ARGS=--require mutation --expect-env 'mutation:MUTATE_TARGET_CLASSES=$MUTATION_EFFECTIVE_CSV'"
else
    echo "MUTATION_RUN=（不运行）"
    echo "MUTATION_VERIFY_ARGS=（无）"
fi
echo ""
echo "规则 ${n} 行 / 是 ${YES_COUNT} / 否 ${NO_COUNT} / 人工 ${MANUAL_COUNT} / 变更文件 ${FILE_COUNT} / 残差 ${RESIDUAL_COUNT}"
exit 0
