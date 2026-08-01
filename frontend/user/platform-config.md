# Platform Config 配置说明 - 用户端

用户端启动配置由两部分组成：

- `public/platform-config.json`：部署和界面配置，例如 `BaseUrlApi`、存储命名空间、布局、主题和功能开关。
- 后台“系统设置”：长期站点配置。应用完整刷新时通过 `GET /api/meta?channel=user` 加载一次。

## 静态配置

```json
{
  "BaseUrlApi": "/api",
  "StorageNameSpace": "",
  "ResponsiveStorageNameSpace": "responsive-",
  "Layout": "vertical",
  "Theme": "light",
  "Acme": true
}
```

`platform-config.json` 不再保存版本号、标题、品牌、DNS 工具、备案号、Logo 或二维码。跨版本首次升级时，Seeder 从旧 user 配置提取活动品牌；品牌显示名称由旧 admin 配置与旧前端词典共同初始化，后续前端完全读取后台配置。

## 后台配置

“站点设置”提供 admin/user 共用配置：

- `name`：系统标题。
- `dnsTools`：DNS 检测服务地址的普通数组，按数组顺序优先尝试。
- `beian`：登录页备案号。
- `copyStart`：可选版权起始年份；不由 Seeder 创建，缺失或无效时回落 `2017`。
- `logo`：折叠态 Logo，上传时按 1:1 裁剪（输出不超过 200×200、200KB；SVG 需为正方形）；未上传时回落用户端公开目录的 `logo.svg`，该文件在升级时保留。
- `logoExpanded`：可选的展开版 Logo，配置后在展开侧栏中代替 `logo + name`；留空时保持原有 `logo + name` 显示。
- `qrcode`：用户首页客服二维码，上传时按 1:1 裁剪（输出不超过 800×800、1MB）；未上传时使用用户端公开目录的 `qrcode.png`。完整包携带 400×400 默认图，升级包不交付该占位图，而是保留安装目录中已有的 PNG。

“品牌设置”的 `all` 是 `{ "品牌值": "显示名称" }` 键值对象，是订单详情和其他品牌展示的唯一名称词典；`admin`、`user` 是活动品牌值普通数组。用户端产品筛选读取 `user` 并严格保持其数组顺序，显示名称读取 `all`。

`url`（用户 URL）为空时，管理员登录后台成功后会自动按 HTTPS 回填当前访问域名（单域名部署下 admin 与 user 同域）；已设置的值不会被覆盖，开发环境不一致时可在设置里手工修改。

这些配置是长期配置，不轮询。后台保存会立即清除服务端设置缓存；完整刷新前端后会重新请求 `/api/meta`。上传图片使用内容哈希文件名，因此替换后不会命中旧图片缓存。

## 使用方式

启动完成后仍统一通过运行时配置读取：

```typescript
import { getConfig } from "@/config";

const title = getConfig("Title");
const allBrands = getConfig("AllBrands");
const brands = getConfig("Brands");
const beian = getConfig("Beian");
const copyStart = getConfig("CopyStart");
const logoExpanded = getConfig("LogoExpanded");
const qrcode = getConfig("Qrcode");
```

敏感信息不得写入静态配置或公开的站点配置。
