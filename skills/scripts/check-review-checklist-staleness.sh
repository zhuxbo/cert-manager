#!/usr/bin/env bash
# 案例腐烂检测 — 验证守护文件（skills/review-checklist.md + skills/finish-check.md）
# 反模式案例引用的类/方法/文件仍存在，commit SHA 锚点仍可解析，并输出条目规模信号
# 失败不阻塞（warning 性质），结果贴入 finish-check 总结的"已知局限性"段
set -euo pipefail

# 非 git 仓库或 git 不可用 → 静默退出 0（不阻塞 finish-check 流程）
if ! git rev-parse --show-toplevel >/dev/null 2>&1; then
    echo "skip: 非 git 仓库或 git 不可用"
    exit 0
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

# 守护文件列表（相对仓库根）；git grep 必须同时排除全部守护文件，
# 否则 finish-check.md 的条目在自身/对方文件命中即假绿
CHECKLISTS=(
    "skills/review-checklist.md"
    "skills/finish-check.md"
)
GREP_EXCLUDES=(
    ':!skills/review-checklist.md'
    ':!skills/finish-check.md'
)

ALLOWLIST="$REPO_ROOT/skills/scripts/.staleness-allowlist"
EXCLUDE_FILE="$REPO_ROOT/skills/scripts/.staleness-exclude-patterns"

# 加载排除模式（空行/注释跳过；正则匹配命中则跳过）
declare -a EXCLUDE_PATTERNS=()
if [[ -f "$EXCLUDE_FILE" ]]; then
    while IFS= read -r line; do
        line="${line%%#*}"
        line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        [[ -z "$line" ]] && continue
        EXCLUDE_PATTERNS+=("$line")
    done <"$EXCLUDE_FILE"
fi

# 加载豁免列表（空行/注释跳过；标识符与 commit SHA 占位符共用）
declare -a allowlist=()
if [[ -f "$ALLOWLIST" ]]; then
    while IFS= read -r line; do
        line="${line%%#*}"
        # 去首尾空白
        line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        [[ -z "$line" ]] && continue
        allowlist+=("$line")
    done <"$ALLOWLIST"
fi

