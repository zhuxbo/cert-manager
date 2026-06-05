# Review Checklist — 反模式清单(单一来源)

> 这份清单来自项目实际遭遇过的回归。每条都有真实案例锚定,不是泛泛工程"最佳实践"。
>
> **两处共同引用**:
>
> - `/finish-check` 阶段 8 Review 循环 — reviewer subagent 必查清单(反模式 1-21)
> - plan / 设计阶段 — 写实现前的"杀手场景 + 对端检查"两栏(设计期清单章节)
>
> **维护原则**:每次真实回归后,把根因抽象成"反模式"加进来,带案例锚定;不写空泛规则。

---

## 反模式分级（reviewer 应用范围）

- **核心层**（每轮 reviewer 必扫，与改动无关）：反模式 1（失败路径数据安全）、2（端到端机制可达性）、3（对称性）、6（现有正确范式优先）、9（资金路径四道网）、15（伪绿测试）
- **条件层**（按改动目录/文件类型触发）：
  - 改 backend → 加 10（锁顺序）、11（afterCommit）、13（Transaction 事务）、16（ApiResponseException 消息）、20（并发 check-then-act / 死事务续写）
  - 改 .sh / 部署脚本 → 加 4（同类扩散）、7（set -e 笔误）
  - 改外部命令调用 → 加 12（BinaryLocator + 开发机/生产环境差异）
  - 改公开 API / 新增 public 方法 → 加 5（新方法边界测试）、8（数组键类型混淆）
  - 改 tests/（新增 / 修改测试）→ 加 14（测试 flaky 四源）
  - 改鉴权 / 下载 / 解压 / CORS / 通知 / 公开端点（安全面）→ 加 17（免登录端点与凭据暴露）、18（外部输入下载 / 解压纵深防御）、19（敏感数据落库与响应头基线）
  - 改 migrations / 升级同步 / 数据库结构 → 加 21（部署 / 迁移静默失败）

---

## 反模式 1: 失败路径数据安全

每个 `exit / throw / return false / die` 前,**画出现场状态**:

- 当前是否处于事务 / 锁 / 维护模式?
- 数据是否已经被 `mv` / `rm` / 截断?备份是否完整?
- 用户能否恢复?恢复需要哪些信息?
- `trap cleanup` / Laravel exception handler 会做什么?会不会加剧损失?

**真实案例**:`upgrade.sh` 把 storage `mv` 到 `TEMP_DIR/preserve`,然后做环境检测;失败时 `trap cleanup` 删 TEMP_DIR → storage 永久丢失,且备份不含 storage。
**修复**:把检测前移到任何 mv/rm 之前。

---

## 反模式 2: 端到端机制可达性

新增的"防御机制"必须自检:**主路径外的产出物真的被消费方读到了吗?**

- 配置文件 / 清单是否在所有包形态(full / upgrade / script)里都存在?
- 解压后的目录结构,消费方查找路径正确吗?
- **实际制造一次失败,这套机制有没有真的拦住?**

**真实案例**:做完整套 PHP 环境检测(`EnvironmentChecker` + 前端弹窗 + status_details),结果 `php-requirements.json` 没被打进升级包 → `EnvironmentChecker` 收到"文件缺失"走 skipped → 整套防御机制等于不存在。
**修复**:`package.sh` 加 cp + `PackageExtractor::findRequirementsJson()` 兼容多种解压形态。

**第二例(鉴权/签名/限流类防御被运行路径挡死或绕过 = 半修假绿)**:`76a2f58` 一次性补了三处"上一轮声称修了但运行路径根本没生效"的防御 —— ① 文档预览改签名 URL(`f58320c`),但 `OrderController::__construct` 无条件 `guard->id() || error('用户不存在')`,**无 JWT 的 signed 请求被构造函数挡死**,签名方法体永远走不到;② SSRF 校验用 `FILTER_FLAG_NO_RES_RANGE` 黑名单(`39cd024`),**169.254 云元数据被当 reserved 放行**;③ 改密吊销会话只修了 `updatePassword`(`dc97990`),漏了 `resetPassword`(账号已失陷的高危入口)。三处都不是"忘了写",是"写了但没在真实路径上生效",单元测试还绿(只断 `assertOk` / data provider 把应拒输入放进放行集)。
**教训**:对任何"声称有鉴权/签名/限流/回调校验"的机制,reviewer 必须**实际制造一次绕过请求**(无 token 的 signed 请求 / 169.254 的下载 / 改密后用旧 token)看是否真被拦住 —— 静态读到"加了防御代码"≠ 防御生效。这也是独立 reviewer 存在的根本理由:防"改完测试绿就停手"的半修。

---

## 反模式 3: 对称性 — 前后端 / shell 与 backend / admin 与 user

改一处时反问:

- backend 改了,shell 端 / Job / Command 有镜像逻辑吗?
- 改 admin 时 user 端是不是也要改?
- 同文件其他类似函数怎么写的?要不要风格统一?

**真实案例**:`H2` 修了 backend `dump-autoload` 失败抛异常,**忘了 shell 端镜像问题就在隔壁文件**,只 `log_warning` 继续跑,后续 migrate 必然 ClassNotFound。
**修复**:`upgrade.sh` `dump-autoload` 失败改 `exit 1` 阻断,与 backend 对齐。

