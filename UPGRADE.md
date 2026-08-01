# SSL Manager 升级回滚演练手册

本文档覆盖：

- 升级前准备（**重要**：备份数据库）
- 升级模式（宝塔后台 / `deploy/upgrade.sh`）
- 升级流程（自动覆盖升级步骤）+ 人工 freeze 加固 + smoke 检查 + 手工回滚
- 失败处理 + 手工回滚演练

> **宝塔环境手工运维注意**：本文档命令示例中的 `php` 在宝塔多版本系统下需替换为绝对路径（避免 root PATH 找到错误 PHP 版本）：
>
> - PHP 8.3：`/www/server/php/83/bin/php`
> - PHP 8.4：`/www/server/php/84/bin/php`
> - composer：`/www/server/php/<ver>/bin/php /usr/local/bin/composer`（绕过 phar shebang 强制版本一致）
>
> `upgrade.sh` 已自动使用绝对路径，本提示仅针对手工运维场景。

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

如果上一次**人工 freeze 加固**未解除（升级自动流程不创建此锁），可能留下 freeze lock：

```bash
ls /www/wwwroot/ssl-manager/backend/storage/framework/upgrade.lock
# 如果文件存在但升级已确认完成 → 删除
rm /www/wwwroot/ssl-manager/backend/storage/framework/upgrade.lock
# 宝塔面板重载 PHP-FPM
```

---

## 升级模式对比

| 模式                | 触发方式                   | sha256 校验      | 失败回滚                                         |
| ------------------- | -------------------------- | ---------------- | ------------------------------------------------ |
| 宝塔后台一键升级    | 后台 → 系统设置 → 在线升级 | ✅ releases.json | ❌ 不自动；手工「备份还原」/ `/upgrade/rollback` |
| `deploy/upgrade.sh` | SSH 直接运行               | ✅ releases.json | ❌ 不自动；`./upgrade.sh rollback`（交互确认）   |

---

## 宝塔：后台一键升级

后台 → 系统设置 → 在线升级 → 选择目标版本 → 确认。前端只调 `POST /upgrade/execute`（后台据此 spawn 一个 `artisan upgrade:run` 后台进程）+ 轮询 `/upgrade/status`。`UpgradeService::performUpgradeWithStatus()` 实际流程：

```
1.  releases.json 检查 + 下载升级包 + sha256 强校验（fail-closed，不匹配即删包中断）
2.  进入维护模式 artisan down --retry 60（仅挡 HTTP；**不冻结队列、不停 worker**）
3.  备份代码 (zip) → storage/backup/<version>/（plugins / storage / .env 保留不动）
4.  解压临时 + 校验包 + PHP 环境检测（不达标抛 PhpEnvironmentException 中断，引导用 upgrade.sh）
5.  rsync 覆盖（保留 storage / .env / plugins / backups）
6.  composer install --no-dev（仅 composer.* 变更时）+ 无条件 dump-autoload
7.  opcache_reset（**CLI 子进程内**，清不到 PHP-FPM 的字节码缓存）
8.  php artisan migrate --force
9.  数据库结构校验 + 自动修复
10. db:seed --force
11. 清缓存（子进程 optimize:clear + config:cache + route:cache）
12. 写版本号 + 清理临时文件 / 旧包
13. 退出维护模式 artisan up
14. queue:restart（常驻 worker 跑完当前 job 后退出、supervisor 自动拉起新代码——**滚动重启，非停 worker**）
```

> **升级冻结（freeze）+ 停 worker 为人工 / 外部编排步骤，一键流程不自动执行。** 冻结锁（`POST /api/admin/upgrade/freeze` 或 `php artisan upgrade:freeze`）让 HTTP 返回 503、且队列 Job 在 `SkipWhenUpgradeFrozen` 中被 `release(60)` 暂存回队列——但它**不停止 worker 进程**；worker 不停则每 60s 逐次 release、累加 attempts，最终可能把 Job 误杀为 `MaxAttemptsExceeded`（详见 `skills/backend/upgrade.md` 升级冻结契约）。要形成"冻结 + 停 worker"双保险，须运维在宝塔面板（软件商店 → Supervisor）手工停掉队列守护进程——**程序名为站点域名**（`bt-install.sh` 按 `$SITE_DOMAIN` 创建以保多站点唯一，**不是** `manager-queue` / `ssl-manager-queue`）——升级完成后 `php artisan upgrade:unfreeze` 再重启该进程。`UpgradeService::performUpgradeWithStatus()` 与 `deploy/upgrade.sh` 当前**均不调用 freeze、也不停 worker**。

---

## smoke test 失败处理

升级后 smoke test（`POST /api/admin/upgrade/smoke` / `php artisan upgrade:smoke`）或外部健康检查未通过时（若采用了上文人工冻结加固流程）：

```
1. **不 unfreeze**（保持 lock + HTTP 503，避免半坏新版本对外服务）
2. 还原代码（解压 pre-upgrade-{date}.tar.gz / backup zip）
3. 重启 HTTP 进程到旧版本
4. 旧版本就绪后再跑一次 smoke test（确认旧版本可用）
5. 旧版本通过 → 删 upgrade.lock → 启 worker（程序名为站点域名）→ 报告"升级失败已回滚"
6. 旧版本也失败 → 保持 freeze + 紧急通知 admin（邮件 / 站内信）
```

以上 1-6 步是**人工回滚 playbook**——升级失败**不会自动回滚**：后台一键升级失败时 `performUpgradeWithStatus()` 仅 `artisan up` 退出维护 + 标 `status=failed`，新代码留在原地；回滚须运维手工触发——后台「备份列表 → 还原」/ `POST /api/admin/upgrade/rollback` / `php artisan upgrade:rollback`（SSH 则 `./upgrade.sh rollback`，均显式/交互确认）。宝塔后台 PHP 版本由 UpgradeService 控制。

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

升级末尾仅在站点 PHP 版本发生变化时修正宝塔 `schedule:run` 计划任务的 PHP 绝对路径，保留任务原有命令主体和日志策略；新安装的任务输出由宝塔面板记录。

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
- [ ] 故意制造升级失败（如让某步迁移报错），确认升级仅标 `status=failed` + 退出维护、**不自动回滚**（新代码留存，需走下一条手工 rollback）
- [ ] 手工 rollback 1 次（A → B → rollback 回 A）
- [ ] 数据库备份 + 还原 1 次（确认 mysqldump / gzip / mysql 链路畅通）
- [ ] freeze 期间访问 `/api/health` 仍返回 200 但 `freeze: true`
- [ ] freeze 期间访问 `/api/admin/order/index` 返回 503

---

## 详细文档

- [skills/ops/deploy-ops.md](skills/ops/deploy-ops.md) — 部署运维规范
- [skills/backend/upgrade.md](skills/backend/upgrade.md) — 升级冻结契约 + freeze lock 实现
- [DEPLOY.md](DEPLOY.md) — 部署指南（含 sha256 校验 + 安全基线）
