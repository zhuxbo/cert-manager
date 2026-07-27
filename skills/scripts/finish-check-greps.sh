#!/usr/bin/env bash
# finish-check 硬零断言收敛脚本 — 把 skills/review-checklist.md 各反模式"检查动作"里的
# 可机器验证 grep 收敛成单入口（挂 finish-check §6，与 check-review-checklist-staleness.sh 同行）。
#
# 检查项 ↔ 反模式映射（增删反模式条目时同步本表）：
#   硬零（命中即 FAIL，退出码 1）：
#     Z1  TaskJob::dispatch 语句聚合 afterCommit+onQueue        — 反模式 11
#     Z2  ExecutableFinder 禁用（排除注释行）                    — 反模式 12
#     Z3  throws(ApiResponseException::class, 假绿断言           — 反模式 16
#     Z4  FILTER_FLAG_NO_RES_RANGE 黑名单制                      — 反模式 18
#     Z5  SHOW INDEX/COLUMNS/TABLES 带 ? 占位（排除注释行）       — 反模式 21
#     Z6  'inline' 出口超出 isImage|isPdf 白名单                  — 反模式 19
#     Z7  Cache::add 当互斥锁（行含 lock|mutex|互斥）             — 反模式 20
#     Z8  Cache::forget 释放 LOCK 风格常量                        — 反模式 20
#     Z9  token_version 直写散落在 Models/{User,Admin}.php 之外   — 反模式 17
#     Z10 插件后端 query(token|api_key|secret) 凭据进 URL         — 反模式 17
#     Z11 > /dev/null 2>&1 丢 stderr（静默降级帮凶）              — 反模式 12
#     Z12 Task 模型 lockForUpdate 逸出 Task/TaskJob 白名单        — 反模式 10
#     Z13 Task 表索引最终态快照禁止回归                          — 反模式 10
#     Z14 Task::lockForMutation 索引 hint 接线必须完整            — 反模式 10
#     Z15 error_code 三份对称副本等价（常量↔yaml enum↔skill 清单）— 反模式 4
#   WARN（命中只列清单人工核对，不影响退出码）：
#     W1  ->password = 赋值点（同方法须 revokeAllSessions，创建/注册豁免）— 反模式 17
#     W2  Services registry 类 register() 未绑 singleton          — 反模式 2
#     W3  markTestSkipped（真环境无关 vs 吞 bug 逐处反问）         — 反模式 15
#     W4  factories 语义噪音 faker（喂业务校验即改固定值）         — 反模式 14
#     W5  Pest ->skip( 非闭包形式（收集期 eager 求值）            — 反模式 14
#
# 豁免：skills/scripts/.finish-check-greps-allowlist
#   每行若干空白分隔的子串（建议"文件路径 模式片段"），命中行需同时包含所有子串才豁免；
#   不用行号防漂移。'#' 后为注释。
set -uo pipefail

# 非 git 仓库或 git 不可用 → 静默退出 0（不阻塞 finish-check 流程）
if ! git rev-parse --show-toplevel >/dev/null 2>&1; then
    echo "skip: 非 git 仓库或 git 不可用"
    exit 0
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

ALLOWLIST="$REPO_ROOT/skills/scripts/.finish-check-greps-allowlist"

# 加载豁免列表（空行/注释跳过；保留整行，匹配时按空白拆子串）
declare -a allowlist=()
if [[ -f "$ALLOWLIST" ]]; then
    while IFS= read -r line; do
        line="${line%%#*}"
        line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        [[ -z "$line" ]] && continue
        allowlist+=("$line")
    done <"$ALLOWLIST"
fi

