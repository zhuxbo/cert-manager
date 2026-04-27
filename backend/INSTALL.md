# SSL 后端安装手册

## 系统要求

- PHP 8.3+
- MySQL 5.7+
- Redis
- Composer **2.8+**（低版本可能出现依赖安装错误）
- **JRE 17+**（可选，用于生成 JKS 格式证书，详见 [JRE_INSTALL.md](./JRE_INSTALL.md)）

**PHP 扩展**：宝塔默认 PHP 已包含大部分扩展，通常需要在面板额外安装的是 `redis`、`fileinfo`、`calendar`、`mbstring`。完整扩展清单由 Web 安装向导自动检测，按提示处理即可。

**PHP 函数**：宝塔默认禁用的 `exec`、`putenv`、`pcntl_signal`、`pcntl_alarm` 必须启用；`proc_open` 强烈建议启用（禁用会导致 Composer 解压异常）。`deploy/scripts/bt-deps.sh` 会自动解除常见禁用函数；也可在宝塔面板 PHP 管理 → 禁用函数 中手工处理。

## 安装步骤

### 1. 克隆项目

```bash
git clone git@github.com:zhuxbo/ssl-manager.git ssl-project
cd ssl-project/backend
```

宝塔面板先进入 wwwroot 目录 然后使用如下命令

```bash
sudo -u www git clone git@github.com:zhuxbo/ssl-manager.git ssl-project
cd ssl-project/backend
```

### 2. 安装依赖

#### 开发环境安装

```bash
composer install
```

#### 生产环境安装

在生产环境中，应该使用优化的安装方式以获得更好的性能：

```bash
# 生产环境安装 - 不安装开发依赖，启用优化
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# 如果需要进一步优化（适用于高流量网站）
composer dump-autoload --optimize --classmap-authoritative
```

**注意：** 自动安装向导已默认使用生产模式安装 Composer 依赖，无需手动执行上述命令。

**生产环境安装参数说明：**

- `--no-dev`: 不安装开发依赖（如测试工具、调试工具等）
- `--optimize-autoloader`: 优化自动加载器，提高性能
- `--no-interaction`: 非交互模式，适合自动化部署
- `--no-scripts`: 跳过脚本执行，避免安装过程中的潜在问题
- `--classmap-authoritative`: 生成权威类映射，进一步提升性能（可选）

### 3. 环境配置

项目已配置自动执行环境配置脚本，在运行 `composer install` 后将会：

1. 自动将 `.env.example` 复制为 `.env`（如果不存在）
2. 生成应用密钥 APP_KEY
3. 生成 JWT 密钥 JWT_SECRET

以上步骤均会自动执行，无需手动操作。如果自动配置失败，可以手动执行以下命令：

```bash
# 复制环境变量文件
cp .env.example .env

# 生成应用密钥 (首次安装)
php artisan key:generate

# 生成JWT密钥 (首次安装)
php artisan jwt:secret
```

### 4. ⚠️ 重要安全说明 - 密钥管理

- **首次安装**: 使用上述命令生成密钥
- **生产环境**: 密钥生成后**绝对不要**重新生成，否则会导致：
    - `APP_KEY` 重新生成：所有加密数据无法解密，现有会话失效
    - `JWT_SECRET` 重新生成：所有用户 token 失效，需要重新登录
- **如果必须重新生成密钥** (如密钥泄露)：

    ```bash
    # 强制重新生成 (谨慎使用)
    php artisan key:generate --force
    php artisan jwt:secret --force
    ```

- **备份建议**: 生产环境应备份 `.env` 文件中的密钥

完成自动配置后，您仍需编辑 `.env` 文件，配置数据库和其他必要设置：

````env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=你的数据库名
DB_USERNAME=你的数据库用户名
DB_PASSWORD=你的数据库密码

# 设置Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# 设置允许跨域的源 支持通配符
ALLOWED_ORIGINS=localhost

### 4. 数据库迁移与初始化

> Web 安装向导会自动执行迁移和种子初始化，通常无需手工运行以下命令。以下仅用于故障排查。

```bash
php artisan migrate
php artisan db:seed   # 生成管理员（admin / 123456）和会员基础级别
```

### 6. 启动应用

#### 开发环境

```bash
php artisan serve
```

默认情况下，应用将在 <http://localhost:8000> 运行

#### 生产环境

1. 使用 Nginx 配置虚拟主机，将根目录指向`public`文件夹。

2. 配置伪静态规则

    ```nginx
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    ```

#### Docker 部署（推荐）

