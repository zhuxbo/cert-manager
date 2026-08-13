# 退款矩阵

本文件是普通证书订单与 ACME 订单退款行为的总览。金额计算、事务锁、防重和资金审计的实现细节见 [订单资金](order-fund.md)；ACME 状态机与上游交互见 [ACME 模块](acme-module.md)。

## 普通证书订单

| 入口                             | 本地前置状态                                                                                                           | 是否退款   | 金额口径                                                                                 | 退款期                           | 最终状态与前驱处理                                                                    | Task 处理                                                                   |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ---------- | ---------------------------------------------------------------------------------------- | -------------------------------- | ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| 用户取消未支付订单               | `unpaid`                                                                                                               | 否         | —                                                                                        | 不检查                           | 删除当前订单；renew/reissue 恢复前驱证书                                              | 不创建取消 task                                                             |
| 用户取消待提交 new               | `pending`、`api_id=null`                                                                                               | 是         | `OrderUtil::getCancelTransaction`：以 `order.amount` 为基准，并核对订单交易净额          | 不检查                           | 当前证书置 `cancelled`                                                                | 删除 `commit`                                                               |
| 用户取消待提交 renew             | `pending`、`api_id=null`                                                                                               | 是         | 同上                                                                                     | 不检查                           | 前驱恢复 `active`；当前证书置 `cancelled` 并释放 `last_cert_id`                       | 删除 `commit`                                                               |
| 用户取消待提交 reissue           | `pending`、`api_id=null`                                                                                               | 是         | 只退本次 reissue 增量 `cert.amount`，数量取最后一笔增量交易的反向值                      | 不检查                           | 回切 `latest_cert_id`、前驱恢复 `active`、删除当前 reissue 证书                       | 删除 `commit`                                                               |
| 自动清理 pending 孤儿单          | `channel=auto`、`pending`、`api_id=null`、提交重试到顶、非产品缺失、无执行中 `commit`                                  | 是         | 委派上述 `cancelPending`，按 action 使用对应口径                                         | 不检查；无开关，满足条件必须退款 | 同对应 pending 取消                                                                   | 同对应 pending 取消                                                         |
| 用户主动取消已提交上游订单       | `processing` / `approving` / `active`，随后进入 `cancelling`                                                           | 是         | new/renew/reissue 均使用 `OrderUtil::getCancelTransaction`，按订单金额与全部交易净额核对 | 提交取消和实际执行取消时检查     | 当前证书置 `cancelled`；已提交 reissue 终结整单，前驱保持 `reissued`，不恢复、不回切  | 提交时删除 `sync/revalidate` 并创建延时 `cancel`；任务消费后由 TaskJob 收尾 |
| 同步发现上游已取消并自动退款     | 本地 `processing` / `approving` / `cancelling`，action 为 new/renew/reissue，上游 `cancelled`，`autoRefundOnSync=true` | 是         | new/renew/reissue 均使用 `OrderUtil::getCancelTransaction`，按订单金额与全部交易净额核对 | 不检查；以上游已取消为权威       | 当前证书置 `cancelled`；已提交 reissue 不恢复前驱                                     | 在退款事务内删除 `cancel/commit/sync/revalidate`；按需新建 `callback`       |
| reissue 同步取消遇到历史退款流水 | 同上，但该订单已有 pending reissue 增量 `cancel` 流水                                                                  | 否，转人工 | 既有增量流水不能冒充整单已退，且唯一索引禁止追加第二笔                                   | 不检查                           | 整笔同步收尾回滚，保持原状态                                                          | 保留现有 task，并释放 sync 防重复占位，立即重试仍明确失败并转人工           |
| 同步发现上游已取消但自动退款关闭 | 可同步状态，上游 `cancelled`，`autoRefundOnSync=false`                                                                 | 否         | —                                                                                        | —                                | 只写回上游终态                                                                        | 按通用同步路径处理                                                          |
| active 同步到取消类终态          | 本地 `active`，上游 `cancelled` / `revoked`                                                                            | 否         | —                                                                                        | —                                | 只写回终态；CA 可能用取消或吊销表示相近结果，无法可靠判断是否应退，极少量异常人工处理 | 按通用同步路径处理                                                          |
| 同步发现上游吊销                 | 上游 `revoked`                                                                                                         | 否         | —                                                                                        | —                                | 写回 `revoked` 并发送对应通知                                                         | 按通用同步路径处理                                                          |