---

## 反模式 4: 同类扩散

发现一个反模式时**全仓库 grep**:

- 笔误的函数名(`log_warn` vs `log_warning`)
- 错误的字符串拼接(`'$var'` 直接嵌入 PHP 字符串)
- 过时的 hardcode 列表
- 不该 catch 的 exception

**真实案例**:`_php_pretty_version` 三处复制;`python3 -c "import json"` 解析散在 8 处;`excluded_ext` hardcode 在 bt-deps.sh 和 python 一行脚本里两份。
**修复**:抽到 common.sh + grep 清光残留。

**第二例(对称注释失效)**:`_php_pretty_version` 收敛后 upgrade.sh 作为独立部署入口保留一份副本,两边用注释互相提示"修改时同步";但 commit 9dd8ce1d 实际写了两种算法 — `upgrade.sh` `echo "8.${ver: -1}"`(硬编码大版本号 "8")vs `common.sh` `echo "${ver:0:1}.${ver:1}"`(通用),PHP 8.x 时输出相同、PHP 9.x 时代会出错,第 5 轮独立 reviewer 才看出。
**教训**:注释 ≠ 技术保证 — 保留"对称副本"时,reviewer 必须实际 diff 两份代码逐行比对,不能因注释说"对称"就信;build 时加 grep 等价校验更可靠。

**强制配套**（commit 9dd8ce1d 第 5 轮 reviewer 教训的二次防御）：

凡在多处保留"对称副本"（如 upgrade.sh 与 common.sh 中各保留一份 `_php_pretty_version`），必须满足以下两条之一：

1. **build 时 grep 等价校验**：CI / package.sh 加一行 `diff <(sed -n 'PATTERN' upgrade.sh) <(sed -n 'PATTERN' common.sh)`，不等就 fail build
2. **运行时输出等价测试**：写一个 bash 用例同时调两个副本，断言输出一致；纳入 finish-check §2.3 测试集

仅靠注释"修改时同步" 是 **不够** 的（commit 9dd8ce1d 第 5 轮发现两份算法已经漂移但注释仍声称对称）。
新增对称副本 PR 必须在 finish-check 总结的"已知局限性"段列出"对端校验机制"。

---

## 反模式 5: 新方法必有边界测试

新加 public/protected 方法必须覆盖:

- 正常路径
- 空输入 / 缺失文件 / 非法格式
- 边界(0 / null / 极大值)
- 三种以上调用形态(如果有多种参数形态)

**真实案例**:`PackageExtractor::findRequirementsJson` 新加但没单测 — 没覆盖"根目录 vs 子目录 vs 缺失 vs 优先级"四种形态。
**修复**:加 4 个用例覆盖完整决策树。

---

## 反模式 6: 现有正确范式优先

写新函数前 grep 本文件 / 本模块,看有没有同类工具函数 / 已建立的范式:

- 不发明轮子
- 风格统一(参数传递方式 / 错误处理 / 日志格式)

**真实案例**:`common.sh` 已有 `_read_req_field` 用 env var 传 PHP 字符串安全范式,但 `upgrade.sh::_php_env_run_checks` 新写时换了一种直接嵌入 `'$req_file'` 的写法,路径含空格/单引号会断。
**修复**:统一改用 env var 传参。

---

## 反模式 7: `set -e` 下函数名 / 命令名笔误 = 静默退出

`set -e` + shell 里调不存在的函数 / 命令 = 脚本直接死。

- `log_warn` vs `log_warning`
- composer 选项 `--no-script` vs `--no-scripts`(都不报错但意图不同)
- 类似的同义词陷阱

**真实案例**:`log_warn "dump-autoload 失败(不阻断升级,可手动重试)"` — 注释明说"不阻断",实际 `set -e` + 函数不存在 → 升级中断;但 commit 时 lint 不报错。
**修复**:统一 `log_warning`,并把这条加入 reviewer 必扫项。

---

## 反模式 8: 数组键类型混淆

PHP 数组 `foreach ($args as $key => $value)` 中 `$key` 可能是 int(位置数组)或 string(关联数组),分支判断要先 `is_int($key)`,不要假设 key 一定是 string。

**真实案例**:`runArtisanInSubprocess('cmd', ['--ansi'])` 触发 `escapeshellarg("0=--ansi")` 拼成错误参数,导致 `package:discover` 失败被记为 warning。
**修复**:增加 `is_int($key)` 位置参数分支。

---

## 反模式 9: 资金路径四道网必须齐全

新增 / 修改 `funds` / `transactions` / `users.balance` 写入路径时,**四道网缺一即资金错乱**:

1. **DB 唯一索引**:`funds(pay_method, pay_sn)` + `transactions(type, transaction_id) WHERE type != 'order'` — 物理阻断重复入账
2. **CAS UPDATE 完整字段匹配**:`Fund::transitionToSuccessful` 走 5 字段 WHERE(id + amount + type + pay_method + status=0)而非 SELECT-then-UPDATE;CAS WHERE 不能简化为 status 单一条件(否则金额/支付方式不匹配的回调也会把本地 fund 标成功)
3. **`DB::transaction` 内 `lockForUpdate` + 锁内二次状态校验**:锁外校验会被并发绕过;支付路径必须同时锁 user 行(否则同一用户跨订单并发支付会绕过 credit_limit)
4. **Pest invariant 测试登记**:动了 funds/transactions/users.balance 的测试自动跑 `FundInvariants::all()` 4 条 SQL(账目恒等 / 事件唯一 / 状态-事件配对 / 金额配对)

