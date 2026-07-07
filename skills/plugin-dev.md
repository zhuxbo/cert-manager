# 插件开发规范

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
- 表名建议加插件前缀（如 `{name}_logs`）避免冲突
- 卸载时可选回滚迁移（`remove_data=true`）
- **插件表独立管理**：主系统 `db:structure --export` 通过 `--path=database/migrations` 排除插件迁移，`structure.json` 仅包含主系统表
- **从主系统迁移分离时注意**：如果原来某些表在主系统迁移文件中，拆分到插件时必须确保主系统迁移文件仍保留主系统自己的表（不能整个删除包含多张表的迁移文件）

### MySQL 兼容性（与主系统同等约束）

插件作为生产代码，跟主系统执行同一套 MySQL 5.7+ 兼容规则。详见 `skills/backend-dev.md` "MySQL 兼容性" 章节，插件特定要点：

- **迁移禁用 `->json()` 列类型**：用 `->text()` 列 + Model `protected $casts = ['col' => 'array']`。理由：MySQL 5.7 对 `->json()` 索引和默认值支持有限，统一走 text + array cast 最稳妥。Model 不要用 `'json'` cast，统一用 `'array'`
- **优先 Eloquent / Query Builder**：避免 `DB::raw` / `DB::statement` / `whereRaw`。仅 mysql 函数表达式（如 `DATE_SUB(NOW(), INTERVAL N DAY)`、`JSON_EXTRACT()`）允许 whereRaw
- **`->change()` 注意点**：复杂列类型变更优先 `Schema::hasColumn` + drop + add 重建，避免 `change()` 翻译歧义

### 解耦原则

- 主系统**不**硬引用插件代码或表
- 主系统通过动态扫描兼容插件数据（如 `_logs` 后缀表 + `user_id` 字段）
- 插件可引用主系统的 Model、Service、Util

---

## 前端开发

### IIFE 打包

插件前端打包为 IIFE 格式，运行时由主系统动态加载。

```typescript
// admin/src/index.ts
const routes = [
  {
    path: "/plugin-{name}/list",
    name: "Plugin{Name}List",
    component: () => import("./views/list/index.vue"),
    meta: { title: "插件列表", icon: "ep:list" }
  }
];
export { routes };
```

### 开发测试

插件前端打包为 IIFE，没有热更新。开发流程：

- **后端**：改完 PHP 代码直接生效，无需额外操作
- **前端**：每次修改后需重新构建 IIFE，然后刷新浏览器

```bash
# 构建单端（在插件前端目录下）
# install 必须加 --ignore-workspace（插件不在根 workspace 内，详见下方「依赖锁定」）
cd plugins/{name}/frontend/admin && pnpm install --ignore-workspace && pnpm build

# 或构建整个插件（admin + user）
bash plugins/release-plugin.sh {name} --version x.y.z --build-only
```

**构建产物不入库（方案 B）+ 开发环境静态资源映射**：插件前端构建产物**一律不入 git**（`*/frontend/{admin,user}/*.{iife.js,css}` 已加入 `plugins/.gitignore`），dev 与 release 都现构建：

- **新 clone 后**：先跑一次 `make plugins-build`（遍历所有插件 `pnpm install --ignore-workspace && pnpm build`，产物输出到各自 `dist/`），否则前端控制台报 `[PluginLoader] Failed to load plugin`（404）
- **改了插件 src 后**：重跑该插件 `pnpm build`（或 `make plugins-build`）+ 主应用**硬刷新**（`<script>` 加载无 cache busting，**无 HMR**）
- `servePlugins()` 中间件把请求的扁平路径映射到 `dist/{file}` 取产物（`plugin.json` bundle 路径不含 `dist/`）；**dist 不存在即返回 `503` + 终端 `[servePlugins]` warn 提示 `make plugins-build`**，不再静默 404 让人误判为路径/插件坏了。生产由 Nginx serve 安装包内的扁平产物

