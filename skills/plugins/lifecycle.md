# 插件测试、发布与生命周期

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

页面只展示执行中和失败的插件任务，成功安装/更新只刷新插件列表、不保留成功记录。安装/更新任务失败后，同插件会被失败记录阻塞，避免重复创建安装任务；管理员可在失败记录上选择「重试」重新入队。失败安装可选择「卸载」清理记录；失败更新可选择「取消」，只删除失败任务并保留更新回滚后的当前插件版本。

卸载选择“完全清除”时，`PluginManager` 重置该插件路径下所有已执行迁移，并执行可选 Seeder `clear()` 钩子。迁移重置或 Seeder 清理失败都必须 fail-closed：抛出错误、中止目录删除并保留插件文件供重试，禁止吞掉异常后返回“数据已清除”。

新插件包应随包携带与 `composer.lock` 对齐的 `backend/vendor`，安装/更新会直接校验并复用，不再联网执行 Composer。仅兼容没有随包 vendor 的历史插件包时才运行 `composer install --no-dev --no-interaction --optimize-autoloader --no-scripts`；`PluginComposerRunner` 会为该兼容路径显式设置 `HOME`、`COMPOSER_HOME` 和 `COMPOSER_CACHE_DIR` 到 `storage/app/plugin-composer`，并在安装成功后原子刷新 `vendor/composer/.ssl-manager-lock.sha256`，不要依赖队列/FPM 环境自带 HOME。插件 `post-autoload-dump` 钩子也会在手工执行 `composer install`、`update` 或 `dump-autoload` 后刷新 marker。

插件安装/更新在包校验和下载完成后获取 `backend/.upgrade-bootstrap.lock` 独占锁，再发布插件文件、运行迁移/Seeder 并清理缓存；HTTP 请求从加载主系统 autoload 前到请求结束持共享锁。成功终局或失败回退完成后才释放独占锁，避免请求观察到半安装、半更新或暂时缺失的插件目录。

在线插件包下载的 `PLUGIN_DOWNLOAD_TIMEOUT` 是单个下载器的上限，默认 120 秒：先由 curl 尝试，失败或超时后清理半包，再给 PHP HTTP 客户端完整 120 秒回退。默认插件任务总超时为 720 秒，数据库、Redis、Beanstalkd 队列的默认 `retry_after` 为 900 秒；自定义这些值时，任务预算必须覆盖两次下载、兼容 Composer、迁移/Seeder/回滚及固定余量，队列可见性超时还必须大于任务超时与安全余量之和。

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