**真实案例**:commit `4afe7313` (feat: 资金安全确定性体系（4 道网）)系统化补齐这四道网 — 在此之前应用层 `exists` 防重 + SELECT-then-UPDATE 状态转换均有竞态窗口,删除已入账 fund 等"删除 SQL 本身合法"的路径也无法被 CAS / 唯一索引拦截。
**修复**:四道网逐项核查,缺一不可。物理阻断(网 1-3)不能被替代为事后发现(网 4) — 已删除的 fund 即便 invariant 报 orphan transaction,钱已入账、订单已消失,损失已发生。

---

## 反模式 10: 锁顺序违反 = 死锁

`TaskJob::handle` 是 **task → order/acme** 顺序(先锁 task,action 内再锁业务行)。业务路径若反向(先锁 order 再锁 task) = InnoDB 周期性死锁回滚,用户看到随机失败。

**真实案例**:commit `3dd44b84` (fix: 资金/状态变更路径全面加行锁,消除并发竞态)统一所有修改 task 的业务路径(`Order::revokeCancel` / `commitCancel(active)` / `batchCommitCancel` / `Acme::revokeCancel` 等)按 task → 业务行 顺序,并修复 `TaskJob::handle` 整体包事务(否则 `lockForUpdate` 在自动提交模式下是"假锁",SELECT 返回即释放)。
**修复**:所有 DELETE / 修改 task 的业务路径,必须先 `Task::where(...)->lockForUpdate()->get()` 拿 task 锁,再锁业务行,再做 DELETE。新增涉及 task + order/acme 的事务路径,先 grep 现有路径确认锁顺序与之对齐。

---

## 反模式 11: 事务内 dispatch Job 缺 `->afterCommit()`

`config/queue.php` 所有连接默认 `after_commit=false`,事务内 `dispatch` 的 Job 会**立即入队**;worker 可能在事务提交前消费 Job,读不到事务内新建的行 / 状态,导致任务静默丢失(task 状态查无记录直接跳过)。

**真实案例**:commit `3dd44b84` 收尾时把所有事务内 `TaskJob::dispatch` 调用统一加 `->afterCommit()`(`createTask` / `createTasks` / 业务路径内手动 dispatch),避免 worker 抢跑外层事务。
**修复**:所有 `TaskJob::dispatch(...)` 调用必须 `->afterCommit()`;新增 Job dispatch 点也要跟进。grep 验证:`git grep -n "TaskJob::dispatch" backend/ | grep -v afterCommit` 应为空。

---

## 反模式 12: 外部命令未走 BinaryLocator

`exec("openssl ...")` / `exec("php ...")` / `exec("composer ...")` / `exec("mysqldump ...")` / `exec("curl ...")` 等裸命令在多版本 PHP 系统(如宝塔多 PHP 版本)/ open_basedir 限制 / `is_executable` 误判等场景会**走错 CLI 或假装找不到** — 探测必须走 `proc_open` 子进程,不能用 `is_executable` / `file_exists`。

**真实案例**:commit `9dd8ce1d` (feat: 升级链路加入 PHP 环境检测与流程加固)引入 `App\Services\Binary\BinaryLocator`,把所有外部命令调用收口 — 在此之前多版本 PHP 系统升级时 `exec("php artisan ...")` 走的是 system 默认 PHP(可能是 7.4)而非项目所需的 8.3+,导致升级运行时崩溃。
**修复**:所有 `exec` 类调用必须 `app(\App\Services\Binary\BinaryLocator::class)->find('tool')` 解析路径 + `escapeshellarg($path).' arg1 arg2'`,不允许变量插值或裸命令。失败抛 `BinaryNotFoundException`,调用方按场景 catch 静默降级(如 keytool 找不到跳过 JKS)或向上抛(升级流程内 PHP/composer 失败应阻塞)。

**第二例(开发机能跑 / 生产挂的环境差异 — 最隐蔽,本地全绿)**:`a546f16` 修了三处"开发机 CLI 跑得通、宝塔 FPM 生产挂"的探测假设 —— ① `open_basedir` 非空时 Symfony `ExecutableFinder` **强制只在 open_basedir 内目录找命令**,宝塔站点必不含 `/usr/bin` → FPM 下永远 miss、CLI 又被候选路径覆盖,留着只让差异被偷偷接住(删 `ExecutableFinder`,让 FPM/CLI 走完全一致路径);② openssl 探测参数 `--version` 在 **OpenSSL 3.0.x 不识别**(3.2+ 才加),Ubuntu 24.04 默认 3.0.13 在 FPM 下报"openssl 不可用",改 `version` 子命令(1.x/2.x/3.x 全系列支持);③ 宝塔 FPM `clear_env=yes` + `env[PATH]` 默认注释 → 子 `sh -c 'command -v'` 拿不到 PATH 必 miss,须显式注入 `SHELL_FALLBACK_PATH` 并返回绝对路径,不依赖调用方 env PATH。
**检查动作**:`git grep -n "ExecutableFinder" backend/app` 应为空;二进制探测参数选**全版本工具都支持**的形式(`openssl version` 而非 `--version`);探测 / exec 子进程不依赖父进程 `env[PATH]`(显式注入或返绝对路径);测试**不得用 `markTestSkipped` 兜底吞"工具找不到"**(否则再次让生产 bug 静默,见反模式 14)。

