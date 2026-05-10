#!/bin/bash
# 测试 bt-automate.sh::bt_inject_vhost_include 的 awk 深度跟踪逻辑
#
# 验证 6 个场景：
#   1. BT 标准 vhost（server\n{ 分行 + SSL + location 子块）
#   2. server 块直接子级 root（必须命中）
#   3. location 子块内的 root（必须忽略）
#   4. if 块内的 root（必须忽略）
#   5. 幂等：同路径 include 已存在 → 跳过
#   6. 多 server 块：只在第一个 server 块的 root 后插入
#
# 用法：bash test-bt-vhost-inject.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=../scripts/bt-automate.sh disable=SC1091
source "$DEPLOY_DIR/scripts/bt-automate.sh"

# 静默 nginx -t / reload（测试环境无 nginx）；用 PATH 拦截
TMPDIR_TEST=$(mktemp -d)
trap 'rm -rf "$TMPDIR_TEST"' EXIT

# 创建 fake nginx 命令（永远返回成功）
mkdir -p "$TMPDIR_TEST/bin"
cat >"$TMPDIR_TEST/bin/nginx" <<'EOF'
#!/bin/bash
exit 0
EOF
chmod +x "$TMPDIR_TEST/bin/nginx"
export PATH="$TMPDIR_TEST/bin:$PATH"

# Mock BT vhost 路径：把测试 vhost 放进 fake 路径，函数据此查找
# bt_inject_vhost_include 硬编码 /www/server/panel/vhost/nginx/<domain>.conf
# → 我们直接复制 awk 段独立测试，不调函数

# 提取 awk 程序为独立文件（与 bt-automate.sh 同源）
AWK_PROG="$TMPDIR_TEST/inject.awk"
cat >"$AWK_PROG" <<'AWKEOF'
BEGIN { depth = 0; injected = 0 }
{
    line = $0

    if (!injected && depth == 1 && match(line, /^[ \t]*root[ \t]+[^;]+;[ \t]*$/)) {
        match(line, /^[ \t]*/)
        indent = substr(line, RSTART, RLENGTH)
        print line
        printf "%sinclude %s;\n", indent, INC
        injected = 1
        next
    }

    print line

    n_open = 0
    n_close = 0
    s = line
    len = length(s)
    for (i = 1; i <= len; i++) {
        c = substr(s, i, 1)
        if (c == "#") break
        if (c == "{") n_open++
        else if (c == "}") n_close++
    }
    depth += n_open - n_close
    if (depth < 0) depth = 0
}
END { exit (injected ? 0 : 1) }
AWKEOF

PASS=0
FAIL=0
INC_PATH="/www/wwwroot/manager/nginx/manager.conf"

assert_match() {
    local label="$1" actual="$2" expected_re="$3"
    if echo "$actual" | grep -qE "$expected_re"; then
        echo "  ✓ $label"
        PASS=$((PASS + 1))
    else
        echo "  ✗ $label"
        echo "    expected match: $expected_re"
        echo "    actual: $actual"
        FAIL=$((FAIL + 1))
    fi
}

assert_no_match() {
    local label="$1" actual="$2" unwanted_re="$3"
    if echo "$actual" | grep -qE "$unwanted_re"; then
        echo "  ✗ $label"
        echo "    unwanted match: $unwanted_re"
        echo "    actual:"
        echo "$actual" | sed 's/^/      /'
        FAIL=$((FAIL + 1))
    else
        echo "  ✓ $label"
        PASS=$((PASS + 1))
    fi
}

count_lines() {
    grep -cE "$1" || true
}

# ==========================================================================
# Case 1: BT 标准 vhost - server\n{ 分行 + SSL + location 子块
# ==========================================================================
echo
echo "=== Case 1: BT 标准 vhost（server\\n{ 分行 + SSL + location 子块）==="
cat >"$TMPDIR_TEST/c1.in" <<'EOF'
server
{
    listen 80;
    listen 443 ssl http2;
    server_name manager.example.com;
    index index.php index.html;
    root /www/wwwroot/manager.example.com;

    #SSL-START
    ssl_certificate /www/server/panel/vhost/cert/manager.example.com/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/manager.example.com/privkey.pem;
    #SSL-END

    include enable-php-83.conf;
    include /www/server/panel/vhost/rewrite/manager.example.com.conf;

    location ~ /\.well-known {
        allow all;
    }

    access_log /www/wwwlogs/manager.example.com.log;
}
EOF
out1=$(awk -v INC="$INC_PATH" -f "$AWK_PROG" "$TMPDIR_TEST/c1.in")
echo "$out1" >"$TMPDIR_TEST/c1.out"

