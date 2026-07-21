# 容器开发环境

后端（PHP 8.4）、MySQL 8.4、Redis 7 跑在容器里；**前端在宿主机跑**（本机已有 Node + pnpm，HMR 更快）。

> PHP 默认 **8.4**（贴合生产）。代码需同时兼容 8.3/8.4，本地按需切版本，CI 跑 8.3/8.4/8.5 全矩阵。

## 组成

| 服务        | 镜像           | 宿主端口 | 说明                                                            |
| ----------- | -------------- | -------- | --------------------------------------------------------------- |
| `app`       | 自建 PHP 8.4   | **5300** | `php artisan serve`，前端 proxy 默认指向它                      |
| `scheduler` | 自建 PHP 8.4   | -        | `php artisan schedule:work`，持续执行开发环境调度任务           |
| `mysql`     | mysql:8.4      | 3306     | root / `password`，库 `ssl_manager`（本地 ARM 原生；CI 用 5.7） |
| `redis`     | redis:7-alpine | 6379     | 默认 file 缓存即可跑，按需切 redis                              |

前端：admin → `5201`，user → `5202`（宿主机 `pnpm dev`）。

## 快速开始

```bash
make up      # 启动容器：首次自动 build + composer install + migrate + seed（管理员 admin/123456）
make front   # 另开一个终端，宿主机跑前端
```

- 用户端 <http://localhost:5202> ｜ 管理端 <http://localhost:5201>
- 后端 API <http://localhost:5300>（前端 vite 已默认代理到这里，无需配置）

首次 `make up` 会在容器内装 Composer 依赖并迁移播种，耗时几分钟，`make logs` 看进度。

## 常用命令（`make help` 查看全部）

```bash
make test                       # 容器内并行跑后端测试（隔离库 ssl_manager_test）
make test ARGS="--filter=Acme"  # 传参
make test PROCESSES=6           # 调并行 worker 数（默认 4）
make test-compat                # 依次用 PHP 8.3 / 8.4 跑测试，验证版本兼容
make shell                      # 进后端容器
make artisan ARGS="route:list"  # 任意 artisan
make php ARGS="-v"              # 容器内任意 php（php -r / 跑脚本等）
make exec ARGS="./vendor/bin/pest"  # 容器内任意命令（pest/phpstan 等）
make migrate / make fresh       # 迁移 / 重建库并 seed
make pint                       # PHP 格式化
make db-structure               # 导出 structure.json（临时干净库，仅主迁移，不碰开发库）
make db / make redis-cli        # 进 MySQL / Redis
make down                       # 停止（保留数据库卷）
```

> **参数必须用 `ARGS="..."` 包裹**：`make php` / `exec` / `artisan` / `composer` 的参数都要放进 `ARGS`（如 `make php ARGS="artisan test --filter=Acme"`、`make exec ARGS="./vendor/bin/pint"`）。直接 `make php -v` 会被 make 当成自身选项、`make php artisan` 会被当成构建目标；含空格/引号的参数也整体放进 `ARGS`。

## PHP 版本切换（8.3 / 8.4）

默认 8.4。临时切到 8.3 验证兼容性：

```bash
PHP_VERSION=8.3 make rebuild    # 用 8.3 重建镜像
PHP_VERSION=8.3 make up         # 用 8.3 启动
PHP_VERSION=8.3 make test       # 用 8.3 跑测试
```

两个版本镜像 tag 不同（`ssl-manager-app:php8.3` / `:php8.4`），互不覆盖、各自缓存。一键依次跑两版本测试用 `make test-compat`。

## 说明

- **数据库配置自动写入**：`backend/.env` 由 `app` 容器启动脚本（`docker/php/entrypoint.sh`）幂等设置 `DB_HOST=mysql`、`REDIS_HOST=redis` 等，指向容器服务名。本机改 `.env` 其它项不受影响。
- **数据持久化**：MySQL 数据在命名卷 `mysql-data`，`make down` 不会清空；`make fresh` 或 `docker compose down -v` 才会重置。
- **vendor 在宿主可见**：`backend/vendor` 通过 bind mount 落到宿主，IDE 可正常跳转。
- **端口冲突**：本机已占用 3306 时，改 `compose.yaml` 的 mysql 端口为 `"3307:3306"`。
- **前端要连别的后端**：设 `VITE_API_TARGET` 即可覆盖默认的 `http://localhost:5300`。
- **开发调度器默认启动**：`scheduler` 等待 `app` 完成依赖安装、环境配置和迁移后运行 `schedule:work`，为调度心跳和定时业务任务提供与生产一致的执行路径；生产仍由宝塔每分钟运行 `schedule:run`。
- **可选 Redis 队列/缓存**：默认 `file`/`sync` 即可开发；需要时在 `backend/.env` 设 `CACHE_DRIVER=redis`、`QUEUE_CONNECTION=redis`（redis 扩展镜像已内置）。
- **MySQL 版本（5.7 与 8.x 双覆盖）**：生产二者都有。本地默认 `mysql:8.4`（ARM 原生，不依赖将被淘汰的 Rosetta）；CI core 跑 `5.7×{8.3,8.4}` + `8.4×{8.4,8.5}`、各 plugin 跑 5.7+8.4，两版本都验证。本地要复现 5.7 用内网实例或看 CI。新增迁移/SQL 避开 8.0+ 保留字（`rank`/`groups`/`system`）与 5.7 不支持的语法。
- **collation 按版本自动选择**（与 `bt-install.sh`/生产一致）：MySQL 8.x→`utf8mb4_0900_ai_ci`、5.7→`utf8mb4_unicode_520_ci`、MariaDB→`utf8mb4_unicode_ci`。三处逻辑统一：容器 `entrypoint.sh`（PDO 探测 `SELECT VERSION()`）、CI 的 `matrix.collation`、`bt-install.sh` 的 `_detect_db_collation`。`structure.json` 以 8.4 为基准（`db:structure --export` 临时容器已改 `mysql:8.4`）。
- **测试库隔离 + 并发**：`make test` 注入 `DB_DATABASE=ssl_manager_test`，并行 worker 各建临时库，绝不触碰开发库 `ssl_manager`。并发 `PROCESSES` 默认 4（满核 10 个 worker 并发加载 phpunit 会超出 VM 内存而 OOM），机器内存富裕可 `make test PROCESSES=8`。