---

## 反模式 13: `Transaction::create` 在 `DB::transaction` 外

`Transaction::creating` 钩子内不再开自己的嵌套事务 / savepoint — 直接使用外层事务保证 `balance` 修改与 INSERT 的原子性。**非事务内调用会抛异常**,但新增资金路径若漏写 `DB::transaction` 闭包,会在生产命中异常导致请求 500。

**真实案例**:commit `3dd44b84` 把 `Transaction::creating` / `Fund::createRecord` 内嵌事务移除,改为强制调用方在 `DB::transaction` 内调用;同时新增异常提示"`Transaction::create` 必须在 `DB::transaction` 内调用(防止 balance 修改与 INSERT 非原子)"。
**修复**:所有 `Transaction::create` 调用前确认包在 `DB::transaction(fn)` 闭包内;`Fund::updating` 同理。资金事务优先用 `DB::transaction(fn)` 闭包(Laravel 自动管 commit/rollback),避免"`$row=null` 控制流穿透"导致的事务计数器漂移;如必须手写 `DB::beginTransaction` + try/catch,所有控制流分支必须 commit 或 rollback(含 no-row、early-return、异常路径),并补单元测试覆盖这些分支。

---

## 反模式 14: 测试 flaky 的不确定源(随机 / 共享磁盘 / 时钟 / 清理顺序)

测试偶发失败(本地绿、CI 偶红 / 只在 coverage+parallel 才复现)几乎都源于四类不确定性,写测试时主动收敛:

- **随机数据撞业务校验**:`fake()->jobTitle()` / `sentence()` / `word()` / `text()` / `catchPhrase()` 在 `faker_locale=zh_CN` 下无实现、回落基类 lorem,长度随机,喂给有长度/格式/枚举校验的字段时低概率越界。改 `randomElement([固定值])`。
- **并行共享真实磁盘**:paratest 各 worker 独立 DB 但**共享 `storage/` 真实目录**,一个测试造文件、另一个跑扫描/删除命令(按本 worker DB 判"孤立") → 跨 worker 误删。运行时 path + Storage 门面按 `TEST_TOKEN` 隔离到 worker 专属目录。
- **时钟不可控**:限流 / 滑动窗口 / 冷却用 `time()` 不受 `Carbon::setTestNow` 控制 → 跨窗口边界 flaky。生产代码改 `now()->timestamp`,测试冻结窗口中点(如 elapsed=30)。
- **tearDown 抛异常跳过父类清理**:`parent::tearDown()`(含 RefreshDatabase 事务 rollback)之前任何可能抛异常的逻辑(如 snapshot compare 的 `Assert::fail`)未 `try/finally` 兜住 → 跳过 rollback → 连接持锁泄漏 → 串行全套 `Lock wait timeout` 雪崩(曾卡 CI 68 分钟)。
- **Pest `->skip(<非闭包>)` 收集期 eager 求值**:skip 条件引用尚未 autoload 的类(插件类晚于 ServiceProvider 注册) → 整个文件收集崩溃。改 `fn () => ...` 延迟求值。

**真实案例**:`45e442a`(faker jobTitle 0.4% 概率 1 字符撞 `title between:2,16`)/ `802804b`(PurgeCommand 跨 worker 误删文档)/ `a546f16`(RateLimiter `time()`→`now()->timestamp`)/ `e2e6a3e`(tearDown 吞 rollback)/ `dff3d07`(Pest skip eager)。
**检查动作**:`grep -rn "fake()->\(jobTitle\|sentence\|word\|text\|paragraph\|catchPhrase\)" backend/database/factories` 命中字段若喂业务校验即改固定值;`grep -n "parent::tearDown" tests/TestCase.php` 确认前置清理被 try/finally 包住;`grep -rn "\->skip(" tests | grep -v "fn ()"`;`grep -rn "\btime()\b" backend/app/Http/Middleware backend/app/Services`。细节见 `skills/backend-dev.md` `## 测试`。

---

## 反模式 15: 伪绿测试 — 断言没真验证行为(核心层,与改动无关每轮必扫)

测试通过 ≠ 行为正确。reviewer 必须确认绿灯断的是**真产出物 + 正确方向**:

- **只 `assertOk()` 假绿**:本项目业务用 `200 + {code:0,msg}` 表错,失败路径也返 200,`assertOk` 永远绿。返文件流 / 重定向 / 真业务产出的端点必须断 `content-type` / `content-disposition` / 真实业务字段,不能只断状态码。
- **安全 data provider 方向反置**:把**应拒绝**的输入(`169.254` 云元数据 / `0.0.0.0` / CGNAT)放进**放行集** → 测试反向锁死漏洞、修复防护时反而"弄红"测试。安全相关 provider 逐条核对"放行集 vs 拒绝集"方向与防护意图一致(保留段必在拒绝集)。
- **`markTestSkipped` 兜底吞 bug**:对"环境前置可能缺失"用 skip 兜底 → 关键能力探测在 CI 静默跳过、生产才炸(openssl 探测参数选错就这么漏过)。关键能力测试应硬要求 CI 镜像装齐,禁 skip 吞错。
- **依赖开发者本机环境**:测试依赖 shell PATH 装了某二进制(mysqldump/composer)才能跑 → mock 掉,否则是"开发机能跑、CI/他人挂"的差异源。

