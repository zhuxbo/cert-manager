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
#     Z16 shell 里 $VAR 紧跟非 ASCII 字节（bash 3.2 吞变量名）      — 反模式 7
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

# 各检查函数的 stderr 收集点（run_check 每次调用前清空）。全程单文件 + trap，
# 免得每项一个 mktemp 在 Ctrl-C / 被 kill 时把临时文件留在 TMPDIR。
ERRFILE="$(mktemp "${TMPDIR:-/tmp}/finish-check-greps.XXXXXX")"
trap 'rm -f "$ERRFILE"' EXIT

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
    local raw filtered raw_n filtered_n exempt rc err
    # 「检查函数没跑完」必须与「检查函数没命中」区分开：原先 `raw="$("$fn" || true)"` 只取 stdout、
    # 用 || true 抹掉退出码、stderr 无人消费——检查函数中途崩掉（未定义变量 / 命令不存在 /
    # awk 语法错 / git 失败）会输出空 stdout，被判成"零命中"即 PASS。这不是理论问题：Z15 的
    # 报错行曾因 bash 3.2 多字节吞变量名（见 Z16）在开发机上每次都崩，整项静默 PASS 放过 7/8 漂移。
    # 故这里捕退出码与 stderr，退出码非零或 stderr 非空即判 FAIL（无论该项是 FAIL 级还是 WARN 级
    # ——检查跑不完是门禁故障，不是"待人工核对"）。**代价对称**：检查函数必须自己消化预期内的
    # 非零与噪音（零命中的 grep / git grep 一律补 `|| true`，pipefail 下管道末尾同理），否则
    # "零命中"会被误报成"没跑完"——那是把本条修复反过来用，同样是错的诊断。
    : >"$ERRFILE"
    raw="$("$fn" 2>"$ERRFILE")"
    rc=$?
    err="$(cat "$ERRFILE")"
    if [[ $rc -ne 0 || -n "$err" ]]; then
        fail=$((fail + 1))
        echo "FAIL: ${name}（检查函数自身未跑完：退出码 ${rc}，是门禁故障而非零命中）"
        [[ -n "$err" ]] && printf '%s\n' "$err" | sed 's/^/    stderr: /'
        return
    fi
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
    # `final public` 两种顺序也都合法，同行属性 `#[Attr] public const X = 'x';` 亦然。
    # 行首到 const 之间只允许属性与 final/public，故 private/protected 仍不匹配。
    local consts yaml_blocks declared extracted const_lines value_re
    value_re="^[[:space:]]*(#\[[^]]*\][[:space:]]*)*(final[[:space:]]+|public[[:space:]]+)*const[[:space:]]+([a-z]+[[:space:]]+)?[A-Z0-9_]+[[:space:]]*=[[:space:]]*['\"]([a-z0-9_]+)['\"];"

    # 奇偶断言：声明条数 ≠ 抽取条数即两数对不上，响亮报错而非静默 PASS。
    # 成因不止"提取正则漏采"一种（同值常量别名、非取值常量如分组数组、跨行声明都会让两数不等），
    # 故文案保持中性并列出未取到取值的整行，别把维护者一律引到正则上去。
    # 分母必须与取值正则**解耦**：两条共用前缀的正则互为分母时，前缀的共同盲区（实测：同行属性
    # 写法曾让两条同时漏采）会让分子分母同步减一、断言静默失效。故分母只认「行内出现 const 关键字、
    # 非注释行、非 private/protected 声明」这一独立口径，不依赖修饰符顺序 / 类型标注 / 属性写法。
    # 已知限制：字符串或 heredoc 体内顶格的 `const X = 'x';` 会被当成真常量（失败方向是误红，
    # 不会放过漂移；该文件是纯常量类，无此形态）。
    # 排除 private/protected 必须**锚定到声明前缀**（行首 → 修饰符串），不能用"整行任意位置命中
    # 就丢"的过滤：那样 `public const X = 'x'; // private 场景专用` 这类行尾注释会把整行从分子和
    # 分母里同时抹掉 → 奇偶断言恒不响、漂移静默放过，等于把本函数刚消灭的失效模式换个位置复活。
    const_lines=$(grep -E '(^|[^[:alnum:]_$])const[[:space:]]' "$src" |
        grep -vE '^[[:space:]]*(//|\*|/\*|#[^[])' |
        grep -vE '^[[:space:]]*(#\[[^]]*\][[:space:]]*)*((final|public|private|protected|readonly)[[:space:]]+)*(private|protected)[[:space:]]' || true)
    consts=$(printf '%s\n' "$const_lines" | sed -nE "s/${value_re}.*/\4/p" | sort -u)
    declared=$(printf '%s' "$const_lines" | grep -c . || true)
    extracted=$(printf '%s' "$consts" | grep -c . || true)
    if [[ "$declared" -ne "$extracted" ]]; then
        echo "$src: 含 const 的声明行 $declared 条但只取到 $extracted 个字面取值，两数不一致（可能是新增非取值常量 / 同值别名 / 跨行声明 / 提取正则漏采）；未取到取值的行：$(printf '%s\n' "$const_lines" | grep -vE "$value_re" | sed 's/^[[:space:]]*//' | tr '\n' '|')"
        return 0
    fi
    # error_code 的 enum 块：定位到 error_code: 后的**首个** enum:，取到闭合 ] 为止。
    # 命中 ] 时把 seen 一并复位：否则该标志永不失效，error_code 之后任何 block 风格 enum:
    # （如给 DeployOrder.status 补枚举）都会被重新拉起，把无关字段的枚举当成漏同步的错误码误红。
    # 闭合 ] 那一行自身的内容仍要取（收尾行可能带最后一个取值），故用 closing 暂存、先取后复位。
    # **逐块输出块号而非并集**：多个 error_code enum 块取并集会让「某一块漏码」被别的块掩盖
    # （比如给新响应 schema 补的 enum 少列一个码，却被全量那块盖住）——正是 Z15 唯一要守方向上的
    # 假绿。判定改成每块都与常量集全等；将来若确有 schema 只需子集，显式加白名单，别退回并集。
    yaml_blocks=$(awk '
        /^[[:space:]]*["'\'']?error_code["'\'']?[[:space:]]*:[[:space:]]*$/ { seen = 1 }
        seen && /^[[:space:]]*enum:[[:space:]]*$/ { grab = 1; blk++; blkline[blk] = FNR; next }
        grab {
            if ($0 ~ /\]/) closing = 1
            gsub(/[][,[:space:]]/, "")
            if ($0 != "") print blk "\t" blkline[blk] "\t" $0
            if (closing) { grab = 0; seen = 0; closing = 0 }
        }
    ' "$yaml")

    if [[ -z "$consts" ]]; then
        echo "$src: 未解析到任何 error_code 常量（提取规则失效，检查已形同虚设）"
        return 0
    fi
    if [[ -z "$yaml_blocks" ]]; then
        echo "$yaml: 未解析到 error_code enum 块（提取规则失效，检查已形同虚设）"
        return 0
    fi

    # 块数的分母同样独立于抽取口径（同 const 侧的教训）：直接数 `error_code:` 这个 key 出现几次，
    # 与抽取器认出的块数比对。不等即说明有块没被认出（flow 风格 `enum: [a, b]` 单行、error_code:
    # 与 enum: 之间隔了别的键、缺 enum 等），响亮报错，而不是让那一块静默逃过逐块比对。
    # 两处都容忍带引号的键（`"error_code":`）：只在一处加会让「分母 +1 而块数 +0」变成误红，
    # 两处都不加则 +0/+0 相互抵消、该块漏码静默 PASS——分母要独立于**抽取口径**，不是独立于**键的写法**。
    # 已知限制：enum 写成 dash 块序列（`- rate_limited` 逐行、无 `]`）时 grab 永不复位，会把后续行
    # 当取值刷一串误红（fail-closed，方向安全但指向错误）；本仓 yaml 由 prettier 统一成 flow 风格，无此形态。
    local yaml_decl_n yaml_blk_n
    yaml_decl_n=$(grep -cE '^[[:space:]]*["'\'']?error_code["'\'']?[[:space:]]*:' "$yaml" || true)
    yaml_blk_n=$(printf '%s\n' "$yaml_blocks" | cut -f1 | sort -un | grep -c . || true)
    if [[ "$yaml_decl_n" -ne "$yaml_blk_n" ]]; then
        echo "$yaml: 有 ${yaml_decl_n} 处 error_code: 键但只抽出 ${yaml_blk_n} 个 enum 块（未被认出的块不会参与比对，等于漏检；检查该键下的 enum 是否写成了单行 flow 风格或与 error_code: 之间隔了别的键）"
        return 0
    fi

    # 变量后紧跟中文/全角标点**必须**写 ${code}，不能写裸的 $code 形态（此处刻意留一个空格，
    # 免得反例本身被 Z16 的同款收敛顺手改掉）：bash 3.2（macOS 系统自带 /bin/bash，
    # `#!/usr/bin/env bash` 在没装 homebrew bash 的开发机上正是它）在 UTF-8 locale 下会把全角字符的
    # 首字节并进标识符，配合 `set -u` 直接 `code?: unbound variable`——报错发生在 run_check 的
    # `raw="$("$fn" || true)"` 里被 `|| true` 吞掉，本项**静默 PASS**，正好是本检查唯一要守方向上的假绿。
    # 无 set -u 时同样丢值（消息里的码名变乱码）。C locale 与 bash 5.x 不复现，故只在开发机终端暴露。
    local code blk blk_line blk_codes
    while IFS= read -r blk; do
        [[ -z "$blk" ]] && continue
        blk_line=$(printf '%s\n' "$yaml_blocks" | awk -F'\t' -v b="$blk" '$1 == b { print $2; exit }')
        blk_codes=$(printf '%s\n' "$yaml_blocks" | awk -F'\t' -v b="$blk" '$1 == b { print $3 }' | sort -u)
        while IFS= read -r code; do
            [[ -z "$code" ]] && continue
            echo "${yaml}:${blk_line}: 第 ${blk} 个 error_code enum 块缺少 ${code}（ApiErrorCode 已定义）"
        done < <(comm -23 <(printf '%s\n' "$consts") <(printf '%s\n' "$blk_codes"))
        while IFS= read -r code; do
            [[ -z "$code" ]] && continue
            echo "${yaml}:${blk_line}: 第 ${blk} 个 error_code enum 块多出 ${code}（ApiErrorCode 无对应常量）"
        done < <(comm -13 <(printf '%s\n' "$consts") <(printf '%s\n' "$blk_codes"))
    done < <(printf '%s\n' "$yaml_blocks" | cut -f1 | sort -un)

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
    # 切首个 → 只能用 awk -F'→'，不能用 sed 's/^[^→]*→//'：→ 是 3 字节 E2 86 92，C/POSIX locale 下
    # sed 按字节解释，[^→] 退化成「不是 E2/86/92 的单字节」，遇到本身含 0x86 的汉字（如「写」E5 86 99）
    # 整条替换不匹配、原样输出——收窄对写侧那行从未生效，且判定随 locale 变化（宿主 UTF-8 与
    # 容器/CI 的 C locale 走不同路径，反模式 12）。awk 的 FS 按整串匹配，两种 locale × mawk/BSD awk
    # 四种组合行为一致（已实测）。$1="" 后余下字段以空格重组，只换分隔符不影响 backtick token 提取。
    # 出口行 → 之后**只允许**出现取值本身；DOC_NON_CODE_TOKENS 是唯一例外白名单：
    # retry_after 是 rate_limited 的伴随字段而非取值。往括注里写别的 backtick token 会让门禁误红，
    # 这是有意的严格——要写说明请放到出口行之外的段落。
    local -a DOC_NON_CODE_TOKENS=(retry_after)
    local doc_codes exclude
    exclude=$(printf '%s\n' "${DOC_NON_CODE_TOKENS[@]}")
    doc_codes=$(printf '%s\n' "$section" | grep '^- `' | grep '→' | awk -F'→' 'NF > 1 { $1 = ""; print }' |
        grep -oE '`[a-z][a-z0-9_]*`' | tr -d '`' | grep -vxF "$exclude" | sort -u)
    if [[ -z "$doc_codes" ]]; then
        echo "$doc: 清单未解析到任何出口取值（提取规则失效，检查已形同虚设）"
        return 0
    fi

    while IFS= read -r code; do
        [[ -z "$code" ]] && continue
        echo "$doc: 清单缺少 ${code}（ApiErrorCode 已定义）"
    done < <(comm -23 <(printf '%s\n' "$consts") <(printf '%s\n' "$doc_codes"))
    while IFS= read -r code; do
        [[ -z "$code" ]] && continue
        echo "$doc: 清单出现 ${code}（ApiErrorCode 无对应常量）"
    done < <(comm -13 <(printf '%s\n' "$consts") <(printf '%s\n' "$doc_codes"))
}

