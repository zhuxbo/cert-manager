# Cert Manager Skills

本目录包含项目开发规范和知识库，按领域组织。

## Skill 列表

| Skill           | 文件                                          | 触发场景                                                                         |
| --------------- | --------------------------------------------- | -------------------------------------------------------------------------------- |
| 后端开发        | `backend-dev.md`                              | Laravel API、升级系统、认证、委托验证                                            |
| ACME 模块       | `acme-module.md`                              | ACME 协议服务端、上游对接、订阅计费、状态流转                                    |
| 前端开发        | `frontend-dev.md`                             | Vue 3、Monorepo、共享组件                                                        |
| 部署运维        | `deploy-ops.md`                               | 宝塔部署、环境配置                                                               |
| 构建发布        | `build-release.md`                            | 版本发布、打包、CI/CD                                                            |
| 插件开发        | `plugin-dev.md`                               | 插件系统、IIFE 打包、安装/更新/卸载                                              |
| ACME E2E 测试   | `acme-e2e-test/`                              | certbot 端到端测试（Manager + 上游系统）                                         |
| Source API 接入 | `source-api.md`                               | 新增上游来源（Order\Api + Acme\Api）                                             |
| Review 清单     | `review-checklist.md`                         | 设计期"杀手场景 + 对端检查" + finish-check Reviewer Subagent 反模式扫描          |
| 案例腐烂检测    | `scripts/check-review-checklist-staleness.sh` | finish-check §6 文档同步阶段跑，验证 review-checklist.md 引用的类/方法是否仍存在 |

## 知识积累

开发过程中遇到以下情况时，将信息写入对应 skill：

- 发现新的架构约定或设计模式
- 解决了疑难问题（记录原因和解决方案）
- 确定了最佳实践
- 发现文档中缺失的重要信息

写入规则：

- 只记录已确定且经过验证的信息
- 保持简洁，避免冗余
- 按对应领域写入正确的 skill 文件