> **为什么不入库**：入库构建产物会与 src **漂移**——`pnpm build` 只更新 `dist/`、不碰扁平副本，dev 又优先用 dist 显示「正常」，改 src 后极易忘记同步且无感（CI 也不构建插件前端、不会拦下）。而扁平副本对生产无用（`PluginManager` 装 release zip，包内产物由 `release-plugin.sh` 打包时现构建），纯属 dev 便利。故统一「不入库、现构建」，从源头消除漂移。

**版本号不入仓库**：`plugin.json` 源文件不含 `version` 字段，由 `release-plugin.sh --version x.y.z` 在打包时动态注入到临时副本。开发环境下 `PluginManager` 读取时回落为 `0.0.0`。

### 依赖锁定（pnpm workspace 注意）

根 `pnpm-workspace.yaml` 的 `packages` 只含 `frontend/{shared,admin,user}`，**不含 `plugins/`**。插件前端是 workspace 之外的独立项目：

- 在插件子目录直接 `pnpm install` 会被根 workspace「劫持」（去装主前端依赖、忽略插件本身），**既不生成也不更新插件自己的 `pnpm-lock.yaml`**（`git status` 看不到新 lock，易误以为已锁定）
- 生成或更新插件 lock 必须加 `--ignore-workspace`：

```bash
pnpm -C plugins/{name}/frontend/admin install --ignore-workspace
pnpm -C plugins/{name}/frontend/user  install --ignore-workspace
```

- 每个有 `package.json` 的插件前端子项目都应提交对应 `pnpm-lock.yaml`（锁定依赖、CI/他人构建可复现）。`build.json` 的 `exclude` 已含 `pnpm-lock.yaml`，不打入发布 zip
- 各子项目依赖不同（notice 极简、invoice 含 `@pureadmin/*`），lock **不可跨子项目复用**，须按各自 `package.json` 生成

### 共享依赖

主系统通过 `exposeSharedDeps()` 暴露以下全局依赖：

- `vue`、`vue-router`、`element-plus`、`pinia`
- `@shared/utils`（getAccessToken 等）

插件 `vite.config.ts` 中通过 `external` 和 `globals` 引用，不重复打包。

### 全局注册的组件

主系统在 `main.ts` 中全局注册了以下组件，插件可直接在模板中使用（无需 import）：

| 组件                        | admin | user | 说明                             |
| --------------------------- | :---: | :--: | -------------------------------- |
| `PureTableBar`              |  ✅   |  ✅  | 表格工具栏（列显隐、刷新、全屏） |
| `ReRemoteSelect`            |  ✅   |  -   | 远程搜索选择器                   |
| `Auth` / `Perms`            |  ✅   |  ✅  | 权限控制                         |
| `IconifyIconOffline/Online` |  ✅   |  ✅  | 图标                             |

插件中使用全局组件时，通过 `resolveComponent()` 动态解析（TSX 中）或直接在 `<template>` 中使用。

### 样式注意事项（重要）

#### 1. 禁止在插件中导入 plus-pro-components 的 CSS

```typescript
// ❌ 错误：会打包 183KB 的 Element Plus 完整 CSS，与主系统样式冲突
import "plus-pro-components/es/components/search/style/css";
import "plus-pro-components/es/components/drawer-form/style/css";

// ✅ 正确：只导入组件本身，CSS 由主系统全局提供
import { PlusSearch, PlusDrawerForm } from "plus-pro-components";
```

**原因**：`plus-pro-components/es/components/*/style/css` 会级联导入完整的 Element Plus 组件 CSS（含 `:root` 变量、Drawer header/footer 边框等），与主系统已加载的 CSS 产生冲突，导致样式覆盖（如抽屉出现边框、表单间距异常）。

主系统已在 `main.ts` 中全局加载了 `PlusSearch` 的 CSS：

```typescript
import "plus-pro-components/es/components/search/style/css";
```

