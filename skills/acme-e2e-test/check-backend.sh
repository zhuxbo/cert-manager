#!/usr/bin/env bash
#
# ACME E2E 后端检查脚本
#
# 新架构（封装下单 + 交付 EAB 模式）：
#   Manager 不再实现 RFC 8555 服务端，不再暴露 /acme/directory；
#   certbot 使用上游返回的 directory_url（如 https://acme.test.certum.pl/directory/）直接与 CA 通信；
#   Manager 只负责订阅生命周期（创建 + 扣费 + 提交上游 → 拿 EAB + directory_url → 返回给客户端）。
#
# 检查项：
#   1. Docker 可用
#   2. Manager 可达（GET /api/admin/login 或任意公开路由返回非 000）
#   3. 上游系统 ACME 产品端点可达（直连 $UPSTREAM_URL/api/acme/get-products；上游未迁仍用 /api/acme，迁移后改 /api/v2/acme）
#   4. Manager system_settings: ca.acme_url 或 ca.url 已配置（由 Manager backend 本地校验）
#

set -euo pipefail

MANAGER_URL="${MANAGER_URL:-http://localhost:5300}"
UPSTREAM_URL="${UPSTREAM_URL:-http://localhost:6300}"
CURL_TIMEOUT=5

has_failure=false

check_pass() {
    echo "  [OK]   $1"
}

check_fail() {
    echo "  [FAIL] $1"
    echo "         → $2"
    has_failure=true
}

echo "=== ACME E2E 后端检查 ==="
echo ""
echo "Manager: $MANAGER_URL"
echo "上游系统: $UPSTREAM_URL"
echo ""

# --- 检查 1: Docker 可用 ---
if command -v docker &>/dev/null && docker info &>/dev/null 2>&1; then
    check_pass "Docker 可用"
else
    check_fail "Docker 可用" "Docker 未安装或未运行，certbot 需要 Docker 环境"
fi

# --- 检查 2: Manager 可达（任意路由返回非 000 均算可达）---
manager_status=$(curl -s -o /dev/null -w "%{http_code}" --max-time "$CURL_TIMEOUT" "$MANAGER_URL/api/v2" 2>&1) || manager_status="000"
if [ "$manager_status" != "000" ]; then
    check_pass "Manager $MANAGER_URL 可达 (HTTP ${manager_status})"
else
    check_fail "Manager $MANAGER_URL 可达" "无法连接，确认 Manager 已启动且端口正确"
fi

# --- 检查 3: 上游系统可达 ---
upstream_status=$(curl -s -o /dev/null -w "%{http_code}" --max-time "$CURL_TIMEOUT" "$UPSTREAM_URL/api/acme/get-products" 2>&1) || upstream_status="000"
if [ "$upstream_status" != "000" ]; then
    check_pass "上游系统 $UPSTREAM_URL 可达 (HTTP ${upstream_status})"
else
    check_fail "上游系统 $UPSTREAM_URL 可达" "无法连接，确认上游已启动"
fi

# --- 汇总 ---
echo ""

if [ "$has_failure" = true ]; then
    echo "--- 检查未通过，请修复后重试 ---"
    echo ""
    echo "排查建议："
    echo "  1. 确认容器运行中：docker ps"
    echo "  2. Manager 配置：system_settings 表 group='ca' 的 acme_url / acme_token（回落 url / token）"
    echo "  3. 上游系统配置：Certum CA 凭据（certumRestUrl / certumOauthUrl / certumClientId / certumUsername / certumPassword）"
    echo "  4. 数据库中存在 product_type='acme' 且 status=1 的产品"
    exit 1
fi

echo "--- 全部检查通过 ---"