**真实案例**:`76a2f58`(DocumentPreviewTest 改断真文件流 / PluginManagerTest 把 169.254 由放行改判拒绝,杀掉前一轮三处半修的假绿)/ `a546f16`(去 markTestSkipped 兜底 + mock BinaryLocator)。
**检查动作**:`grep -rn "assertOk()" tests | grep -v "content-type\|content-disposition\|assertJson"` 核对返产出物的端点;`grep -rn "markTestSkipped" tests` 逐处反问"真环境无关 vs 在吞 bug";安全 provider 人工核对方向。**这条是独立 reviewer 的灵魂 —— 防"改完测试绿就停手",与反模式 2 第二例(机制实际可达)互为表里。**

---

## 反模式 16: `ApiResponseException` 的消息在 `getApiResponse()['msg']`,`getMessage()` 恒空

项目自定义异常 `ApiResponseException`(`$this->error()` 抛出)的业务消息存在 `getApiResponse()['msg']`,标准 `getMessage()` **恒返回空串**。两处反复踩:

- **测试假绿**:`->throws(ApiResponseException::class, '某消息')` 第二参与 `getMessage()` 比对 → 恒不匹配(消息内容永远没被真正验证)。改 `try/catch` + `expect($e->getApiResponse()['msg'])->toContain(...)`。
- **生产丢错**:`catch (ApiResponseException $e)` 后用 `$e->getMessage()` 写日志 / 落库 → 恒空抹掉真实错误(`SubmitDocumentJob::failed()` 曾把 `submit_error` 写成空串,线上排障无据)。改 `getApiResponse()['msg'] ?? ''`,fallback `$e::class`。

**真实案例**:`dff3d07`(测试 `->throws` 断言恒空)/ `9588fa9`(`SubmitDocumentJob::failed()` 落库被抹空)。同一陷阱跨测试 + 生产两次出现,属反模式 4(同类扩散)的具体实例。
**检查动作**:`grep -rn "throws(ApiResponseException::class," tests` 应为 0;`grep -rn "getMessage()" backend/app | grep -iE "catch.*ApiResponse|Log::|->update\(|submit_error"` 逐处确认改走 `getApiResponse()['msg']`。

---

## 反模式 17: 免登录端点与凭据暴露(安全面)

免鉴权 / 自证端点、凭据回显、会话失效是安全审核反复命中的面,新增任何对外端点必逐项核对:

- **免登录端点必挂限流**:免鉴权或凭弱组合自证(email / tid+email)的端点缺限流可被爆破 / 枚举 / 滥用。挂双维度限流(业务键防换 IP + IP 维度防轰炸),且**同插件 / 同模块多个路由文件逐个比对中间件栈**(对称性,见反模式 3) —— `0d91294` 就是 easy `invoice.php` 漏挂 `easy.throttle` 而 `api.php` 有。
- **防账号枚举**:登录 / 重置 / 发码 validator 不用 `exists:users`,查无此人不走可区分分支(不抛"用户不存在");限流 key 归一化大小写 + 去空白(否则大小写变体分散绕过爆破限制)。
- **改密所有入口吊销旧会话**:每个写 `password` 的方法必须**同事务** bump `token_version` + `logout_at` + 删 refresh token —— `dc97990` 只修 `updatePassword`、漏 `resetPassword`(账号已失陷的高危入口),`76a2f58` 才补齐。一处会改密就全部入口都要吊销。
- **凭据不进 URL / 不回显**:长效 token 不拼进前端 URL(用短时签名 URL);admin 详情接口 `makeHidden` 他人 token 明文、编辑留空不覆盖原 token。**签名 / `withoutMiddleware` 路由要核对 Controller 构造函数 / 父类没抢先做登录校验**(否则签名形同虚设,见反模式 2 第二例)。
- **鉴权配置双空 fail-close**:回调等端点 token 与 IP 白名单**双空时必须拒绝**,不能默默放行(出厂双空裸奔被刷 sync / 探测 api_id)。

**真实案例**:`39cd024`(验证码限流 + reset 去 exists 防枚举)/ `c81ce00`(DeployToken/Callback 隐藏 token + 回调双空拒绝 + 登录限流 account 归一化)/ `dc97990`(改密撤销 token)/ `f58320c`+`76a2f58`(签名 URL + 构造函数放行)/ `0d91294`(easy/invoice 端点补限流)。
**检查动作**:grep 免登录路由组逐条核对限流中间件;grep 写 `password` 的方法逐个核对同事务吊销会话;grep `access_token` 是否进前端 URL;**实际发一次绕过请求**验证签名 / 限流真生效。细节见 `skills/backend-dev.md` `## 安全补强` + `### 凭据不进 URL`。

---

