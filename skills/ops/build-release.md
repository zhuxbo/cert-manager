# 构建发布规范

## 目录结构

```
build/
├── build.sh              # 主构建脚本
├── release.sh            # 远程服务器发布
├── config.json           # 构建配置
├── build.env             # 构建环境变量
├── scripts/
│   ├── release-common.sh
│   ├── package.sh
│   ├── collect-artifacts.sh
│   └── container-build.sh
├── nginx/
├── web/
└── temp/                 # 临时目录（.gitignore）
```

---

## 版本发布

### 发布流程

1. 提交代码并推送
2. 构建并发布到远程服务器：`./build/release.sh <版本号>`
   - 正式版在 main 分支发布时自动创建/更新 tag 并 push
   - 测试版无需 tag

---

## 构建命令

```bash
# 构建所有模块（默认）
bash build/build.sh

# 指定版本构建
bash build/build.sh --version 0.2.1-beta

# 构建并打包
bash build/build.sh --version 0.2.1-beta --package

# 仅构建指定模块
bash build/build.sh api
bash build/build.sh admin
bash build/build.sh user

# 指定发布通道
bash build/build.sh --channel dev

# 强制重建（忽略缓存）
bash build/build.sh --force-build

# 清空依赖缓存后构建
bash build/build.sh --clear-cache
```

> **注意**：`release.sh` 内部会自动调用 `build.sh` 构建打包，无需手动先执行 `build.sh`。

---

## 打包

### 输出文件

| 文件                                | 说明                                                         |
| ----------------------------------- | ------------------------------------------------------------ |
| `ssl-manager-full-{version}.zip`    | 完整安装包（不含 vendor；宝塔脚本会运行 `composer install`） |
| `ssl-manager-upgrade-{version}.zip` | 升级包（不含 vendor，升级时保留现有 `backend/vendor`）       |
| `ssl-manager-script-{version}.zip`  | 部署脚本包（install.sh / upgrade.sh / scripts/）             |

> 包内 `manifest.json` 已弃用。包清单与 sha256 由 `release.sh` 上传时写入 release 站根目录的 `releases.json`（GitHub Release API 风格 + `assets[].sha256` 字段），install/upgrade 链路统一从该文件强校验。

### releases.json 字段（唯一真相源）

```json
{
  "releases": [
    {
      "tag_name": "v1.0.0",
      "name": "v1.0.0",
      "prerelease": false,
      "created_at": "2026-05-02T00:00:00+00:00",
      "published_at": "2026-05-02T00:00:00+00:00",
      "assets": [
        {
          "name": "ssl-manager-full-1.0.0.zip",
          "sha256": "...",
          "size": 57000000,
          "browser_download_url": "main/v1.0.0/ssl-manager-full-1.0.0.zip"
        },
        {
          "name": "ssl-manager-upgrade-1.0.0.zip",
          "sha256": "...",
          "size": 5500000,
          "browser_download_url": "main/v1.0.0/ssl-manager-upgrade-1.0.0.zip"
        },
        {
          "name": "ssl-manager-script-1.0.0.zip",
          "sha256": "...",
          "size": 32000,
          "browser_download_url": "main/v1.0.0/ssl-manager-script-1.0.0.zip"
        }
      ]
    }
  ]
}
```

`build/scripts/release-common.sh::generate_releases_update_script` 在 `release.sh` 上传 zip 后远程执行 Python 计算 sha256，合并入站点根的 `releases.json`；main/dev 通道分别按发布时间保留 `KEEP_VERSIONS` 条（默认各 5 条）。install.sh / bt-install.sh / upgrade.sh 下载产物后强校验，失败立即退出（不降级）。`latest`/`dev` 占位符通过 `_resolve_version`（depth 计数解析 release 块）映射到 `prerelease=false`/`prerelease=true` 的最新版本。

每台服务器完成上传、索引更新、脚本部署、latest 链接更新和旧目录清理后，`release.sh` 自动执行两层验收：先经 SSH 校验远程 `releases.json`、三个 zip 的大小/sha256、latest 链接及入口脚本，再从该服务器的公网 URL 下载索引和全部 zip 复算大小/sha256。任一目标服务器的任一校验失败，发布命令返回失败，不得只凭上传命令成功判定发布完成。

