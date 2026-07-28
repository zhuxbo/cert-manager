#!/usr/bin/env bash
# 孤儿 compat fixture 检测 — 挂 finish-check §6，与 check-review-checklist-staleness.sh
# / finish-check-greps.sh 同行。硬零断言：发现孤儿即退出 1。
#
# 孤儿 = fixture 文件还在，但它记录的测试已被删除或改名。compare 只查「测试有没有 fixture」，
# 不查反向，所以孤儿既不报错也不被清理 —— 改一次测试名就沉积一个，长期无人察觉。
# （2026-07 一次性清出 11 个，最早可追到 cc7c9d60 改名时漏了重录 fixture。）
#
# 实现在 backend/tests/Compat/detect-orphans.php：纯静态反查（读 fixture 的 test 字段 →
# 反推测试文件 → 用 Pest 的 Str::evaluable 比对该文件所有 test()/it() 名），无副作用，
# 且覆盖 fixtures 全集 —— 不像 capture+mtime 差集那样只能覆盖 capture 的目标目录，
# 也不会带出 BREAKING_CHANGES.md 重复追加与 Metrics 键序抖动这两处需回滚的副作用。
#
# 需要 PHP + vendor（Pest\Support\Str）。默认走仓库 Docker 容器（与 pint/phpstan 同口径）；
# 已在容器内或宿主装了对应 PHP 时可设 ORPHAN_FIXTURE_PHP=php 直接本地跑。
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

SCRIPT_IN_REPO="tests/Compat/detect-orphans.php"

if [[ -n "${ORPHAN_FIXTURE_PHP:-}" ]]; then
    (cd backend && "$ORPHAN_FIXTURE_PHP" "$SCRIPT_IN_REPO")
    exit $?
fi

if docker compose ps --status running --services 2>/dev/null | grep -qx app; then
    docker compose exec -T app php "/var/www/$SCRIPT_IN_REPO"
    exit $?
fi

# 容器没起：回退宿主 php，仍需 backend/vendor 已安装
if command -v php >/dev/null 2>&1 && [[ -f backend/vendor/autoload.php ]]; then
    echo "NOTE: Compose app 未运行，回退宿主 php" >&2
    (cd backend && php "$SCRIPT_IN_REPO")
    exit $?
fi

echo "SKIP: 需要 Compose app 容器运行（make up），或宿主具备 php + backend/vendor" >&2
exit 0