z16_shell_var_multibyte() {
    # bash 3.2（macOS 系统自带 /bin/bash；`#!/usr/bin/env bash` 在没装 homebrew bash 的开发机上
    # 解析到的正是它）在 UTF-8 locale 下把紧跟变量名的全角字符首字节并进标识符：
    # `echo "缺少 $code（已定义）"` → 变量名成了 code\xef，`set -u` 直接 unbound variable，
    # 无 set -u 时静默丢值。本仓 Z15 的四条差异报错曾全中此形态，报错又被 run_check 的
    # `|| true` 吞掉 → 整项静默 PASS（门禁自身假绿）。bash ≥4.2 与 C locale 不复现，
    # 所以容器/CI 恒绿、只在开发机终端暴露，靠跑一遍验不出来，必须靠本检查。
    # 判据：未转义的 $VAR 紧跟非 ASCII 字节。${VAR} 与 \$VAR 安全，注释行跳过。
    # 已知限制（方向 fail-closed 误红，不会放过真违例）：单引号串 / quoted heredoc 内不展开变量，
    # 写 '$VAR（' 同样判违例。扫描面 = 「已跟踪 + 未跟踪未忽略」的全部 .sh，并上首行是 shell
    # shebang 的其他脚本（frontend/*/.husky/* 即此类）；Makefile 配方与 workflow 的 run: 块
    # 不是独立文件，不在面内。**必须含未跟踪文件**：新写一个带中文提示的脚本、跑门禁、再 git add
    # 是最常见的引入顺序，只认索引等于在唯一能拦住它的那次运行里放行（同 z6 的 grep -rn 取向）。
    # 候选先粗筛 ^#! 再 head -1 复核确在首行——防文档 / heredoc 里的示例 shebang 混进来。
    # LC_ALL=C 让 [^...] 按字节判定（UTF-8 locale 下 awk 按字符解释会漏掉首字节这一层）。
    local f files
    files=$(
        {
            git ls-files '*.sh'
            git ls-files --others --exclude-standard '*.sh'
            {
                git grep -lE '^#!.*sh' -- . 2>/dev/null || true
                git ls-files --others --exclude-standard
            } | grep -v '\.sh$' | while IFS= read -r f; do
                [[ -f "$f" ]] || continue
                head -1 "$f" 2>/dev/null | LC_ALL=C grep -qE '^#!.*sh' && printf '%s\n' "$f"
            done
        } | sort -u
    )
    printf '%s\n' "$files" | while IFS= read -r f; do
        [[ -z "$f" ]] && continue
        # 已跟踪但工作区已删 / 已改名未 stage 是正常中间态，不是门禁故障——不跳过的话
        # awk 会往 stderr 写 can't open file，被 run_check 判成"检查没跑完"。
        [[ -f "$f" ]] || continue
        LC_ALL=C awk -v F="$f" '
            /^[[:space:]]*#/ { next }
            {
                line = $0
                sub(/\r$/, "", line)
                gsub(/\\\$/, "@", line)
                if (line ~ /\$[A-Za-z_][A-Za-z0-9_]*[^\t -~]/) print F ":" FNR ":" $0
            }
        ' "$f"
    done
}

