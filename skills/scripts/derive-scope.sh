#!/usr/bin/env bash
# derive-scope.sh — 从 git diff 机器推导 skills/finish-check.md §1 范围表
# 取代纯自报枚举制：路径行 glob 推导、内容行只扫 + 新增行、安全面/删除审核保持人工（仅给关键词提示）
#
# 规则 ↔ finish-check.md §1 范围表行 映射（**范围表行增删时必须同步本脚本**）：
#   [路径] 行1  backend/app/Services/Acme               ^backend/app/Services/Acme(/|\.php$)
#   [路径] 行2  backend/app/Services/Order              ^backend/app/Services/Order(/|\.php$)
#   [路径] 行3  Models/Fund|Transaction（资金路径）     ^backend/app/Models/(Fund|Transaction)\.php$
#   [路径] 行4  backend/database/migrations             ^backend/database/migrations/
#   [内容] 行5  原生 SQL                                DB::raw|whereRaw|DB::statement
#   [路径] 行6  AppServiceProvider 连接/时区注入        ^backend/app/Providers/AppServiceProvider\.php$
#   [路径] 行7  frontend/shared                         ^frontend/shared/
#   [路径] 行8  plugins/                                ^plugins/
#   [路径] 行9  deploy/ 升级脚本                        ^deploy/
#   [路径] 行10 tests/ 文件本身                         ^backend/tests/|^plugins/.*/tests/
#   [人工] 行11 安全面（鉴权/下载/解压/CORS/公开端点）  仅提示：routes/|Middleware/|Controller 改动 + 新增 public function
#   [内容] 行12 外部命令调用                            exec\(|proc_open|shell_exec
#   [内容] 行13 节流/防重/并发事务                      Cache::add|lockForUpdate|TaskJob::dispatch|Cache::lock
#   [内容] 行14 catch ApiResponseException              ApiResponseException
#   [内容] 行15 通知模板                                NotificationTemplate|NotificationCenter
#   [人工] 行16 删除审核（§1.5）                        仅提示：- 删除行含 class/function/Schema::drop 或 config 键
#   [路径] 行17 build/ 打包脚本 / .github/workflows     ^build/|^\.github/workflows/   （plan B1 新增行）
#
# 内容规则只扫 .php 文件的 + 新增行（避免文档/skill 提及关键字、或触碰含关键字的大文件即误报）；
# 默认模式额外把 untracked .php 文件按全文新增计入。
# 改判规则（单向棘轮）：脚本判"是"的行不得改判"否"（降级须附一行理由，reviewer 抽查）；
# "否"/"人工"行可由人工升级为"是"。
#
# 用法：bash skills/scripts/derive-scope.sh [--base <ref>]
#   默认           git diff HEAD + git diff --cached + untracked 合并去重
#   --base <ref>   git diff <ref>（对历史 ref 推导/自测，如 --base origin/main）
set -uo pipefail

usage() {
    cat <<'EOF'
用法: bash skills/scripts/derive-scope.sh [--base <ref>]
  默认           diff 范围 = git diff HEAD + git diff --cached + untracked 合并去重
  --base <ref>   diff 范围 = git diff <ref>（如 --base origin/main、--base HEAD~5）
EOF
}

BASE_REF=""
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
    CHANGED_FILES="$(git diff "$BASE_REF" --name-only | sort -u)"
    RAW_DIFF="$(git diff "$BASE_REF" -U0 --no-color)"
    DIFF_DESC="git diff $BASE_REF"
else
    CHANGED_FILES="$(
        {
            git diff HEAD --name-only
            git diff --cached --name-only
            git ls-files --others --exclude-standard
        } | sort -u
    )"
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
    '^deploy/'
    '^backend/tests/|^plugins/.*/tests/'
    ''
    'exec\(|proc_open|shell_exec'
    'Cache::add|lockForUpdate|TaskJob::dispatch|Cache::lock'
    'ApiResponseException'
    'NotificationTemplate|NotificationCenter'
    ''
    '^build/|^\.github/workflows/'
)
ROW_TRIG=(
    "ACME 测试集必跑"
    "Order 测试集必跑"
    "§2.6 资金证据必贴 + §2.4 mysql 5.7 容器必跑"
    "§2.4 必跑；实跑 migrate + db:structure --check、增量迁移回灌建表迁移（反模式 21）"
    "§2.4 必跑"
    "§2.4 必跑"
    "admin + user 两端构建必验 + pnpm test:shared 必跑"
    "§4 插件检查必跑"
    "反模式 7/21 + 19（仅其部署脚本外部值条目）重点扫描；必跑 deploy/test/test-*.sh 贴输出"
    "§2.3 测试集 + 反模式 14（flaky 四源）+ 15（伪绿）"
    "§7 安全风险细化 + 反模式 17/18/19；实发一次绕过请求验证防御生效"
    "反模式 12（BinaryLocator + 开发机/生产环境差异）"
    "反模式 10（task→业务行锁顺序）/ 20（check-then-act 原子化、占位失败回滚、防重占位 ≠ 互斥锁、死锁不可吞）"
    "反模式 16（消息走 getApiResponse()['msg']，getMessage() 恒空）"
    "§7 部署风险加 db:seed NotificationTemplateSeeder + 模板渲染单测"
    "§1.5 删除审核必跑"
    "反模式 2/21 重点扫描（实跑 build.sh 后 unzip -l 验包；CI job 增删逐个确认）"
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
echo "## §1 范围表（skills/scripts/derive-scope.sh 推导）"
echo ""
echo "tree: $(git rev-parse --short HEAD) dirty: $(git status --porcelain | wc -l | tr -d ' ') files"
echo "diff 基准: ${DIFF_DESC}；变更文件 ${FILE_COUNT} 个"
if [[ "$FILE_COUNT" -eq 0 ]]; then
    echo "注意: diff 为空（无改动文件），全部判否属预期"
fi
echo ""
echo "| 维度 | 是/否/人工 | 触发 |"
echo "|------|------------|------|"
printf '%s' "$TABLE_ROWS"
echo ""
if [[ -n "$DETAIL_LINES" ]]; then
    echo "命中明细："
    printf '%s' "$DETAIL_LINES"
    echo ""
fi
echo "**残差路径**（未匹配任何规则的改动文件——执行者必须逐个显式归入上表某行，或写一句\"确认无触发\"理由，不允许整体留空）："
if [[ "$RESIDUAL_COUNT" -gt 0 ]]; then
    printf '%s' "$RESIDUAL"
else
    echo "（无）"
fi
echo ""
echo "改判规则（单向棘轮）：脚本判\"是\"的行不得改判\"否\"（降级须附一行理由，reviewer 抽查）；\"否\"/\"人工\"行可人工升级为\"是\"。"
echo ""
echo "规则 ${n} 行 / 是 ${YES_COUNT} / 否 ${NO_COUNT} / 人工 ${MANUAL_COUNT} / 变更文件 ${FILE_COUNT} / 残差 ${RESIDUAL_COUNT}"
exit 0