后台升级（PHP 端 `ReleaseClient`）同样 **fail-closed**：releases.json 缺 sha256 或下载产物不匹配时拒绝升级（不降级放行）；`validateReleaseUrl` 对下载 URL 做 SSRF 校验（https 放行 / 公网 http 拒绝 / 明文 http 仅放行 RFC1918 私网 + loopback，link-local 169.254 含云元数据 / CGNAT / 保留段拒绝），下载 curl/Http 重定向限 https + 限 5 跳，防「https 校验通过 → 302 降级到 http 内网」绕过。

### 开发文件排除（单一真相源）

打包排除规则的唯一真相源是 `build/config.json` 的 `exclude_patterns.backend`，由 `collect-artifacts.sh`（收集到 production-code）和 `package.sh`（生成 full/upgrade 包）两处共享——新增需排除的开发文件只改这一处，两道防线同时生效。已覆盖：IDE Helper 产物与 publish 配置（`_ide_helper.php` / `_ide_helper_models.php` / `.phpstorm.meta.php` / `config/ide-helper.php`——后者由 `require-dev` 的 `barryvdh/laravel-ide-helper` publish，生产 `--no-dev` 不装该包故冗余）、过程文档目录 `.superpowers/`、测试与工具配置（`tests/` / `phpunit.xml` / `phpstan.neon` / `.pint.json` / `.editorconfig`）、`.env` / `.env.testing` 等。**保留**：`.ssl-manager`（部署 marker，`upgrade.sh` 据此定位安装目录，勿排除）、`.env.example`（仅 full 包需要；upgrade 包按 `.env.*` 规则一并排除，不覆盖用户配置）。

### 手动打包

手动打包必须使用完整构建后的 `build/temp/production-code`。`package.sh` 会在打包前校验后端、前端和 nginx 关键产物，缺失时直接失败并清理半成品 zip。

```bash
# 使用默认 build/temp/production-code
./build/scripts/package.sh

# 指定生产代码目录和输出目录
./build/scripts/package.sh --source build/temp/production-code --output build/temp/packages
```

---

## 版本号管理

`version.json` 不在仓库中，构建时自动生成。

### 版本获取优先级

| 场景       | 优先级                 |
| ---------- | ---------------------- |
| release.sh | 命令行参数（必须指定） |
| GitHub CI  | git tag                |

### 本地开发

无 `version.json` 时，PHP 返回：`version=0.0.0-beta, channel=dev`

### SemVer 比较语义（三处必须对齐）

升级链路三处独立实现版本比较，行为必须一致：

| 位置                                              | 实现                                                         |
| ------------------------------------------------- | ------------------------------------------------------------ |
| `backend/app/Services/Upgrade/VersionManager.php` | `compareVersions()` 直接调 PHP 原生 `version_compare`        |
| `backend/app/Services/Upgrade/ReleaseClient.php`  | `getLatestRelease()` 用 `version_compare` 比完整版本号选最高 |
| `frontend/admin/src/views/upgrade/index.vue`      | `compareVersions()` 关键字优先级表 + 数字段整数比较          |
| `deploy/upgrade.sh::version_gt`                   | 纯 bash 拆主版本/预发布段，按整数 + 关键字优先级比较         |

约定：

- 数字段按整数大小（`beta.10 > beta.9`，**不能**字典序）
- 主版本相同时：正式版 > 预发布版
- 预发布关键字优先级：`dev < alpha < beta < rc < 正式版`
- 未知关键字保守归到最高（避免误判为旧版降级）
- **大小写不敏感**：`v/V` 前缀剥除、关键字（`Beta`/`BETA`/`beta`）等价 — PHP 端 `compareVersions` 入口 `strtolower` 标准化（version_compare 原生会把大写当未知映射为 `#`）；bash 用 `tr '[:upper:]' '[:lower:]'`；TS 用 `.toLowerCase()`

历史陷阱（**不要回滚**）：

- `sort -V`：GNU coreutils 8.32 把 `0.5.2-beta.10` 排在 `0.5.2` **之后**，违反 SemVer
- `strcmp(pre1, pre2)`：会判 `beta.10 < beta.9`（字典序）
- 后缀剥光对比（`stripPreReleaseSuffix`）：`beta.9` 和 `beta.10` 被剥成同一个版本号，"最新"取决于循环顺序

测试入口：

- PHP 单测：`backend/tests/Unit/VersionManagerTest.php`、`backend/tests/Unit/ReleaseClientTest.php`
- bash 单测：`bash deploy/test/test-version-gt.sh`（21 个 case，覆盖数字段递增 / 正式 vs 预发 / 关键字优先级 / v 前缀 / 边界）

