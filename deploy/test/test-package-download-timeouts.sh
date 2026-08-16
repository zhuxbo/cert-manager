#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

grep -q "'timeout' => (int) env('PLUGIN_DOWNLOAD_TIMEOUT', 120)" \
    "$ROOT/backend/config/plugin.php" || fail "插件包单次下载默认超时不是 120 秒"

grep -q "'timeout' => (int) env('PLUGIN_OPERATION_TIMEOUT', 720)" \
    "$ROOT/backend/config/plugin.php" || fail "插件任务总超时不足以覆盖两次完整下载尝试"

grep -q "'retry_after' => (int) env('QUEUE_RETRY_AFTER', 900)" \
    "$ROOT/backend/config/queue.php" || fail "队列 retry_after 未覆盖插件任务与安全余量"

grep -q "'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 900)" \
    "$ROOT/backend/config/queue.php" || fail "Beanstalkd retry_after 未覆盖插件任务与安全余量"

grep -q "'download_timeout' => 300" \
    "$ROOT/backend/config/upgrade.php" || fail "后台升级包下载超时不足 300 秒"

grep -A 45 '^download_script_package()' "$ROOT/deploy/install.sh" |
    grep -q -- '--max-time 120' || fail "安装入口脚本包下载超时不足 120 秒"

grep -A 55 '^download_release_file()' "$ROOT/deploy/scripts/common.sh" |
    grep -q -- '--max-time 300' || fail "安装完整包下载超时不足 300 秒"

grep -A 55 '^download_upgrade_package()' "$ROOT/deploy/upgrade.sh" |
    grep -q -- '--max-time 300' || fail "Shell 升级包下载超时不足 300 秒"

echo "PASS: 所有安装与升级包下载路径单次超时均不少于 120 秒"
