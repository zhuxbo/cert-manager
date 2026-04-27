#!/usr/bin/env bash
#
# ACME E2E 完整流程测试（封装下单 + 交付 EAB 模式）
#
# 流程：
#   1. 环境检查
#   2. 通过 Manager Deploy API 一步到位创建订阅（new + pay + commit）→ 拿到 {order_id, eab_kid, eab_hmac, directory_url}
#   3. certbot register → 使用 directory_url 直接向 CA 注册 ACME 账号（Manager 不经手此步）
#   4. certbot certonly → 同样直连 CA，用户自行完成 DNS-01 验证
#   5. certbot certificates → 验证签发结果
#   6. certbot revoke → 吊销证书（直连 CA）
#   7. 打印订阅取消指引（通过 Manager User/Admin API 取消）
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VOLUME_ETC="certbot-e2e-etc"
VOLUME_VAR="certbot-e2e-var"
DEFAULT_MANAGER="http://localhost:5300"
DEFAULT_EMAIL="test@example.com"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

step_num=0
step() {
    ((step_num++))
    echo ""
    echo -e "${CYAN}=== 步骤 $step_num: $1 ===${NC}"
    echo ""
}

ok() {
    echo -e "${GREEN}✓ $1${NC}"
}

warn() {
    echo -e "${YELLOW}! $1${NC}"
}

fail() {
    echo -e "${RED}✗ $1${NC}"
    exit 1
}

sql_hint() {
    echo ""
    echo -e "${YELLOW}--- 数据库验证 SQL ---${NC}"
    echo "$1"
    echo -e "${YELLOW}---------------------${NC}"
}

# --- 参数解析 ---
deploy_token=""
product_id=""
period="12"
plus="1"
domain=""
email="$DEFAULT_EMAIL"
manager_url="$DEFAULT_MANAGER"
do_clean=false