项目提供了完整的 Docker 部署方案，使用 Docker 可以快速部署并减少环境配置问题。

生产部署请使用项目根目录的一键安装脚本，详见 [主 README](../README.md#安装)

## PHP 特殊设置

- 内存限制建议至少 `memory_limit=128M`
- PHP-FPM/Nginx 工作进程数根据并发合理配置
- 禁用函数处理见"系统要求"章节，Web 安装向导会逐项检测并报错

## 开发调试工具

### Laravel Tinker

项目已安装 Laravel Tinker 用于开发调试。Tinker 是一个强大的 REPL 工具，可以让你在命令行中与 Laravel 应用交互。

**注意：** Tinker 作为开发依赖安装在 `require-dev` 中，在生产环境使用 `composer install --no-dev` 时不会被安装。

#### 启动 Tinker

```bash
php artisan tinker
```

#### 常用 Tinker 命令示例

```php
# 查看所有用户
App\Models\User::all();

# 创建测试用户
$user = new App\Models\User();
$user->name = 'Test User';
$user->email = 'test@example.com';
$user->save();

# 查看应用配置
config('app.name');

# 测试队列任务
dispatch(new App\Jobs\TestJob());

# 清除缓存
cache()->flush();

# 退出 Tinker
exit;
```

**注意：** Tinker 仅适用于开发环境，生产环境中请谨慎使用。

## 队列和计划任务设置

### 配置队列

1. 确保 `.env` 文件中 `QUEUE_CONNECTION=redis`
2. 启动队列工作进程：

```bash
php artisan queue:work --queue tasks,notifications --sleep=3 --tries=3 --max-time 3600
```

**注意：** 队列进程需要 www 用户运行（宝塔面板）

### 设置计划任务

在服务器上添加以下 Cron 条目：

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

宝塔面板在定时任务添加如下脚本，选择 www 用户运行：

```bash
cd /path-to-your-project && php artisan schedule:run
```

注意：如果使用 Docker 部署，队列和计划任务会自动配置和启动，无需手动设置。

## 文件权限设置

脚本部署（`bt-install.sh` / `docker-install.sh`）会自动处理权限。手工部署时：

```bash
chmod -R 775 storage bootstrap/cache

# 宝塔面板
chown -R www:www storage bootstrap/cache

# Docker（Alpine PHP-FPM）
chown -R www-data:www-data storage bootstrap/cache
```

## 常见问题排查

1. **500 服务器错误**

    - 检查 `storage/logs` 下的日志文件获取详细错误信息
    - 确保所有必需的 PHP 扩展已安装

2. **数据库连接问题**

    - 验证`.env`文件中的数据库凭据
    - 确保数据库服务正在运行

3. **Redis 连接失败**

    - 检查 Redis 服务是否正在运行
    - 验证`.env`中的 Redis 配置是否正确

4. **权限问题**

    - 确保`storage`和`bootstrap/cache`目录可写
    - 检查 web 服务器用户是否有适当的权限

5. **JWT 相关问题**

    - 项目已预先配置好 JWT，现在 `composer install` 会自动运行 `jwt:secret` 命令生成密钥
    - 如果遇到 JWT 相关错误，确认`.env`文件中存在有效的`JWT_SECRET`

6. **PHP 函数被禁用问题**

    Web 安装向导会逐项报错（`Call to undefined function xxx()`）并给出处理建议。

    - 宝塔：运行 `deploy/scripts/bt-deps.sh` 自动解除禁用（会备份 `php.ini` / `php-cli.ini`）
    - 手工：在宝塔 PHP 管理 → 禁用函数 中移除对应函数
    - Docker：镜像已预配置启用

7. **Composer 版本过低**

    版本低于 2.8 可能出现依赖解析或安装错误。升级命令：`composer self-update`

## JRE 安装 (可选)

JRE (Java Runtime Environment) 是可选组件，主要用于生成 JKS 格式证书和使用 `keytool` 工具。

如果您需要使用 JKS 格式证书或相关 Java 功能，请参阅详细的安装指南：

📖 **[JRE 安装指南](./JRE_INSTALL.md)**

该文档包含：

- 各平台详细安装步骤 (Linux、macOS、Windows)
- 环境变量配置
- 版本管理工具使用
- 常见问题解决方案
- 验证和测试方法

**注意**: 我们只需要 `keytool` 工具，因此安装 JRE 17 即可满足需求，无需完整的 JDK。

如果您不需要 JKS 证书功能，可以跳过 JRE 安装。