#### 2. 不要使用非常规 Tailwind class

插件目录不在主系统 Tailwind 的 `content` 扫描范围内，因此**插件模板中使用的 Tailwind class 必须在主系统其他页面中也有使用**，否则不会生成对应 CSS。

```html
<!-- ✅ 安全：这些 class 在主系统中广泛使用 -->
<div class="bg-bg_color w-[99/100] pl-4 pr-4 pt-[24px] pb-[12px]">
  <!-- ❌ 危险：p-6、mb-3、gap-12 等可能不在主系统 CSS 中 -->
  <div class="p-6 mb-3 gap-12">
    <!-- ✅ 推荐：对不确定的样式使用内联 style -->
    <div style="padding: 20px 24px; margin-bottom: 12px"></div>
  </div>
</div>
```

**经验法则**：布局类的 padding/margin/gap 如果值不常见，优先用内联 `style`。

#### 3. 插件路由和 API 路径

**后端路由**：主系统 RouteServiceProvider 统一加 `api/` 前缀。admin 路由文件内部自带 `Route::prefix('admin')`，user 路由文件**没有** prefix。插件路由同理：

```php
// admin 路由 → /api/admin/invoice
Route::prefix('api/admin')->middleware(['global', 'api.admin'])->group(...);

// user 路由 → /api/invoice（没有 /user/）
Route::prefix('api')->middleware(['global', 'api.user'])->group(...);
```

**前端 API**：admin 端 http 自动带 `/admin` 前缀，user 端**不带**额外前缀，路径直接对应后端路由：

```typescript
// admin 端
http.get("/invoice", { params }); // → GET /api/admin/invoice

// user 端（不加 /user/）
http.get("/invoice", { params }); // → GET /api/invoice
```

#### 4. IIFE 插件中不能使用 useRoute/useRouter

`vue-router` 被 external 后，`useRoute()`/`useRouter()` 因 Symbol 注入不匹配会返回 undefined。必须通过组件实例获取：

```typescript
import { getCurrentInstance } from "vue";

// ❌ 错误：IIFE 插件中 Symbol 不匹配，生产环境报错
import { useRoute } from "vue-router";
const route = useRoute();

// ✅ 正确：通过组件实例的全局属性获取
const instance = getCurrentInstance();
const route = instance?.appContext.config.globalProperties.$route;
const router = instance?.appContext.config.globalProperties.$router;
```

### 加载机制

1. 公共接口 `GET /api/plugins` 返回已安装插件的 bundle/css 路径
2. `plugin-loader.ts`（`@shared/utils/plugin-loader`）动态加载 IIFE 脚本
3. 校验 URL 必须以 `/` 开头（防止加载外部资源）

### Widget 插槽

插件可以通过 `widgets` 向主系统已有页面注入组件（如 Dashboard 横幅）：

```typescript
window.__registerPlugin({
  name: "my-plugin",
  widgets: [{ slot: "user-dashboard-top", component: MyBanner, order: 0 }]
});
```

- `slot`：插槽名称，主系统在页面中预埋渲染点
- `component`：Vue 组件，在主系统 Vue 树内渲染（共享 Element Plus 主题和深色模式）
- `order`：排序权重，越大越靠前（默认 0）

已定义的插槽：

| 插槽名               | 位置                  | 说明                       |
| -------------------- | --------------------- | -------------------------- |
| `user-dashboard-top` | 用户端 Dashboard 顶部 | 欢迎信息上方，适合公告横幅 |

主系统通过 `getPluginWidgets(slot)` 获取并渲染插件组件。使用 widgets 的插件需要设置版本兼容（release 中 `requires` 字段），确保主系统已支持对应插槽。

### 词典扩展

插件通过 `dictionaries` 注册字典扩展，按命名空间组织：

