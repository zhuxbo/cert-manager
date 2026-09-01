# 插件系统

## 插件目录结构

```
plugins/
├── release-plugin.sh     # 通用构建脚本
├── temp/                 # 构建产物（git 忽略）
└── {name}/               # 已安装的插件
    ├── plugin.json       # 插件元数据（必须）
    ├── build.json        # 打包配置（开发用）
    ├── backend/          # PHP 后端
    ├── frontend/         # 前端（可选）
    │   ├── admin/        # 管理端 IIFE Vue 组件
    │   ├── user/         # 用户端 IIFE Vue 组件
    │   └── web/          # 独立静态页（原生 HTML+JS，如 Easy 简易申请）
    └── nginx/            # nginx 配置（可选）
```

## 内置插件

| 插件              | 功能                                                                      | 参考用途                                 |
| ----------------- | ------------------------------------------------------------------------- | ---------------------------------------- |
| `plugins/notice`  | 单表 CRUD（公告），用户/管理端基本对称                                    | 最小可用插件骨架                         |
| `plugins/invoice` | 发票管理（双端 CRUD）+ 外部开票方接入（pending/complete）+ Admin 配置面板 | CRUD + 对外鉴权接口 + storage 加密配置   |
| `plugins/easy`    | Easy 简易证书申请                                                         | 多回调、产品级别映射、tid+email 业务鉴权 |

开发规范按主题拆分，索引见 [`skills/SKILL.md`](../skills/SKILL.md)。

## 安装插件

### 方式一：管理面板安装

进入 **系统管理 → 插件管理** 页面：

- **远程安装**：输入插件名，自动从 release 服务下载
- **上传安装**：上传 `.zip` 插件包

### 方式二：API 安装

```bash
# 远程安装（从 release 服务下载）
curl -X POST /api/admin/plugin/install \
  -d '{"name": "{name}"}'

# 指定版本
curl -X POST /api/admin/plugin/install \
  -d '{"name": "{name}", "version": "0.0.1"}'

# 指定第三方 release 地址
curl -X POST /api/admin/plugin/install \
  -d '{"name": "{name}", "release_url": "https://example.com/plugins/{name}"}'

# 上传 ZIP 文件
curl -X POST /api/admin/plugin/install \
  -F "file=@{name}-plugin-0.0.1.zip"
```

### 方式三：手动安装

将插件包解压到 `plugins/` 目录，然后运行迁移：

```bash
cd plugins && unzip {name}-plugin-0.0.1.zip
cd ../backend && php artisan migrate --path=../plugins/{name}/backend/migrations --force
php artisan route:clear && php artisan config:clear
```

如果插件包含 nginx 配置，需要将 `plugins/{name}/nginx/*.conf` 引入到 nginx 主配置并 reload。

## 更新插件

### 管理面板

插件管理页面点击 **检查更新** → **更新**。

### API

```bash
# 更新到最新版
curl -X POST /api/admin/plugin/update \
  -d '{"name": "{name}"}'

# 更新到指定版本
curl -X POST /api/admin/plugin/update \
  -d '{"name": "{name}", "version": "0.0.2"}'
```

更新时自动备份旧版本，失败会自动回滚。

## 卸载插件

### 管理面板

插件管理页面点击 **卸载**，可选是否删除数据。

### API

```bash
# 卸载（保留数据库表）
curl -X POST /api/admin/plugin/uninstall \
  -d '{"name": "{name}"}'

# 卸载并删除数据
curl -X POST /api/admin/plugin/uninstall \
  -d '{"name": "{name}", "remove_data": true}'
```

## 构建插件

使用 `release-plugin.sh` 构建打包：

```bash
# 仅构建（产物在 plugins/temp/）
bash plugins/release-plugin.sh {name} --build-only

# 构建 + 发布
bash plugins/release-plugin.sh {name}

# 只发布到指定服务器
bash plugins/release-plugin.sh {name} --server cn
```

发布需要配置文件 `plugins/release.conf`（不存在时回落到 `build/release.conf`）。

## 更新地址优先级

1. `plugin.json` 中的 `release_url`（第三方插件）
2. `{主系统 release_url}/plugins/{name}`（官方插件）
