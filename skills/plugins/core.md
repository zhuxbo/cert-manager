# 插件开发规范

IIFE 前端接入见 `frontend.md`；测试、发布、安装/更新/卸载与安全机制见 `lifecycle.md`。

## 目录结构

```
plugins/
├── release-plugin.sh          # 通用构建脚本
├── temp/                    # 构建产物（git 忽略）
├── README.md                # 安装/更新/卸载说明
└── {name}/                  # 插件目录
    ├── plugin.json          # 插件元数据（必须）
    ├── build.json           # 打包配置
    ├── backend/             # PHP 后端
    │   ├── {Name}ServiceProvider.php
    │   ├── Controllers/
    │   ├── Models/
    │   ├── Requests/
    │   ├── routes/
    │   └── migrations/
    ├── admin/               # 管理端前端（Vite IIFE）
    │   ├── src/index.ts     # 入口，导出 routes
    │   ├── vite.config.ts
    │   └── dist/            # 构建产物（git 忽略）
    ├── user/                # 用户端前端（Vite IIFE）
    ├── frontend/            # 静态页面（可选）
    └── nginx/               # nginx 配置（可选）
```

---

## plugin.json

```json
{
  "name": "{name}",
  "version": "0.0.1",
  "description": "插件描述",
  "provider": "{Name}ServiceProvider",
  "admin_bundle": "admin/{name}-plugin.iife.js",
  "admin_css": "admin/{name}-plugin-admin.css",
  "user_bundle": "user/{name}-plugin.iife.js",
  "release_url": ""
}
```

- `provider`：ServiceProvider 类名，位于 `backend/{Provider}.php`
- `admin_bundle` / `user_bundle`：前端 IIFE 入口，相对于插件目录的路径
- `release_url`：第三方更新地址（留空则使用主系统 release 子目录）

---

## 后端开发

插件后端应维护独立 PHPStan 配置并以 0 errors 为准入要求。服务返回 Eloquent 查询时用 `Builder<Model>` PHPDoc 保留模型泛型；注册表、工厂等公共入口返回接口类型，可选能力在调用点用 `instanceof` 收窄，不要把内部实现基类泄漏到公共参数契约。

### ServiceProvider

每个插件有一个 ServiceProvider，由 `PluginServiceProvider` 自动扫描注册。

- 命名空间：`Plugin\{Name}\`（自动注册，基于 `plugins/{name}/backend/`）
- 路由注册：在 `boot()` 中加载 `routes/*.php`
- 日志处理：实现 `PluginLogHandler` 接口注册到主系统日志

```php
namespace Plugin\{Name};

use Illuminate\Support\ServiceProvider;

class {Name}ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/routes/admin.php');
        // ...
    }
}
```

### 路由

- 使用主系统中间件（`auth:api`、`admin` 等）
- 路由前缀遵循主系统约定：`api/admin/`、`api/user/`、`api/{name}/`（公共）、`api/callback/`

### 数据库

- 迁移文件放 `backend/migrations/`，ServiceProvider `boot()` 中调 `$this->loadMigrationsFrom("$basePath/backend/migrations")`，主系统 `php artisan migrate` 会自动包含
- 插件 migration 创建通知模板时必须同时写 `variables` 元数据并与对应 Builder payload 字段一致；插件独占模板在 `down()` 删除，使“保留数据/完全清除”语义与卸载选项一致
- 表名建议加插件前缀（如 `{name}_logs`）避免冲突
- 卸载时可选回滚迁移（`remove_data=true`）；完全清除必须用 `migrate:reset --path` 覆盖插件全部历史 batch，不能用只处理全局最后 batch 的单次 `migrate:rollback`
- **插件表独立管理**：主系统 `db:structure --export` 通过 `--path=database/migrations` 排除插件迁移，`structure.json` 仅包含主系统表
- **从主系统迁移分离时注意**：如果原来某些表在主系统迁移文件中，拆分到插件时必须确保主系统迁移文件仍保留主系统自己的表（不能整个删除包含多张表的迁移文件）

### MySQL 兼容性（与主系统同等约束）

插件作为生产代码，跟主系统执行同一套 MySQL 5.7+ 兼容规则。详见 `skills/backend/database.md` "MySQL 兼容性" 章节，插件特定要点：

- **迁移禁用 `->json()` 列类型**：用 `->text()` 列 + Model `protected $casts = ['col' => 'array']`。理由：MySQL 5.7 对 `->json()` 索引和默认值支持有限，统一走 text + array cast 最稳妥。Model 不要用 `'json'` cast，统一用 `'array'`
- **优先 Eloquent / Query Builder**：避免 `DB::raw` / `DB::statement` / `whereRaw`。仅 mysql 函数表达式（如 `DATE_SUB(NOW(), INTERVAL N DAY)`、`JSON_EXTRACT()`）允许 whereRaw
- **`->change()` 注意点**：复杂列类型变更优先 `Schema::hasColumn` + drop + add 重建，避免 `change()` 翻译歧义

### 解耦原则

- 主系统**不**硬引用插件代码或表
- 主系统通过动态扫描兼容插件数据（如 `_logs` 后缀表 + `user_id` 字段）
- 插件可引用主系统的 Model、Service、Util

---