## 反模式 18: 外部输入下载 / 解压的纵深防御(SSRF / 供应链)

下载可执行载荷(升级包 / 插件包)和解压外部归档是高危面,缺一层即可被供应链投毒 / SSRF:

- **sha256 fail-closed**:校验值缺失即**拒绝**(不是空串跳过)。插件侧 verify-if-present 是过渡例外。
- **SSRF 用白名单制,不用黑名单**:明文 http 只放行 RFC1918 私网 + loopback,**显式拒 169.254 link-local(云元数据 169.254.169.254)/ CGNAT / 0.0.0.0**。禁用 `FILTER_FLAG_NO_RES_RANGE` 这类黑名单过滤(169.254 落在 reserved 内会被当"可放行")。
- **重定向收敛协议**:`curl --proto-redir =https --max-redirs 5` / Guzzle `allow_redirects.protocols=['https']` —— 堵"https 预校验过 → 302 降级到内网 http"。
- **最终下载 URL 再校验**:来自可被篡改的 `releases.json`(`browser_download_url`)的最终 URL 必须**再校验一次**,不能只校验 Admin 填的配置基址。
- **解压走 ArchiveGuard**:zip-slip / 符号链接统一防护,备份恢复与插件 / 升级包解压共用。

**真实案例**:`39cd024`(升级包 sha256 fail-closed + 强制 HTTPS,但 169.254 半修)/ `dc97990`(插件 sha256 + SSRF 双重收敛 + ArchiveGuard)/ `76a2f58`(补 link-local 拒绝 + 重定向限 https)。
**检查动作**:`grep -rn "FILTER_FLAG_NO_RES_RANGE" backend/app`(用了即黑名单制,改白名单 `isPrivateOrLoopbackIp`);grep 下载点是否有 `--proto-redir` / `allow_redirects.protocols`;sha256 缺失是抛异常还是跳过;下载入口(非仅配置入口)是否再校验 URL。细节见 `skills/plugin-dev.md` `## 安全机制` + `skills/backend-dev.md` `### 归档解压统一防护`。

---

## 反模式 19: 敏感数据落库与跨域 / 响应头基线

- **携密 / 安全字段走专用 Builder,不回落 Default**:`DefaultNotificationBuilder` 把 context 明文直通 `notifications.data` 列。携初始密码走 `NotificationPayload.transient`(仅渲染入邮件、不入库);安全事件走白名单字段的专用 Builder,**显式不回落 Default**(防调用方误塞敏感字段被直通)。
- **CORS 单一白名单**:不 reflect 任意 Origin、下载直出流不回落 `*`。
- **用户可控字节下载**:非图片强制 `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`,不 inline(防钓鱼 / 存储型 XSS)。
- **部署脚本外部值**:走 env + `getenv` 不插值进 PHP / shell 字符串(防注入);`curl -k` 仅限 loopback;composer 等下载校验 SHA384。

**真实案例**:`0d91294`(user_created 密码走 transient)/ `3f716ec`(security 专用 Builder 白名单 + 不回落 Default)/ `dc97990`(CORS 白名单 + nosniff attachment + 部署脚本 URL env 传参 + composer SHA384)。
**检查动作**:grep 新增通知 code 是否注册专用 Builder(携密 / 安全字段绝不回落 Default);`grep -rn "Access-Control-Allow-Origin.*\*" backend`;`grep -rn "Content-Disposition: inline" backend` 是否限图片;部署脚本 `php -r "...$VAR..."` 插值。细节见 `CLAUDE.md` 通知体系章节。

---

## 反模式 20: 并发 check-then-act 与死事务续写

(与反模式 10 互补:10 防死锁发生、20 防把偶发竞态放大成持续故障)

- **check-then-act 必须原子化**:防重 / 节流用 `Cache::get` 判断 + `Cache::set` 写入,两步间窗口被并发击穿(重复打上游 sync/pay/commit、超发验证码)。改 `Cache::add`(SETNX 原子占位)。占位提交点要在**业务前置校验之后、副作用之前**(否则对象不存在也因占位变 success);catch 降级方向按场景区分 —— 资金类 `add` 异常 fail-open 放行(靠 DB 唯一索引兜底),已知重复的 `get` 失败 fail-closed 不放行,两个相反方向用注释固化。
- **死锁不可吞、不可在回滚事务上续写**:InnoDB 死锁(1213/1205/序列化失败)被 `catch(Throwable)` 当普通业务异常吞掉,再在**已被 MySQL 整体回滚的连接上** `$task->update()` → `PDOException: no active transaction`,job 失败被 queue 无脑重试 → 雪崩。并发错误必须**重抛交 queue 错峰重试**,`failed()` 钩子守卫兜底标记;手写 `begin/commit/rollback` 改 `DB::transaction` 闭包(嵌套死锁时手写 `DB::rollback` 抛 1305 淹没原异常);事务级 `attempts>1` 仅当上游 HTTP 调用在事务外(`sync` 可重试、`commit` 下单在事务内不可)。

