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
   - 正式版先按 `skills/remote-release.md` 在当前 main fingerprint 完成全部 6 个精确 mutation 分片的加权汇总；可复用有效分片缓存，但 `release.sh` 只接受当前 fingerprint 的汇总 gate 证据，验证后才创建/更新 tag 并 push
   - 测试版无需 tag

### 首次启用请求排空锁的兼容边界

- 首个包含 `SSL_MANAGER_BOOTSTRAP_LOCK_V1` 的版本，其用户可见升级说明必须明确：从不含该入口锁标记的历史版本升级，只支持使用该版本随附的新版 `upgrade.sh` 完成首次跨越；旧版本后台 Web UI 不支持这一首跳。
- 首次跨越完成后，后续版本可继续使用后台 Web UI 或 `upgrade.sh` 升级。
- “桥接版本”目前只是备选设计，不得在发布说明中描述为现成功能；若未来实现，除入口 marker 外还必须建立持久化 draining 状态并完成旧请求排空。

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

前端增量构建指纹同时覆盖 admin/user 源码、shared 共享源码，以及根
`package.json`、`pnpm-workspace.yaml`、`pnpm-lock.yaml`、`.npmrc`。任一依赖清单、
锁文件或 pnpm workspace 配置变化都必须重新构建前端 `dist`，不能只重新安装依赖后复用旧产物。

---

## 打包

### 输出文件

| 文件                                | 说明                                                             |
| ----------------------------------- | ---------------------------------------------------------------- |
| `ssl-manager-full-{version}.zip`    | 完整安装包（含已锁定的生产 vendor，目标机无需联网安装 PHP 依赖） |
| `ssl-manager-upgrade-{version}.zip` | 升级包（含已锁定的生产 vendor，兼容历史无 vendor 包）            |
| `ssl-manager-script-{version}.zip`  | 部署脚本包（install.sh / upgrade.sh / scripts/）                 |

> 包内 `manifest.json` 已弃用。包清单与 sha256 由 `release.sh` 上传时写入 release 站根目录的 `releases.json`（GitHub Release API 风格 + `assets[].sha256` 字段），install/upgrade 链路统一从该文件强校验。

### Vendor 长期发布策略

完整安装包和升级包长期携带由同一份 `composer.lock` 生成的生产
`backend/vendor`；插件存在 `backend/composer.json` 时，其发布包同样必须携带配套的
`composer.lock` 与 `backend/vendor`。这不是临时兼容措施，后续版本不得恢复为在目标服务器
在线解析生产依赖。

原因：国内 Composer 镜像同步存在不可控延迟，目标服务器访问官方 Packagist 或 GitHub 也可能
失败；若安装或升级阶段再解析依赖，同一版本会因时间、镜像和网络环境得到不同结果，甚至在代码
已覆盖后留下半成品 vendor。构建阶段统一解析并打包 vendor，可让发布包成为可重复、可审计的完整
运行快照，也使目标服务器在无外网时仍能完成安装、升级与回滚。

构建和消费端必须共同遵守以下不变量：

- `composer.json`、`composer.lock`、`vendor` 三者配套出现，不允许只打包其中一部分；
- `vendor/composer/.ssl-manager-lock.sha256` 必须等于对应 `composer.lock` 的 SHA-256；
- 安装、Shell 升级、后台升级和插件安装/更新必须在覆盖现有代码前校验该标记；
- Composer 联网安装仅用于兼容历史上不含 vendor 的旧发布包，不是新包的正常路径。

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