---

## 远程发布

### 配置

```bash
cp build/release.conf.example build/release.conf
chmod 600 build/release.conf
```

配置示例：

```bash
SERVERS=(
    "cn,release-cn.example.com,22,/var/www/release,https://release-cn.example.com"
    "us,release-us.example.com,22,/var/www/release,https://release-us.example.com"
)
SSH_USER="release"
SSH_KEY="~/.ssh/release"
KEEP_VERSIONS=5
```

### 发布命令

```bash
# 发布到所有服务器（自动构建+打包+上传+更新 releases.json）
bash build/release.sh <版本号>

# 只发布到指定服务器
bash build/release.sh <版本号> --server cn

# 只上传（不重新构建，仍需版本号）
bash build/release.sh <版本号> --upload-only

# 测试连接
bash build/release.sh --test
```

> `release.sh` 完整流程：测试 SSH 连接 → 调用 `build.sh` 构建打包 → 上传 zip → 更新 `releases.json` → 部署 install.sh/upgrade.sh → 创建符号链接 → 清理旧版本

---

## version.json

构建自动生成，包含在安装/升级包中：

```json
{
  "version": "0.0.9-beta",
  "channel": "dev",
  "release_url": "https://release.example.com"
}
```

| 字段        | 说明                             |
| ----------- | -------------------------------- |
| version     | 当前版本号                       |
| channel     | main（正式）或 dev（开发）       |
| release_url | 自定义 release URL（升级时保留） |

---

## CI/CD

### GitHub Actions

| Workflow    | 触发条件     | 功能                     |
| ----------- | ------------ | ------------------------ |
| release.yml | 推送 v\* tag | 构建、打包、创建 Release |
| ci.yml      | PR/push      | 代码检查、构建测试       |

GitHub Release 仅用于代码存档，实际部署使用自建 release 服务。

---

## 定制构建

`build/custom/` 目录（不纳入版本控制）：

- `build.env` - 覆盖默认构建变量
- `config.json` - 覆盖默认配置
- `logo.svg` - 自定义默认 Logo
- `qrcode.svg` - 自定义默认二维码占位图

打包资产边界：`backend/storage/app` 是运行数据，构建工作区、产物汇总和完整包都必须排除并清空旧缓存；Web 根入口不携带默认 `favicon.ico`，站点图标只由后台 `site.favicon` 配置提供。前端 `src/assets` 中无引用的图片应删除，`public` 目录则只保留仍在使用的运行时回落资源。

二维码占位资产分包边界：完整包携带新版 `frontend/user/qrcode.svg`；升级包同时排除 `qrcode.svg` 和旧版 `qrcode.png`，由升级流程保留安装目录原有文件。这样旧安装继续使用 PNG，新安装继续使用 SVG，前端仅在后台未上传二维码时按 SVG → PNG 顺序回落。

---

## 快速发布指令

### 完整发布流程（推荐）

```bash
# 1. 提交代码
git add . && git commit -m "feat: 功能描述" && git push

# 2. 远程发布（构建 + 打包 + 部署到服务器）
# 正式版在 main 分支上会自动创建/更新 tag 并 push
./build/release.sh <版本号>
```

- **正式版**（不含 `-`）：在 main 分支发布时，脚本自动创建/更新 `v{版本号}` tag 并 push，无需手动操作
- **测试版**（含 `-`）：无需 tag，直接发布

### Tag 命名规范

- **必须带 `v` 前缀**：`v0.0.11-beta`、`v1.0.0`
- 不带 `v` 的 tag 应清理

---

## 数据库结构导出

`structure.json` 是主系统数据库标准结构，升级时用于校验和修复。

```bash
# 容器开发环境：在 compose MySQL 里开临时干净库导出，不碰开发库（详见 /db-structure）
make db-structure
```

- 导出命令自动排除插件迁移（`--path=database/migrations` 限制）
- 插件表由插件自身管理，不纳入主系统 `structure.json`
- 发布前确保 `structure.json` 是最新的

## 注意事项

- **不要并行执行多个构建任务**：同时运行多个 `build.sh` 会导致资源竞争和卡死
- **内存限制**：容器限制 2GB 内存，前端构建可能因 OOM 被 kill
- **构建顺序**：后端 → 管理端 → 用户端（串行，不可并行）
- **Worktree 无 git tag**：在 worktree 中构建需显式指定 `--version`，否则版本号为 `0.0.0-dev`
