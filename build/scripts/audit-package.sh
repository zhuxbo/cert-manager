#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -ne 3 ]; then
    echo "用法: $0 <full.zip> <upgrade.zip> <script.zip>" >&2
    exit 2
fi

FULL_PACKAGE="$1"
UPGRADE_PACKAGE="$2"
SCRIPT_PACKAGE="$3"

for command_name in unzip grep; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "缺少发布包审计依赖: $command_name" >&2
        exit 2
    fi
done

archive_stream_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum | awk '{print $1}'
    else
        shasum -a 256 | awk '{print $1}'
    fi
}

verify_vendor_marker() {
    local archive="$1" prefix="$2" expected actual
    expected="$(unzip -p "$archive" "$prefix/backend/composer.lock" | archive_stream_sha256)"
    actual="$(unzip -p "$archive" "$prefix/backend/vendor/composer/.ssl-manager-lock.sha256" | tr -d '[:space:]' | tr 'A-F' 'a-f')"
    if [ "$expected" != "$actual" ]; then
        audit_error "$(basename "$archive") 的 vendor 标记与 composer.lock 不匹配"
        return 1
    fi
}

AUDIT_TMP="$(mktemp -d)"
trap 'rm -rf "$AUDIT_TMP"' EXIT

audit_error() {
    echo "[PACKAGE_AUDIT_ERROR] $*" >&2
}

write_listing() {
    local archive="$1"
    local listing="$2"

    if [ ! -f "$archive" ]; then
        audit_error "发布包不存在: $archive"
        return 1
    fi
    if ! unzip -tq "$archive" >/dev/null; then
        audit_error "ZIP 完整性校验失败: $archive"
        return 1
    fi
    unzip -Z -1 "$archive" >"$listing"
}

require_entry() {
    local archive="$1"
    local listing="$2"
    local entry="$3"

    if ! grep -Fqx "$entry" "$listing"; then
        audit_error "$(basename "$archive") 缺少必需文件: $entry"
        return 1
    fi
}

reject_matches() {
    local archive="$1"
    local listing="$2"
    local description="$3"
    local pattern="$4"
    local hits

    hits="$(grep -E "$pattern" "$listing" || true)"
    if [ -n "$hits" ]; then
        audit_error "$(basename "$archive") 包含${description}:"
        printf '%s\n' "$hits" >&2
        return 1
    fi
}

reject_file_matches() {
    local archive="$1"
    local listing="$2"
    local description="$3"
    local pattern="$4"
    local hits

    hits="$(grep -Ev '/$' "$listing" | grep -E "$pattern" || true)"
    if [ -n "$hits" ]; then
        audit_error "$(basename "$archive") 包含${description}:"
        printf '%s\n' "$hits" >&2
        return 1
    fi
}

COMMON_FORBIDDEN='(^|/)(tests?|testing|specs?|fixtures?|mocks?|__tests__|coverage|node_modules)(/|$)|/backend/(scripts|\.github|\.gitlab|\.circleci|\.idea|\.vscode|\.cursor|\.superpowers)(/|$)|/backend/(README[^/]*|LICENSE[^/]*|INSTALL\.md|JRE_INSTALL\.md)$|/(\.gitignore|\.gitattributes|phpunit\.xml|phpstan([^/]*)?\.neon|\.pint\.json|\.editorconfig|\.phpunit\.result\.cache|_ide_helper\.php|_ide_helper_models\.php|\.phpstorm\.meta\.php)$|\.(map|log|bak|tmp|swp|orig)$'

FULL_LIST="$AUDIT_TMP/full.list"
UPGRADE_LIST="$AUDIT_TMP/upgrade.list"
SCRIPT_LIST="$AUDIT_TMP/script.list"

write_listing "$FULL_PACKAGE" "$FULL_LIST"
write_listing "$UPGRADE_PACKAGE" "$UPGRADE_LIST"
write_listing "$SCRIPT_PACKAGE" "$SCRIPT_LIST"

reject_matches "$FULL_PACKAGE" "$FULL_LIST" "测试或开发文件" "$COMMON_FORBIDDEN"
reject_matches "$UPGRADE_PACKAGE" "$UPGRADE_LIST" "测试或开发文件" "$COMMON_FORBIDDEN"
reject_matches "$SCRIPT_PACKAGE" "$SCRIPT_LIST" "测试或开发文件" "$COMMON_FORBIDDEN"

# 完整包会复制整个 production-code，根目录必须使用白名单，阻断未知陈旧文件。
FULL_UNEXPECTED="$(grep -Ev '/$' "$FULL_LIST" | grep -Ev '^full/(backend|frontend|nginx|scripts)/|^full/(version\.json|manifest\.json|php-requirements\.json)$' || true)"
if [ -n "$FULL_UNEXPECTED" ]; then
    audit_error "$(basename "$FULL_PACKAGE") 包含根目录白名单外文件:"
    printf '%s\n' "$FULL_UNEXPECTED" >&2
    exit 1
fi

