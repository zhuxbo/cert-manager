#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$(dirname "$SCRIPT_DIR")"
ROOT="$(dirname "$BUILD_DIR")"
COLLECT="$BUILD_DIR/scripts/collect-artifacts.sh"
TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP"' EXIT

SOURCE="$TEST_TMP/source"
WORKSPACE="$TEST_TMP/workspace"
PRODUCTION="$TEST_TMP/production"

assert_unsafe_production_rejected() {
    local label="$1"
    local case_root="$2"
    local source_dir="$3"
    local workspace_dir="$4"
    local production_dir="$5"
    local marker="$source_dir/source-marker.txt"
    local output status

    mkdir -p "$source_dir/build" "$workspace_dir"
    cp "$BUILD_DIR/php-requirements.json" "$source_dir/build/php-requirements.json"
    touch "$marker"

    set +e
    output="$(
        CONFIG_FILE="$BUILD_DIR/config.json" \
            SOURCE_DIR="$source_dir" \
            WORKSPACE_DIR="$workspace_dir" \
            PRODUCTION_DIR="$production_dir" \
            BUILD_ASSETS_DIR="$BUILD_DIR" \
            BUILD_BACKEND=false \
            BUILD_ADMIN=false \
            BUILD_USER=false \
            BUILD_NGINX=false \
            BUILD_WEB=false \
            BUILD_VERSION=9.9.9-test \
            RELEASE_CHANNEL=dev \
            "$COLLECT" 2>&1
    )"
    status=$?
    set -e

    if [ "$status" -eq 0 ] || [ ! -f "$marker" ] || ! printf '%s\n' "$output" | grep -Fq "拒绝清理不安全的生产产物目录"; then
        echo "未安全拒绝生产目录路径: $label" >&2
        printf '%s\n' "$output" >&2
        return 1
    fi

    rm -rf "$case_root"
}

ALIAS_ROOT="$TEST_TMP/unsafe-alias"
assert_unsafe_production_rejected \
    "点路径别名" \
    "$ALIAS_ROOT" \
    "$ALIAS_ROOT/source" \
    "$ALIAS_ROOT/workspace" \
    "$ALIAS_ROOT/source/."

SYMLINK_ROOT="$TEST_TMP/unsafe-symlink"
mkdir -p "$SYMLINK_ROOT/source"
ln -s "$SYMLINK_ROOT/source" "$SYMLINK_ROOT/production-link"
assert_unsafe_production_rejected \
    "符号链接别名" \
    "$SYMLINK_ROOT" \
    "$SYMLINK_ROOT/source" \
    "$SYMLINK_ROOT/workspace" \
    "$SYMLINK_ROOT/production-link"

ANCESTOR_ROOT="$TEST_TMP/unsafe-ancestor"
assert_unsafe_production_rejected \
    "源码祖先目录" \
    "$ANCESTOR_ROOT" \
    "$ANCESTOR_ROOT/source" \
    "$ANCESTOR_ROOT/workspace" \
    "$ANCESTOR_ROOT"

OUTPUT_LINK_ROOT="$TEST_TMP/output-symlink"
mkdir -p \
    "$OUTPUT_LINK_ROOT/source/build" \
    "$OUTPUT_LINK_ROOT/workspace" \
    "$OUTPUT_LINK_ROOT/real-production/backend"
cp "$BUILD_DIR/php-requirements.json" "$OUTPUT_LINK_ROOT/source/build/php-requirements.json"
touch "$OUTPUT_LINK_ROOT/real-production/backend/reviewer-old-code.php"
ln -s "$OUTPUT_LINK_ROOT/real-production" "$OUTPUT_LINK_ROOT/production-link"

CONFIG_FILE="$BUILD_DIR/config.json" \
    SOURCE_DIR="$OUTPUT_LINK_ROOT/source" \
    WORKSPACE_DIR="$OUTPUT_LINK_ROOT/workspace" \
    PRODUCTION_DIR="$OUTPUT_LINK_ROOT/production-link" \
    BUILD_ASSETS_DIR="$BUILD_DIR" \
    BUILD_BACKEND=false \
    BUILD_ADMIN=false \
    BUILD_USER=false \
    BUILD_NGINX=false \
    BUILD_WEB=false \
    BUILD_VERSION=9.9.9-test \
    RELEASE_CHANNEL=dev \
    "$COLLECT" >/dev/null

if [ -e "$OUTPUT_LINK_ROOT/real-production/backend/reviewer-old-code.php" ]; then
    echo "合法输出符号链接未清除陈旧代码" >&2
    exit 1
fi
if [ ! -f "$OUTPUT_LINK_ROOT/real-production/version.json" ]; then
    echo "合法输出符号链接未生成新产物" >&2
    exit 1
fi

mkdir -p \
    "$SOURCE/build" \
    "$SOURCE/backend/tests/Fixtures" \
    "$WORKSPACE/backend/bootstrap/cache" \
    "$WORKSPACE/backend/scripts" \
    "$WORKSPACE/backend/storage/app/private" \
    "$WORKSPACE/backend/storage/databak" \
    "$WORKSPACE/backend/storage/framework/views" \
    "$WORKSPACE/backend/storage/pay" \
    "$WORKSPACE/backend/tests" \
    "$PRODUCTION/backend/bootstrap/cache" \
    "$PRODUCTION/backend/storage/databak" \
    "$PRODUCTION/backend/storage/pay"

