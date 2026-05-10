# SSL证书管理系统 - 前端用户端

[![license](https://img.shields.io/github/license/pure-admin/vue-pure-admin.svg)](LICENSE)

## 项目介绍

这是一个基于 Vue 3 + TypeScript 开发的SSL证书管理系统前端用户端项目。系统为用户提供了完整的SSL证书申请、管理、下载和部署功能，采用现代化的Web技术栈构建，提供直观易用的用户界面和流畅的操作体验。

项目基于 [vue-pure-admin](https://github.com/pure-admin/vue-pure-admin) 精简版进行开发，专门针对SSL证书用户端应用场景进行了深度定制。

## 技术栈

- **前端框架**: Vue 3 + TypeScript
- **UI组件库**: Element Plus
- **状态管理**: Pinia
- **路由管理**: Vue Router
- **HTTP客户端**: Axios
- **构建工具**: Vite
- **样式方案**: Sass + TailwindCSS
- **代码规范**: ESLint + Prettier + Stylelint
- **包管理器**: pnpm
- **Monorepo**: pnpm workspace

## Monorepo 架构

本项目是 monorepo 的一部分，与 `admin` 应用共享 `shared` 包中的代码：

```
frontend/
├── shared/     # 共享代码库
├── admin/      # 管理端应用
└── user/       # 用户端应用 (本项目)
```

### 共享包使用

通过 `@shared/*` 别名访问共享代码：

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

### 初始化配置

在 `src/utils/setup.ts` 中配置 shared 模块的依赖注入：

```typescript
import { createAuth, createHttp } from "@shared/utils";
import { setHasAuth } from "@shared/directives/auth";

// 初始化 Auth、Http 和权限指令
```

## 核心功能模块

### 🔐 用户认证

- 用户注册与登录
- 密码重置功能
- JWT令牌认证
- 自动登录保持

### 📜 证书申请与管理

- **证书申请**: 支持单个域名和批量域名申请
- **多种验证方式**: DNS验证、文件验证、邮箱验证
- **自动CSR生成**: 支持自动生成和手动上传CSR
- **证书类型**: 支持DV、OV、EV证书
- **多CA品牌**: Certum、GoGetSSL、Positive、SslTrus、TrustAsia等

### 📦 订单管理

- 订单创建与跟踪
- 订单状态实时更新
- 支付管理（未支付、已支付）
- 批量操作（批量申请、批量支付、批量提交）
- 订单详情查看

### 🔍 域名验证

- **DNS验证**: 自动生成CNAME/TXT记录
- **文件验证**: 提供验证文件下载
- **邮箱验证**: 管理员邮箱验证
- **验证状态检测**: 实时检测验证状态
- **批量验证**: 支持批量域名验证

### 📥 证书下载与部署

- **多格式下载**: Nginx、Apache、IIS、Tomcat等
- **一键部署**: 宝塔面板快捷部署
- **证书详情**: 查看证书信息、有效期等
- **私钥管理**: 安全的私钥查看和复制

### 💰 财务管理

- 账户余额查询
- 充值功能
- 交易记录查看
- 发票管理
- 资金流水

### 👤 个人设置

- 个人信息管理
- 联系人信息管理
- 组织信息管理（企业用户）
- 账户安全设置

## 项目结构

```txt
src/
├── api/                    # API接口定义
│   ├── auth.ts            # 用户认证相关
│   ├── order.ts           # 订单管理
│   ├── cert.ts            # 证书管理
│   ├── product.ts         # 产品查询
│   ├── funds.ts           # 资金管理
│   └── ...
├── assets/                # 静态资源
├── components/            # 公共组件
├── config/                # 配置文件
├── directives/            # 自定义指令
├── layout/                # 布局组件
├── plugins/               # 插件配置
├── router/                # 路由配置
├── store/                 # 状态管理
├── style/                 # 全局样式
├── utils/                 # 工具函数
├── views/                 # 页面组件
│   ├── login/             # 登录注册
│   ├── order/             # 订单管理
│   ├── product/           # 产品浏览
│   ├── transaction/       # 交易记录
│   ├── funds/             # 资金管理
│   ├── setting/           # 个人设置
│   └── ...
├── App.vue                # 根组件
└── main.ts                # 应用入口
```

## 用户操作流程

### 证书申请流程

1. **选择产品** → 浏览SSL证书产品，选择合适的证书类型
2. **填写信息** → 输入域名、选择验证方式、填写组织信息
3. **生成订单** → 系统生成订单，用户确认信息
4. **支付订单** → 完成订单支付
5. **域名验证** → 根据选择的验证方式完成域名验证
6. **证书签发** → CA机构验证通过后签发证书
7. **下载部署** → 下载证书文件并部署到服务器

### 验证方式说明

- **DNS验证**: 在域名DNS中添加指定的CNAME或TXT记录
- **文件验证**: 在网站根目录放置验证文件
- **邮箱验证**: 通过域名管理员邮箱接收验证邮件

## 开发指南

### 环境要求

- Node.js >= 18.18.0
- pnpm >= 9.0.0

### 安装依赖

```bash
# 在 monorepo 根目录运行
pnpm install
```

### 开发模式

```bash
# 在 monorepo 根目录运行
pnpm dev:user

# 或同时启动 admin 和 user
pnpm dev

# 或在当前目录运行
pnpm dev
```

### 构建生产版本

```bash
# 在 monorepo 根目录运行
pnpm build:user

# 或在当前目录运行
pnpm build
```

### 代码规范检查

```bash
# ESLint检查
pnpm lint:eslint

# Prettier格式化
pnpm lint:prettier

# Stylelint样式检查
pnpm lint:stylelint

# 全部检查
pnpm lint
```

### 类型检查

```bash
pnpm typecheck
```

## 配置说明

### Platform Config

项目使用 `public/platform-config.json` 进行核心配置，详细说明请参考 [platform-config.md](./platform-config.md)。

#### 主要配置项

```json
{
  "BaseUrlApi": "http://localhost:5300",
  "Brands": ["certum", "gogetssl", "positive", "ssltrus", "trustasia"],
  "Beian": "豫ICP备123456789号"
}
```

#### 核心配置说明

- **BaseUrlApi**: 用户端API基础地址，对应后端 `routes/api.user.php`
- **Brands**: 支持的SSL证书CA品牌列表，包含5个主流品牌
- **ResponsiveStorageNameSpace**: 本地存储命名空间，使用 `user-` 前缀
- **Beian**: 网站备案号，显示在页面底部

## 开发规范

### 代码组织

- 按功能模块组织代码结构
- 使用TypeScript增强类型安全
- 遵循Vue 3 Composition API最佳实践
- 组件采用单文件组件(.vue)格式

### API接口

- 统一的HTTP请求封装
- 自动JWT令牌管理
- 请求响应拦截器处理
- 错误统一处理

### 用户体验

- 响应式设计，支持移动端
- 加载状态提示
- 操作反馈和确认
- 友好的错误提示

### 安全特性

- JWT令牌自动刷新
- 敏感操作二次确认
- 输入数据验证
- XSS和CSRF防护

## 部署说明

### Nginx配置

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## 用户指南

### 首次使用

1. 注册账户并完成邮箱验证
2. 完善个人信息和联系方式
3. 充值账户余额（如需要）
4. 选择合适的SSL证书产品
5. 提交证书申请订单

### 常见问题

- **域名验证失败**: 检查DNS解析是否正确配置
- **证书申请被拒**: 确认域名所有权和组织信息准确性
- **支付失败**: 检查账户余额或联系客服
- **证书下载问题**: 确认证书状态为"已签发"

## 相关链接

- [后端路由目录](../../backend/routes/)
- [管理端项目](../admin/)
- [Vue 3 官方文档](https://vuejs.org/)
- [Element Plus 组件库](https://element-plus.org/)
- [SSL证书知识库](https://help.ssl.com/)

## 版本说明

当前版本基于 vue-pure-admin 精简版开发，专门为SSL证书用户端定制。

## 许可证

[MIT © 2020-present, pure-admin](./LICENSE)