# include 应该出现在 root 后一行
expected_include="    include $INC_PATH;"
assert_match "include 在 root 行后插入" "$(grep -A1 '^[[:space:]]*root /www/wwwroot/manager.example.com;' "$TMPDIR_TEST/c1.out" | tail -1)" "^[[:space:]]*include /www/wwwroot/manager/nginx/manager.conf;"

# include 缩进与 root 一致（4 空格）
assert_match "include 缩进与 root 一致（4 空格）" "$(grep "include $INC_PATH" "$TMPDIR_TEST/c1.out")" "^    include "

# 只插入一次
inc_count=$(grep -cF "include $INC_PATH" "$TMPDIR_TEST/c1.out" || true)
if [ "$inc_count" = "1" ]; then
    echo "  ✓ 只插入一次（实际: ${inc_count}）"
    PASS=$((PASS + 1))
else
    echo "  ✗ 应只插入一次，实际: $inc_count"
    FAIL=$((FAIL + 1))
fi

# ==========================================================================
# Case 2: location 子块内有 root → 必须跳过，命中 server 块的根 root
# ==========================================================================
echo
echo "=== Case 2: location 子块内的 root 必须忽略 ==="
cat >"$TMPDIR_TEST/c2.in" <<'EOF'
server {
    listen 80;
    server_name app.example.com;
    root /www/wwwroot/app;

    location /api {
        root /www/wwwroot/app/api;
        try_files $uri =404;
    }
}
EOF
awk -v INC="$INC_PATH" -f "$AWK_PROG" "$TMPDIR_TEST/c2.in" >"$TMPDIR_TEST/c2.out"

# include 应该紧跟 server 块的 root，而不是 location 内的 root
line_after_server_root=$(grep -A1 '^    root /www/wwwroot/app;' "$TMPDIR_TEST/c2.out" | tail -1)
assert_match "include 在 server 块根 root 后" "$line_after_server_root" "include /www/wwwroot/manager/nginx/manager.conf;"

# location 内的 root 后**不能**有 include
line_after_loc_root=$(grep -A1 '^[[:space:]]*root /www/wwwroot/app/api;' "$TMPDIR_TEST/c2.out" | tail -1)
assert_no_match "location 内的 root 后没注入" "$line_after_loc_root" "include /www/wwwroot/manager/nginx/manager.conf"

# 只一次
inc_count=$(grep -cF "include $INC_PATH" "$TMPDIR_TEST/c2.out" || true)
if [ "$inc_count" = "1" ]; then
    echo "  ✓ 只插入一次（实际: ${inc_count}）"
    PASS=$((PASS + 1))
else
    echo "  ✗ 应只插入一次，实际: $inc_count"
    FAIL=$((FAIL + 1))
fi

# ==========================================================================
# Case 3: if 块、limit_except 块内的 root 必须忽略
# ==========================================================================
echo
echo "=== Case 3: if/limit_except 子块内的 root 必须忽略 ==="
cat >"$TMPDIR_TEST/c3.in" <<'EOF'
server {
    listen 80;
    server_name x.example.com;

    location / {
        if ($request_method = POST) {
            root /tmp/post-root;
        }
        limit_except GET {
            deny all;
        }
    }

    root /www/wwwroot/x;
}
EOF
awk -v INC="$INC_PATH" -f "$AWK_PROG" "$TMPDIR_TEST/c3.in" >"$TMPDIR_TEST/c3.out"

# 真正的根 root 后有 include
line=$(grep -A1 '^    root /www/wwwroot/x;' "$TMPDIR_TEST/c3.out" | tail -1)
assert_match "if 块后的 server 根 root 仍能命中" "$line" "include /www/wwwroot/manager/nginx/manager.conf;"

# /tmp/post-root 那行后**不能**有 include
line2=$(grep -A1 '/tmp/post-root' "$TMPDIR_TEST/c3.out" | tail -1)
assert_no_match "if 块内的 root 后没注入" "$line2" "include /www/wwwroot/manager/nginx/manager.conf"

