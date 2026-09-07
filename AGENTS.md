# ssl-manager 项目智能体规则

## 项目定位

ssl-manager 是 Laravel 13 + Vue 3 的证书管理 Monorepo，包含后端、admin/user 前端、插件系统、构建与宝塔部署流程。生产数据库同时覆盖 MySQL 5.7 与 8.x，后端本地验证优先使用仓库 Docker 环境。

## 不可违反的规则

- `main`、`dev` 不自动提交或推送；只有用户明确授权时才执行对应 Git 操作。不得自动执行真实发布。
- 测试发现 bug 时修复实现，不修改测试去迎合错误代码；验证范围按改动风险扩展。
- 资金、订单、ACME、任务和异步队列改动必须遵守对应 skill 中的事务、锁顺序、幂等与队列约束，不得以机械检查通过替代运行路径验证。
- 插件不得让主系统硬依赖插件代码或表；迁移和 SQL 必须同时兼容 MySQL 5.7 与 8.x。
- 过程性 plan、设计稿和调试记录只写入已忽略的 `.superpowers/`，不得被代码、注释或入库文档引用。
- 匹配任务时优先使用工具原生薄入口；无对应入口时先读取 `skills/SKILL.md`，再按路由读取对应叶子资源。

## 工作方式

- 按用户目标完成最小充分改动；常规实现选择自行处理，只有缺失信息会实质改变结果时才澄清。
- 先读实际 diff 和直接调用链，再选相关 skill 章节。领域资料按需查阅，不把历史案例当作每次任务的全量清单。
- 局部维护直接实施和定向验证；重要能力、实质歧义或高风险变更才按需启用设计、计划和独立审核。普通改动不强制创建过程文档或派子智能体。
- 验证范围由行为和风险决定。所需检查通过、无已知阻塞后结束；只有新修改、失败或具体未解决风险才扩大或重复验证。
- 汇报改动结果、验证范围和未解决问题，保持简短；详细日志按需引用，不逐项复述清单。

## 权威入口

- Skill 路由：`skills/SKILL.md`
- 后端开发：`skills/backend/core.md`
- 前端开发：`skills/frontend/core.md`、`skills/frontend/ui.md`、`skills/frontend/table.md`
- 插件开发：`skills/plugins/core.md`、`skills/plugins/frontend.md`、`skills/plugins/lifecycle.md`
- 部署与构建：`skills/ops/deploy-ops.md`、`skills/ops/build-release.md`
- 数据库结构导出：`skills/db-structure.md`
- 完成检查：`skills/finish-check.md`
- 远程发布：`skills/remote-release.md`

## 核心命令与平台边界

- `make test`：在容器内并行运行后端测试。
- `docker compose exec -T app ./vendor/bin/pint --test`：检查 PHP 格式。
- `docker compose exec -T app ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G`：运行后端静态分析。
- `pnpm lint:check`、`pnpm build`：只读检查并构建 admin/user 前端；`pnpm lint` 会修改源码，局部改动的检查入口见完成检查 skill。
- `make check-agent-config`：检查共享智能体配置和 Claude/Codex 薄入口防漂移。
- `/finish-check`：按 `skills/finish-check.md` 默认快检；高风险变更或明确要求完整检查时升级，按所选模式完成适用门禁。
- 正式发布只按 `skills/remote-release.md` 执行；命令成功不等于发布完成，必须完成其中的远端验收。

## 更新原则

- 只记录长期有效、项目级、会影响智能体行为的规则；不写临时决策、调试记录或单一模块实现细节。
- 新增内容前先判断职责：领域知识和工作流写入对应叶子资源，本文只保留入口和不可违反的项目约束，不复制正文。
- 只直接维护 `AGENTS.md`；`CLAUDE.md` 始终保持固定薄入口，不追加项目规则。
- 新增、删除或重命名 skill 时，同步更新 `skills/SKILL.md` 和受影响的引用入口。
- 修改后删除失效或重复内容，并检查 `CLAUDE.md` 固定模板、skill 路由、引用路径和确定性防漂移门禁；未经明确需求不得新增全局约束。