# stdin 逐行过滤：命中行同时包含某豁免行的全部子串则剔除
filter_allowlist() {
    if [[ ${#allowlist[@]} -eq 0 ]]; then
        cat
        return
    fi
    local line entry token matched
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        matched=0
        for entry in "${allowlist[@]}"; do
            local all=1
            for token in $entry; do
                if [[ "$line" != *"$token"* ]]; then
                    all=0
                    break
                fi
            done
            if [[ $all -eq 1 ]]; then
                matched=1
                break
            fi
        done
        [[ $matched -eq 0 ]] && printf '%s\n' "$line"
    done
}

total=0
fail=0
warn=0

run_check() {
    # $1=级别(FAIL|WARN) $2=名称 $3=检查函数名
    local level="$1" name="$2" fn="$3"
    total=$((total + 1))
    local raw filtered raw_n filtered_n exempt
    raw="$("$fn" || true)"
    filtered="$(printf '%s\n' "$raw" | filter_allowlist)"
    raw_n=$(printf '%s' "$raw" | grep -c . || true)
    filtered_n=$(printf '%s' "$filtered" | grep -c . || true)
    exempt=$((raw_n - filtered_n))
    if [[ -n "$filtered" ]]; then
        if [[ "$level" == "FAIL" ]]; then
            fail=$((fail + 1))
            echo "FAIL: $name"
        else
            warn=$((warn + 1))
            echo "WARN: ${name}（人工核对）"
        fi
        printf '%s\n' "$filtered" | sed 's/^/    /'
    else
        if [[ $exempt -gt 0 ]]; then
            echo "PASS: ${name}（豁免 $exempt 处）"
        else
            echo "PASS: $name"
        fi
    fi
}

chk_zero() { run_check FAIL "$1" "$2"; }
chk_warn() { run_check WARN "$1" "$2"; }

# ---------- 硬零项 ----------

z1_taskjob_dispatch() {
    # 语句聚合到分号再断言（多行链式不误报），跳过注释行
    local files
    files=$(git grep -l 'TaskJob::dispatch' -- backend/app || true)
    [[ -z "$files" ]] && return 0
    # shellcheck disable=SC2086
    awk '/TaskJob::dispatch/ && $0 !~ /^[[:space:]]*(\/\/|\*|#)/ {
        stmt = $0; line = FNR
        while (stmt !~ /;/ && (getline nl) > 0) stmt = stmt nl
        if (stmt !~ /afterCommit/ || stmt !~ /onQueue/) print FILENAME ":" line
    }' $files
}

z2_executable_finder() {
    git grep -n 'ExecutableFinder' -- backend/app | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#)' || true
}

z3_throws_apiresponse() {
    git grep -nF 'throws(ApiResponseException::class,' -- backend/tests || true
}

z4_filter_flag_no_res_range() {
    git grep -n 'FILTER_FLAG_NO_RES_RANGE' -- backend/app || true
}

z5_show_placeholder() {
    git grep -nE 'SHOW (INDEX|COLUMNS|TABLES)' -- backend | grep '?' | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#)' || true
}

z6_inline_whitelist() {
    # grep -rn 而非 git grep：覆盖未跟踪新文件
    grep -rn "'inline'" backend/app --include='*.php' | grep -v 'isImage\|isPdf' || true
}

z7_cache_add_as_mutex() {
    git grep -n 'Cache::add(' -- backend/app | grep -iE 'lock|mutex|互斥' || true
}

z8_cache_forget_lock() {
    git grep -nE 'Cache::forget\((self::)?\$?[A-Za-z_]*LOCK' -- backend/app ':(glob)plugins/*/backend/**' || true
}

z9_token_version_inline_write() {
    # [^=>] 排除 JWT claims 数组的 'token_version' => 形态；吊销三件套必须走 revokeAllSessions 单点
    git grep -nE 'token_version[[:space:]]*(=[^=>]|\+\+)' -- backend/app plugins | grep -vE 'Models/(User|Admin)\.php' || true
}

z10_plugins_token_query() {
    git grep -nE "query\(['\"](token|api_key|secret)" -- ':(glob)plugins/*/backend/**' || true
}

z11_devnull_discard() {
    git grep -n '> /dev/null 2>&1' -- backend/app || true
}

z12_task_lockforupdate() {
    # Task:: 起头的语句聚合到分号，若链中出现 lockForUpdate 即为「Task 模型直接 FOR UPDATE」；
    # 仅允许三处：Task 模型 scope 定义（app/Models/Task.php）、TaskJob 主键锁（app/Jobs/TaskJob.php）、
    # SweepStaleTasksCommand 主键 CAS 复检锁（主键等值单行锁与 TaskJob 同构、无间隙锁风险）；
    # 其余一律走 Task::lockForMutation scope（强制复合索引 + 统一锁顺序，防退回单列索引宽间隙锁 → 1213）。
    # 触发词是 Task:: 而非 lockForUpdate：其他模型（Order/User/Acme）的 lockForUpdate 不以 Task:: 起头，零误报；
    # Task::lockForMutation(...) 调用点不含 lockForUpdate 字面量，不误命中。
    local files
    files=$(git grep -l 'Task::' -- backend/app || true)
    files=$(printf '%s\n' "$files" | grep -vE 'app/Models/Task\.php$|app/Jobs/TaskJob\.php$|app/Console/Commands/SweepStaleTasksCommand\.php$' || true)
    [[ -z "$files" ]] && return 0
    # shellcheck disable=SC2086
    awk '/Task::/ && $0 !~ /^[[:space:]]*(\/\/|\*|#)/ {
        stmt = $0; line = FNR
        while (stmt !~ /;/ && (getline nl) > 0) stmt = stmt nl
        if (stmt ~ /lockForUpdate/) print FILENAME ":" line
    }' $files
}

z13_task_structure_snapshot() {
    local -a php_runner
    local output status
    if command -v php >/dev/null 2>&1; then
        php_runner=(php)
    elif command -v docker >/dev/null 2>&1 && docker compose exec -T app php -r 'exit(0);' >/dev/null 2>&1; then
        php_runner=(docker compose exec -T app php)
    else
        echo "backend/database/structure.json: php unavailable; cannot verify tasks indexes"
        return 0
    fi

    output="$(
        "${php_runner[@]}" <<'PHP'
<?php

$label = 'backend/database/structure.json';
$path = $label;
if (! is_file($path) && is_file('database/structure.json')) {
    $path = 'database/structure.json';
}
$target = 'tasks_order_action_status_index';
$expectedColumns = ['order_id', 'action', 'status'];

function z13_fail(string $message): void
{
    echo $message, PHP_EOL;
}

if (! is_file($path)) {
    z13_fail("$label: missing file");
    exit(0);
}

$json = file_get_contents($path);
if ($json === false) {
    z13_fail("$label: cannot read file");
    exit(0);
}

$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    z13_fail("$label: invalid json: ".json_last_error_msg());
    exit(0);
}

$indexes = $data['tables']['tasks']['indexes'] ?? null;
if (! is_array($indexes)) {
    z13_fail("$label: missing tables.tasks.indexes");
    exit(0);
}

$targetIndex = $indexes[$target] ?? null;
if (! is_array($targetIndex)) {
    z13_fail("$label: missing tables.tasks.indexes.$target");
} else {
    if (($targetIndex['unique'] ?? null) !== false) {
        $actual = json_encode($targetIndex['unique'] ?? null, JSON_UNESCAPED_SLASHES);
        z13_fail("$label: $target must be non-unique, got unique=$actual");
    }

    $columns = $targetIndex['columns'] ?? null;
    if ($columns !== $expectedColumns) {
        $actual = json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        z13_fail("$label: $target columns must be [order_id,action,status], got $actual");
    }
}

foreach ($indexes as $name => $index) {
    if (! is_array($index)) {
        z13_fail("$label: tables.tasks.indexes.$name must be an object");
        continue;
    }

    $columns = $index['columns'] ?? null;
    if (! is_array($columns)) {
        z13_fail("$label: tables.tasks.indexes.$name.columns must be an array");
        continue;
    }

    if (($columns[0] ?? null) === 'order_id' && $name !== $target) {
        $actual = implode(',', $columns);
        z13_fail("$label: forbidden tasks order_id-leading index $name($actual)");
    }
}
PHP
    )"
    status=$?
    printf '%s\n' "$output"

    if [[ $status -ne 0 ]]; then
        echo "backend/database/structure.json: php execution failed; cannot verify tasks indexes"
    fi
}

