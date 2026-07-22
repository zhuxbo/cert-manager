- [2026-06-01T20:05:55+08:00] 1.0.0
  - test: P\Tests\Feature\Http\Controllers\Admin\DeployTokenControllerTest::__pest_evaluable_管理员可以查看部署令牌详情
  - reason: A1: admin 详情接口移除 token 明文字段，防跨用户泄漏部署凭据

- [2026-06-01T20:05:55+08:00] 1.0.0
  - test: P\Tests\Feature\Http\Controllers\Admin\DeployTokenControllerTest::__pest_evaluable_管理员可以批量获取部署令牌
  - reason: A1: admin 批量详情接口移除 token 明文字段

- [2026-07-17T18:50:30+08:00] 1.0.0
  - test: P\Tests\Feature\Http\Controllers\User\AuthControllerTest::__pest_evaluable_重置密码_验证码无效返回错误
  - reason: audit-2026-06: reset-password 移除 exists:users,email 枚举校验，未注册邮箱不再返回 errors.email

- [2026-07-17T18:50:30+08:00] 1.0.0
  - test: P\Tests\Feature\Http\Controllers\User\AuthControllerTest::__pest_evaluable_重置密码_不暴露邮箱是否注册（账号枚举消歧）
  - reason: audit-2026-06: reset-password 对存在/不存在邮箱统一返回成功式响应
- [2026-07-22T13:07:09+08:00] 1.0.0
  - test: P\Tests\Feature\Http\Controllers\MetaControllerTest::__pest_evaluable_平台设置缺失时返回与现有静态配置一致的默认值
  - reason: platform-config-2026-07: site.dnsTools 缺失时移除程序硬编码回落并返回空数组
