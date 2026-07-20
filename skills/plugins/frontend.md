# 插件前端开发规范

## IIFE 打包

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

## 开发测试

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

## 依赖锁定（pnpm workspace 注意）

根 `pnpm-workspace.yaml` 的 `packages` 只含 `frontend/{shared,admin,user}`，**不含 `plugins/`**。插件前端是 workspace 之外的独立项目：

- 在插件子目录直接 `pnpm install` 会被根 workspace「劫持」（去装主前端依赖、忽略插件本身），**既不生成也不更新插件自己的 `pnpm-lock.yaml`**（`git status` 看不到新 lock，易误以为已锁定）
- 生成或更新插件 lock 必须加 `--ignore-workspace`：

```bash
pnpm -C plugins/{name}/frontend/admin install --ignore-workspace
pnpm -C plugins/{name}/frontend/user  install --ignore-workspace
```

- 每个有 `package.json` 的插件前端子项目都应提交对应 `pnpm-lock.yaml`（锁定依赖、CI/他人构建可复现）。`build.json` 的 `exclude` 已含 `pnpm-lock.yaml`，不打入发布 zip
- 各子项目依赖不同（notice 极简、invoice 含 `@pureadmin/*`），lock **不可跨子项目复用**，须按各自 `package.json` 生成

## 共享依赖

主系统通过 `exposeSharedDeps()` 暴露以下全局依赖：

- `vue`、`vue-router`、`element-plus`、`pinia`
- `@shared/utils`（getAccessToken 等）

插件 `vite.config.ts` 中通过 `external` 和 `globals` 引用，不重复打包。

## 全局注册的组件

主系统在 `main.ts` 中全局注册了以下组件，插件可直接在模板中使用（无需 import）：

| 组件                        | admin | user | 说明                             |
| --------------------------- | :---: | :--: | -------------------------------- |
| `PureTableBar`              |  ✅   |  ✅  | 表格工具栏（列显隐、刷新、全屏） |
| `ReRemoteSelect`            |  ✅   |  -   | 远程搜索选择器                   |
| `Auth` / `Perms`            |  ✅   |  ✅  | 权限控制                         |
| `IconifyIconOffline/Online` |  ✅   |  ✅  | 图标                             |

插件中使用全局组件时，通过 `resolveComponent()` 动态解析（TSX 中）或直接在 `<template>` 中使用。

菜单表格与内嵌表格的组件选择、尺寸约定见 `../frontend/table.md`。

## 样式注意事项（重要）

### 1. 禁止在插件中导入 plus-pro-components 的 CSS

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

### 2. 不要使用非常规 Tailwind class

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

### 3. 插件路由和 API 路径

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

### 4. IIFE 插件中不能使用 useRoute/useRouter

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

## 加载机制

1. 公共接口 `GET /api/plugins` 返回已安装插件的 bundle/css 路径
2. `plugin-loader.ts`（`@shared/utils/plugin-loader`）动态加载 IIFE 脚本
3. 校验 URL 必须以 `/` 开头（防止加载外部资源）

## Widget 插槽

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

## 词典扩展

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
