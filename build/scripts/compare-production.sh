#!/usr/bin/env bash

# Compare local production artifacts with remote production-code repository.
# Output a summary and write a full report to temp/reports.

set -Eeuo pipefail

BLUE='\033[0;34m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'
log() { echo -e "${BLUE}[INFO]${NC} $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
ok() { echo -e "${GREEN}[SUCCESS]${NC} $*"; }
err() { echo -e "${RED}[ERROR]${NC} $*"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="${SCRIPT_DIR}/.."
cd "${BASE_DIR}"

CONFIG_JSON="${BASE_DIR}/config.json"
TEMP_DIR="${BASE_DIR}/temp"
LOCAL_DIR_DEFAULT="${TEMP_DIR}/production-code"
REPORT_DIR="${TEMP_DIR}/reports"
mkdir -p "${REPORT_DIR}"

REMOTE_URL=""
REMOTE_BRANCH=""
LOCAL_DIR="${LOCAL_DIR_DEFAULT}"
SHOW_LINES=200
STRICT=false

usage() {
    cat <<EOF
Usage: scripts/compare-production.sh [options]

Options:
  --remote <url>      Remote repo URL (default: parse from config.json)
  --branch <branch>   Remote branch (default: parse from config.json or main)
  --local <dir>       Local production dir (default: temp/production-code)
  --show <n>          Show first n diff lines (default: 200)
  --strict            Exit non-zero if any diffs found
  -h, --help          Show help
EOF
}

while (($#)); do
    case "$1" in
        --remote)
            REMOTE_URL="${2:-}"
            shift 2
            ;;
        --branch)
            REMOTE_BRANCH="${2:-}"
            shift 2
            ;;
        --local)
            LOCAL_DIR="${2:-}"
            shift 2
            ;;
        --show)
            SHOW_LINES="${2:-200}"
            shift 2
            ;;
        --strict)
            STRICT=true
            shift
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            err "unknown arg: $1"
            usage
            exit 1
            ;;
    esac
done

for cmd in git diff grep sed awk; do
    command -v "$cmd" >/dev/null 2>&1 || {
        err "missing dependency: $cmd"
        exit 1
    }
done

# parse production_repo.url/branch from config.json without jq
parse_from_config() {
    local key="$1"
    [ -f "$CONFIG_JSON" ] || return 1
    # 提取 production_repo 段落（从匹配行开始，到下一个独立的 } 行结束）
    awk 'BEGIN{f=0} /"production_repo"[[:space:]]*:/ {f=1} f==1 {print} f==1 && /^\s*}\s*,?\s*$/ {exit}' "$CONFIG_JSON" |
        tr -d '\r' |
        sed -nE "s/^[[:space:]]*\"$key\"[[:space:]]*:[[:space:]]*\"([^\"]+)\".*/\1/p" |
        head -1
}

if [ -z "$REMOTE_URL" ]; then
    REMOTE_URL="$(parse_from_config url || true)"
fi
if [ -z "$REMOTE_BRANCH" ]; then
    REMOTE_BRANCH="$(parse_from_config branch || true)"
fi
REMOTE_BRANCH="${REMOTE_BRANCH:-main}"

# 兜底：从本地产物仓的 git 配置中读取 origin url
if [ -z "$REMOTE_URL" ] && [ -d "$LOCAL_DIR/.git" ]; then
    REMOTE_URL="$(git -C "$LOCAL_DIR" remote get-url origin 2>/dev/null || echo "")"
fi

if [ -z "$REMOTE_URL" ]; then
    err "无法从 config.json 或本地产物仓读取远端 URL；可使用 --remote 指定"
    exit 1
fi

to_https_url() {
    local url="$1"
    if [[ "$url" =~ ^git@([^:]+):([^/]+)/(.+)\.git$ ]]; then
        echo "https://${BASH_REMATCH[1]}/${BASH_REMATCH[2]}/${BASH_REMATCH[3]}.git"
    else
        echo "$url"
    fi
}

REMOTE_URL_HTTPS="$(to_https_url "$REMOTE_URL")"

if [ ! -d "$LOCAL_DIR" ]; then
    err "local production dir not found: $LOCAL_DIR"
    log "first build it: ./build.sh --test"
    exit 1
fi

TS="$(date +%Y%m%d-%H%M%S)"
WORK_DIR="${TEMP_DIR}/compare-${TS}"
REMOTE_DIR="${WORK_DIR}/remote"
mkdir -p "$WORK_DIR"

log "Cloning remote: $REMOTE_URL_HTTPS branch: $REMOTE_BRANCH"
if git clone --depth=1 --branch "$REMOTE_BRANCH" "$REMOTE_URL_HTTPS" "$REMOTE_DIR" >/dev/null 2>&1; then
    ok "Remote clone OK [HTTPS]"
else
    warn "HTTPS clone failed, try original URL - may require SSH"
    if git clone --depth=1 --branch "$REMOTE_BRANCH" "$REMOTE_URL" "$REMOTE_DIR" >/dev/null 2>&1; then
        ok "Remote clone OK [SSH]"
    else
        err "failed to clone remote"
        exit 1
    fi
fi

cd "$REMOTE_DIR"
REMOTE_HEAD="$(git rev-parse HEAD 2>/dev/null || echo "")"
log "Remote HEAD: ${REMOTE_HEAD:-unknown}"
cd "$BASE_DIR"

REPORT_FILE="${REPORT_DIR}/diff-${TS}.txt"
log "Start diff"
log "Local:  $LOCAL_DIR"
log "Remote: $REMOTE_DIR branch: $REMOTE_BRANCH"
log "Report: $REPORT_FILE"

set +e
diff -qr --exclude .git --exclude .DS_Store "$LOCAL_DIR" "$REMOTE_DIR" >"$REPORT_FILE" 2>&1
set -e

NEW_COUNT=$(grep -F -c "Only in $LOCAL_DIR" "$REPORT_FILE" || true)
GONE_COUNT=$(grep -F -c "Only in $REMOTE_DIR" "$REPORT_FILE" || true)
MOD_COUNT=$(grep -c '^Files ' "$REPORT_FILE" || true)
TOTAL=$((NEW_COUNT + GONE_COUNT + MOD_COUNT))

echo "" >>"$REPORT_FILE"
log "Diff summary: new=$NEW_COUNT, removed=$GONE_COUNT, modified=$MOD_COUNT, total=$TOTAL"

log "First $SHOW_LINES lines - see full report:"
sed -n "1,${SHOW_LINES}p" "$REPORT_FILE" || true

if [ "$STRICT" = true ] && [ "$TOTAL" -gt 0 ]; then
    exit 2
fi

ok "Compare done"
