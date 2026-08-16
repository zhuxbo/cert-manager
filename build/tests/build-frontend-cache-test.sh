#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_FRONTEND="$BUILD_DIR/scripts/build-frontend.sh"
TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP"' EXIT

WORKSPACE="$TEST_TMP/workspace"
FAKE_BIN="$TEST_TMP/bin"
PNPM_LOG="$TEST_TMP/pnpm.log"

mkdir -p \
    "$FAKE_BIN" \
    "$WORKSPACE/node_modules" \
    "$WORKSPACE/frontend/shared/node_modules" \
    "$WORKSPACE/frontend/shared/src" \
    "$WORKSPACE/frontend/admin/src"

printf '%s\n' '{"name":"fixture","private":true}' >"$WORKSPACE/package.json"
printf '%s\n' 'packages:' "  - 'frontend/shared'" "  - 'frontend/admin'" >"$WORKSPACE/pnpm-workspace.yaml"
printf '%s\n' "lockfileVersion: '9.0'" >"$WORKSPACE/pnpm-lock.yaml"
printf '%s\n' 'export const shared = true;' >"$WORKSPACE/frontend/shared/src/index.ts"
printf '%s\n' 'export const admin = true;' >"$WORKSPACE/frontend/admin/src/index.ts"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'set -euo pipefail' \
    'printf "%s\n" "$*" >>"$FAKE_PNPM_LOG"' \
    'if [[ " $* " == *" vite build "* ]]; then' \
    '    mkdir -p "$WORKSPACE_DIR/frontend/admin/dist"' \
    '    printf "%s\n" "built" >"$WORKSPACE_DIR/frontend/admin/dist/index.html"' \
    'fi' >"$FAKE_BIN/pnpm"
chmod +x "$FAKE_BIN/pnpm"

run_build() {
    WORKSPACE_DIR="$WORKSPACE" \
        BUILD_ADMIN=true \
        BUILD_USER=false \
        FORCE_BUILD=false \
        FAKE_PNPM_LOG="$PNPM_LOG" \
        PATH="$FAKE_BIN:$PATH" \
        bash "$BUILD_FRONTEND" >/dev/null
}

run_build
grep -Fq 'vite build' "$PNPM_LOG" || {
    echo '首次运行没有执行管理端构建' >&2
    exit 1
}

: >"$PNPM_LOG"
printf '%s\n' "lockfileVersion: '9.0'" 'overrides:' '  dependency: 2.0.0' >"$WORKSPACE/pnpm-lock.yaml"

run_build

if ! grep -Fq 'vite build' "$PNPM_LOG"; then
    echo 'pnpm-lock.yaml 变化后错误复用了旧前端 dist' >&2
    exit 1
fi

echo 'frontend build cache tests passed'
