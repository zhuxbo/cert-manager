# Certum 验证文档上传

Certum（及 OV/EV 等非 DV 产品）的验证文档上传：用户/Admin 上传 → 本地 `order_documents` 表 → base64 经 V2 API 逐级提交到上游 → 上游系统 → Certum SOAP。

**核心安全**：证书签发后（`latestCert.status==='active'`）禁止再上传；上传即自动异步转发上游，依赖 queue worker 常驻。

## 存储与显示

- **独立表**：`order_documents`（本地上传的文档）
- **不改 Cert.documents**：该字段仅存 Certum 同步回来的审核状态（只读），职责不同
- **多级代理传递**：用户/Admin 上传 → `order_documents` 表 → 提交到上游（base64 via V2 API）→ 逐级到 上游系统 → Certum SOAP
- **V2 端点**：`POST /api/v2/upload-document`（接收下游 base64）
- **显示条件**：`brand.toLowerCase() === 'certum'` 且 `validation_type !== 'dv'`
- **文件限制**：单文件 5MB，类型 PDF/JPG/JPEG/PNG/XADES，控制器层 `mimes` 验证
- **提交权限**：Admin 和 User 均可提交文档到上游
- **新增列**：`submitted_at` / `submit_attempts` / `submit_error` / `content_hash`（迁移 `2026_05_31_100000_*`，幂等 `hasColumn` 守护）

## 签发后禁止上传

- 证书 `latestCert.status === 'active'` 后不再接受文档上传——`ActionDocumentTrait::uploadDocument`/`uploadDocumentFromBase64` 单点拦截（覆盖 Admin/User UI + V2 API 三入口，全仓写 `order_documents` 仅此二方法），前端 admin/user 的 process.vue 均在 active 态隐藏上传入口

## 异步提交上游 + 重试

- **提交上游异步化 + 重试**：`submitDocuments` 不再同步阻塞，改为每个未提交文档派发 `App\Jobs\SubmitDocumentJob`（`tries=3`、`backoff=[60,300]` 指数退避、`->afterCommit()`）。Job 调 `ActionDocumentTrait::submitDocument(int $docId)`：成功标 `submitted`+`submitted_at`、永久失败（文件缺失）记 `submit_error` 不重试、可重试失败（订单未提交/上游错误）抛异常退避重试；`failed()` 兜底记错 + `Log::error`。前端 `documentUpload.vue` 状态列展示 submitted/失败(submit_error)+轮询
- **上传即自动转发上游**：`uploadDocument`（UI 文件上传）与 `uploadDocumentFromBase64`（V2 接收下游）存档后**都**自动派发 `SubmitDocumentJob` 往上游转（含 dedup-hit 补转），多级链全自动、免手动点提交。前端「提交」按钮降级为兜底（仅 `unsubmittedCount>0` 时显示、全部 submitted 后隐藏；上传后前端轮询刷新状态）。**注意：自动转发仍依赖 queue worker 常驻**——无 worker 则 Job 滞留 `jobs` 表、文档不会真正到达上游

## 跨级去重（content_hash）

- `order_documents` 加 `content_hash`(sha256) + 唯一索引 `(order_id, content_hash)`。**纯接收端**实现 —— `uploadDocumentFromBase64` 对解码字节算 hash，同 order 同内容已存在则跳过（重试/重复推送幂等），唯一索引兜底并发竞态（catch `QueryException` errorInfo 1062）。**发送端/线协议不变**，旧版下游推到新接收端也能去重。Gateway 侧 `V2/ApiController::uploadDocument` 镜像同一逻辑（对端对称）。防止 Job 重试在上游产生重复行 → Certum 重复提交
