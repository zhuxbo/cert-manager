#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$(dirname "$SCRIPT_DIR")"
AUDITOR="$BUILD_DIR/scripts/audit-package.sh"
TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP"' EXIT

STAGE="$TEST_TMP/stage"
FULL_ZIP="$TEST_TMP/full.zip"
UPGRADE_ZIP="$TEST_TMP/upgrade.zip"
SCRIPT_ZIP="$TEST_TMP/script.zip"

make_valid_packages() {
    rm -rf "$STAGE" "$FULL_ZIP" "$UPGRADE_ZIP" "$SCRIPT_ZIP"
    mkdir -p \
        "$STAGE/full/backend" \
        "$STAGE/full/backend/storage/domain-rules" \
        "$STAGE/full/frontend/admin" \
        "$STAGE/full/frontend/user" \
        "$STAGE/full/nginx" \
        "$STAGE/full/scripts" \
        "$STAGE/upgrade/backend" \
        "$STAGE/upgrade/frontend/admin" \
        "$STAGE/upgrade/frontend/user" \
        "$STAGE/upgrade/nginx" \
        "$STAGE/upgrade/scripts" \
        "$STAGE/script-deploy/scripts"

    touch \
        "$STAGE/full/backend/.ssl-manager" \
        "$STAGE/full/backend/.env.example" \
        "$STAGE/full/backend/artisan" \
        "$STAGE/full/backend/composer.json" \
        "$STAGE/full/backend/composer.lock" \
        "$STAGE/full/backend/storage/domain-rules/public_suffix_list.dat" \
        "$STAGE/full/frontend/admin/index.html" \
        "$STAGE/full/frontend/user/index.html" \
        "$STAGE/full/frontend/user/login.svg" \
        "$STAGE/full/frontend/user/qrcode.png" \
        "$STAGE/full/nginx/manager.conf" \
        "$STAGE/full/version.json" \
        "$STAGE/full/manifest.json" \
        "$STAGE/full/php-requirements.json" \
        "$STAGE/full/scripts/bt-automate.sh" \
        "$STAGE/full/scripts/bt-deps.sh" \
        "$STAGE/full/scripts/common.sh" \
        "$STAGE/upgrade/backend/.ssl-manager" \
        "$STAGE/upgrade/backend/artisan" \
        "$STAGE/upgrade/backend/composer.json" \
        "$STAGE/upgrade/backend/composer.lock" \
        "$STAGE/upgrade/frontend/admin/index.html" \
        "$STAGE/upgrade/frontend/user/index.html" \
        "$STAGE/upgrade/frontend/user/login.svg" \
        "$STAGE/upgrade/nginx/manager.conf" \
        "$STAGE/upgrade/version.json" \
        "$STAGE/upgrade/manifest.json" \
        "$STAGE/upgrade/php-requirements.json" \
        "$STAGE/upgrade/UPGRADE.md" \
        "$STAGE/upgrade/scripts/bt-automate.sh" \
        "$STAGE/upgrade/scripts/bt-deps.sh" \
        "$STAGE/upgrade/scripts/common.sh" \
        "$STAGE/script-deploy/install.sh" \
        "$STAGE/script-deploy/upgrade.sh" \
        "$STAGE/script-deploy/php-requirements.json" \
        "$STAGE/script-deploy/scripts/bt-automate.sh" \
        "$STAGE/script-deploy/scripts/bt-deps.sh" \
        "$STAGE/script-deploy/scripts/common.sh"

    (cd "$STAGE" && zip -rq "$FULL_ZIP" full && zip -rq "$UPGRADE_ZIP" upgrade && zip -rq "$SCRIPT_ZIP" script-deploy)
}

expect_rejected() {
    local label="$1"
    if "$AUDITOR" "$FULL_ZIP" "$UPGRADE_ZIP" "$SCRIPT_ZIP" >/dev/null 2>&1; then
        echo "审计未拒绝: $label" >&2
        exit 1
    fi
}

make_valid_packages
"$AUDITOR" "$FULL_ZIP" "$UPGRADE_ZIP" "$SCRIPT_ZIP" >/dev/null

mkdir -p "$STAGE/full/backend/storage/databak"
touch "$STAGE/full/backend/storage/databak/backup.sql.gz"
(cd "$STAGE" && zip -q "$FULL_ZIP" full/backend/storage/databak/backup.sql.gz)
expect_rejected "完整包数据库备份"

make_valid_packages
mkdir -p "$STAGE/full/backend/storage/framework/views"
touch "$STAGE/full/backend/storage/framework/views/compiled.php"
(cd "$STAGE" && zip -q "$FULL_ZIP" full/backend/storage/framework/views/compiled.php)
expect_rejected "完整包编译视图"

make_valid_packages
mkdir -p "$STAGE/full/backend/storage/pay"
touch "$STAGE/full/backend/storage/pay/private.pem"
(cd "$STAGE" && zip -q "$FULL_ZIP" full/backend/storage/pay/private.pem)
expect_rejected "完整包支付凭据"

make_valid_packages
touch "$STAGE/full/backend/.env.production"
(cd "$STAGE" && zip -q "$FULL_ZIP" full/backend/.env.production)
expect_rejected "完整包环境配置"

make_valid_packages
touch "$STAGE/full/reviewer-stale-root.txt"
(cd "$STAGE" && zip -q "$FULL_ZIP" full/reviewer-stale-root.txt)
expect_rejected "完整包根目录未知文件"

make_valid_packages
mkdir -p "$STAGE/upgrade/backend/scripts"
touch "$STAGE/upgrade/backend/scripts/test-mutate.sh"
(cd "$STAGE" && zip -q "$UPGRADE_ZIP" upgrade/backend/scripts/test-mutate.sh)
expect_rejected "升级包测试脚本"

make_valid_packages
mkdir -p "$STAGE/upgrade/backend/tests"
touch "$STAGE/upgrade/backend/tests/ExampleTest.php"
(cd "$STAGE" && zip -q "$UPGRADE_ZIP" upgrade/backend/tests/ExampleTest.php)
expect_rejected "升级包测试目录"

make_valid_packages
touch "$STAGE/script-deploy/README.md"
(cd "$STAGE" && zip -q "$SCRIPT_ZIP" script-deploy/README.md)
expect_rejected "脚本包白名单外文档"

echo "package audit tests passed"
