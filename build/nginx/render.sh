#!/usr/bin/env bash
# nginx 路由渲染器 —— 三条部署路径(bt-install / upgrade.sh / 后台 PackageExtractor)共用。
# 纯文件操作,不依赖 Laravel app。把 default(受管) 与 custom(用户) 解析成 enabled(产物):
#   每路由名:custom 存在 → enabled 写 `include <绝对>/custom/...`(指针,改内容直接 reload);
#             否则        → enabled = default 的内容(替换 __PROJECT_ROOT__ 后拷贝)。
# 用法: render.sh <PROJECT_ROOT> [--reload]
#   不带 --reload:仅渲染文件(安装/升级三路径用)。
#   带 --reload  :渲染后 nginx -t,通过则 reload、失败则回滚 enabled 且不 reload(手动同步用)。
set -euo pipefail

PROJECT_ROOT="${1:?用法: render.sh <PROJECT_ROOT> [--reload]}"
DO_RELOAD=0
[ "${2:-}" = "--reload" ] && DO_RELOAD=1

NGINX_DIR="$PROJECT_ROOT/nginx"
DEFAULT_DIR="$NGINX_DIR/default"
CUSTOM_DIR="$NGINX_DIR/custom"
ENABLED_DIR="$NGINX_DIR/enabled"
LEGACY_WEB_CONF="$PROJECT_ROOT/frontend/web/web.conf"

trap 'rm -f "$NGINX_DIR/manager.conf.tmp"; rm -rf "$NGINX_DIR/.enabled.new.$$"' EXIT

sub() { sed "s|__PROJECT_ROOT__|$PROJECT_ROOT|g" "$1"; }

# 1. manager.conf 占位替换(幂等:替换后无占位,再跑 sed 无副作用)
if [ -f "$NGINX_DIR/manager.conf" ]; then
    sub "$NGINX_DIR/manager.conf" >"$NGINX_DIR/manager.conf.tmp"
    mv "$NGINX_DIR/manager.conf.tmp" "$NGINX_DIR/manager.conf"
fi

mkdir -p "$CUSTOM_DIR/routes" "$CUSTOM_DIR/snippets"

# 2. web.conf 播种:custom 缺失且 legacy 存在 → 复制(替换占位)。legacy = web.conf 出厂模板。
if [ ! -f "$CUSTOM_DIR/routes/web.conf" ] && [ -f "$LEGACY_WEB_CONF" ]; then
    sub "$LEGACY_WEB_CONF" >"$CUSTOM_DIR/routes/web.conf"
fi

# 3. 从空 stage 重建 enabled(从空重建即剪枝)
STAGE="$NGINX_DIR/.enabled.new.$$"
rm -rf "$STAGE"
for cat in routes snippets; do
    mkdir -p "$STAGE/$cat"
    # 末尾 || true:pipefail 下 grep 无命中(空目录)会非零退出,会被 set -e 误杀
    names=$(
        {
            ls -1 "$DEFAULT_DIR/$cat" 2>/dev/null || true
            ls -1 "$CUSTOM_DIR/$cat" 2>/dev/null || true
        } |
            grep -E '\.conf$' | sort -u || true
    )
    # 注:.conf 文件名为受控命名(不含空格),故可对 $names 按空白分词
    for name in $names; do
        if [ -f "$CUSTOM_DIR/$cat/$name" ]; then
            printf 'include %s/custom/%s/%s;\n' "$NGINX_DIR" "$cat" "$name" >"$STAGE/$cat/$name"
        elif [ -f "$DEFAULT_DIR/$cat/$name" ]; then
            sub "$DEFAULT_DIR/$cat/$name" >"$STAGE/$cat/$name"
        fi
    done
done

# 4. 原子切换(enabled 始终存在,除两次 mv 间的亚毫秒窗口)
rm -rf "$ENABLED_DIR.bak"
if [ -d "$ENABLED_DIR" ]; then
    mv "$ENABLED_DIR" "$ENABLED_DIR.bak"
fi
mv "$STAGE" "$ENABLED_DIR"

# 5. 校验 + reload(仅 --reload)
if [ "$DO_RELOAD" = 1 ]; then
    if ! command -v nginx >/dev/null 2>&1; then
        echo "render.sh: 指定了 --reload 但未找到 nginx,跳过 reload" >&2
        rm -rf "$ENABLED_DIR.bak" 2>/dev/null || true
    elif nginx -t; then
        nginx -s reload 2>/dev/null || true
        rm -rf "$ENABLED_DIR.bak" 2>/dev/null || true
    else
        rm -rf "$ENABLED_DIR"
        if [ -d "$ENABLED_DIR.bak" ]; then
            mv "$ENABLED_DIR.bak" "$ENABLED_DIR"
        else
            # 首次渲染即 nginx -t 失败:无旧态可回滚,建空 enabled 避免 include 指向缺失目录
            mkdir -p "$ENABLED_DIR/routes" "$ENABLED_DIR/snippets"
        fi
        echo "render.sh: nginx -t 失败,已回滚 enabled、未 reload" >&2
        exit 1
    fi
else
    rm -rf "$ENABLED_DIR.bak" 2>/dev/null || true
fi