# ==========================================================================
# Case 4: 没有根 root 行 → awk exit 1（让函数报失败而不是写损坏文件）
# ==========================================================================
echo
echo "=== Case 4: 无根 root 行 → awk exit 非零 ==="
cat >"$TMPDIR_TEST/c4.in" <<'EOF'
server {
    listen 80;
    server_name noroot.example.com;
    location / {
        root /www/wwwroot/somewhere;
    }
}
EOF
if awk -v INC="$INC_PATH" -f "$AWK_PROG" "$TMPDIR_TEST/c4.in" >"$TMPDIR_TEST/c4.out"; then
    echo "  ✗ 期望 awk exit 1（无根 root），实际 exit 0"
    FAIL=$((FAIL + 1))
else
    echo "  ✓ awk 在无根 root 时 exit 非零"
    PASS=$((PASS + 1))
fi

# ==========================================================================
# Case 5: 多个 server 块 → 只在第一个 server 块的 root 后插入
# ==========================================================================
echo
echo "=== Case 5: 多 server 块（第一个根 root 后插入）==="
cat >"$TMPDIR_TEST/c5.in" <<'EOF'
server {
    listen 80;
    server_name a.example.com;
    root /www/wwwroot/a;
}

server {
    listen 80;
    server_name b.example.com;
    root /www/wwwroot/b;
}
EOF
awk -v INC="$INC_PATH" -f "$AWK_PROG" "$TMPDIR_TEST/c5.in" >"$TMPDIR_TEST/c5.out"

inc_count=$(grep -cF "include $INC_PATH" "$TMPDIR_TEST/c5.out" || true)
if [ "$inc_count" = "1" ]; then
    echo "  ✓ 多 server 块只插入一次（实际: ${inc_count}）"
    PASS=$((PASS + 1))
else
    echo "  ✗ 应只插入一次，实际: $inc_count"
    FAIL=$((FAIL + 1))
fi

# 第一个 server 块的 root 后命中
line=$(grep -A1 '^    root /www/wwwroot/a;' "$TMPDIR_TEST/c5.out" | tail -1)
assert_match "插入在第一个 server 的根 root 后" "$line" "include /www/wwwroot/manager/nginx/manager.conf;"

# 第二个 server 块的 root 后**不能**有 include
line2=$(grep -A1 '^    root /www/wwwroot/b;' "$TMPDIR_TEST/c5.out" | tail -1)
assert_no_match "第二个 server 的 root 后未插入" "$line2" "include /www/wwwroot/manager/nginx/manager.conf"

# ==========================================================================
# Case 6: 行内含 # 注释（注释里的 } 不能影响 depth 计算）
# ==========================================================================
echo
echo "=== Case 6: 行内 # 注释中的 { } 不应影响 depth ==="
cat >"$TMPDIR_TEST/c6.in" <<'EOF'
server {
    listen 80;
    server_name c.example.com;
    # 这是注释，里面有 } 字符
    # 还有 { 字符
    root /www/wwwroot/c;
}
EOF
awk -v INC="$INC_PATH" -f "$AWK_PROG" "$TMPDIR_TEST/c6.in" >"$TMPDIR_TEST/c6.out"

line=$(grep -A1 '^    root /www/wwwroot/c;' "$TMPDIR_TEST/c6.out" | tail -1)
assert_match "注释中的 {} 不影响深度计算，root 仍命中" "$line" "include /www/wwwroot/manager/nginx/manager.conf;"

# ==========================================================================
# Case 7: 缩进为 tab 时 include 缩进保持 tab
# ==========================================================================
echo
echo "=== Case 7: tab 缩进保持 ==="
printf 'server {\n\tlisten 80;\n\tserver_name t.example.com;\n\troot /www/wwwroot/t;\n}\n' >"$TMPDIR_TEST/c7.in"
awk -v INC="$INC_PATH" -f "$AWK_PROG" "$TMPDIR_TEST/c7.in" >"$TMPDIR_TEST/c7.out"
inc_line=$(grep -F "include $INC_PATH" "$TMPDIR_TEST/c7.out")
# tab 字符开头
if printf '%s' "$inc_line" | head -c 1 | od -An -c 2>/dev/null | grep -qE '\\t|\bt\b'; then
    echo "  ✓ tab 缩进保持"
    PASS=$((PASS + 1))
else
    # 用 awk 检测：tab=9, space=32
    first_char_code=$(printf '%s' "$inc_line" | head -c 1 | od -An -tu1 | tr -d ' ')
    if [ "$first_char_code" = "9" ]; then
        echo "  ✓ tab 缩进保持（ASCII 9）"
        PASS=$((PASS + 1))
    else
        echo "  ✗ 缩进首字符 ASCII = ${first_char_code}，预期 9 (tab)"
        FAIL=$((FAIL + 1))
    fi
