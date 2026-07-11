# Cert Manager Skills

本目录包含项目开发规范和知识库，按领域组织。

## Skill 列表

| Skill           | 文件                                          | 触发场景                                                                                                      |
| --------------- | --------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| 后端·核心       | `backend/core.md`                             | 技术栈/架构/代码规范/Artisan/缓存日志/关键文件索引/测试/变异测试                                              |
| 后端·订单资金   | `backend/order-fund.md`                       | order 级互斥锁、下单韧性、资金四道网、支付验签、退款/Purge                                                    |
| 后端·认证安全   | `backend/auth.md`                             | Token 认证、安全补强（tasks 死锁/归档解压/节流/凭据 URL）                                                     |
| 后端·升级       | `backend/upgrade.md`                          | 升级系统、freeze 冻结契约（unfreeze 先于 up/watchdog 自愈/备份互斥）、BinaryLocator 外部命令                  |
| 后端·数据库     | `backend/database.md`                         | 迁移规范、列类型防溢出、MySQL 5.7/8.x 兼容                                                                    |
| 后端·委托验证   | `backend/delegation.md`                       | 委托验证、S/MIME 验证字段                                                                                     |
| ACME 模块       | `backend/acme-module.md`                      | ACME 协议服务端、上游对接、订阅计费、状态流转                                                                 |
| Source API 接入 | `backend/source-api.md`                       | 新增上游来源（Order\Api + Acme\Api）                                                                          |
| 自动续费重签    | `backend/auto-renew.md`                       | 自动续费/重签、算法继承防静默降级、失败兜底通知堵过期洞                                                       |
| 国密证书        | `backend/sm2-cert.md`                         | 国密 SM2 双证书、能力探测、fail-closed 防降级、下载包、多级透传                                               |
| Certum 文档     | `backend/certum-document.md`                  | 验证文档上传、签发后禁上传、异步转发上游、content_hash 跨级去重                                               |
| 企业信息查询    | `backend/enterprise-lookup.md`                | 工商查询（阿里云市场）+ 邮编查询（本地县级市识别）、企业-联系人绑定                                           |
| 通知体系        | `backend/notification.md`                     | 主系统 mail + 插件通道注入、ChannelManager singleton、携密不入库、SystemAlert 运维告警（去重指纹/净化管线）   |
| 前端开发        | `frontend/frontend-dev.md`                    | Vue 3、Monorepo、共享组件                                                                                     |
| 插件开发        | `plugins/plugin-dev.md`                       | 插件系统、IIFE 打包、安装/更新/卸载                                                                           |
| 部署运维        | `ops/deploy-ops.md`                           | 宝塔部署、环境配置、升级中断恢复 runbook                                                                      |
| 构建发布        | `ops/build-release.md`                        | 版本发布、打包、CI/CD                                                                                         |
| Review 清单     | `review-checklist.md`                         | 设计期"杀手场景 + 对端检查" + finish-check Reviewer Subagent 反模式扫描                                       |
| ACME E2E 测试   | `acme-e2e-test/`                              | certbot 端到端测试（Manager + 上游系统）                                                                      |
| 案例腐烂检测    | `scripts/check-review-checklist-staleness.sh` | finish-check §6 文档同步阶段跑，验证 review-checklist.md 与 finish-check.md 引用的类/方法/文件/SHA 是否仍存在 |

## 知识积累

开发过程中遇到以下情况时，将信息写入对应 skill：

- 发现新的架构约定或设计模式
- 解决了疑难问题（记录原因和解决方案）
- 确定了最佳实践
- 发现文档中缺失的重要信息

写入规则：

- 只记录已确定且经过验证的信息
- 保持简洁，避免冗余
- **按领域子目录归类**：`backend/`（core/order-fund/auth/upgrade/database/delegation/acme-module/source-api/auto-renew/sm2-cert/certum-document/enterprise-lookup/notification）、`frontend/`、`plugins/`、`ops/`；跨领域（review-checklist、acme-e2e-test）放根目录。单文件过大或多主题混杂（经验阈值 ~600 行）时按子主题拆分
- **详情下沉、红线上浮**：skill 是实现细节/坑/复现的**唯一落点**；仅当某约定属"任何改动都可能踩、不读 skill 就会违规"的安全铁律（资金/死锁/事务/安全）时，才在 `CLAUDE.md` 系统架构约定补一句**可执行红线 + 本目录指针**，绝不把细节复制进 CLAUDE.md
