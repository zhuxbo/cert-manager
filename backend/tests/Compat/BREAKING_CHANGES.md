- [2026-06-01T20:05:55+08:00] 1.0.0
  - test: P\Tests\Feature\Http\Controllers\Admin\DeployTokenControllerTest::__pest_evaluable_管理员可以查看部署令牌详情
  - reason: A1: admin 详情接口移除 token 明文字段，防跨用户泄漏部署凭据

- [2026-06-01T20:05:55+08:00] 1.0.0
  - test: P\Tests\Feature\Http\Controllers\Admin\DeployTokenControllerTest::__pest_evaluable_管理员可以批量获取部署令牌
  - reason: A1: admin 批量详情接口移除 token 明文字段

