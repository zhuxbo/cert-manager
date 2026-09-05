# 认证与安全补强

## Token 认证体系

JWT 撤销黑名单由 `App\Auth\JwtBlacklistStorage` 显式写入 `runtime` 缓存仓库。普通 `cache:clear` / 管理端“安全清理缓存”不会解除已登出的 token；数据库恢复的运行态重置会清理该黑名单。首次从旧缓存库切换到 `runtime` 时，`2026_09_04_000001_invalidate_sessions_for_runtime_cache_cutover` 迁移会递增全部账号的 `token_version`、清空 refresh token。该迁移在升级冻结期间通过 `shouldRun=false` 跳过且不记入 migrations，成功收尾后才执行；失败升级不吊销，已记录该迁移的实例后续升级不再吊销。待迁移期间 JWT 同时读取旧黑名单，`cache:clear` 暂缓清理，`cache:clear-all` 拒绝运行，避免旧 token 在升级途中复活。仅首次切库升级完成后要求用户和管理员重新登录一次。

| Token 类型  | 中间件              | 路由前缀               | 用途             |
| ----------- | ------------------- | ---------------------- | ---------------- |
| ApiToken    | `api.v1` / `api.v2` | `/api/v1/`, `/api/v2/` | 第三方 API 调用  |
| DeployToken | `api.deploy`        | `/api/deploy/`         | 部署工具证书管理 |

### 共同特性

- Token 使用 SHA-256 hash 存储
- 支持 IP 白名单（最多 100 个，`allowed_ips` 字段 2000 字符）
- 支持速率限制（`rate_limit` 字段，每分钟请求数）
- 请求结束后异步更新 `last_used_at` 和 `last_ip`

### DeployToken 特性

- 每个用户仅一个 DeployToken（唯一约束）
- 通过 `UserScope` 限制只能访问用户自己的 Order
- 支持查询证书、续费/重签、部署回调
- 认证失败（token 缺失/无效/被禁用、账号禁用、IP 不允许）与限流均返回 HTTP 200 + `code=0`，靠 `errors.error_code` 给下游机器可读分类，取值见 `App\Support\ApiErrorCode`；契约详见 [deploy-renewal.md](deploy-renewal.md)

### certimate URL 拉取模式

- `GET /api/deploy/?order={id|domain}&field=certificate|private_key`：返回纯 PEM 文本（`Content-Type: text/plain`），适配 certimate `BizUpload` 节点 URL 源
- `field=certificate` 返回 `cert + intermediate_cert`（fullchain）；`field=private_key` 返回私钥
- `order` 支持单个数字 ID（跟随 renewed 链）或单个域名（按 `common_name` 精确匹配，`issued_at` 降序取最新 active 证书），续费后 certimate URL 无需变更
- 不带 `field` 时走 JSON 响应：`order` 必填且仅接受订单 ID（多个逗号分隔，上限 100），无分页字段；域名形态**只在带 `field` 时**受理（见 [deploy-renewal.md](deploy-renewal.md)）

### 凭据不进 URL：用短时签名 URL

- **JWT access_token 是全权限长效凭据，绝不可拼进 URL query** —— 进了就落入浏览器历史 / 服务端 access log / Referer，被截获即等于泄漏该用户全部 API 权限。
- iframe / img / `<a download>` 这类无法带 `Authorization` header 的场景（如文档预览/下载），**一律用分钟级短时签名 URL**（`URL::temporarySignedRoute` + `signed` 中间件验签），不复用 access_token。
- 文档预览实现（参考）：`GET order/document-preview-url/{id}`（走 JWT header 鉴权 + 归属校验）返回 `temporarySignedRoute('{role}.order.document-preview', now()->addMinutes(10), ['id' => $id])`；`order/document-preview/{id}` 路由用 `withoutMiddleware([JWT 中间件类])->middleware('signed')` 脱离 JWT、仅验签名。归属安全链：取 URL 接口经 UserScope 限本人（admin 全局）→ 签名防 docId 篡改 → signed 预览路由本身无需再查归属。
- 前端两步：先调「取签名 URL」接口（header 带 JWT），再把返回的签名 URL 作 iframe/img/下载 src。`withoutMiddleware` 移除组中间件需传**展开后的中间件类名**（不是组别名）；`route:list` 仍显示组名属正常（运行时 pipeline 才排除），以 HTTP 测试「无 JWT + 有效签名 → 200」验证真正生效。
- **坑（access_token 不进 URL 的前提）**：User 控制器构造函数若有 `$this->guard->id() || $this->error()` 登录校验，必须对签名预览路由（`request()->routeIs('user.order.document-preview')`）放行，否则无 JWT 的签名请求在构造函数就被挡死、返回「用户不存在」（HTTP 200 JSON）—— 签名预览形同虚设。HTTP 测试须断言**真文件流**（`content-type=application/pdf` / `attachment` disposition），只 `assertOk()` 会因 200 JSON 假绿。Admin 控制器构造函数无此登录校验，故不受影响。

