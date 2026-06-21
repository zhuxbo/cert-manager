#!/bin/bash
# 测试 build/nginx/render.sh:default/custom → enabled 渲染、web.conf 播种、占位替换、剪枝、幂等
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
RENDER="$REPO_ROOT/build/nginx/render.sh"

PASS=0
FAIL=0
ok() {
    PASS=$((PASS + 1))
    echo "  ✓ $1"
}
ng() {
    FAIL=$((FAIL + 1))
    echo "  ✗ $1"
}
assert_file() { [ -f "$1" ] && ok "存在 $2" || ng "缺失 $2 ($1)"; }
assert_grep() { grep -qF "$2" "$1" && ok "$3" || ng "$3 — 未在 $1 命中 [$2]"; }
assert_nogrep() { grep -qF "$2" "$1" && ng "$3 — 不该命中 [$2]" || ok "$3"; }
assert_absent() { [ -e "$1" ] && ng "$2 不该存在 ($1)" || ok "$2"; }

# ---- 搭建 fake 安装目录 ----
ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT
mkdir -p "$ROOT/nginx/default/routes" "$ROOT/nginx/default/snippets" "$ROOT/frontend/web"

cat >"$ROOT/nginx/manager.conf" <<'EOF'
include __PROJECT_ROOT__/nginx/enabled/routes/*.conf;
EOF
cat >"$ROOT/nginx/default/routes/admin.conf" <<'EOF'
location ^~ /admin {
    root __PROJECT_ROOT__/frontend;
    include __PROJECT_ROOT__/nginx/enabled/snippets/spa-static-cache.conf;
    try_files $uri $uri/ /admin/index.html;
}
EOF
cat >"$ROOT/nginx/default/routes/api.conf" <<'EOF'
location ^~ /api { try_files $uri /backend/public/index.php?$query_string; }
EOF
cat >"$ROOT/nginx/default/snippets/spa-static-cache.conf" <<'EOF'
location ~* \.(?:js|css)$ { expires 365d; }
EOF
cat >"$ROOT/frontend/web/web.conf" <<'EOF'
location /help { root __PROJECT_ROOT__/frontend/web; try_files $uri $uri/ /help/index.html; }
EOF

echo "== 场景 1:无 custom,全默认 =="
bash "$RENDER" "$ROOT"
assert_file "$ROOT/nginx/enabled/routes/admin.conf" "enabled/routes/admin.conf"
assert_file "$ROOT/nginx/enabled/routes/api.conf" "enabled/routes/api.conf"
assert_file "$ROOT/nginx/enabled/snippets/spa-static-cache.conf" "enabled/snippets/spa-static-cache.conf"
assert_grep "$ROOT/nginx/enabled/routes/admin.conf" "$ROOT/frontend" "admin 默认=替换占位的拷贝"
assert_nogrep "$ROOT/nginx/enabled/routes/admin.conf" "__PROJECT_ROOT__" "admin 无占位残留"
assert_nogrep "$ROOT/nginx/manager.conf" "__PROJECT_ROOT__" "manager.conf 占位已替换"
# web.conf 播种 + 经 enabled 指针引入
assert_file "$ROOT/nginx/custom/routes/web.conf" "custom/routes/web.conf 已播种"
assert_nogrep "$ROOT/nginx/custom/routes/web.conf" "__PROJECT_ROOT__" "播种的 web.conf 无占位残留"
assert_grep "$ROOT/nginx/enabled/routes/web.conf" "$ROOT/nginx/custom/routes/web.conf" "web 走 custom 指针"

echo "== 场景 2:custom 覆盖 admin(同名抹默认) =="
mkdir -p "$ROOT/nginx/custom/routes"
cat >"$ROOT/nginx/custom/routes/admin.conf" <<EOF
location ^~ /admin { return 302 https://example.com; }
EOF
bash "$RENDER" "$ROOT"
assert_grep "$ROOT/nginx/enabled/routes/admin.conf" "$ROOT/nginx/custom/routes/admin.conf" "admin 走 custom 指针"
assert_nogrep "$ROOT/nginx/enabled/routes/admin.conf" "try_files" "默认 admin 已被抹掉(指针不含默认内容)"

echo "== 场景 3:新增全新路由 myapp =="
cat >"$ROOT/nginx/custom/routes/myapp.conf" <<EOF
location ^~ /myapp { return 200; }
EOF
bash "$RENDER" "$ROOT"
assert_grep "$ROOT/nginx/enabled/routes/myapp.conf" "$ROOT/nginx/custom/routes/myapp.conf" "myapp 新增走 custom 指针"

echo "== 场景 4:删除默认 api → enabled 剪枝 =="
rm -f "$ROOT/nginx/default/routes/api.conf"
bash "$RENDER" "$ROOT"
assert_absent "$ROOT/nginx/enabled/routes/api.conf" "已删默认 api 不复活(剪枝)"

echo "== 场景 5:覆盖 snippet =="
cat >"$ROOT/nginx/custom/snippets/spa-static-cache.conf" <<EOF
location ~* \.(?:js|css)$ { expires 7d; }
EOF
bash "$RENDER" "$ROOT"
assert_grep "$ROOT/nginx/enabled/snippets/spa-static-cache.conf" "$ROOT/nginx/custom/snippets/spa-static-cache.conf" "snippet 走 custom 指针"

echo "== 场景 6:幂等(再跑一次产物不变) =="
SUM1=$(find "$ROOT/nginx/enabled" -type f | sort | xargs cat | cksum)
bash "$RENDER" "$ROOT"
SUM2=$(find "$ROOT/nginx/enabled" -type f | sort | xargs cat | cksum)
[ "$SUM1" = "$SUM2" ] && ok "幂等" || ng "幂等失败"

echo "== 场景 7:--reload 且 nginx -t 失败 → 回滚旧 enabled、退出非零 =="
FAKEBIN="$ROOT/fakebin"
mkdir -p "$FAKEBIN"
cat >"$FAKEBIN/nginx" <<'NGINX'
#!/bin/sh
[ "$1" = "-t" ] && exit 1
exit 0
NGINX
chmod +x "$FAKEBIN/nginx"
# 新增一个 custom 路由,使本次 stage 与当前 enabled 不同,以验证"回滚到旧态"
cat >"$ROOT/nginx/custom/routes/zzz.conf" <<EOF
location ^~ /zzz { return 204; }
EOF
set +e
PATH="$FAKEBIN:$PATH" bash "$RENDER" "$ROOT" --reload
RC=$?
set -e
[ "$RC" -ne 0 ] && ok "nginx -t 失败时退出非零" || ng "应退出非零"
[ -d "$ROOT/nginx/enabled/routes" ] && ok "回滚后 enabled 目录仍在" || ng "enabled 丢失"
assert_absent "$ROOT/nginx/enabled/routes/zzz.conf" "回滚:新 stage 的 zzz 未进入 enabled(已还原旧态)"

echo "== 场景 8:--reload 且 nginx -t 通过 → 应用新 enabled + 调 reload =="
cat >"$FAKEBIN/nginx" <<NGINX
#!/bin/sh
[ "\$1" = "-t" ] && exit 0
[ "\$1" = "-s" ] && touch "$ROOT/reloaded"
exit 0
NGINX
chmod +x "$FAKEBIN/nginx"
set +e
PATH="$FAKEBIN:$PATH" bash "$RENDER" "$ROOT" --reload
RC=$?
set -e
[ "$RC" -eq 0 ] && ok "nginx -t 通过时退出 0" || ng "应退出 0"
assert_grep "$ROOT/nginx/enabled/routes/zzz.conf" "$ROOT/nginx/custom/routes/zzz.conf" "通过:新 zzz 进入 enabled"
[ -f "$ROOT/reloaded" ] && ok "调用了 nginx -s reload" || ng "未调用 reload"
rm -f "$ROOT/nginx/custom/routes/zzz.conf"

echo "== 场景 9:manager.conf 替换写失败 → render 非零退出(set -e 生效) =="
ROOT9=$(mktemp -d)
mkdir -p "$ROOT9/nginx/default/routes" "$ROOT9/frontend/web"
cp "$REPO_ROOT/build/nginx/render.sh" "$ROOT9/nginx/render.sh"
printf 'include __PROJECT_ROOT__/nginx/enabled/routes/*.conf;\n' >"$ROOT9/nginx/manager.conf"
printf 'location ^~ /admin { return 204; }\n' >"$ROOT9/nginx/default/routes/admin.conf"
mkdir "$ROOT9/nginx/manager.conf.tmp" # 占位为目录 → `> manager.conf.tmp` 必失败(任何 uid)
set +e
bash "$ROOT9/nginx/render.sh" "$ROOT9" >/dev/null 2>&1
RC9=$?
set -e
[ "$RC9" -ne 0 ] && ok "manager.conf 替换失败时 render 非零退出" || ng "应非零退出(set -e 未生效?)"
rm -rf "$ROOT9"

echo ""
echo "通过 $PASS,失败 $FAIL"
[ "$FAIL" -eq 0 ]
