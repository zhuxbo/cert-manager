# API 兼容性快照对照

固化 v(N-1) 的 HTTP 响应 schema 为 JSON fixture，vN 跑同样测试时与 fixture 对比，
检测意外的 schema 变化（字段增减 / 类型改变 / status 变化）。

## 目录结构

```
tests/Compat/
├── README.md                # 本文档
├── SchemaDiffer.php         # schema 提取 + 差异计算工具
├── SnapshotListener.php     # Pest listener，监听 RequestHandled 事件
├── Helpers.php              # fixture 路径规范化 / 模式判定
├── GlobalFunctions.php      # expectsBreakingChange() 全局函数
├── BREAKING_CHANGES.md      # 累积变更日志（capture 时自动追加）
├── diff-report.md           # 失败时累积写入的 diff（CI 上传 artifact）
└── fixtures/                # 每个测试用例对应一个 JSON
    └── Tests_Feature_Http_Controllers_*.json
```

## 工作模式（环境变量驱动）

| 环境变量              | 模式    | 行为                                                |
| --------------------- | ------- | --------------------------------------------------- |
| 无                    | 默认    | 零开销，对所有现有测试无影响                        |
| `COMPAT_CAPTURE=true` | capture | 监听 RequestHandled → 抽 schema → 写 fixture        |
| `COMPAT_COMPARE=true` | compare | 监听 RequestHandled → 与 fixture 对比 → 差异时 fail |

## 常用命令

```bash
# 重新生成全套 fixtures（v(N-1) 分支跑）
COMPAT_CAPTURE=true php artisan test tests/Feature/Http/Controllers --parallel
# 或：
composer test:snapshot:capture

# 跑对照（vN 分支跑）
COMPAT_COMPARE=true php artisan test tests/Feature/Http/Controllers --parallel
# 或：
composer test:snapshot

# Compat 自身的单元测试（SchemaDiffer + 自检）
php artisan test tests/Unit/Compat/
```

## Fixture 格式

```json
{
  "test": "P\\Tests\\Feature\\Http\\Controllers\\Admin\\AcmeControllerTest::__pest_evaluable_index_返回列表",
  "version": "1.0.0",
  "calls": [
    {
      "method": "GET",
      "uri_pattern": "/api/admin/acme",
      "request_keys": null,
      "response_status": "2xx",
      "response_schema": {
        "code": "integer",
        "data": {
          "items": { "__list_of__": { "id": "integer", "user_id": "integer", ... } },
          "total": "integer"
        }
      }
    }
  ]
}
```

**关键约束**：

- fixture **不含真实值**（id / 时间戳 / 邮箱等），仅含 schema → 可以 commit 进 git
- HTTP status 归类为 `2xx` / `4xx` / `5xx` 等，避免 200/201/204 抖动
- URI 优先用 Laravel route pattern（`/api/admin/order/show/{id}`），fallback 把数字段抽象为 `{id}`、长 token 段抽象为 `{token}`
- 同一测试同一 endpoint 多次调用仅记录首次（避免 token 一次性消费等场景导致 fixture 不稳定）
- 自动剥离仅 `APP_DEBUG=true` 时输出的调试字段（`errors.exception_type` / `errors.exception_trace`），保证本地与 CI 采集结果一致

## 预期破坏性变更：豁免

显式 API 改造时在测试用例顶部声明：

```php
test('admin can update user', function () {
    expectsBreakingChange('v1.1.0: 移除 user.password 字段，由独立 reset-password 端点处理');

    // ... 测试逻辑（即使 schema 不匹配也豁免）
});
```

豁免的副作用：

- compare 模式：跳过该用例的 schema 校验
- capture 模式：fixture 仍然被新 schema 覆盖
- 同时把 reason + 测试名追加到 `tests/Compat/BREAKING_CHANGES.md`，PR review 时可看到累积变更日志

## CI 集成

`compat-snapshot` job 仅在 push to main / release tag 时触发（PR 不阻断）：

- mysql + PHP 8.3 单组合（snapshot 与 db driver 无关）
- 失败时上传 `diff-report.md` 作为 artifact

## 何时刷新 fixture？

| 场景                            | 处理                                                |
| ------------------------------- | --------------------------------------------------- |
| API 加新字段（向后兼容）        | capture 重新跑 → commit fixture 改动                |
| API 删字段 / 改类型             | 在用例加 `expectsBreakingChange` + capture + commit |
| 新增测试用例                    | capture + commit                                    |
| 仅改控制器内部不影响响应 schema | 无需操作（schema 不变 fixture 无 diff）             |

## 已知限制

1. listener 只看 `/api/*` 路径（前端静态资源、健康检查等不入 fixture）
2. JsonResponse 与普通 Response 的 content 处理略有不同；空 body 或非 JSON body 的响应 schema 记为 `null`，diff 时 null↔null 兼容
3. fixture 文件名包含中文，不在路径里跨系统迁移会有问题（git 默认 UTF-8 OK）
4. `Mockery` mock 的 controller action 不返回完整响应时，fixture schema 可能为 `null`（属正常）

## 故障排查

**`Call to undefined function expectsBreakingChange()`**
→ 确认 `tests/Pest.php` 内有 `require_once __DIR__.'/Compat/GlobalFunctions.php';`

**fixture 数量异常少**
→ 检查 RefreshDatabase 是否吞了 listener；listener 通过 `Tests\TestCase::setUp` 注册，每次跑都重注册

**fixture 文件名重复（多个测试覆盖同一 fixture）**
→ Pest `Str::evaluable` 会把英文部分压缩；如果中文测试名英文部分一致则可能冲突 — 改测试名即可