# full 包仅允许示例环境文件；其他 .env 变体都可能覆盖或泄漏配置。
FULL_ENV_HITS="$(grep -E '/backend/\.env($|\.)' "$FULL_LIST" | grep -Fvx 'full/backend/.env.example' || true)"
if [ -n "$FULL_ENV_HITS" ]; then
    audit_error "$(basename "$FULL_PACKAGE") 包含非示例环境文件:"
    printf '%s\n' "$FULL_ENV_HITS" >&2
    exit 1
fi
reject_matches "$UPGRADE_PACKAGE" "$UPGRADE_LIST" "环境配置文件" '/backend/\.env($|\.)'

# 运行数据、凭据、备份与缓存目录可以保留空目录，但绝不能携带文件。
FULL_RUNTIME_PATTERN='/backend/storage/(app|backups|databak|debugbar|framework/(cache|sessions|testing|views)|logs|pail|pay|temp-certs|upgrades)/|/backend/storage/[^/]+\.(crt|der|jks|key|pem|pfx)$|/backend/bootstrap/cache/|/backups/'
reject_file_matches "$FULL_PACKAGE" "$FULL_LIST" "运行数据、凭据、备份或缓存文件" "$FULL_RUNTIME_PATTERN"
reject_file_matches "$UPGRADE_PACKAGE" "$UPGRADE_LIST" "storage 或 bootstrap/cache 文件" '/backend/(storage|bootstrap/cache)/'
reject_matches "$UPGRADE_PACKAGE" "$UPGRADE_LIST" "storage 目录项" '/backend/storage(/|$)'

reject_matches "$UPGRADE_PACKAGE" "$UPGRADE_LIST" "仅安装期文件" '/backend/public/install\.php$|/backend/public/install-assets/|/frontend/user/(logo\.svg|qrcode\.png)$'

for required in \
    full/backend/.ssl-manager \
    full/backend/.env.example \
    full/backend/artisan \
    full/backend/bootstrap/cache/ \
    full/backend/composer.json \
    full/backend/composer.lock \
    full/backend/vendor/autoload.php \
    full/backend/vendor/composer/.ssl-manager-lock.sha256 \
    full/backend/storage/ \
    full/backend/storage/app/private/ \
    full/backend/storage/app/public/ \
    full/backend/storage/framework/ \
    full/backend/storage/framework/cache/data/ \
    full/backend/storage/framework/sessions/ \
    full/backend/storage/framework/views/ \
    full/backend/storage/logs/ \
    full/backend/storage/domain-rules/public_suffix_list.dat \
    full/backups/upgrades/ \
    full/frontend/admin/index.html \
    full/frontend/user/index.html \
    full/frontend/user/login.svg \
    full/frontend/user/qrcode.png \
    full/nginx/manager.conf \
    full/version.json \
    full/manifest.json \
    full/php-requirements.json \
    full/scripts/bt-automate.sh \
    full/scripts/bt-deps.sh \
    full/scripts/common.sh; do
    require_entry "$FULL_PACKAGE" "$FULL_LIST" "$required"
done
verify_vendor_marker "$FULL_PACKAGE" full

for required in \
    upgrade/backend/.ssl-manager \
    upgrade/backend/artisan \
    upgrade/backend/bootstrap/cache/ \
    upgrade/backend/composer.json \
    upgrade/backend/composer.lock \
    upgrade/backend/vendor/autoload.php \
    upgrade/backend/vendor/composer/.ssl-manager-lock.sha256 \
    upgrade/frontend/admin/index.html \
    upgrade/frontend/user/index.html \
    upgrade/frontend/user/login.svg \
    upgrade/nginx/manager.conf \
    upgrade/version.json \
    upgrade/manifest.json \
    upgrade/php-requirements.json \
    upgrade/UPGRADE.md \
    upgrade/scripts/bt-automate.sh \
    upgrade/scripts/bt-deps.sh \
    upgrade/scripts/common.sh; do
    require_entry "$UPGRADE_PACKAGE" "$UPGRADE_LIST" "$required"
done
verify_vendor_marker "$UPGRADE_PACKAGE" upgrade

for required in \
    script-deploy/install.sh \
    script-deploy/upgrade.sh \
    script-deploy/php-requirements.json \
    script-deploy/scripts/bt-automate.sh \
    script-deploy/scripts/bt-deps.sh \
    script-deploy/scripts/common.sh; do
    require_entry "$SCRIPT_PACKAGE" "$SCRIPT_LIST" "$required"
done

SCRIPT_UNEXPECTED="$(grep -Ev '/$' "$SCRIPT_LIST" | grep -Ev '^script-deploy/(install\.sh|upgrade\.sh|php-requirements\.json|scripts/[^/]+\.sh)$' || true)"
if [ -n "$SCRIPT_UNEXPECTED" ]; then
    audit_error "$(basename "$SCRIPT_PACKAGE") 包含白名单外文件:"
    printf '%s\n' "$SCRIPT_UNEXPECTED" >&2
    exit 1
fi

echo "发布包内容审计通过"
