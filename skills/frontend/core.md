# 前端开发规范

通用 UI、轮询和图表见 `ui.md`；菜单表格与内嵌表格见 `table.md`。

## 技术栈

- **框架**: Vue 3 + TypeScript
- **UI**: Element Plus
- **状态管理**: Pinia
- **路由**: Vue Router
- **HTTP**: Axios
- **构建**: Vite
- **样式**: Sass + TailwindCSS
- **包管理**: pnpm 11 (corepack + workspace)

## Monorepo 架构

```
frontend/
├── shared/     # 共享代码库
├── admin/      # 管理端应用
└── user/       # 用户端应用
```

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
| `@shared/hooks`      | `usePolling`, `useLazyVisible` 等                        |

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
- **不修改代码块内部**（fenced ` ``` ` / 缩进式 code block 保持原样；shell 脚本用 `shfmt` 单独处理）

---

## 配置

### Platform Config

`public/platform-config.json` 只保存部署与界面配置。`Title`、`AllBrands`、`Brands`、`DnsTools`、`Beian`、`CopyStart`、`Favicon`、`Logo`、`LogoExpanded`、`Qrcode`、`LoginImage` 由后台系统设置提供，admin/user 在完整刷新时分别通过 `/api/meta?channel=admin|user` 加载一次，不轮询。

后台配置归属：`site.name` 为两端共用标题；`site.dnsTools` 是可选的两端共用 DNS 工具普通数组，Seeder 不创建该设置，如需使用由管理员手工添加，配置后后端按数组顺序轮询，均未通过再使用本地实时检测。`site.beian/copyStart/favicon/logo/logoExpanded/qrcode` 为共用站点信息，其中 `copyStart` 不由 Seeder 创建，缺失或无效时版权起始年份回落 `2017`；`favicon` 仅接受 ICO，未配置时以空 data URL 阻止浏览器请求不存在的 `/favicon.ico`，不提供系统默认图标；`logo`、`logoExpanded`、`qrcode`、`loginImage` 使用 `image` 类型，普通 `logo` 与二维码锁定 1:1，`logoExpanded` 保持自由比例，留空时展开侧栏保持 `logo + site.name`；二维码留空时使用用户端公开目录的 `qrcode.png`，后台上传地址加载失败时不替换。`loginImage` 为用户端登录/注册/找回密码页左侧配图（原图免裁剪直传保留构图，超出 2048×2048 前端等比缩小，≤2MB，后端上限 2560×2560），已上传时整图 cover 展示且不叠加文字；留空回落 `public/login.svg`（升级保留，可被运营商替换）+ 标语，`login.svg` 缺失再降级主题色纯色面板 + 标语（见 user 端 `LoginAside.vue`）；admin 登录页为极简纯色底居中卡片，无配图。`brand.all` 是品牌值到显示名称的唯一词典，供产品维护和品牌展示；`brand.admin`、`brand.user` 是两端独立的活动品牌值普通数组，产品筛选严格保持对应数组顺序。

`public/platform-config.json` 核心配置：

**管理端 (admin)**:

```json
{
  "BaseUrlApi": "http://localhost:5300/admin",
  "StorageNameSpace": "admin-"
}
```

**用户端 (user)**:

```json
{
  "BaseUrlApi": "http://localhost:5300",
  "ResponsiveStorageNameSpace": "responsive-"
}
```

---

## 环境要求

- Node.js >= 22.13.0（pnpm 11 要求；vite 7 要 `^20.19 || >=22.12`，CI 用 Node 22）
- pnpm >= 11，本地 `corepack enable` 启用（按 `package.json` 的 `packageManager` 字段解析）

### pnpm 11 供应链安全（默认开启）

- **minimumReleaseAge**（默认拒 24h 内新发布的包）——**何时撞、何时不用管**：
  - 日常 `pnpm install --frozen-lockfile`（CI / 容器构建 / 部署）**永不撞**：锁定的版本都早过 24h
  - 主动升级时，**纯传递依赖**（未在 package.json 声明的）resolution 阶段自动回落到 cutoff 前版本（如 electron-to-chromium 1.5.368→1.5.367），无感；但**直接依赖**（package.json 显式声明）pnpm 按你的 range 选 caret 内最新、**不回落**，撞到它当天发版才需处理
  - 处理：**最省事是等一天**（cutoff 滚动，今天发的明天必合规）；急则 `override` 钉稳定版 + `minimumReleaseAgeExclude` 豁免（注意 override 在校验**之后**应用、单独救不了，须二者配合：实际装合规版、豁免只绕验证时机）。**预防**：升级直接依赖别用 `--latest` 盲追当天最新
- **allowBuilds**（build 脚本白名单，取代 `onlyBuiltDependencies`）：依赖的 postinstall/native build 须在 `pnpm-workspace.yaml` 的 `allowBuilds` 显式 `true`，否则被忽略（如 `esbuild` binary 不装致 vite build 挂）。当前已批准 `@parcel/watcher`、`esbuild`
- **切版本首次 install** 在无 TTY 环境（脚本/后台）需设 `CI=true`，否则报 `ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY`（GitHub Actions 自带）