z14_task_lock_scope_wiring() {
    local file="backend/app/Models/Task.php"
    if [[ ! -f "$file" ]]; then
        echo "$file: missing file"
        return 0
    fi

    if ! grep -Fq "public const TASK_LOCK_INDEX = 'tasks_order_action_status_index';" "$file"; then
        echo "$file: TASK_LOCK_INDEX must equal tasks_order_action_status_index"
    fi

    local method
    method=$(awk '
        /function scopeLockForMutation\(/ { capture = 1 }
        capture { print }
        capture && /^[[:space:]]*}[[:space:]]*$/ { exit }
    ' "$file")

    if [[ -z "$method" ]]; then
        echo "$file: missing scopeLockForMutation"
        return 0
    fi

    if ! printf '%s\n' "$method" | grep -Fq -- "->forceIndex(self::TASK_LOCK_INDEX)"; then
        echo "$file: scopeLockForMutation missing forceIndex(self::TASK_LOCK_INDEX)"
    fi
    if ! printf '%s\n' "$method" | grep -Fq -- "->where('order_id', \$orderId)"; then
        echo "$file: scopeLockForMutation missing where('order_id', \$orderId)"
    fi
    if ! printf '%s\n' "$method" | grep -Fq -- "->whereIn('action', \$actions)"; then
        echo "$file: scopeLockForMutation missing whereIn('action', \$actions)"
    fi
    if ! printf '%s\n' "$method" | grep -Fq -- "->whereIn('status', ['executing', 'stopped'])"; then
        echo "$file: scopeLockForMutation missing whereIn('status', ['executing', 'stopped'])"
    fi
    if ! printf '%s\n' "$method" | grep -Fq -- "->select('id')"; then
        echo "$file: scopeLockForMutation missing select('id')"
    fi
    if ! printf '%s\n' "$method" | grep -Fq -- "->lockForUpdate()"; then
        echo "$file: scopeLockForMutation missing lockForUpdate()"
    fi
}

z15_error_code_symmetry() {
    # ApiErrorCode 常量 ↔ deploy.yaml enum ↔ deploy-renewal.md 清单 三份对称副本的等价校验。
    # 三份都在本仓内（客户端 deploy-spec.md 已移出同步面），故可机器比对——此前"跨仓章节号无法
    # 校验"的借口不再成立。漏同步的代价：新增的永久性失败码在下游落进"未分类"→ 沿用重试策略 →
    # 需人工介入的事故被降级成 daemon 每日静默重试（正是 ApiErrorCode 类注释要消灭的形态）。
    local src='backend/app/Support/ApiErrorCode.php'
    local yaml='backend/resources/docs/api/deploy.yaml'
    local doc='skills/backend/deploy-renewal.md'
    local f
    for f in "$src" "$yaml" "$doc"; do
        if [[ ! -f "$f" ]]; then
            echo "$f: missing file"
            return 0
        fi
    done

    # 常量提取必须覆盖全部 public 形态；只认一种写法会让偏离形态静默漏采——那正是本检查唯一要守
    # 的方向（只改常量、漏改 yaml/清单）上的假绿。修饰符写成 (final|public)* 而非要求 public 字面量：
    # **PHP 类常量省略可见性即为 public**（裸 `const X = 'x';` 是正式发布的取值），`public final` 与
    # `final public` 两种顺序也都合法。行首到 const 之间只允许 final/public，故 private/protected 仍不匹配。
    local consts yaml_enum declared extracted
    consts=$(sed -nE "s/^[[:space:]]*(final[[:space:]]+|public[[:space:]]+)*const[[:space:]]+([a-z]+[[:space:]]+)?[A-Z0-9_]+[[:space:]]*=[[:space:]]*['\"]([a-z0-9_]+)['\"];.*/\3/p" "$src" | sort -u)

    # 奇偶断言：声明条数 ≠ 抽取条数即两数对不上，响亮报错而非静默 PASS。
    # 成因不止"提取正则漏采"一种（同值常量别名、非取值常量如分组数组、跨行声明都会让两数不等），
    # 故文案保持中性并列出差集常量名，别把维护者一律引到正则上去。
    local declared_names missing_names
    declared_names=$(sed -nE 's/^[[:space:]]*(final[[:space:]]+|public[[:space:]]+)*const[[:space:]]+([a-z]+[[:space:]]+)?([A-Z0-9_]+).*/\3/p' "$src" | sort -u)
    declared=$(printf '%s' "$declared_names" | grep -c . || true)
    extracted=$(printf '%s' "$consts" | grep -c . || true)
    if [[ "$declared" -ne "$extracted" ]]; then
        missing_names=$(sed -nE 's/^[[:space:]]*(final[[:space:]]+|public[[:space:]]+)*const[[:space:]]+([a-z]+[[:space:]]+)?([A-Z0-9_]+)[[:space:]]*=[[:space:]]*['\''"][a-z0-9_]+['\''"];.*/\3/p' "$src" | sort -u)
        echo "$src: 声明 $declared 个 public const 但只取到 $extracted 个字面取值，两数不一致（可能是新增非取值常量 / 同值别名 / 跨行声明 / 提取正则漏采）；未取到取值的常量：$(comm -23 <(printf '%s\n' "$declared_names") <(printf '%s\n' "$missing_names") | tr '\n' ' ')"
        return 0
    fi
    # error_code 的 enum 块：定位到 error_code: 后的**首个** enum:，取到闭合 ] 为止。
    # 命中 ] 时把 seen 一并复位：否则该标志永不失效，error_code 之后任何 block 风格 enum:
    # （如给 DeployOrder.status 补枚举）都会被重新拉起，把无关字段的枚举当成漏同步的错误码误红。
    yaml_enum=$(awk '
        /^[[:space:]]*error_code:[[:space:]]*$/ { seen = 1 }
        seen && /^[[:space:]]*enum:[[:space:]]*$/ { grab = 1; next }
        grab { if ($0 ~ /\]/) { grab = 0; seen = 0 } gsub(/[][,[:space:]]/, ""); if ($0 != "") print }
    ' "$yaml" | sort -u)

    if [[ -z "$consts" ]]; then
        echo "$src: 未解析到任何 error_code 常量（提取规则失效，检查已形同虚设）"
        return 0
    fi
    if [[ -z "$yaml_enum" ]]; then
        echo "$yaml: 未解析到 error_code enum 块（提取规则失效，检查已形同虚设）"
        return 0
    fi

    local code
    while IFS= read -r code; do
        [[ -z "$code" ]] && continue
        echo "$yaml: enum 缺少 $code（ApiErrorCode 已定义）"
    done < <(comm -23 <(printf '%s\n' "$consts") <(printf '%s\n' "$yaml_enum"))
    while IFS= read -r code; do
        [[ -z "$code" ]] && continue
        echo "$yaml: enum 多出 $code（ApiErrorCode 无对应常量）"
    done < <(comm -13 <(printf '%s\n' "$consts") <(printf '%s\n' "$yaml_enum"))

    # 文档清单段：从「错误响应的机器可读标识」小节标题到下一个同级或更高层级标题（h1-h3）为止；
    # 只停在 ### 会漏掉后续 ## 小节，把别处的 backtick token 卷进反向校验（已踩过）。
    # 边界写成显式并列而非 /^#{1,3} /：**mawk 不支持 ERE 区间量词**，会把 {1,3} 当字面串，
    # 边界永不成立 → 干净树在 Debian/Ubuntu（awk 默认 provider 即 mawk，CI runner 正是它）恒红。
    local section
    section=$(awk '/^### 错误响应的机器可读标识/ { f = 1; next } f && /^# |^## |^### / { f = 0 } f' "$doc")
    if [[ -z "$section" ]]; then
        echo "$doc: 未找到「错误响应的机器可读标识」小节（提取规则失效，检查已形同虚设）"
        return 0
    fi

    # 只认「出口清单行」——判据是「以 `- \`` 开头**且**含 →」，不是所有 `- ` 行：小节里新增一条
    # 普通说明列表项（无 → 或以别的字符起头）不该被当成出口清单参与比对（否则一句补充说明就误红）。
    # 取首个 → 之后的 backtick token，不认全节文本：
    # 同节的说明段落也会提到码名，拿整节做 containment 会让「清单漏登记新码」蒙混过关（已踩过）。
    # 切首个 → 用 [^→]* 而非 .*：贪婪匹配会在出口行出现第二个 → 时把其左侧的码整段丢掉，
    # 表现为「清单缺少 X」误红（失败方向安全但排查成本高、错误信息误导）。
    # 出口行 → 之后**只允许**出现取值本身；DOC_NON_CODE_TOKENS 是唯一例外白名单：
    # retry_after 是 rate_limited 的伴随字段而非取值。往括注里写别的 backtick token 会让门禁误红，
    # 这是有意的严格——要写说明请放到出口行之外的段落。
    local -a DOC_NON_CODE_TOKENS=(retry_after)
    local doc_codes exclude
    exclude=$(printf '%s\n' "${DOC_NON_CODE_TOKENS[@]}")
    doc_codes=$(printf '%s\n' "$section" | grep '^- `' | grep '→' | sed 's/^[^→]*→//' |
        grep -oE '`[a-z][a-z0-9_]*`' | tr -d '`' | grep -vxF "$exclude" | sort -u)
    if [[ -z "$doc_codes" ]]; then
        echo "$doc: 清单未解析到任何出口取值（提取规则失效，检查已形同虚设）"
        return 0
    fi

    while IFS= read -r code; do
        [[ -z "$code" ]] && continue
        echo "$doc: 清单缺少 $code（ApiErrorCode 已定义）"
    done < <(comm -23 <(printf '%s\n' "$consts") <(printf '%s\n' "$doc_codes"))
    while IFS= read -r code; do
        [[ -z "$code" ]] && continue
        echo "$doc: 清单出现 $code（ApiErrorCode 无对应常量）"
    done < <(comm -13 <(printf '%s\n' "$consts") <(printf '%s\n' "$doc_codes"))
}

# ---------- WARN 项 ----------

w1_password_assign() {
    git grep -ne '->password = ' -- backend/app plugins || true
}

w2_registry_singleton() {
    local f base
    git grep -l 'public function register(' -- backend/app/Services 2>/dev/null | while read -r f; do
        base="$(basename "$f")"
        git grep -q "singleton(.*${base%.php}::class" -- backend/app/Providers || echo "未绑 singleton: $f"
    done
}

w3_mark_test_skipped() {
    # 排除注释行（//、*、# 开头）：注释里提到 markTestSkipped 不算实际 skip（与 z2/z5 同口径）
    git grep -n 'markTestSkipped' -- backend/tests | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#)' || true
}

w4_faker_semantic_noise() {
    git grep -nE 'fake\(\)->(jobTitle|sentence|word|text|paragraph|catchPhrase)' -- backend/database/factories || true
}

w5_skip_eager() {
    git grep -ne '->skip(' -- backend/tests | grep -v 'fn ()' || true
}

# ---------- 执行 ----------

echo "tree: $(git rev-parse --short HEAD)$(git diff --quiet HEAD 2>/dev/null || echo ' (dirty)')"
echo ""

chk_zero "Z1 TaskJob::dispatch 必须 afterCommit+onQueue（反模式 11）" z1_taskjob_dispatch
chk_zero "Z2 ExecutableFinder 禁用（反模式 12）" z2_executable_finder
chk_zero "Z3 throws(ApiResponseException::class, 假绿断言（反模式 16）" z3_throws_apiresponse
chk_zero "Z4 FILTER_FLAG_NO_RES_RANGE 黑名单制（反模式 18）" z4_filter_flag_no_res_range
chk_zero "Z5 SHOW INDEX/COLUMNS/TABLES 带 ? 占位（反模式 21）" z5_show_placeholder
chk_zero "Z6 'inline' 出口超出 isImage|isPdf 白名单（反模式 19）" z6_inline_whitelist
chk_zero "Z7 Cache::add 当互斥锁（反模式 20）" z7_cache_add_as_mutex
chk_zero "Z8 Cache::forget 释放 LOCK 常量（反模式 20）" z8_cache_forget_lock
chk_zero "Z9 token_version 直写在单点之外（反模式 17）" z9_token_version_inline_write
chk_zero "Z10 插件后端 query(token|api_key|secret)（反模式 17）" z10_plugins_token_query
chk_zero "Z11 > /dev/null 2>&1 丢 stderr（反模式 12）" z11_devnull_discard
chk_zero "Z12 Task 模型 lockForUpdate 逸出 Task/TaskJob 白名单（反模式 10）" z12_task_lockforupdate
chk_zero "Z13 Task 表索引最终态快照禁止回归（反模式 10）" z13_task_structure_snapshot
chk_zero "Z14 Task::lockForMutation 索引 hint 接线必须完整（反模式 10）" z14_task_lock_scope_wiring
chk_zero "Z15 error_code 三份对称副本必须等价（反模式 4）" z15_error_code_symmetry

chk_warn "W1 ->password = 赋值点须同方法 revokeAllSessions（反模式 17）" w1_password_assign
chk_warn "W2 Services registry 类未绑 singleton（反模式 2）" w2_registry_singleton
chk_warn "W3 markTestSkipped 兜底（反模式 15）" w3_mark_test_skipped
chk_warn "W4 factories 语义噪音 faker（反模式 14）" w4_faker_semantic_noise
chk_warn "W5 Pest ->skip( 非闭包（反模式 14）" w5_skip_eager

echo ""
echo "检查 $total 项 / 失败 $fail 项 / 警告 $warn 项"
[[ $fail -gt 0 ]] && exit 1
exit 0
