#!/usr/bin/env bash
# 案例腐烂检测 — 验证 skills/review-checklist.md 反模式案例引用的类/方法/文件仍存在
# 失败不阻塞（warning 性质），结果贴入 finish-check 总结的"已知局限性"段
set -euo pipefail

# 非 git 仓库或 git 不可用 → 静默退出 0（不阻塞 finish-check 流程）
if ! git rev-parse --show-toplevel >/dev/null 2>&1; then
    echo "skip: 非 git 仓库或 git 不可用"
    exit 0
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

CHECKLIST="$REPO_ROOT/skills/review-checklist.md"
ALLOWLIST="$REPO_ROOT/skills/scripts/.staleness-allowlist"
EXCLUDE_FILE="$REPO_ROOT/skills/scripts/.staleness-exclude-patterns"

if [[ ! -f "$CHECKLIST" ]]; then
    echo "错误：$CHECKLIST 不存在"
    exit 0
fi

# 加载排除模式（空行/注释跳过；前缀匹配 ^pattern 命中则跳过）
declare -a EXCLUDE_PATTERNS=()
if [[ -f "$EXCLUDE_FILE" ]]; then
    while IFS= read -r line; do
        line="${line%%#*}"
        line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        [[ -z "$line" ]] && continue
        EXCLUDE_PATTERNS+=("$line")
    done <"$EXCLUDE_FILE"
fi

# 加载豁免列表（空行/注释跳过）
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

# 提取反引号包裹的项目标识符（聚焦含 :: 或扩展名的形式）
# 模式 1：ClassName::method 形式（含命名空间分隔符也算）
# 模式 2：File.php / File.sh / File.json 形式
items=$(
    {
        grep -oE '`[A-Za-z][A-Za-z0-9_\\]*(::\$?[A-Za-z_][A-Za-z0-9_]*)+(\(\))?`' "$CHECKLIST" || true
        grep -oE '`[A-Za-z][A-Za-z0-9_/.\\-]*\.(php|sh|json|ts|vue)`' "$CHECKLIST" || true
    } | sort -u | sed 's/^`//; s/`$//; s/()$//'
)

total=0
stale=0
allowed=0
declare -a warnings=()

while IFS= read -r item; do
    [[ -z "$item" ]] && continue
    if check_excluded "$item"; then
        continue
    fi
    total=$((total + 1))

    if check_allowed "$item"; then
        allowed=$((allowed + 1))
        continue
    fi

    # 用类/方法名拆出真正能 grep 的关键词：含 :: 时取最后一段（方法名/类名都能命中）
    # 但保留完整串先 grep，命中即认；不命中再退化
    if git grep -l --fixed-strings "$item" -- ':!skills/review-checklist.md' >/dev/null 2>&1; then
        continue
    fi

    # 退化：取 :: 后的方法名再试一次（容忍方法跨类移动）
    if [[ "$item" == *::* ]]; then
        method="${item##*::}"
        # 方法名太短（< 5 字符）跳过退化以免误判
        if [[ ${#method} -ge 5 ]]; then
            if git grep -l --fixed-strings "$method" -- ':!skills/review-checklist.md' >/dev/null 2>&1; then
                continue
            fi
        fi
    fi

    warnings+=("WARNING: $item 在代码库中已找不到，请检查 skills/review-checklist.md")
    stale=$((stale + 1))
done <<<"$items"

# 输出 warnings
for w in "${warnings[@]:-}"; do
    [[ -n "$w" ]] && echo "$w"
done

echo ""
echo "检查 $total 项 / 失效 $stale 项 / 豁免 $allowed 项"
exit 0
