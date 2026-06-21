#!/bin/bash
# 断言三条部署路径(bt-install / upgrade.sh / PackageExtractor)对称接入 render + 清 default
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$SCRIPT_DIR/../.." && pwd)"
FAIL=0
chk() { grep -qE "$2" "$1" && echo "  ✓ $3" || {
    echo "  ✗ $3 ($1)"
    FAIL=1
}; }

BT="$REPO/deploy/scripts/bt-install.sh"
UP="$REPO/deploy/upgrade.sh"
PE="$REPO/backend/app/Services/Upgrade/PackageExtractor.php"

chk "$BT" 'render\.sh' "bt-install 调 render.sh"
chk "$UP" 'render\.sh' "upgrade.sh 调 render.sh"
chk "$PE" 'render\.sh' "PackageExtractor 调 render.sh"
chk "$BT" 'rm -rf .*nginx/default' "bt-install 清 default"
chk "$UP" 'rm -rf .*nginx/default' "upgrade.sh 清 default"
chk "$PE" 'deleteDirectory.*default' "PackageExtractor 清 default"

[ "$FAIL" -eq 0 ] && echo "对称 ✓" || {
    echo "对称断言失败"
    exit 1
}
