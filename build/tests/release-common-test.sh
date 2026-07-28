#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$(dirname "$SCRIPT_DIR")"
source "$BUILD_DIR/scripts/release-common.sh"

TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP"' EXIT

RELEASE_DIR="$TEST_TMP/release"
mkdir -p "$RELEASE_DIR/main" "$RELEASE_DIR/dev"

create_release() {
    local version="$1"
    local channel="$2"
    local version_dir="$RELEASE_DIR/$channel/v$version"
    local rel_path="$channel/v$version"

    mkdir -p "$version_dir"
    for package_type in full upgrade script; do
        printf '%s\n' "$channel-$version-$package_type" >"$version_dir/ssl-manager-$package_type-$version.zip"
    done

    generate_releases_update_script \
        "$RELEASE_DIR/releases.json" "$version" "$channel" "$version_dir" "$rel_path" 5 | python3 >/dev/null
}

for number in 0 1 2 3 4 5; do
    create_release "1.0.$number" main
    create_release "1.0.$number-beta" dev
done

python3 - "$RELEASE_DIR/releases.json" <<'PYEOF'
import json
import sys

with open(sys.argv[1], 'r') as fp:
    releases = json.load(fp)['releases']

main = [item for item in releases if item.get('prerelease') is False]
dev = [item for item in releases if item.get('prerelease') is True]
assert len(main) == 5, main
assert len(dev) == 5, dev
assert 'v1.0.0' not in {item['tag_name'] for item in main}
assert 'v1.0.0-beta' not in {item['tag_name'] for item in dev}
assert main[0]['tag_name'] == 'v1.0.5'
assert dev[0]['tag_name'] == 'v1.0.5-beta'
PYEOF

TARGET_VERSION="1.0.5-beta"
TARGET_DIR="$RELEASE_DIR/dev/v$TARGET_VERSION"
LATEST_DIR="$RELEASE_DIR/dev-latest"
mkdir -p "$LATEST_DIR"
for package_type in full upgrade script; do
    ln -s "../dev/v$TARGET_VERSION/ssl-manager-$package_type-$TARGET_VERSION.zip" \
        "$LATEST_DIR/ssl-manager-$package_type-latest.zip"
done
printf '#!/bin/bash\n' >"$RELEASE_DIR/install.sh"
printf '#!/bin/bash\n' >"$RELEASE_DIR/upgrade.sh"
chmod +x "$RELEASE_DIR/install.sh" "$RELEASE_DIR/upgrade.sh"

generate_release_verify_script \
    "$RELEASE_DIR/releases.json" "$TARGET_VERSION" dev "$TARGET_DIR" \
    "dev/v$TARGET_VERSION" "$LATEST_DIR" 5 | python3 >/dev/null

generate_public_release_verify_script \
    "file://$RELEASE_DIR" "$TARGET_VERSION" dev 5 | python3 >/dev/null

printf 'tampered\n' >>"$TARGET_DIR/ssl-manager-full-$TARGET_VERSION.zip"
if generate_release_verify_script \
    "$RELEASE_DIR/releases.json" "$TARGET_VERSION" dev "$TARGET_DIR" \
    "dev/v$TARGET_VERSION" "$LATEST_DIR" 5 | python3 >/dev/null 2>&1; then
    echo "篡改后的发布包未被校验发现" >&2
    exit 1
fi

echo "release-common tests passed"
