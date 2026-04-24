# SSL 证书管理系统 - 后端 API

基于 Laravel 11.x 构建的 SSL 证书管理系统后端 API，采用纯 API 架构，支持多 CA 品牌证书申请、管理和自动化处理。

## 项目特点

- 🔐 **纯 API 架构** - 无 session/cookie 依赖，完全前后端分离
- 🎯 **多端认证** - 支持用户端、管理端 JWT 认证和 API Token 认证
- 🏢 **多 CA 支持** - 集成 GoGetSSL、Racent 等多个 CA 品牌
- 💰 **完整财务** - 订单、交易、发票、资金管理一体化
- 🔄 **异步处理** - Redis 队列支持，任务状态实时跟踪
- 📊 **全面日志** - API、用户、管理员、CA、错误等分类日志
- 🐳 **部署简单** - 脚本一键部署

## 技术栈

- **框架**: Laravel 11.x
- **PHP 版本**: 8.3+
- **数据库**: MySQL 8.0
- **缓存**: 文件缓存（默认）/ Redis（可选）
- **队列**: 同步（默认）/ Redis（可选）
- **认证**: JWT (tymon/jwt-auth)
- **测试**: PHPUnit + Pest
- **代码质量**: PHPStan + PHP Pint
- **第三方集成**:
  - 支付: 支付宝、微信支付 (yansongda/pay)
  - 通信: 短信 (overtrue/easy-sms)、邮件 (phpmailer/phpmailer)
  - 文档: Excel 处理 (phpoffice/phpspreadsheet)

## 快速开始

### 环境要求

- PHP 8.3+
- MySQL 8.0+
- Composer
- Redis（可选，用于缓存和队列）
- JRE 17+（可选，用于 keytool 生成 JKS 证书）

### 安装步骤

1. **克隆项目**

    ```bash
    git clone <repository-url>
    cd backend
    ```

2. **安装依赖**

    ```bash
    composer install
    ```

3. **环境配置**

    ```bash
    cp .env.example .env
    # 编辑 .env 文件配置数据库、Redis等信息
    ```

4. **生成密钥**

    ```bash
    php artisan key:generate
    php artisan jwt:secret
    ```

5. **数据库迁移**

    ```bash
    php artisan migrate
    php artisan db:seed
    ```

6. **启动服务**

    ```bash
    php artisan serve
    ```

详细安装说明请参考 [INSTALL.md](INSTALL.md)

### Redis 配置（可选）

系统默认使用文件缓存和同步队列，无需 Redis 即可运行。如需启用 Redis 以提升性能，在 `.env` 中添加：

```bash
# Redis 连接配置
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# 启用 Redis 缓存
CACHE_DRIVER=redis
REDIS_CACHE_DB=1

# 启用 Redis 队列（需要运行 queue:work）
QUEUE_CONNECTION=redis
```

**注意**：启用 Redis 队列后，需要运行队列处理进程：

```bash
php artisan queue:work --queue tasks,notifications --tries=3
```

IDE 代码提示辅助工具：
Phpstorm Laravel Idea 插件优先
barryvdh/laravel-ide-helper 包用于其它 IDE

### 认证方式

- **用户端 API**: JWT 认证，路径前缀 `/api/`
- **管理端 API**: JWT 认证，路径前缀 `/api/admin/`
- **API v2**: Token 认证，路径前缀 `/api/v2/`
- **Deploy API**: Deploy Token 认证，路径前缀 `/api/deploy/`

### 响应格式

```json
{
  "code": 1,
  "data": {
  }
}
```

```json
{
  "code": 0,
  "msg": "错误信息",
  "errors": {
  }
}
```

### 主要 API 端点

- **认证相关**: 登录、注册、刷新令牌
- **证书管理**: 申请、续费、重新颁发、同步状态
- **订单管理**: 创建、支付、提交、批量操作
- **财务管理**: 交易记录、资金流水、发票管理
- **系统管理**: 用户管理、产品管理、设置配置

## 项目结构

