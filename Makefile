# 开发环境快捷命令（容器：后端+MySQL+Redis；前端在宿主跑）
# PHP 默认 8.4；测 8.3 兼容：PHP_VERSION=8.3 make rebuild && PHP_VERSION=8.3 make up
#                一键双版本： make test-compat
# 用法示例：
#   make up                          启动容器（首次自动 build + composer install + migrate + seed）
#   make front                       宿主机跑前端（admin:5201 / user:5202）
#   make test                        容器内并行跑后端测试（与 CI 对齐）
#   make test ARGS="--filter=Acme"   传参给 artisan test
#   make artisan ARGS="route:list"   任意 artisan 命令
#   make shell                       进后端容器

DC := docker compose
ARGS ?=
PROCESSES ?= 4 # 并行测试 worker 数（amd64 Rosetta 下不宜过高，防 OOM；机器内存大可调高）

.DEFAULT_GOAL := help

.PHONY: help up down stop restart build rebuild ps logs shell test test-compat migrate fresh seed \
        tinker composer artisan pint db redis-cli front install

help: ## 显示本帮助
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## 启动容器（后台）
	$(DC) up -d

down: ## 停止并移除容器（保留数据库卷）
	$(DC) down

stop: ## 仅停止容器
	$(DC) stop

restart: ## 重启容器
	$(DC) restart

build: ## 构建镜像
	$(DC) build

rebuild: ## 无缓存重建镜像
	$(DC) build --no-cache

ps: ## 查看容器状态
	$(DC) ps

logs: ## 跟踪后端日志
	$(DC) logs -f app

shell: ## 进后端容器 bash
	$(DC) exec app bash

test: ## 并行跑后端测试（隔离测试库 ssl_manager_test），可加 ARGS= / PROCESSES=
	$(DC) exec -e DB_DATABASE=ssl_manager_test app php artisan test --parallel --processes=$(PROCESSES) $(ARGS)

test-compat: ## 依次用 PHP 8.3 / 8.4 跑测试（验证版本兼容）
	PHP_VERSION=8.3 $(DC) build app && PHP_VERSION=8.3 $(DC) run --rm -e DB_DATABASE=ssl_manager_test app php artisan test --parallel --processes=$(PROCESSES)
	PHP_VERSION=8.4 $(DC) build app && PHP_VERSION=8.4 $(DC) run --rm -e DB_DATABASE=ssl_manager_test app php artisan test --parallel --processes=$(PROCESSES)

migrate: ## 执行迁移
	$(DC) exec app php artisan migrate

fresh: ## 重建数据库并 seed（清空数据！）
	$(DC) exec app php artisan migrate:fresh --seed

seed: ## 填充种子数据
	$(DC) exec app php artisan db:seed

tinker: ## 进 tinker
	$(DC) exec app php artisan tinker

composer: ## 容器内 composer，如 make composer ARGS="require xxx"
	$(DC) exec app composer $(ARGS)

artisan: ## 容器内 artisan，如 make artisan ARGS="route:list"
	$(DC) exec app php artisan $(ARGS)

pint: ## 跑 Laravel Pint 格式化
	$(DC) exec app ./vendor/bin/pint

db: ## 进 MySQL 客户端
	$(DC) exec mysql mysql -uroot -ppassword ssl_manager

redis-cli: ## 进 redis-cli
	$(DC) exec redis redis-cli

install: ## 宿主机安装前端依赖
	pnpm install

front: ## 宿主机启动前端 dev（admin:5201 / user:5202）
	pnpm install && pnpm dev