```typescript
window.__registerPlugin({
  name: "my-plugin",
  dictionaries: {
    funds: {
      fundPayMethodOptions: [{ label: "新支付方式", value: "new_pay" }],
      fundPayMethodMap: { new_pay: "success" }
    },
    transaction: {
      transactionTypeOptions: [{ label: "新类型", value: "new_type" }],
      transactionTypeMap: { new_type: "info" }
    }
  }
});
```

可用命名空间（admin 端全部可用，user 端不含 task、notificationRecord、notificationTemplate）：

| 命名空间               | 词典文件                                 | 常用可扩展字段                                                                  |
| ---------------------- | ---------------------------------------- | ------------------------------------------------------------------------------- |
| `funds`                | `views/funds/dictionary`                 | `fundPayMethodOptions`、`fundPayMethodMap`、`fundTypeOptions`、`fundTypeMap`    |
| `transaction`          | `views/transaction/dictionary`           | `transactionTypeOptions`、`transactionTypeMap`                                  |
| `order`                | `views/order/dictionary`                 | `channelOptions`、`channel`、`channelType`、`productTypeOptions`、`productType` |
| `system`               | `views/system/dictionary`                | `brandOptionsAll`、`productTypeOptions`、`productTypeLabels`                    |
| `task`                 | `views/task/dictionary`                  | `actionLabels`、`actionTypes`、`statusLabels`、`statusTypes`                    |
| `notificationRecord`   | `views/notification/record/dictionary`   | `statusOptions`、`multilineFields`                                              |
| `notificationTemplate` | `views/notification/template/dictionary` | `statusOptions`                                                                 |

合并规则：数组用 `push` 追加，对象用 `Object.assign` 合并。

---

## 测试与 CI

### 测试目录

插件测试放 `plugins/{name}/backend/tests/`，结构与主系统一致（Pest + `Feature/Unit` 子目录）。命名空间通过主系统 `tests/` 的自动加载链可用。

### CI 矩阵

每个插件在 `.github/workflows/ci.yml` 拥有独立 job（`backend-{name}-plugin-test`），单 mysql 5.7 + redis service：

- 复用主系统的 `.env`（与 `backend-core-test` 一致）
- 跑 `php artisan migrate --force`（包括插件迁移），再跑 `php artisan test --parallel ../plugins/{name}/backend/tests`

### 无自带 tests 的插件

插件没有业务逻辑测试时（如 invoice 仅作 CRUD）仍要进 CI，验证迁移能跑通：

- migrate step 之后用 `php artisan tinker --execute="..."` 检查关键表已创建
- 不跑 `artisan test`（指向不存在的目录会 fail）
- 参考 `backend-invoice-plugin-test`

### 加新插件 → CI 增量

1. 复制 `backend-easy-plugin-test` job，把名字、tests 路径替换成新插件
2. 通过 yml 语法和 plugin migration 的兼容性

---

## 构建与发布

### 构建

```bash
# 仅构建打包（产物在 plugins/temp/）
bash plugins/release-plugin.sh {name} --build-only

# 构建 + 本地发布
bash plugins/release-plugin.sh {name} --local

# 构建 + 远程发布
bash plugins/release-plugin.sh {name} --remote

# 远程发布到指定服务器
bash plugins/release-plugin.sh {name} --remote --server cn
```

### build.json

定义哪些文件打入 zip：

```json
{
  "include": [
    "plugin.json",
    "backend/",
    "admin/{name}-plugin.iife.js",
    "admin/{name}-plugin-admin.css",
    "user/{name}-plugin.iife.js",
    "web/",
    "nginx/"
  ],
  "exclude": [
    "node_modules/",
    "src/",
    "*.config.ts",
    "package.json",
    "pnpm-lock.yaml"
  ]
}
```

### 发布配置

配置文件查找优先级：`plugins/*.conf` → `build/*.conf`（回落）

### 更新地址

系统检查更新时按以下优先级获取 `releases.json`：

1. `plugin.json.release_url`（第三方插件自定义）
2. `{主系统 release_url}/plugins/{name}`（官方插件）

