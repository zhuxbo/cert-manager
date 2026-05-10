# SSL Manager 升级回滚演练手册

本文档覆盖：

- 升级前准备（**重要**：备份数据库）
- 升级模式（宝塔后台 / `deploy/upgrade.sh`）
- 升级流程（freeze / smoke test / 自动回滚）
- 失败处理 + 手工回滚演练

---

## 升级前必读

### 备份数据库

**升级流程默认不备份数据库**（仅备份代码 + 前端）。后台升级页有 `⚠️ 建议升级前先备份数据库` 提示与 "立即备份" 按钮。

```bash
cd /www/wwwroot/ssl-manager/backend
sudo -u www php artisan schedule:backup
# 备份产物在 storage/databak/，明文 .sql.gz 格式（异地保存时请自行 gpg/age 加密）
```

### 检查 freeze 锁

如果上一次升级失败，可能留下 freeze lock：

```bash
ls /www/wwwroot/ssl-manager/backend/storage/framework/upgrade.lock
# 如果文件存在但升级已确认完成 → 删除
rm /www/wwwroot/ssl-manager/backend/storage/framework/upgrade.lock
# 宝塔面板重载 PHP-FPM
```

---

## 升级模式对比

| 模式                | 触发方式                   | sha256 校验      | 自动回滚               |
| ------------------- | -------------------------- | ---------------- | ---------------------- |
| 宝塔后台一键升级    | 后台 → 系统设置 → 在线升级 | ✅ releases.json | ✅ smoke test 失败回滚 |
| `deploy/upgrade.sh` | SSH 直接运行               | ✅ releases.json | ✅                     |

---

## 宝塔：后台一键升级

后台 → 系统设置 → 在线升级 → 选择目标版本 → 确认。流程：

```
1.  releases.json 检查 + 下载升级包 + sha256 强校验
2.  /api/admin/upgrade/freeze + 等排空 + supervisorctl stop manager-queue
3.  备份代码 (zip) → storage/backup/<version>/（plugins 不动）
4.  解压临时 → rsync 覆盖（保留 storage / .env / plugins / backups）
5.  composer install --no-dev（升级包不含 vendor，如依赖变更则补装）
6.  php artisan migrate --force
7.  optimize:clear && optimize
8.  清 bootstrap/cache/*.php
9.  opcache 重置（POST /api/admin/upgrade/opcache-reset）
10. 宝塔 API php_reload（如已配 BT_KEY）
11. freeze 状态下 smoke test
12. unfreeze
13. supervisorctl start manager-queue
14. 外部健康检查
```

---

## smoke test 失败处理

步骤 11（freeze 内部）或 14（unfreeze 后外部）失败时：

```
1. **不 unfreeze**（保持 lock + HTTP 503，避免半坏新版本对外服务）
2. 还原代码（解压 pre-upgrade-{date}.tar.gz / backup zip）
3. 重启 HTTP 进程到旧版本
4. 旧版本就绪后再跑一次 smoke test（确认旧版本可用）
5. 旧版本通过 → 删 upgrade.lock → 启 worker → 报告"升级失败已自动回滚"
6. 旧版本也失败 → 保持 freeze + 紧急通知 admin（邮件 / 站内信）
```

宝塔后台 PHP 版本由 UpgradeService 控制；smoke test 失败时 UpgradeService 自动执行 1-5 步回滚。

---

## 手工回滚演练

后台 → 系统设置 → 在线升级 → 备份列表 → 选择 backup → 还原。或 SSH：

```bash
cd /www/wwwroot/ssl-manager/backend
php artisan upgrade:rollback
```

### 数据库还原（手工）

升级前自行备份的产物：

```bash
cd /www/wwwroot/ssl-manager/backend
sudo -u www php artisan schedule:backup:restore <id>
```

`<id>` 是备份记录 ID，从 `php artisan schedule:backup:list` 获取。

备份是 gzip 压缩的明文 SQL（`.sql.gz`），无需密钥，直接 `gunzip | mysql` 即可还原。

---

## 健康检查端点

```bash
# 公开端点
curl http://your-host/api/health
# {"status":"ok","freeze":false,"checks":{"db":{"ok":true,"latency_ms":2},"queue_lag_seconds":12,"disk_free_gb":24.5}}

# 关键检查阈值（system_setting 可调）：
#   db ping 失败 → 503
#   queue_lag_seconds > 600（10 分钟）→ 503
#   disk_free_gb < 1 → 503

# admin metrics（需鉴权）
curl -H "Authorization: Bearer <admin_token>" http://your-host/api/admin/metrics
# 返回订单 24h/7d 量、queue 深度、CA 出站延迟 P50/P95、日志表大小、DB 大小等
```

---

## 演练清单

部署前建议在测试环境完成以下演练：

- [ ] 完整升级 1 次（A → B）确认所有 14 步通过
- [ ] 故意触发 smoke test 失败（修改 backend 中某个关键路由让其 500），确认自动回滚
- [ ] 手工 rollback 1 次（A → B → rollback 回 A）
- [ ] 数据库备份 + 还原 1 次（确认 mysqldump / gzip / mysql 链路畅通）
- [ ] freeze 期间访问 `/api/health` 仍返回 200 但 `freeze: true`
- [ ] freeze 期间访问 `/api/admin/order/index` 返回 503

---

## 详细文档

- [skills/deploy-ops.md](skills/deploy-ops.md) — 部署运维规范
- [skills/backend-dev.md](skills/backend-dev.md) — 升级冻结契约 + freeze lock 实现
- [DEPLOY.md](DEPLOY.md) — 部署指南（含 sha256 校验 + 安全基线）