### 普通订单金额原则

- 已提交上游后，new、renew、reissue 取消统一按订单口径退款，不按当前证书的 reissue 增量退款。
- `OrderUtil::getCancelTransaction` 先取 `order.amount`，同时核对同一订单全部 `order/cancel` 交易净额；不一致时以交易净额为准并告警。
- 只有尚未提交上游、取消后会恢复前驱订单状态的 pending reissue，才只退本次增量。
- `transactions` 唯一索引和 `Transaction::creating` 防重共同保证同一订单不会产生第二笔取消退款。

## ACME 订单

| 入口                           | 本地前置状态                                                   | 是否退款           | 金额口径                                                                         | 退款期                                   | 最终状态                                                  | Task 处理                                  |
| ------------------------------ | -------------------------------------------------------------- | ------------------ | -------------------------------------------------------------------------------- | ---------------------------------------- | --------------------------------------------------------- | ------------------------------------------ |
| 取消未支付 ACME                | `unpaid`                                                       | 否                 | —                                                                                | 不检查                                   | `cancelled`                                               | 不创建取消 task                            |
| 取消未提交上游 ACME            | `pending`、`api_id=null`                                       | 是                 | `OrderUtil::getCancelTransaction`：以 ACME `amount` 为基准，并核对 ACME 交易净额 | 不检查                                   | `cancelled`                                               | 不创建取消 task                            |
| 提交已上游 ACME 取消           | `active`，或 `pending` 且已有 `api_id`                         | 暂不退款           | —                                                                                | 检查；超期拒绝进入取消中                 | `cancelling`                                              | 创建延时 `cancel_acme`                     |
| 立即或延时执行已上游 ACME 取消 | `active`（立即取消）或 `cancelling`（延时取消），且有 `api_id` | 上游取消成功后退款 | 同上，创建 `acme_cancel` 冲正流水                                                | 调上游前重新检查；超期不调用上游、不退款 | 上游返回 `revoked` 则落 `revoked`，否则落 `cancelled`     | 立即取消不建 task；延时任务由 TaskJob 收尾 |
| cancelling 同步到上游终态      | 本地 `cancelling`，上游 `cancelled` / `revoked` / `expired`    | 是                 | 同上，创建 `acme_cancel` 冲正流水                                                | 补退款前检查；超期整笔回滚               | 期内落上游终态；超期保持 `cancelling` 转人工              | 期内删除 `cancel_acme`；超期保留 task      |
| active 同步到取消类终态        | 本地 `active`，上游 `cancelled` / `revoked`                    | 否                 | —                                                                                | —                                        | 只写回终态；因 CA 取消/吊销语义无法可靠区分，异常人工处理 | 按通用同步路径处理                         |
| cancelling 已退款订单同步终态  | 本地仍为 `cancelling`、上游已终态，且已有 `acme_cancel` 流水   | 否，不重复退款     | —                                                                                | 不重复检查已完成的退款                   | 只补齐终态                                                | 删除残留 `cancel_acme`                     |

### ACME 退款期原则

- 未提交上游的 `pending + api_id=null` 必须退款，不受退款期限制。
- 已提交上游后，在提交取消、实际调用上游取消、`cancelling` 同步终态补退款三个时点检查产品 `refund_period`。
- 边界时刻仍允许取消；只有当前时间严格晚于 `created_at + refund_period` 才判定超期。
- active 状态只是同步到取消类终态时不自动退款，因此不进入退款期判断；该类极少量异常由人工核对处理。

## 共同资金约束

- 退款流水、余额变化、状态更新和必要的 task 清理必须位于同一事务边界。
- 资金路径使用数据库唯一索引作为最终防重底线，应用层 `exists` 只作提前拒绝或幂等分流。
- pending 自动退款必须先确认未提交上游；锁内若发现 late commit 已推进状态，拒绝退款并交给后续流程。
- 同步退款失败必须释放事务外的防重复占位，避免立即重试被伪装成成功；成功同步继续保留短期防重复占位。
- 所有新增或修改的资金测试必须纳入 `FundAuditGuard`，完整门禁同时执行资金恒等式和 `finance:audit`。
