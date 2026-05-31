# 数据库结构导出

重新生成 `backend/database/structure.json`（主系统标准结构，升级时用于校验/修复）。

## 容器开发环境（推荐）

默认的 `php artisan db:structure --export` 会再 `docker run` 起一个临时 MySQL 容器；但本机 PHP 跑在 `app` 容器内、容器里没有 docker，跑不通。改用 Makefile 封装——在 compose 的 MySQL（8.4）里开一个临时库，仅跑主系统迁移后导出，**不碰开发库 `ssl_manager`**：

```bash
make db-structure          # 超时设 5 分钟（含一次全量 migrate:fresh）
```

等价既定流程的「全新干净库 + 仅主迁移（`--path=database/migrations`，排除插件）+ 8.4 基准」。底层 4 步见 `Makefile` 的 `db-structure` target（建临时库 → 迁移 → `db:structure --export --use-local` → 删库）。

## 验证

- `backend/database/structure.json` 的 `generated_at` 已更新
- `git diff backend/database/structure.json` 只反映预期的迁移改动
- 可选复查：`make php ARGS="db:structure --check"`（对比开发库与标准，无差异即一致）

## 排查

- 临时库残留（命令中断时）手动清理：
  `docker compose exec -T -e MYSQL_PWD=password mysql mysql -uroot -e "DROP DATABASE IF EXISTS structure_export"`
- 仅当宿主装了 PHP + docker 时，才能用原生 `cd backend && php artisan db:structure --export`（自动起 `laravel-mysql-temp` 临时容器，失败查 `docker logs laravel-mysql-temp`）

## 注意

- 插件表由插件自管，不纳入主系统 `structure.json`
- 发布前确保 `structure.json` 最新（见 `skills/build-release.md`）