check_excluded() {
    local item="$1"
    [[ ${#EXCLUDE_PATTERNS[@]} -eq 0 ]] && return 1
    for pattern in "${EXCLUDE_PATTERNS[@]}"; do
        if [[ "$item" =~ $pattern ]]; then
            return 0
        fi
    done
    return 1
}

check_allowed() {
    local item="$1"
    if [[ ${#allowlist[@]} -eq 0 ]]; then
        return 1
    fi
    for allowed in "${allowlist[@]}"; do
        if [[ "$item" == "$allowed" ]]; then
            return 0
        fi
    done
    return 1
}

# 全局计数器
id_total=0
id_stale=0
id_allowed=0
sha_total=0
sha_stale=0
sha_allowed=0
declare -a warnings=()

# ---- 标识符存在性检查 ----
# 提取反引号包裹的项目标识符（聚焦含 :: 或扩展名的形式）
# 模式 1：ClassName::method 形式（含命名空间分隔符也算）
# 模式 2：File.php / File.sh / File.json 形式
check_identifiers() {
    local rel="$1"
    local items item method
    items=$(
        {
            grep -oE '`[A-Za-z][A-Za-z0-9_\\]*(::\$?[A-Za-z_][A-Za-z0-9_]*)+(\(\))?`' "$rel" || true
            grep -oE '`[A-Za-z][A-Za-z0-9_/.\\-]*\.(php|sh|json|ts|vue)`' "$rel" || true
        } | sort -u | sed 's/^`//; s/`$//; s/()$//'
    )

    while IFS= read -r item; do
        [[ -z "$item" ]] && continue
        if check_excluded "$item"; then
            continue
        fi
        id_total=$((id_total + 1))

        if check_allowed "$item"; then
            id_allowed=$((id_allowed + 1))
            continue
        fi

        # 先用完整串 grep，命中即认；不命中再退化
        if git grep -l --fixed-strings "$item" -- "${GREP_EXCLUDES[@]}" >/dev/null 2>&1; then
            continue
        fi

        # 退化 1：取 :: 后的方法名再试一次（容忍方法跨类移动）
        if [[ "$item" == *::* ]]; then
            method="${item##*::}"
            # 方法名太短（< 5 字符）跳过退化以免误判
            if [[ ${#method} -ge 5 ]]; then
                if git grep -l --fixed-strings "$method" -- "${GREP_EXCLUDES[@]}" >/dev/null 2>&1; then
                    continue
                fi
            fi
        fi

        # 退化 2：文件路径类条目按 tracked 文件存在性兜底
        # （文档常写相对 backend/ 的路径，如 tests/Feature/...，用 */ 通配前缀匹配）。
        # finish-check 发生在提交前，必须同时接受工作树里的未跟踪新文件，不能把
        # “尚未 git add”误报成路径腐烂。
        if [[ "$item" == *.* ]]; then
            if [[ -e "$item" ]] ||
                [[ -n "$(git ls-files --others --exclude-standard -- "$item" "*/$item" 2>/dev/null)" ]] ||
                [[ -n "$(git ls-files -- "$item" "*/$item" 2>/dev/null)" ]]; then
                continue
            fi
        fi

        warnings+=("WARNING: $item 在代码库中已找不到，请检查 $rel")
        id_stale=$((id_stale + 1))
    done <<<"$items"
}

# ---- commit SHA 锚点检查 ----
# 仅提取有上下文锚定的 7-8 位 hex（反引号包裹，或紧跟 "commit " 字样），
# 避免裸 [0-9a-f]{7,8} 误抓普通单词/数字
check_shas() {
    local rel="$1"
    local shas sha
    shas=$(
        grep -ohE '(commit[[:space:]]+`?[0-9a-f]{7,8}`?|`[0-9a-f]{7,8}`)' "$rel" 2>/dev/null |
            grep -oE '[0-9a-f]{7,8}' | sort -u || true
    )

    while IFS= read -r sha; do
        [[ -z "$sha" ]] && continue
        sha_total=$((sha_total + 1))

        if check_allowed "$sha"; then
            sha_allowed=$((sha_allowed + 1))
            continue
        fi

        if git cat-file -e "$sha^{commit}" 2>/dev/null; then
            continue
        fi

        warnings+=("WARNING: commit $sha 在 git 历史中已找不到，请检查 $rel")
        sha_stale=$((sha_stale + 1))
    done <<<"$shas"
}

for CHECKLIST in "${CHECKLISTS[@]}"; do
    if [[ ! -f "$CHECKLIST" ]]; then
        warnings+=("WARNING: 守护文件 $CHECKLIST 不存在，已跳过")
        continue
    fi
    check_identifiers "$CHECKLIST"
    check_shas "$CHECKLIST"
done

# ---- 条目数熵增信号 ----
entry_count=$(grep -cE '^## 反模式 [0-9]+' skills/review-checklist.md 2>/dev/null || true)
entry_count=${entry_count:-0}
if [[ "$entry_count" -gt 25 ]]; then
    warnings+=("WARNING: 反模式条目数 $entry_count 已超过 25，建议做一次合并 pass（同类条目并入既有条目作第二例）")
fi

# 输出 warnings
for w in "${warnings[@]:-}"; do
    [[ -n "$w" ]] && echo "$w"
done

total=$((id_total + sha_total))
stale=$((id_stale + sha_stale))
allowed=$((id_allowed + sha_allowed))

echo ""
echo "检查 $total 项 / 失效 $stale 项 / 豁免 $allowed 项（标识符 ${id_total}/${id_stale}/${id_allowed} + commit SHA ${sha_total}/${sha_stale}/${sha_allowed}）"
echo "反模式条目数 $entry_count"
exit 0
