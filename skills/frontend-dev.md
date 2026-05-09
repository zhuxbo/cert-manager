# 前端开发规范

## 技术栈

- **框架**: Vue 3 + TypeScript
- **UI**: Element Plus
- **状态管理**: Pinia
- **路由**: Vue Router
- **HTTP**: Axios
- **构建**: Vite
- **样式**: Sass + TailwindCSS
- **包管理**: pnpm 9+ (workspace)

## Monorepo 架构

```
frontend/
├── shared/     # 共享代码库
├── admin/      # 管理端应用
├── user/       # 用户端应用
└── base/       # 上游框架（只读）
```

### base 目录规则

- **只读** - 通过 git subtree 同步上游代码，不要修改
- 本地开发需执行 `cd base && pnpm install --ignore-workspace`

---

## 共享包 (shared)

使用 `@shared/*` 别名访问：

```typescript
// 组件
import { ReDialog } from "@shared/components/ReDialog";
import { ReRemoteSelect } from "@shared/components/ReRemoteSelect";
import { useRenderIcon } from "@shared/components/ReIcon";

// 工具函数
import { message, http, emitter } from "@shared/utils";

// 指令
import * as directives from "@shared/directives";
```

### 可用模块

| 别名                 | 内容                                                     |
| -------------------- | -------------------------------------------------------- |
| `@shared/components` | ReIcon, ReDialog, Auth, Perms, PureTableBar 等           |
| `@shared/utils`      | http, auth, message, fetchMeta, renderChannelDisabled 等 |
| `@shared/directives` | auth, perms, copy 等                                     |

### 启动期 Channel 检测

admin / user 应用启动时调 `/api/meta` 检测后端 channel 开关。channel 关闭时不挂载主应用，渲染 inline HTML 降级页：

```ts
import { fetchMeta, renderChannelDisabled } from "@shared/utils";

getPlatformConfig(app).then(async config => {
  const meta = await fetchMeta();
  if (meta && meta.channels.admin === false) {
    renderChannelDisabled("admin"); // 替换 #app innerHTML
    return; // 不走主应用初始化
  }
  // ... 原 setupStore / loadPlugins / mount 流程
});
```

`fetchMeta` 用原生 `fetch`（不依赖 `setupSharedModules`，避免循环初始化）。`/api/meta` 是匿名公开端点，返回 channels（4 项布尔）+ plugins（name/version 精简）+ version。失败（网络错 / 老版本无端点）→ 返回 null，调用方按"channels=true"默认放行。

### 依赖注入初始化

shared 模块使用依赖注入，需在应用启动时初始化。参考 `admin/src/utils/setup.ts`：

```typescript
import { createAuth, createHttp } from "@shared/utils";
import { setHasAuth } from "@shared/directives/auth";

// 初始化 Auth、Http 和权限指令
```

---

## 项目结构

```
src/
├── api/            # API 接口定义
├── assets/         # 静态资源
├── components/     # 公共组件
├── config/         # 配置文件
├── directives/     # 自定义指令
├── layout/         # 布局组件
├── plugins/        # 插件配置
├── router/         # 路由配置
├── store/          # 状态管理
├── style/          # 全局样式
├── utils/          # 工具函数
├── views/          # 页面组件
├── App.vue
└── main.ts
```

---

## 开发命令

```bash
# 在 monorepo 根目录运行
pnpm install          # 安装依赖

pnpm dev              # 同时启动 admin + user
pnpm dev:admin        # 仅管理端 (localhost:5173)
pnpm dev:user         # 仅用户端 (localhost:5174)

pnpm build            # 构建所有前端
pnpm build:admin      # 仅构建管理端
pnpm build:user       # 仅用户端

# 代码检查
pnpm lint:eslint      # ESLint
pnpm lint:prettier    # Prettier
pnpm lint:stylelint   # Stylelint
pnpm lint             # 全部检查
pnpm typecheck        # 类型检查
```

### Markdown 格式化

Prettier 原生支持 markdown（无需额外插件，解析器列表里有 `markdown|mdx`）。
项目根 `.prettierrc.js` 对所有 md 生效，prettier 装在 `frontend/admin/`。

```bash
# 仅本次 PR 改过的 md（推荐，避免修历史格式问题污染 PR）
git diff --name-only | grep "\.md$" | xargs npx --prefix frontend/admin prettier --write

# 单个 md 文件
npx --prefix frontend/admin prettier --write README.md

# 检查（不修改，只列报错文件）
npx --prefix frontend/admin prettier --check "**/*.md" --ignore-path .gitignore
```

Prettier 对 markdown 的处理：

- 表格列宽对齐（管道符纵向对齐）
- JSON 代码块多行展开（每属性一行）
- 编号列表项之间不留空行
- **不修改代码块内部**（fenced ` ``` ` / 缩进式 code block 保持原样；shell 脚本用 `shfmt` 单独处理，详见 CLAUDE.md "提交前格式化"）

---

## 配置

### Platform Config

`public/platform-config.json` 核心配置：

**管理端 (admin)**:

```json
{
  "BaseUrlApi": "http://localhost:5300/admin",
  "Brands": [
    "certum",
    "gogetssl",
    "positive",
    "geotrust",
    "digicert",
    "ssltrus",
    "trustasia"
  ]
}
```

**用户端 (user)**:

```json
{
  "BaseUrlApi": "http://localhost:5300",
  "Brands": ["certum", "gogetssl", "positive", "ssltrus", "trustasia"],
  "Beian": "豫ICP备123456789号"
}
```

---

## 开发规范

### 代码组织

- 按功能模块组织
- TypeScript 类型安全
- Vue 3 Composition API
- 单文件组件 (.vue)

### API 接口

- 统一 HTTP 请求封装
- 请求响应拦截器
- 错误统一处理
- 支持请求取消

### 状态管理

- Pinia 按模块划分
- 支持持久化存储

### 字典显示

- 表格中用 `options.find()?.label` 渲染字典值时，必须用 `?? row.xxx` 回落显示原值，防止插件卸载后字典不全导致空白
- 插件通过 `dictionaries` 机制（`mergePluginDictionaries`）在运行时追加 options 和 map

### 样式

- Sass 预处理器
- TailwindCSS 工具类
- 响应式设计
- 主题定制化

---

## 环境要求

- Node.js >= 18.18.0
- pnpm >= 9.0.0