# ---------- WARN 项 ----------

w1_password_assign() {
    git grep -ne '->password = ' -- backend/app plugins || true
}

w2_registry_singleton() {
    # 末尾 || true 不能省：git grep 零命中返回 1，`set -o pipefail` 下整条管道 rc=1，
    # 会被 run_check 判成"检查函数没跑完"——把"零命中"误诊成门禁故障，正是那条修复的反面。
    local f base
    git grep -l 'public function register(' -- backend/app/Services 2>/dev/null | while read -r f; do
        base="$(basename "$f")"
        git grep -q "singleton(.*${base%.php}::class" -- backend/app/Providers || echo "未绑 singleton: $f"
    done || true
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
chk_zero "Z16 shell \$VAR 紧跟非 ASCII 字节（反模式 7）" z16_shell_var_multibyte

chk_warn "W1 ->password = 赋值点须同方法 revokeAllSessions（反模式 17）" w1_password_assign
chk_warn "W2 Services registry 类未绑 singleton（反模式 2）" w2_registry_singleton
chk_warn "W3 markTestSkipped 兜底（反模式 15）" w3_mark_test_skipped
chk_warn "W4 factories 语义噪音 faker（反模式 14）" w4_faker_semantic_noise
chk_warn "W5 Pest ->skip( 非闭包（反模式 14）" w5_skip_eager

echo ""
echo "检查 $total 项 / 失败 $fail 项 / 警告 $warn 项"
[[ $fail -gt 0 ]] && exit 1
exit 0