**真实案例**:`1bac824`(Order checkDuplicate / Acme sync 占位 / VerifyCode 冷却统一 `Cache::add`)/ `19384ef`(TaskJob 死锁重抛 + 死事务续写致 no active transaction 雪崩)。
**检查动作**:`grep -rn "Cache::get\|Cache::has" backend/app | grep -iE "重复|节流|cooldown|sync|防重"` 看是否紧跟 `Cache::set`(TOCTOU);`grep -rn "catch.*Throwable" backend/app/Jobs backend/app/Services` 看 catch 体是否对并发错误分流;`grep -rn "DB::beginTransaction\|DB::rollback" backend/app/Services` 应趋零。细节见 `skills/backend-dev.md` `### tasks 死锁防护与并发错误处理` + `### 节流统一 Cache::add 原子占位`。

---

## 反模式 21: 部署 / 迁移静默失败("绿了但线上没生效" — 最阴险)

CI / `migrate` 显示成功,但生产实际没生效,是最难发现的一类:

- **迁移内 `SHOW INDEX/COLUMNS/TABLES` 禁带 `?` 占位符**:这类 SHOW 语句不支持服务端 prepared 参数绑定,`EMULATE_PREPARES=false`(Laravel 默认)下抛 1064;若该异常落在 try / 早退分支被"幂等跳过"吃掉 → migration 记录入表(看似成功)但索引 / 列**根本没建**。一律全量 `SHOW INDEX` 取回 + Collection 过滤。
- **结构校验要比 indexes,不止 columns**:`db:structure --check` 只 diff columns 会漏报索引差异(后台 manual_actions 报警但 `--check` 绿)。
- **增量迁移回灌建表迁移**:`add_*/drop_*` 加的列 / 索引必须同步回 `create_*_table`,否则干净库 `migrate:fresh` 与线上结构漂移。
- **升级同步用动态发现,非硬编码目录白名单**:后台升级硬编码同步白名单漏新增顶层目录(`resources` 被漏 → 对外 API 文档 yaml 不部署 → 端点 404);改 `File::directories()` 动态发现 + skip storage/vendor,同步范围与可写预检对齐。

**真实案例**:`7fda164`(`SHOW INDEX WHERE ?` 抛 1064,迁移看似成功但 code 唯一索引没升级 + 结构校验漏 `modified_indexes`)/ `1050297`(增量列/索引回灌建表迁移)/ `0c97e50`(升级漏 resources 目录致文档 404)。
**检查动作**:`git grep -n "SHOW INDEX\|SHOW COLUMNS\|SHOW TABLES" backend | grep "?"` 必须为空;含 `add_*/drop_*` 迁移 → 改结构后**实跑 `php artisan migrate` 再 `db:structure --check` 验证确已生效**(不能只信 migration 入表);`migrate:fresh` + `--check` 双跑验建表同步;`grep -rn "\['app'.*'config'.*'database'" backend/app/Services/Upgrade` 硬编码目录列表应消除。细节见 `skills/backend-dev.md` `### 数据库结构校验` + `## 迁移规范`。

---

# 设计期清单(写 plan / 改动前)

进入实现前,显式回答下面两组问题。**回答不出来 → 设计未完成,不要开始写代码**。

## A. 杀手场景(至少列 3 个)

| 失败场景  | 现场状态                  | 用户损失 | 防御机制           |
| --------- | ------------------------- | -------- | ------------------ |
| 例:磁盘满 | 维护模式 + storage 已移走 | 数据丢失 | 检测前移到 mv 之前 |
| ...       | ...                       | ...      | ...                |
| ...       | ...                       | ...      | ...                |

**关键提问**:

- 这套新机制的产出物(配置 / 清单 / API)能被所有消费路径读到吗?
- 故意制造一次失败,机制真的拦住了吗?

## B. 对端检查

| 维度            | 当前改动   | 对端需要同步?  |
| --------------- | ---------- | -------------- |
| backend ↔ shell | (具体什么) | (是/否/已对齐) |
| admin ↔ user    | ...        | ...            |
| 同文件已有范式  | ...        | (列出参考函数) |

---

# Reviewer Subagent 任务模板(单一权威)

> 这是 `/finish-check` §8 派 reviewer 时使用的 prompt 模板。**主智能体直接复制本章节填空**,不要在别处再写一份(避免漂移)。

## 模板正文(复制到 Agent prompt)