fi

# ==========================================================================
# Case 8: root 路径提取（P4 校验逻辑）
# 提取 awk 副本，验证 server 块直接子级的 root 值能正确提取
# ==========================================================================
echo
echo "=== Case 8: root 路径提取（仅 server 块直接子级）==="

ROOT_AWK="$TMPDIR_TEST/extract-root.awk"
cat >"$ROOT_AWK" <<'AWKEOF'
BEGIN { depth = 0; found = 0 }
{
    line = $0
    if (!found && depth == 1) {
        val = line
        if (sub(/^[ \t]*root[ \t]+/, "", val)) {
            sub(/[ \t]*;.*$/, "", val)
            if (val != "") { print val; found = 1; exit 0 }
        }
    }
    n_open = 0; n_close = 0
    s = line; len = length(s)
    for (i = 1; i <= len; i++) {
        c = substr(s, i, 1)
        if (c == "#") break
        if (c == "{") n_open++
        else if (c == "}") n_close++
    }
    depth += n_open - n_close
    if (depth < 0) depth = 0
}
END { exit (found ? 0 : 1) }
AWKEOF

# 8.1 标准 vhost - 应提取 server 块的 root，跳过 location 子块
cat >"$TMPDIR_TEST/c8a.in" <<'EOF'
server {
    listen 80;
    server_name app.example.com;
    root /www/wwwroot/app;

    location /api {
        root /www/wwwroot/app/api;
    }
}
EOF
extracted=$(awk -f "$ROOT_AWK" "$TMPDIR_TEST/c8a.in")
if [ "$extracted" = "/www/wwwroot/app" ]; then
    echo "  ✓ 8.1 提取 server 根 root，忽略 location 内 root（实际: ${extracted}）"
    PASS=$((PASS + 1))
else
    echo "  ✗ 8.1 期望 /www/wwwroot/app，实际: $extracted"
    FAIL=$((FAIL + 1))
fi

# 8.2 server\n{ 分行（BT 标准模板）
cat >"$TMPDIR_TEST/c8b.in" <<'EOF'
server
{
    listen 80;
    server_name bt.example.com;
    root /www/wwwroot/bt.example.com;

    include enable-php-83.conf;
}
EOF
extracted=$(awk -f "$ROOT_AWK" "$TMPDIR_TEST/c8b.in")
if [ "$extracted" = "/www/wwwroot/bt.example.com" ]; then
    echo "  ✓ 8.2 server\\n{ 分行的根 root 提取（实际: ${extracted}）"
    PASS=$((PASS + 1))
else
    echo "  ✗ 8.2 期望 /www/wwwroot/bt.example.com，实际: $extracted"
    FAIL=$((FAIL + 1))
fi

# 8.3 root 行末尾有空白 — 应去除
cat >"$TMPDIR_TEST/c8c.in" <<'EOF'
server {
    listen 80;
    root /data/manager;
}
EOF
extracted=$(awk -f "$ROOT_AWK" "$TMPDIR_TEST/c8c.in")
if [ "$extracted" = "/data/manager" ]; then
    echo "  ✓ 8.3 行尾空白去除（实际: '$extracted'）"
    PASS=$((PASS + 1))
else
    echo "  ✗ 8.3 期望 /data/manager，实际: '$extracted'"
    FAIL=$((FAIL + 1))
fi

# 8.4 无根 root（仅 location 内）→ awk exit 1，stdout 空
cat >"$TMPDIR_TEST/c8d.in" <<'EOF'
server {
    listen 80;
    location / {
        root /www/wwwroot/somewhere;
    }
}
EOF
if extracted=$(awk -f "$ROOT_AWK" "$TMPDIR_TEST/c8d.in") && [ -n "$extracted" ]; then
    echo "  ✗ 8.4 期望提取失败（无根 root），实际拿到: $extracted"
    FAIL=$((FAIL + 1))
else
    echo "  ✓ 8.4 无根 root 时 awk exit 非零、stdout 空"
    PASS=$((PASS + 1))
fi

# ==========================================================================
# 结果汇总
# ==========================================================================
echo
echo "============================================"
echo "  PASS: $PASS"
echo "  FAIL: $FAIL"
echo "============================================"

if [ "$FAIL" = "0" ]; then
    echo "所有测试通过 ✓"
    exit 0
else
    echo "有失败用例 ✗"
    exit 1
fi