---

## 安全补强

### 邮件验证码防爆破（VerifyCodeRateLimiter）

- 中间件 `App\Http\Middleware\VerifyCodeRateLimiter` 挂在发码/校验路由（注册、重置密码）；`VerifyCodeHelper` 按失败次数计数 + 冷却，验证码用 `random_int` 生成
- 重置密码接口去掉 `exists:users,email` 校验（避免邮箱枚举），邮箱不存在也返回统一成功文案

### 归档解压统一防护（ArchiveGuard）

- `App\Services\Upgrade\ArchiveGuard`：`assertSafeEntries()`（解压前校验 zip 条目无 `..`/绝对路径/symlink 逃逸）+ `assertExtractedWithin()`（解压后校验落地路径在目标目录内）
- 所有解压路径统一走它：`PackageExtractor`（升级）/ `BackupManager`（备份）/ `PluginManager`（插件安装）。**新增解压点必须接入**，不要各写各的 zip-slip 校验

### sync 终态守卫（防复活）

- `Order\Action::sync`/`Acme\Action::sync` 锁内用上游状态回写本地前，若本地已是终态（`cancelled`/`revoked`/`renewed`/`reissued`/`failed`）则 `unset($data['status'])`，防上游旧状态把已取消/已吊销订单复活回 active（与 commitCancel 串行化配合）
- **Acme 侧另有 `(cancelling, active)` 单格拦截**（D1/P1-4）：本地 `cancelling` 时**仅挡上游滞后 `active` 回写**、放行终态（cancelled/revoked/expired），判定用锁内重取行（读=写同一行）——否则详情页 `syncDirectoryUrl` 只读回源就能把 cancelling 翻回 active，延时 `cancel_acme` 任务到点因状态非 cancelling 抛错 → 取消静默失败（不退费、订阅存活）。**Order 侧 cancelling→active 可回写是有意设计**（配合 `refundForSyncedCancel` 与 revokeCancel-后-sync 恢复链），两侧不对称是深思结果，勿顺手「对称化」

### sync 回调抑制（下游 pull 不冗余回调）

- `Order\Action::sync(int $orderId, bool $force = false, bool $suppressCallback = false)` 第三参 `suppressCallback=true` 时跳过状态变终态后对下游的主动回调（`createTask($orderId, 'callback')`），**仅抑制回调创建**，`deleteTask` 与退款 `Transaction` 照常。该参数透传给 `refundForSyncedCancel($order, $data, $suppressCallback)`，覆盖 sync 的两条回调路径（主路径 + 同步退款取消路径），两处 `DB::transaction` 闭包 `use` 均捕获它
- **按"触发来源"gate，不按订单 channel**：仅 V1/V2 `ApiController::get`（下游主动 pull）两个入口传 `true` —— get 内 sync 后已重新查询并把新状态同步返回给下游，再异步回调即多一次冗余触发。后台 `TaskJob`（动态单参调用）、手动 `sync`、`PurgeCommand`/`ValidateCommand`、Action 内部 sync 均不传 → 默认 `false` → 回调照常。**反例（已规避）**：按 `channel='api'` 一刀切会误杀"后台 sync 把订单推进到 active"时对不轮询客户的必要回调，故必须按入口而非订单来源判定

### 节流统一 Cache::add 原子占位

**坑**：业务防重/节流若用 `Cache::get` 判断 + `Cache::set` 写入（check-then-act），两步之间有窗口，并发请求都读到空 → 都放行 → 击穿（重复调上游 sync/pay/commit、超发验证码）。

**规则**：所有"N 秒内防重复"节流统一用 `Cache::add($key, $val, $ttl)`（SETNX 语义，redis/database/array driver 均原子）—— 返回 true = 抢占成功放行，false = 已有占位拒绝。已落地的范式（新增节流点照此写，勿再用 get+set）：

- `ActionTrait::checkDuplicate`（Order new/batchNew/renew/reissue/sync/revalidate/updateDCV 7 入口防重）：返回 `0`=放行 / `>0`=剩余秒数拒绝；`Cache::add` 抛异常时 catch 降级**放行**（return 0），不阻塞业务
- `Acme\Action::sync` 内联 `acme_sync_` 节流：占位放在 `find`+api_id 校验**之后**、上游调用**之前**（acme 不存在始终走 error，不因占位变 success）；占位后删除原末尾 `Cache::set`
- V1/V2 `ApiController::get` 的 `api_get_`：`Cache::add` 决定是否进 sync/pay/commit（并发只放一个）；**末尾保留 `Cache::set`** 按最终 status 刷新滑动窗口（active=120s/其他=10s，避免已签发证书每 10s 重复 sync 打上游）
- `VerifyCodeHelper::checkSendCooldown` 发送冷却：占位前移到发送前 → **所有发送失败路径必须 `releaseSendCooldown` 释放占位**（sendSmsCode else / sendEmailCode 未配置 return / catch），否则失败后正常用户白卡 60s；今日超限也要释放冷却
- 计数型配额（每日上限）仿 `AliyunDriver::enforceAndIncrementDailyQuota`：`Cache::add(0)+increment` 后判 `>limit`，超限 `decrement` 回滚