---

## 安装/更新/卸载

### 管理面板

系统管理 → 插件管理页面操作。

页面只展示执行中和失败的插件任务，成功安装/更新只刷新插件列表、不保留成功记录。安装/更新任务失败后，同插件会被失败记录阻塞，避免重复创建安装任务；管理员需在失败记录上选择「重试」重新入队，或选择「卸载」清理失败安装记录。

带 `backend/composer.json` 的插件安装/更新时会在插件 `backend/` 目录内运行 `composer install --no-dev --no-interaction --optimize-autoloader --no-scripts`。`PluginComposerRunner` 会为 Composer 子进程显式设置 `HOME`、`COMPOSER_HOME` 和 `COMPOSER_CACHE_DIR` 到 `storage/app/plugin-composer`，不要依赖队列/FPM 环境自带 HOME。

### API

| 操作       | 端点                                  | 参数                                                 |
| ---------- | ------------------------------------- | ---------------------------------------------------- |
| 已安装列表 | `GET /api/admin/plugin/installed`     | -                                                    |
| 检查更新   | `GET /api/admin/plugin/check-updates` | -                                                    |
| 安装       | `POST /api/admin/plugin/install`      | `name`, `release_url?`, `version?` 或 `file`（上传） |
| 更新       | `POST /api/admin/plugin/update`       | `name`, `version?`                                   |
| 卸载       | `POST /api/admin/plugin/uninstall`    | `name`, `remove_data?`                               |

### 手动安装

```bash
cd plugins && unzip {name}-plugin-0.0.1.zip
cd ../backend
php artisan migrate --path=../plugins/{name}/backend/migrations --force
php artisan route:clear && php artisan config:clear
```

---

## 安全机制

- autoload 使用 `realpath()` 防止路径遍历
- ZIP 解压前检查所有条目，拒绝含 `..` 的路径
- 公共端点仅返回 bundle/css 路径，管理端返回完整信息
- plugin-loader 校验 URL 必须以 `/` 开头
- 插件包 sha256：`PluginManager` 安装/更新时若 `release.json` 提供 sha256 则强校验（verify-if-present）；下载入口 `validateReleaseUrl` 对**最终下载 URL**做 SSRF 校验（https 放行 / 公网 http 拒绝 / 明文 http 仅放行 RFC1918 私网 + loopback，**link-local 169.254（含云元数据 169.254.169.254）/CGNAT/保留段一律拒绝**，由 `isPrivateOrLoopbackIp` 判定）。下载 curl/Http 重定向限 `--proto-redir =https` + 限 5 跳（Guzzle `allow_redirects.protocols=['https']`），防「校验通过的 https → 302 降级到 http 内网/元数据」绕过
- 插件可自注册限流中间件：`easy` 插件的 `EasyRateLimiter` 对其公开回调/简易开票端点限流（中间件别名插件内自注册，参考 `invoice` 插件）

---

## 内置插件参考

新增插件可对照以下内置实现：

| 插件               | 特点                                                                                                                                                                                     | 适合参考                                                           |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `plugins/notice`   | 单表 CRUD（公告），用户/管理端基本对称，自带 Pest 测试 + Factory                                                                                                                         | 最小可用插件骨架                                                   |
| `plugins/invoice`  | 双端 CRUD（发票）+ 配额服务 + 外部开票方接入（`/api/invoice/external/{pending,complete}`，token+IP 鉴权）+ Admin 配置面板（storage 文件 + Crypt 加密 token）                             | 对外接口 + 中间件别名插件内自注册 + 跨插件被 `easy` 软依赖         |
| `plugins/easy`     | 复杂度最高：多回调控制器、log handler 接入主系统、产品级别映射；简易开票（独立 web 静态页 `invoice.html`，tid+email 鉴权，class_exists 软依赖 invoice 插件）                             | 涉及 Callback / 日志处理 / 跨模型关联 / 跨插件软依赖               |
| `plugins/api-docs` | **纯前端插件**（无 backend / 无迁移 / 无 CI job，仅 user 端）：iframe(srcdoc) 内嵌 Scalar 官方 standalone 渲染对外 API 文档；spec 由主系统 `/api/meta/api-doc` 提供、iframe 内同源 fetch | 纯前端插件骨架 + 第三方重型库 iframe 隔离 + Scalar Shadow DOM 定制 |

