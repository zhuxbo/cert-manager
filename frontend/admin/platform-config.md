# Platform Config 配置说明

管理端启动配置由两部分组成：

- `public/platform-config.json`：部署和界面配置，例如 `BaseUrlApi`、存储命名空间、布局、主题和功能开关。
- 后台“系统设置”：长期站点配置。应用完整刷新时通过 `GET /api/meta?channel=admin` 加载一次。

## 静态配置

```json
{
  "BaseUrlApi": "/api/admin",
  "StorageNameSpace": "admin-",
  "ResponsiveStorageNameSpace": "admin-responsive-",
  "Layout": "double",
  "Theme": "light",
  "Acme": true
}
```

`platform-config.json` 不再保存版本号、标题、品牌、DNS 工具、备案号、Logo 或二维码。跨版本首次升级后即使旧文件暂未替换，只要后台已提供品牌设置，前端就会忽略其中已迁移的站点与品牌字段，避免重复处理。

## 后台配置

“站点设置”提供 admin/user 共用配置：

- `name`：系统标题。
- `dnsTools`：DNS 检测服务地址。
- `beian`：备案号。
- `logo`：侧边栏 Logo，上传时可裁剪（自由比例，输出不超过 200×200、200KB；SVG 直传不裁剪）。
- `qrcode`：用户首页客服二维码，上传时按 1:1 裁剪（输出不超过 800×800、1MB）；未上传时回落到用户端公开目录的 `qrcode.png`，该文件在升级时保留。

“品牌设置”中的 `admin` 和 `user` 是两个独立的品牌键值对象，格式为 `{ "品牌值": "显示名称" }`，直接使用数组组件已有的“键值对模式”维护。管理端由 `admin` 配置生成品牌选项和标签，不保留固定品牌字典。

`url`（用户 URL）为空时，管理员登录后台成功后会自动回填为当前访问域名（单域名部署下 admin 与 user 同域）；已设置的值不会被覆盖，开发环境不一致时可在设置里手工修改。

这些配置是长期配置，不轮询。后台保存会立即清除服务端设置缓存；完整刷新前端后会重新请求 `/api/meta`。上传图片使用内容哈希文件名，因此替换后不会命中旧图片缓存。

## 使用方式

启动完成后仍统一通过运行时配置读取：

```typescript
import { getConfig } from "@/config";

const title = getConfig("Title");
const brands = getConfig("Brands");
const logo = getConfig("Logo");
```

敏感信息不得写入静态配置或公开的站点配置。