**例外（不必改）**：登录限流 `LoginRateLimiter` / `VerifyCodeRateLimiter` 走 Laravel `RateLimiter` facade，`tooManyAttempts`+`hit` 有固有 TOCTOU（注释已承认，登录/发码场景可接受）；自定义 `RateLimiter` 中间件滑动窗口已是 `Cache::add+increment` 原子；互斥锁用 `Cache::lock`（`ValidateCommand`/`SnowFlake`/Backup Job）。

### 批量操作上限（`config/batch.php`）

- `max_ids=100`：所有 `GetIdsRequest` 的 ids 数量上限（`BaseRequest::messages` 统一错误文案）
- `max_upstream=20`：batchPay/batchCommitCancel 等"逐条调上游"循环的硬上限，防单请求打爆上游
- **`GetIdsRequest` 规则单点**：共享的 `ids` 数组+max 规则收敛进 `BaseRequest::idsRules(string $idRule)`，子类 `rules()` 调它即可。**两个行为族不可混淆**：exists 族传 `'integer|exists:表名,id'`（任一 id 不存在则整体校验失败拒绝），filter 族传 `'integer'` + 各自 `passedValidation()` 用 `Model::whereIn` **静默剔除**未知 id（如 Order/Acme，UserScope 已自动限当前用户范围）。新增子类按语义选族，勿把 exists 退化为 filter 或反之。

### Controllers/Concerns 共享 trait（DRY 收口）

`App\Http\Controllers\Concerns` 是控制器层去重的统一去处：`ResolvesContactId`（企业-联系人 contact_id 解析）、`HandlesEnterpriseLookup`/`HandlesZipcodeLookup`（工商/邮编查询 admin·user 端逐字相同的方法体）。Admin/User 两端逐字相同的控制器方法优先抽 trait 而非复制（类名/方法名/可路由性不变，路由按类名引用）。阿里云 composer 镜像命令收敛进 `App\Services\Composer\ComposerMirror`（仅命令字符串+网络探测，执行器/`FORCE_CHINA_MIRROR`/日志保留各调用方）。

### 控制器 request 取值：`input($key, $default)` 默认值对显式 null 不生效

`$request->input($key, $default)` 的默认值**只在请求体不含该 key 时生效**；客户端显式传 `{"key": null}`（JSON null）时 key 存在、值为 null，`input()` 返回 **null** 而非 `$default`（底层 `data_get` 仅在 key 缺失时才回落默认）。`query()`/`get()`/`post()`/`Arr::get`/`data_get` 同理。该 null 流入**非 nullable 类型参数**（Action 方法 `string`/`int`、`Hash::make`/`Hash::check(string)` 等）即 `TypeError` → 500。曾致 V2/V1 `updateDCV`·`download`、`login`、`upgrade/releases` 多端点线上 500——**API 入口尤危**，下游把空字段序列化成 `"k":null` 即触发。

**取值后要喂给类型化参数的，一律 cast 或 `??` 兜底**，不能依赖 `input` 第二参：

- string：`(string) $request->input('k')`（null/缺失→`''`）
- 带字符串默认：`$request->input('k', 'all') ?? 'all'`（与 V2 download 一致）
- int（**须保默认值**）：`(int) ($request->input('k') ?? 5)`——**不可** `(int) $request->input('k', 5)`：显式 null 时 input 返回 null、`(int) null = 0`，默认值丢失

**无需改的安全情形**：值仅用于 Eloquent `where()`（接受 null）/ 前置有 `! $x && $this->error(...)` 非空守卫 / 方法有 `Validator::make`·FormRequest 的 `required` 在使用前拦截 / 下游形参是 `mixed`·`?string`。内部函数（`trim`/`escapeshellarg`）传 null 在未开 `declare(strict_types=1)` 时仅 deprecation、不 500，但仍应 cast 收口。

**排查**：`grep -rE "\->(input|query|get|post)\('[^']+',\s*[^)?]" app/Http/Controllers/` 列出带默认值取值点，逐个追踪是否「流入非 nullable 用户方法参数 + 中间无守卫/校验」。