各插件的 ServiceProvider `boot()` 同时调 `loadRoutesFrom`（admin / user / api / callback 视需要）+ `loadMigrationsFrom`，主系统 `php artisan migrate` 自动覆盖。

### 纯前端插件（api-docs 范例）

`api-docs` 无 `backend/`，纯前端接入对外 API 文档（Scalar 渲染），要点：

- **无后端也能加载**：`PluginServiceProvider` 仅在存在 `backend/` 时注册命名空间/provider，`boot()` 对 `provider=null` 跳过，故纯前端插件正常加载、`/api/plugins` 仍返回 bundle 路径。无迁移、无 CI job。
- **重型库进插件 + iframe 隔离**：Scalar（~1MB JS）用官方 standalone bundle —— `vite.config.ts` 的 `closeBundle` hook 把 `node_modules/@scalar/api-reference/dist/browser/standalone.js` 复制到 `dist/scalar-standalone.js`（**不放 package.json `build`**：`make plugins-build` 直接 `exec vite build` 绕过 package.json 脚本，cp 只挂 package.json 时它不产出 standalone、文档页 404；放 closeBundle 则 `make plugins-build`（exec vite build）与 `release-plugin.sh`（`pnpm build`）两条路径都触发）；外壳 IIFE 仅 ~1KB（external vue），页面用 `<iframe srcdoc>` 加载 standalone。好处：CSS 完全隔离、按需加载（打开才载）、布局 Scalar 原生。`release-plugin.sh` 打插件 zip 时 `cp frontend/{side}/dist/*`，`scalar-standalone.js` 随包。
- **重型产物 `scalar-standalone.js` ~3.5MB**：vite 构建（`closeBundle`）把它拷进 `dist/`、`release` 打包时进包。与所有插件一样产物不入库（见上方「构建产物不入库（方案 B）」），`make plugins-build` 会一并构建，无需单独处理。
- **iframe `servers` 须用 surface→路径映射**：Scalar `config.servers` 覆盖 spec 的 servers，ApiDocs.vue 显式拼绝对 server URL 时**不能假设 surface 名 == 路径段**（acme 的对外路径是 `/api/v2/acme` 而非 `/api/acme`）——用 `SERVER_PATH` 映射表（须与各 yaml `servers.url` 一致），否则 Server/Test Request 打到废弃路径。
- **iframe srcdoc 三个坑**：① srcdoc 的 base 是 `about:srcdoc`、`location.origin` 可能为 `"null"`，spec 的相对 server 会拼成 null（test request 地址 null）→ **父页拼好绝对 url + 显式 `servers`** 传入。② 高度：Pure Admin 用 `el-scrollbar` 内部滚动、`documentElement` 不滚 → 向上找真正滚动祖先测 `scrollHeight-clientHeight` 扣除，避免高出页脚。③ Scalar 渲染在 **Shadow DOM**，外层 CSS/JS 穿不透 → 隐藏 Introduction 用 Scalar `customCss`（注入 shadow）+ JS 递归穿 `shadowRoot` 按文本隐藏侧栏项。
- **开发期识别**：`compose.yaml` 把 `./plugins` 挂到 `/var/plugins`（= 容器内 `base_path('../plugins')`，注意 `/var/www` 父目录是 `/var`），`make restart` 后 `/api/plugins` 才返回插件；user dev 的 `servePlugins` 中间件把 `/plugins/{name}/frontend/user/*` 映射到宿主 `dist`。
