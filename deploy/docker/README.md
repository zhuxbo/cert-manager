# SSL Manager Docker 部署

## 一键安装

```bash
# 国内服务器
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash -s -- docker

# 海外服务器
curl -fsSL https://release-us.cnssl.com/install.sh | sudo bash -s -- docker

# 非交互模式
curl -fsSL https://release-cn.cnssl.com/install.sh | sudo bash -s -- docker -y
```

脚本将引导完成 7 步配置：Docker 环境 → 镜像源 → MySQL（三档内存）→ Redis → HTTP 端口 → SSL 证书 → 安装目录。

## 目录结构

```
/opt/ssl-manager/
├── .env                      # Docker 环境变量
├── docker-compose.yml
├── Dockerfile
├── version.json              # 版本配置（release_url / network / channel）
├── backend/                  # Laravel 后端（含 .env）
├── frontend/
│   ├── admin/               # 管理端
│   ├── user/                # 用户端
│   └── web/                 # 自定义前端（升级时保留）
├── plugins/                  # 插件目录（在线安装）
├── nginx/                    # 应用路由配置（manager.conf 等）
├── config/
│   ├── nginx.conf           # Nginx 主配置
│   ├── site.conf            # Nginx 站点配置
│   ├── php.ini              # PHP 配置
│   ├── my.cnf               # MySQL 配置（档位文件副本）
│   ├── mysql/               # MySQL 档位模板（minimal/standard/performance）
│   └── ssl/                 # SSL 证书（可选）
├── backups/                  # 备份和升级包
└── data/
    ├── mysql/
    ├── redis/
    └── logs/nginx/
```

## 配置修改

部署完成后修改配置：

| 文件 | 说明 | 修改后操作 |
|------|------|-----------|
| `.env` | Docker 环境变量（端口、数据库密码） | `docker compose up -d` |
| `docker-compose.yml` | 服务编排 | `docker compose up -d` |
| `Dockerfile` | PHP 镜像定义 | `docker compose build && docker compose up -d` |
| `config/nginx.conf` | Nginx 主配置 | `docker compose restart nginx` |
| `config/site.conf` | Nginx 站点配置 | `docker compose restart nginx` |
| `config/php.ini` | PHP 配置 | `docker compose restart php queue scheduler` |
| `config/my.cnf` | MySQL 配置（档位） | `docker compose restart mysql` |
| `backend/.env` | Laravel 应用配置 | `docker compose restart php queue scheduler` |

## 常用命令

```bash
cd /opt/ssl-manager

# 状态/日志
docker compose ps
docker compose logs -f
docker compose logs -f php
docker compose logs -f nginx

# 重启/停止
docker compose restart
docker compose down
docker compose down -v          # 停止并删除数据卷

# 进入容器
docker compose exec php sh
docker compose exec php php artisan <command>
```

## 升级

推荐从 **管理后台 → 系统 → 升级** 操作（自动备份、支持回滚）。

## MySQL 档位切换

容器化 MySQL 内存占用分三档：

| 档位 | 内存占用 | 适用场景 |
|------|---------|---------|
| minimal | ~150MB | 1-2G 内存服务器，万级以下订单 |
| standard | ~300MB | 2-4G 内存服务器，十万级订单（默认） |
| performance | ~600MB | 4G+ 内存服务器，大规模部署 |

切换档位：

```bash
cp config/mysql/performance.cnf config/my.cnf
docker compose restart mysql
```

## 镜像源切换

安装时脚本已根据网络环境自动处理 Alpine/Composer 镜像源。如需切换：

```bash
# 编辑 version.json，"network" 改为 "china" 或 "global"
# 重建 PHP 镜像
docker compose build --no-cache php
docker compose up -d
```