usage() {
    cat <<EOF
用法: $0 [选项]

必填参数:
  --deploy-token <token>  Manager Deploy Token（用于调 /api/deploy/acme/*）
  --product-id <id>       ACME 产品 ID（product_type=acme，status=1）
  --domain <domain>       测试域名（可含通配符，如 *.test.example.com）

可选参数:
  --period <months>       订阅时长（月），默认 12
  --plus <0|1>            赠送时间，默认 1
  --email <email>         certbot 注册邮箱，默认 $DEFAULT_EMAIL
  --manager <url>         Manager URL，默认 $DEFAULT_MANAGER
  --clean                 清理 certbot volumes 后退出

示例:
  $0 --deploy-token "dpl_xxx" --product-id 57 --domain "test.example.com"
  $0 --clean
EOF
    exit 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --deploy-token) deploy_token="$2"; shift 2 ;;
        --product-id)   product_id="$2"; shift 2 ;;
        --period)       period="$2"; shift 2 ;;
        --plus)         plus="$2"; shift 2 ;;
        --domain)       domain="$2"; shift 2 ;;
        --email)        email="$2"; shift 2 ;;
        --manager)      manager_url="$2"; shift 2 ;;
        --clean)        do_clean=true; shift ;;
        -h|--help)      usage ;;
        *)              echo "未知参数: $1"; usage ;;
    esac
done

# --- 清理模式 ---
if [ "$do_clean" = true ]; then
    echo "=== 清理 certbot E2E 数据 ==="
    docker volume rm "$VOLUME_ETC" "$VOLUME_VAR" 2>/dev/null && echo "已删除 volumes" || echo "volumes 不存在，无需清理"
    exit 0
fi

# --- 参数校验 ---
if [ -z "$deploy_token" ] || [ -z "$product_id" ] || [ -z "$domain" ]; then
    echo "错误: --deploy-token / --product-id / --domain 为必填参数"
    echo ""
    usage
fi

echo "========================================"
echo "  ACME E2E 完整流程测试"
echo "========================================"
echo ""
echo "  Manager:    $manager_url"
echo "  Product ID: $product_id"
echo "  Domain:     $domain"
echo "  Email:      $email"
echo "  Period:     $period 月"
echo "  Plus:       $plus"

# ============================================================
# 步骤 1: 环境检查
# ============================================================
step "环境检查"
MANAGER_URL="$manager_url" bash "$SCRIPT_DIR/check-backend.sh"
ok "环境检查通过"

# ============================================================
# 步骤 2: 创建订阅 — 通过 Manager Deploy API 拿 EAB + directory_url
# ============================================================
step "创建订阅（Manager Deploy API）"

subscription_response=$(curl -sS --max-time 60 -X POST \
    -H "Authorization: Bearer $deploy_token" \
    -H "Content-Type: application/json" \
    -d "{\"product_id\":$product_id,\"period\":$period,\"plus\":$plus}" \
    "$manager_url/api/deploy/acme/new") || fail "创建订阅失败"

echo "$subscription_response" | jq . 2>/dev/null || echo "$subscription_response"

code=$(echo "$subscription_response" | jq -r '.code' 2>/dev/null || echo "")
if [ "$code" != "1" ]; then
    fail "订阅创建失败（code=$code），检查 Deploy Token、product_id、余额"
fi

order_id=$(echo "$subscription_response" | jq -r '.data.order_id')
eab_kid=$(echo "$subscription_response" | jq -r '.data.eab_kid')
eab_hmac=$(echo "$subscription_response" | jq -r '.data.eab_hmac')
directory_url=$(echo "$subscription_response" | jq -r '.data.directory_url')

[ -z "$eab_kid" ] || [ "$eab_kid" = "null" ] && fail "响应缺少 eab_kid"
[ -z "$eab_hmac" ] || [ "$eab_hmac" = "null" ] && fail "响应缺少 eab_hmac"
[ -z "$directory_url" ] || [ "$directory_url" = "null" ] && fail "响应缺少 directory_url（上游可能未返回，检查上游 /acme/new 实现）"

ok "订阅创建成功"
echo "  order_id:      $order_id"
echo "  directory_url: $directory_url"
echo "  eab_kid:       ${eab_kid:0:20}..."

sql_hint "-- Manager: 检查订阅记录
SELECT id, user_id, brand, period, plus, api_id, eab_kid, status, amount, created_at
FROM acmes WHERE id = $order_id;

-- Manager: 检查扣费交易
SELECT id, order_id, type, amount, balance_before, balance_after, created_at
FROM transactions WHERE order_id = $order_id AND type = 'acme_order';"

# ============================================================
# 步骤 3: certbot 注册（直连 CA，使用上游返回的 directory_url）
# ============================================================
step "certbot 注册账户"

docker run --rm \
    -v "$VOLUME_ETC":/etc/letsencrypt \
    -v "$VOLUME_VAR":/var/lib/letsencrypt \
    certbot/certbot register \
    --server "$directory_url" \
    --eab-kid "$eab_kid" \
    --eab-hmac-key "$eab_hmac" \
    --email "$email" \
    --no-eff-email \
    --agree-tos

ok "certbot 账户注册成功"

# ============================================================
# 步骤 4: certbot 申请证书（DNS-01 手动验证，用户自行配置 DNS）
# ============================================================
step "certbot 申请证书"

echo "域名: $domain"
echo "验证方式: DNS-01 手动（certbot 会提示添加 TXT 记录，请自行配置后回车）"
echo ""

docker run --rm -it \
    -v "$VOLUME_ETC":/etc/letsencrypt \
    -v "$VOLUME_VAR":/var/lib/letsencrypt \
    certbot/certbot certonly \
    --server "$directory_url" \
    --manual --preferred-challenges dns \
    --key-type rsa \
    --rsa-key-size 2048 \
    -d "$domain"

ok "证书申请成功"

# ============================================================
# 步骤 5: 验证签发
# ============================================================
step "验证证书签发"

docker run --rm \
    -v "$VOLUME_ETC":/etc/letsencrypt \
    certbot/certbot certificates

ok "证书列表已打印"

# ============================================================
# 步骤 6: 吊销证书
# ============================================================
step "吊销证书"

cert_name="${domain#\*.}"

docker run --rm \
    -v "$VOLUME_ETC":/etc/letsencrypt \
    -v "$VOLUME_VAR":/var/lib/letsencrypt \
    certbot/certbot revoke \
    --server "$directory_url" \
    --cert-path "/etc/letsencrypt/live/$cert_name/cert.pem" \
    --non-interactive

ok "证书已吊销（注意：吊销的是证书本身，订阅仍然有效）"

# ============================================================
# 步骤 7: 取消订阅指引（手动）
# ============================================================
step "取消订阅指引（手动）"

echo "证书吊销完成。订阅取消通过 Manager User/Admin API 进行："
echo ""
echo -e "${YELLOW}通过 User API 取消（推荐：订阅归属用户）：${NC}"
echo "  POST $manager_url/api/user/acme/commit-cancel/$order_id"
echo "  Authorization: Bearer <user-jwt-token>"
echo ""
echo -e "${YELLOW}通过 Admin API 取消：${NC}"
echo "  POST $manager_url/api/admin/acme/commit-cancel/$order_id"
echo "  Authorization: Bearer <admin-jwt-token>"
echo ""
echo "预期行为："
echo "  - 订阅状态 → cancelling"
echo "  - 创建 Task (action=cancel_acme, 延迟 120s)"
echo "  - TaskJob 延时 123s 后调用 Api->cancel() 通知上游取消"
echo "  - 上游成功 → 状态 cancelled/revoked，退费（transactions type=acme_cancel）"
echo "  - 上游失败 → 保持 cancelling，等待重试"

sql_hint "-- Manager: 检查订阅状态
SELECT id, status, cancelled_at FROM acmes WHERE id = $order_id;

-- Manager: 检查延迟任务
SELECT id, action, order_id, scheduled_at, processed_at, created_at
FROM tasks WHERE order_id = $order_id AND action = 'cancel_acme'
ORDER BY id DESC LIMIT 3;

-- Manager: 检查退费（type=acme_cancel）
SELECT id, order_id, type, amount, balance_before, balance_after, created_at
FROM transactions WHERE order_id = $order_id AND type = 'acme_cancel';

-- Manager: 检查用户余额是否恢复
SELECT u.id, u.username, u.balance
FROM acmes a JOIN users u ON u.id = a.user_id
WHERE a.id = $order_id;"

# ============================================================
# 完成
# ============================================================
echo ""
echo "========================================"
echo -e "  ${GREEN}E2E 自动化流程完成${NC}"
echo "========================================"
echo ""
echo "已完成: 环境检查 → 创建订阅 → certbot 注册 → 申请证书 → 验证签发 → 吊销证书"
echo "待手动: 调 Manager API 取消订阅（见上方指引）"
echo ""
echo "后续操作："
echo "  查看证书:  docker run --rm -v $VOLUME_ETC:/etc/letsencrypt certbot/certbot certificates"
echo "  清理数据:  bash $SCRIPT_DIR/run-e2e.sh --clean"