打包排除规则的唯一真相源是 `build/config.json` 的 `exclude_patterns.backend`，由 `container-build.sh`（构建工作区）、`collect-artifacts.sh`（收集到 production-code）、`package.sh`（生成 full/upgrade 包）和 GitHub Release 共享。已覆盖：IDE Helper 产物与 publish 配置（`_ide_helper.php` / `_ide_helper_models.php` / `.phpstorm.meta.php` / `config/ide-helper.php`——后者由 `require-dev` 的 `barryvdh/laravel-ide-helper` publish，生产 `--no-dev` 不装该包故冗余）、过程文档目录 `.superpowers/`、测试与工具配置（`tests/` / `scripts/` / `phpunit.xml` / `phpstan.neon` / `.pint.json` / `.editorconfig`）、`.env` / `.env.testing`，以及 `storage/app`、`storage/databak`、`storage/pay`、`storage/temp-certs`、Laravel 缓存等机器运行数据。构建工作区和 production-code 在同步前还会清空旧 `storage` / `bootstrap/cache`，避免 rsync 排除项残留；`audit-package.sh` 对三个 zip 做最终失败即停审计。**保留**：`.ssl-manager`（部署 marker，`upgrade.sh` 据此定位安装目录，勿排除）、`.env.example`（仅 full 包需要；upgrade 包按 `.env.*` 规则一并排除，不覆盖用户配置）、`storage/domain-rules/public_suffix_list.dat`（运行时离线规则）。

运行文件排除不等于删除目录契约：full 包必须保留空的 `bootstrap/cache`、`storage/{logs,framework/cache/data,framework/runtime-cache/data,framework/sessions,framework/views,app/public,app/private}` 与 `backups/upgrades`；upgrade 包必须保留空 `bootstrap/cache`，但连空的 `backend/storage/` 目录项也不得携带，避免覆盖存量数据。`audit-package.sh` 同时校验“必需空目录存在”和“目录内无运行文件”。

### 手动打包

手动打包必须使用完整构建后的 `build/temp/production-code`。`package.sh` 会在打包前校验后端、前端和 nginx 关键产物，打包后审计测试/开发文件、运行数据、凭据、备份、缓存、包类型边界和必需文件；任一检查失败都会清理半成品 zip。

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
- `qrcode.png` - 自定义默认二维码占位图（400×400）

打包资产边界：`backend/storage` 默认是机器运行数据，构建工作区、产物汇总和完整包都必须排除 `app`、`databak`、`pay`、`temp-certs`、日志与框架缓存等内容；只保留离线运行所需的 `domain-rules/public_suffix_list.dat` 并重建必要空目录。Web 根入口不携带默认 `favicon.ico`，站点图标只由后台 `site.favicon` 配置提供。前端 `src/assets` 中无引用的图片应删除，`public` 目录则只保留仍在使用的运行时回落资源。

二维码占位资产分包边界：完整包携带 `frontend/user/qrcode.png`；升级包排除该文件，由升级流程保留安装目录已有的 PNG。前端在后台未上传二维码时直接使用该 PNG，不再探测 SVG。

登录配图 `frontend/user/login.svg` 的边界不同：完整包与升级包都携带（无历史兼容包袱，存量部署升级后即可获得默认配图），升级保护由两条路径的保留逻辑负责——PackageExtractor `protectedFrontendAssets` 与 `deploy/upgrade.sh` 的 `frontend_config` 清单在目标机已存在 `login.svg`（含运营商定制版）时原样保留，包内默认图仅在目标机缺失时落地。

---

## 快速发布指令

### 完整发布流程（推荐）

```bash
# 1. 提交代码
git add . && git commit -m "feat: 功能描述" && git push

# 2. 远程发布（构建 + 打包 + 部署到服务器）
# 正式版须先按 skills/remote-release.md 生成 main-release-<版本号> 完整 mutation 证据
# 证据有效后，脚本才会在 main 分支创建/更新 tag 并 push
./build/release.sh <版本号>
```

- **正式版**（不含 `-`）：必须先完成当前 main fingerprint 的全部 6 类 mutation；脚本在 tag 前硬校验 `.superpowers/finish-check-runs/main-release-<版本号>`，通过后自动创建/更新 `v{版本号}` tag 并 push
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
