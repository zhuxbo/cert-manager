#!/usr/bin/env bash

# Deploy shell 测试的 PHP runner：本地 Docker-first，CI 回落 setup-php。
# source 后调用 test_php_init <repo_root> <wrapper_dir>，成功时设置：
# TEST_PHP_BIN / TEST_PHP_RUNTIME / TEST_PHP_HAS_PROC。

test_php_init() {
    local repo_root="$1"
    local wrapper_dir="$2"
    local candidate

    TEST_PHP_BIN=""
    TEST_PHP_RUNTIME=""
    TEST_PHP_HAS_PROC=0

    if command -v docker >/dev/null 2>&1 &&
        docker compose --project-directory "$repo_root" -f "$repo_root/compose.yaml" \
            exec -T app php -r 'exit(0);' >/dev/null 2>&1; then
        TEST_PHP_RUNTIME="docker"
        TEST_PHP_HAS_PROC=1
        TEST_PHP_BIN="$wrapper_dir/php-docker-wrapper"
        export TEST_PHP_REPO_ROOT="$repo_root"
        export TEST_PHP_BACKEND_ROOT="$repo_root/backend"

        cat >"$TEST_PHP_BIN" <<'EOF'
#!/usr/bin/env bash
set -eo pipefail

map_php_path() {
    case "$1" in
        "$TEST_PHP_BACKEND_ROOT"/*)
            printf '/var/www/%s' "${1#"$TEST_PHP_BACKEND_ROOT"/}"
            ;;
        *) printf '%s' "$1" ;;
    esac
}

docker_env=()
if [ "${STATUS_FILE+x}" = x ]; then
    docker_env+=("-e" "STATUS_FILE=$(map_php_path "$STATUS_FILE")")
fi
if [ "${ENV_FILE+x}" = x ]; then
    docker_env+=("-e" "ENV_FILE=$(map_php_path "$ENV_FILE")")
fi
if [ "${V1+x}" = x ]; then
    docker_env+=("-e" "V1=$V1")
fi
if [ "${V2+x}" = x ]; then
    docker_env+=("-e" "V2=$V2")
fi

mapped_args=()
for arg in "$@"; do
    mapped_args+=("$(map_php_path "$arg")")
done

exec docker compose --project-directory "$TEST_PHP_REPO_ROOT" -f "$TEST_PHP_REPO_ROOT/compose.yaml" \
    exec -T "${docker_env[@]}" app php "${mapped_args[@]}"
EOF
        chmod +x "$TEST_PHP_BIN"
        return 0
    fi

    candidate="$(command -v php 2>/dev/null || true)"
    if [ -z "$candidate" ]; then
        for candidate in /www/server/php/*/bin/php /opt/homebrew/bin/php /usr/local/bin/php; do
            [ -x "$candidate" ] && break
            candidate=""
        done
    fi
    if [ -n "$candidate" ]; then
        TEST_PHP_RUNTIME="host"
        TEST_PHP_BIN="$candidate"
        [ -d /proc ] && TEST_PHP_HAS_PROC=1
        return 0
    fi

    return 1
}

test_php_mktemp_dir() {
    local prefix="$1"
    local base

    if [ "${TEST_PHP_RUNTIME:-}" = "docker" ]; then
        base="$TEST_PHP_BACKEND_ROOT/storage/framework/testing"
        mkdir -p "$base"
        mktemp -d "$base/${prefix}.XXXXXX"
    else
        mktemp -d
    fi
}

test_php_version() {
    "$TEST_PHP_BIN" -r 'echo PHP_VERSION;'
}