cp "$BUILD_DIR/php-requirements.json" "$SOURCE/build/php-requirements.json"
cp "$ROOT/backend/tests/Fixtures/public_suffix_list.dat" "$SOURCE/backend/tests/Fixtures/public_suffix_list.dat"

touch \
    "$WORKSPACE/backend/.env" \
    "$WORKSPACE/backend/.env.example" \
    "$WORKSPACE/backend/.ssl-manager" \
    "$WORKSPACE/backend/.upgrade-bootstrap.lock" \
    "$WORKSPACE/backend/.upgrade-bootstrap-prepared.json" \
    "$WORKSPACE/backend/artisan" \
    "$WORKSPACE/backend/bootstrap/cache/services.php" \
    "$WORKSPACE/backend/scripts/test-mutate.sh" \
    "$WORKSPACE/backend/scripts/write-composer-lock-marker.php" \
    "$WORKSPACE/backend/storage/app/private/customer.txt" \
    "$WORKSPACE/backend/storage/databak/backup.sql.gz" \
    "$WORKSPACE/backend/storage/framework/views/compiled.php" \
    "$WORKSPACE/backend/storage/pay/private.pem" \
    "$WORKSPACE/backend/tests/ExampleTest.php" \
    "$PRODUCTION/backend/bootstrap/cache/stale.php" \
    "$PRODUCTION/backend/storage/databak/stale.sql.gz" \
    "$PRODUCTION/backend/storage/pay/stale.pem" \
    "$PRODUCTION/reviewer-stale-root.txt"

mkdir -p "$PRODUCTION/frontend/admin" "$PRODUCTION/frontend/user"
touch "$PRODUCTION/frontend/admin/stale-index.html" "$PRODUCTION/frontend/user/stale-index.html"

set +e
MISSING_FRONTEND_OUTPUT="$(
    CONFIG_FILE="$BUILD_DIR/config.json" \
        SOURCE_DIR="$SOURCE" \
        WORKSPACE_DIR="$WORKSPACE" \
        PRODUCTION_DIR="$PRODUCTION" \
        BUILD_ASSETS_DIR="$BUILD_DIR" \
        BUILD_BACKEND=false \
        BUILD_ADMIN=true \
        BUILD_USER=true \
        BUILD_NGINX=false \
        BUILD_WEB=false \
        BUILD_VERSION=9.9.9-test \
        RELEASE_CHANNEL=dev \
        "$COLLECT" 2>&1
)"
MISSING_FRONTEND_STATUS=$?
set -e

if [ "$MISSING_FRONTEND_STATUS" -eq 0 ]; then
    echo "请求构建前端但 dist 缺失时不应返回成功" >&2
    printf '%s\n' "$MISSING_FRONTEND_OUTPUT" >&2
    exit 1
fi

if ! printf '%s\n' "$MISSING_FRONTEND_OUTPUT" | grep -Fq "管理端 dist 目录不存在"; then
    echo "前端 dist 缺失时未返回明确错误" >&2
    printf '%s\n' "$MISSING_FRONTEND_OUTPUT" >&2
    exit 1
fi

CONFIG_FILE="$BUILD_DIR/config.json" \
    SOURCE_DIR="$SOURCE" \
    WORKSPACE_DIR="$WORKSPACE" \
    PRODUCTION_DIR="$PRODUCTION" \
    BUILD_ASSETS_DIR="$BUILD_DIR" \
    BUILD_BACKEND=true \
    BUILD_ADMIN=false \
    BUILD_USER=false \
    BUILD_NGINX=false \
    BUILD_WEB=false \
    BUILD_VERSION=9.9.9-test \
    RELEASE_CHANNEL=dev \
    "$COLLECT" >/dev/null

cmp "$SOURCE/backend/tests/Fixtures/public_suffix_list.dat" \
    "$PRODUCTION/backend/storage/domain-rules/public_suffix_list.dat"

for forbidden in \
    backend/.env \
    backend/.upgrade-bootstrap.lock \
    backend/.upgrade-bootstrap-prepared.json \
    backend/bootstrap/cache/services.php \
    backend/bootstrap/cache/stale.php \
    backend/scripts/test-mutate.sh \
    backend/storage/app/private/customer.txt \
    backend/storage/databak/backup.sql.gz \
    backend/storage/databak/stale.sql.gz \
    backend/storage/framework/views/compiled.php \
    backend/storage/pay/private.pem \
    backend/storage/pay/stale.pem \
    backend/tests/ExampleTest.php \
    reviewer-stale-root.txt; do
    if [ -e "$PRODUCTION/$forbidden" ]; then
        echo "产物仍包含禁止文件: $forbidden" >&2
        exit 1
    fi
done

for required in \
    backend/.env.example \
    backend/.ssl-manager \
    backend/artisan \
    backend/scripts/write-composer-lock-marker.php \
    backend/storage/app/public \
    backend/storage/app/private \
    backend/storage/framework/cache \
    backend/storage/framework/sessions \
    backend/storage/framework/views \
    backend/storage/logs \
    backend/storage/pay \
    php-requirements.json \
    version.json; do
    if [ ! -e "$PRODUCTION/$required" ]; then
        echo "产物缺少必需路径: $required" >&2
        exit 1
    fi
done

echo "collect-artifacts tests passed"