```
你是带着怀疑的独立 reviewer,目标是找毛病而非确认正确。

## 改动范围
- diff：<由主智能体填，如 `git diff <base>..HEAD` 输出>
- 主要功能背景：<由主智能体填，1-3 句话说明这次 PR 想解决什么>
- 相关 plan 文档（无则填'无'）：<由主智能体填 `.superpowers/plans/<filename>.md` 的相对路径；reviewer 可选阅读以理解设计决策；本次改动确实无 plan 时显式填"无"，需补充上下文可附若干 commit SHA 供 reviewer 用 `git show` 翻历史>
- 相关 spec/brainstorm 文档（如果有）：<由主智能体填 `.superpowers/specs/<filename>.md` 的路径；同上>

## 已知 review 历史（避免重复报告）
- 上一轮 critical/high 列表：<由主智能体填，如 "P1.1 机制失效（已修，commit abc1234）/ P1.2 数据丢失（已修，commit def5678）" 或 "无 — 首轮">
- 上一轮 medium 用户决议为'当场修'的项：<由主智能体填，同上格式；用户选 follow-up/接受 的不列>
- 当前是第 N 轮 / 共 5 轮：<由主智能体填，如 "第 2 轮 / 共 5 轮"；临近第 5 轮除非 critical/high 否则应倾向 REVIEW_PASS>
- 重要：同一处已修复的问题不要重复报告

## 必查反模式清单
读 `skills/review-checklist.md` "反模式分级" 章节 + 设计期清单，按本次改动确定本轮扫描范围（核心层 6 条必扫 + 条件层按改动目录触发）。当前项目特有反模式由该文件保持单一来源。
- 反模式 4 特别强调：遇到"对称副本"模式（多处保留同名函数 / 同语义算法）→ 必须验证是否有 build 时 grep 等价校验或运行时输出等价测试；只靠注释提示同步 = 报 high
- 反模式 2 + 15 特别强调：对任何"声称有鉴权/签名/限流/回调校验"的防御机制，必须**实际制造一次绕过请求**（无 token 的 signed 请求 / 169.254 下载 / 改密后用旧 token）确认真被拦——静态读到"加了防御代码"≠ 生效；测试只断 `assertOk` / 把应拒输入放进放行集 = 伪绿。这是上一批 `76a2f58` 三处"半修"的根因，命中报 high
- 反模式 21 特别强调：改了 migrations / 数据库结构 → 不能只信 CI 绿 / migration 入表，必须**实跑 `migrate` 再 `db:structure --check`** 验证索引/列确已生效（`SHOW INDEX WHERE ?` 静默失败曾让索引"看似升级实则没建"）

## 必须实际跑(不只是静态推理!)
1. `cd backend && ./vendor/bin/pint --test`(PHP 格式)
2. `cd backend && ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G`(静态分析)
3. `bash -n` 改过的 .sh 文件 + `shfmt -d` 看格式
4. `cd backend && php artisan test --filter=<改动相关>`(改动涉及的测试集)
5. **至少 1 个失败场景 / 绕过请求模拟**(关键 — 静态推理 ≠ 实际验证):
   - 删一个新引入的配置文件 / 给个非法输入 / mock 命令失败
   - 安全机制必做:发一次**绕过请求**(无 token 的 signed 请求 / 169.254 下载 / 改密后用旧 token / 免登录端点超频)看是否真被拦
   - 这条曾漏掉"防御机制完全失效"(产出物没打进包,整套机制等于不存在)与三处"半修"(`76a2f58`:签名被构造函数挡死 / SSRF 黑名单漏保留段 / 改密只修一个入口),都是"代码加了 + 测试绿"的伪装
6. **挑 3-5 个 §2.5-§2.7 / §1.5 / §2.6 与本次 diff 相关的复选框做反向断言验证**(如改了 ACME → 验证"Action 无 userId 构造参数";改了资金 → 验证"CAS UPDATE 完整字段")。证据不足或与主智能体声称不符 → 报 critical

## 输出格式
- 按 **Critical / High / Medium / Low/Nit** 分级
- 每条必须附 `confidence: NN`（0-100），**仅 ≥ 80 才列出**（过滤理论问题，避免噪音）
- 每条带 `文件:行` + 真实案例锚定（参照 review-checklist.md 反模式格式）
- 主智能体会 spot-check `Critical|High` 评级条目，故意降级为 Medium 规避会被退回

## 退出签字（必须 — 机器可 grep 前缀验证）

最后一行必须是下面两种之一，**前缀（含冒号）必须原样**，前缀后可附简短人读说明：

- critical/high 清零 → 最后一行写：
  `REVIEW_PASS: critical/high 已清零（medium N 条 / low N 条，列于上方供用户决议）`
- critical/high > 0 → 列完所有问题后，最后一行写：
  `REVIEW_FAIL: 发现 N 个 critical/high 问题需修复`

主智能体会 `grep -F "REVIEW_PASS:"` / `grep -F "REVIEW_FAIL:"` 仅匹配前缀验证。任何近义句（如"看起来通过"、"没有新问题"）**不被接受**。

报告控制在 1500 字内。
```

## 字段填法

- **改动范围 / 主要功能背景**:主智能体根据当次 PR 填,确保 reviewer 不依赖主对话上下文
- **已知 review 历史**:首轮填"无 — 首轮";第 2 轮起填上一轮的 critical/high 列表 + 决议,避免重复报告
- **必查反模式清单**:固定引用 `skills/review-checklist.md`,不复制粘贴清单内容(单一来源)

## 修改本模板的注意事项

修改任何字段前,先检查:

- `/finish-check` §8 引用了本章节,只引用不重写
- 修改后 reviewer 的退出签字字符串保持稳定(`REVIEW_PASS:` / `REVIEW_FAIL:` 前缀不可变,否则 grep 失效)
- **`REVIEW_STALLED:` 不在本模板维护范围** — 它是主智能体在第 5 轮硬停时自己输出的标记,reviewer 不输出它。`finish-check.md §8.3` 才是其单一来源

---

# 维护

新案例进入清单的标准:

- 是项目实际遭遇过的回归(不写假想)
- 修复带 commit 锚定
- 能抽象成 1-2 句话的"反模式"

每条 review checklist 都应有真实案例,案例腐烂后(代码已删除 / 路径已变)及时更新或下线。