```txt
app/
├── Http/Controllers/     # API控制器
│   ├── User/            # 用户端控制器
│   ├── Admin/           # 管理端控制器
│   ├── V2/              # API v2版本
│   ├── Deploy/          # Deploy API
│   └── Callback/        # 回调处理
├── Models/              # 数据模型
├── Services/            # 业务逻辑层
│   └── Order/    # 订单服务核心
├── Http/Requests/       # 表单验证
├── Exceptions/          # 异常处理
├── Traits/              # 通用特性
├── Utils/               # 工具类
├── Jobs/                # 队列任务
└── Bootstrap/           # 启动配置

routes/                  # 路由定义
├── api.user.php        # 用户端路由
├── api.admin.php       # 管理端路由
├── api.v2.php          # API v2路由
├── api.deploy.php      # Deploy API路由
└── callback.php        # 回调路由

database/
├── migrations/         # 数据库迁移
└── seeders/           # 数据种子

tests/
├── Feature/
│   ├── Commands/      # 命令行测试
│   ├── Models/        # 模型测试
│   ├── Middleware/     # 中间件测试
│   ├── Http/          # 控制器测试
│   ├── Services/      # 服务测试
│   └── Jobs/          # 队列任务测试
├── Unit/              # 单元测试
└── Traits/            # 测试辅助 Traits
```

## 开发规范

### 代码质量

```bash
# 代码格式化
./vendor/bin/pint

# 静态分析
./vendor/bin/phpstan analyse

# 运行测试
php artisan test
```

### 开发流程

1. 遵循功能优先开发模式
2. 使用 PSR-12 编码规范
3. 统一异常处理 (ApiResponseException)
4. 分类日志记录
5. 代码审查和优化
6. 根据需要编写测试（可选）

## 核心功能

### 证书管理

- 支持 DV、OV、EV 等多种证书类型
- 自动域名验证 (DNS、HTTP、Email)
- 证书状态实时同步
- 批量操作支持

### 订单系统

- 完整的订单生命周期管理
- 支付集成 (支付宝、微信)
- 自动扣费和发票生成
- 订单状态追踪

### 用户管理

- 多级用户权限体系
- API 访问控制
- 操作审计日志
- 资金账户管理

### ACME 订阅管理

- 单一 `Acme` 模型（表 `acmes`），独立于传统订单/证书
- 计费三步流程：createOrder（unpaid）→ payOrder（pending）→ commitOrder（active，含 EAB）
- 取消流程：commitCancel（标记 cancelling + 延时任务）→ executeCancel（调上游 + 退费）
- Source API 层：4 方法接口（new/get/cancel/getProducts），按 product.source 路由，通过 SDK 代理调用 Gateway 端点
- 产品映射由 Gateway 侧维护，Manager 通过 `GET /api/acme/get-products` 获取产品列表
- 产品导入：Action::importProduct() 同时查询 Order + ACME 两端产品
- Admin/User/Deploy 三端独立 ACME 控制器，Deploy 端支持一步到位下单

### 系统集成

- 多 CA 品牌适配器模式
- 异步任务处理
- 回调通知机制
- 缓存优化策略

### 证书部署

通过 Deploy API 支持证书自动部署到服务器：

- [cert-deploy](https://github.com/zhuxbo/cert-deploy) - Nginx/Apache 证书部署客户端
- [cert-deploy-iis](https://github.com/zhuxbo/cert-deploy-iis) - Windows IIS 证书部署客户端

## 监控与日志

系统提供完整的日志记录和监控功能：

- **API 日志**: 记录所有 API 调用
- **用户日志**: 用户操作审计
- **管理员日志**: 管理员操作审计
- **CA 日志**: CA 接口调用记录
- **错误日志**: 系统错误追踪
- **回调日志**: 回调处理记录

## 安全特性

- JWT 令牌认证和刷新机制
- API 访问频率限制
- 敏感数据加密存储
- SQL 注入和 XSS 防护
- 操作权限验证
- 审计日志记录

## 性能优化

- 缓存策略（文件/Redis）
- 数据库查询优化
- 队列处理（同步/异步）
- 防重复提交机制
- 分页查询支持

## 许可证

本项目采用 MIT 许可证 - 查看 [LICENSE](LICENSE) 文件了解详情。
