# Review Checklist — 反模式清单(单一来源)

> 这份清单来自项目实际遭遇过的回归。每条都有真实案例锚定,不是泛泛工程"最佳实践"。
>
> **两处共同引用**:
>
> - `/finish-check` 阶段 8 Review 循环 — reviewer subagent 必查清单(反模式 1-22)
> - plan / 设计阶段 — 写实现前的"杀手场景 + 对端检查"两栏(设计期清单章节)
>
> **维护原则**:每次真实回归后,把根因抽象成"反模式"加进来,带案例锚定;不写空泛规则。

---

## 反模式分级（reviewer 应用范围）

- **核心层**（每轮 reviewer 必扫，与改动无关）：反模式 1（失败路径数据安全）、2（端到端机制可达性）、3（对称性）、4（同类扩散 — 每轮必答：本次 diff 是否包含 bug 修复？是 → 修复点的同类模式全仓 grep，不限文件类型；案例横跨 shell 与 PHP，见反模式 16 案例锚定）、6（现有正确范式优先）、15（伪绿测试）
- **条件层**（按改动目录/文件类型触发；插件后端代码与主系统同等约束，"改 backend"均含 `plugins/*/backend/`）：
  - 改 backend/ 或 plugins/\*/backend/ → 加 10（锁顺序）、11（队列 dispatch 双守卫）、13（Transaction 事务）、16（ApiResponseException 消息）、20（并发 check-then-act / 死事务续写）
  - 改资金路径（`git diff -- backend plugins | grep -icE 'fund|transaction|->balance|transitionToSuccessful'` 输出 > 0，或 finish-check §1 范围表资金行为"是"/不确定）→ 加 9（资金路径四道网；触发宁可过扫不可漏扫，与"不确定视为是"对齐）
  - 改 .sh / 部署脚本 → 加 7（set -e 笔误）、19（仅其"部署脚本外部值"条目：env 传参防注入 / curl -k 限 loopback / 下载校验）
  - 改外部命令调用 → 加 12（BinaryLocator + 开发机/生产环境差异）
  - 改公开 API / 新增 public 方法 → 加 5（新方法边界测试）、8（数组键类型混淆）
  - 改 tests/（新增 / 修改测试）→ 加 14（测试 flaky 四源）
  - 改鉴权 / 下载 / 解压 / CORS / 通知 / 公开端点（安全面）→ 加 17（免登录端点与凭据暴露）、18（外部输入下载 / 解压纵深防御）、19（敏感数据落库与响应头基线）
  - 改 migrations / 升级同步 / 数据库结构 → 加 21（部署 / 迁移静默失败）
  - 改 build/ 打包脚本或 .github/workflows → 加 2（产出物可达：新文件真进包了吗）、21（绿了但没生效：job 被静默移除/条件短路）
  - 改 frontend/ → 加 22（前端生命周期 / 轮询失控）

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

**第三例(容器生命周期形态 — registry 服务未绑 singleton = 运行时注册即丢弃)**:凡暴露 `register()`/`extend()` 等运行时注册 API、靠 `app()` 解析共享状态的 registry 类服务,必须在 `AppServiceProvider::register` 绑 singleton,否则插件/晚期注册落在一次性实例上、消费方解析到只含内置项的新实例,机制端到端静默失效(主路径正常、测试全绿)。`51fb463c` 通知体系引入 `ChannelManager::register()` 供插件注入通道但漏绑 singleton,插件通道全部静默丢弃,`d8f75e63` 才补绑。
**检查动作**:`git grep -l 'public function register(' backend/app/Services | xargs -n1 basename | while read f; do git grep -q "singleton(.*${f%.php}::class" backend/app/Providers || echo "未绑 singleton: $f"; done` —— 有输出逐项反问"该 register() 是否承载跨解析点共享状态",是则必须绑 singleton 并配「容器注册 → 消费方可见」端到端测试(范式:NotificationCenterTest 'ChannelManager 绑为单例' 用例),确属局部对象才可豁免(注明理由)。

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

**仓内已落地实例**：`ApiErrorCode` 常量 ↔ `deploy.yaml` enum ↔ `skills/backend/deploy-renewal.md` 清单三份副本，由 `finish-check-greps.sh` 的 Z15 做双向集合等价的硬零断言（走上述第 1 条）。教训：这三份此前以"跨仓章节号无法校验"为由只挂注释约束，直到客户端 spec 移出同步面才发现障碍早已消失——**"无法机器校验"的理由要随结构变化重新审视**，否则一个本可一行 grep 覆盖的漂移面会长期裸奔。

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

**第二例(`set -u` + UTF-8 locale 下变量名吞掉全角首字节 — 门禁自身静默假绿)**:bash 3.2(macOS 系统自带 `/bin/bash`;`#!/usr/bin/env bash` 在没装 homebrew bash 的开发机上解析到的正是它)在 UTF-8 locale 下会把紧跟变量名的全角字符首字节并进标识符 —— `echo "enum 缺少 $code（已定义）"` 被解析成变量 `code\xef`,`set -u` 直接 `unbound variable`,无 `set -u` 时静默丢值(消息里变量位置变乱码)。`finish-check-greps.sh` 的 Z15 四条差异报错全是这个形态,而报错又被 `run_check` 里 `raw="$("$fn" || true)"` 的 `|| true` 吞掉 → **整项静默 PASS**:实测修复前在 macOS UTF-8 终端下 8 个漂移形变有 7 个被放过(裸 const / 同行属性 / 清单漏码多码 / yaml 漏码多码),而容器与 CI(C locale + bash 5.2)全绿,门禁看起来一直在工作。bash ≥4.2 与 C locale 均不复现,所以只在开发机终端暴露 —— 与第一例同源:shell 层把"检查失效"伪装成"检查通过"。
**修复**:变量后紧跟中文 / 全角标点一律加大括号 —— **shell 写 `${var}`、PHP 写 `{$var}`**(PHP 8.2 起 `"${var}"` 已 deprecated,别把 shell 写法搬过去);shell 侧全仓 22 处一并收敛(Z15 引入见 `cbcc6f07`),并加 Z16 硬零断言防复发。
**检查动作**:`finish-check-greps.sh` Z16 扫「已跟踪 + 未跟踪未忽略的全部 `*.sh`,并上首行是 shell shebang 的非 `.sh` 脚本(`frontend/*/.husky/*` 即此类)」,未转义 `$VAR` 紧跟非 ASCII 字节即 FAIL(`${VAR}` / `\$VAR` / 注释行不算;Makefile 配方与 workflow `run:` 块不是独立文件,不在扫描面内);同时 `run_check` 捕检查函数的退出码与 stderr,**退出码非零或 stderr 非空**即判门禁故障 —— 「检查没跑完」不再和「检查零命中」同形(本次 Z15 静默假绿的放大器正是原先的 `raw="$("$fn" || true)"`);代价对称:检查函数必须自己消化预期内的非零(零命中的 `git grep` 一律补 `|| true`,`pipefail` 下管道末尾同理),否则"零命中"会被误报成"没跑完"。**PHP 侧同源**(`"$var，"` 把全角首字节并进变量名致整个值消失)无硬零断言,由 finish-check §2.7 的复选框人工守;本轮已清仓全仓仅存的 2 处(`PluginManagerTest.php` / `VendorCoexistenceTest.php` 的诊断串)。第一例的函数名笔误无机器判据(`bash -n` 与 shfmt 都不查函数是否存在),仍靠 reviewer 逐处核对 `log_warn` 类同义词。

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
**修复**:所有 DELETE / 修改 task 的业务路径,必须先 `Task::lockForMutation($orderId, $actions)->get()` 拿 task 锁,再锁业务行,再做 DELETE。新增涉及 task + order/acme 的事务路径,先 grep 现有路径确认锁顺序与之对齐。
**检查动作**:`finish-check-greps.sh` Z12 禁止业务代码内联 `Task::...->lockForUpdate()` 绕过 scope;Z13 校验 `structure.json` 里 tasks 表只保留 `tasks_order_action_status_index(order_id, action, status)` 这一条 `order_id` 首列索引;Z14 校验 `Task::lockForMutation` 的 forceIndex/where/status/select/lock 接线。涉及 tasks 索引迁移时还要跑 Feature/Database 的 Task 索引最终态测试,由真实测试库 `SHOW INDEX FROM tasks` 兜住 migration add-path。

---

## 反模式 11: 队列 dispatch 双守卫缺失 — `->afterCommit()` / `->onQueue(tasks|notifications)`

`config/queue.php` 所有连接默认 `after_commit=false`,事务内 `dispatch` 的 Job 会**立即入队**;worker 可能在事务提交前消费 Job,读不到事务内新建的行 / 状态,导致任务静默丢失(task 状态查无记录直接跳过)。同一个 dispatch 点的孪生约定:生产 supervisor worker 只监听 `tasks,notifications` 两个队列(`bt-install.sh` 写入),Job 类内无 `$queue` 属性兜底,漏写 `->onQueue()` 的 Job 落 `default` 队列**永远没人消费**——本地 `QUEUE_CONNECTION=sync` 测试全绿,生产静默丢任务。

**真实案例**:commit `3dd44b84` 收尾时把所有事务内 `TaskJob::dispatch` 调用统一加 `->afterCommit()`(`createTask` / `createTasks` / 业务路径内手动 dispatch),避免 worker 抢跑外层事务;`SubmitDocumentJob` 曾漏 `onQueue` 落 default,文档静默不上传上游。
**修复**:所有 `TaskJob::dispatch(...)` 调用一律 `->afterCommit()`(Laravel 无活动事务时 afterCommit 立即派发,语义等价零成本,统一口径免去"是否在事务内"的语义判定);全部异步 dispatch 必须显式 `->onQueue()` 到 tasks/notifications 之一。grep 验证(语句聚合到分号,多行链式不误报;**不可用逐行 `grep -v` 当 0 命中签字**——链式调用的 afterCommit/onQueue 常在后续行):

```bash
awk '/TaskJob::dispatch/ && $0 !~ /^[[:space:]]*(\/\/|\*|#)/ { stmt=$0; line=FNR; while (stmt !~ /;/ && (getline nl) > 0) stmt=stmt nl; if (stmt !~ /afterCommit/ || stmt !~ /onQueue/) print FILENAME":"line }' $(git grep -l "TaskJob::dispatch" backend/app)
```

应为空(已收敛进 `skills/scripts/finish-check-greps.sh` 统一执行)。

---

## 反模式 12: 外部命令未走 BinaryLocator

`exec("openssl ...")` / `exec("php ...")` / `exec("composer ...")` / `exec("mysqldump ...")` / `exec("curl ...")` 等裸命令在多版本 PHP 系统(如宝塔多 PHP 版本)/ open_basedir 限制 / `is_executable` 误判等场景会**走错 CLI 或假装找不到** — 探测必须走 `proc_open` 子进程,不能用 `is_executable` / `file_exists`。

**真实案例**:commit `9dd8ce1d` (feat: 升级链路加入 PHP 环境检测与流程加固)引入 `App\Services\Binary\BinaryLocator`,把所有外部命令调用收口 — 在此之前多版本 PHP 系统升级时 `exec("php artisan ...")` 走的是 system 默认 PHP(可能是 7.4)而非项目所需的 8.3+,导致升级运行时崩溃。
**修复**:所有 `exec` 类调用必须 `app(\App\Services\Binary\BinaryLocator::class)->find('tool')` 解析路径 + `escapeshellarg($path).' arg1 arg2'`,不允许变量插值或裸命令。失败抛 `BinaryNotFoundException`,失败处理分两档:**用户显式请求的产物/格式/算法**(type=iis/tomcat、alg=sm2)失败必须硬报错 + `Log::error`,绝不静默给残缺产物;仅 **best-effort 聚合路径**(type=all、DCV 单点)可静默跳过,且须 returnCode + file_exists 双判 + Log 留痕、不得 `> /dev/null` 丢 stderr(`71db6d7b` 显式 iis/tomcat 曾静默给残缺包;同族:`21b5ab45` SM2 绝不静默降级 RSA、`06e40f74` fail-closed 拒 dual-sm2);升级流程内 PHP/composer 失败应向上抛阻塞。

**第二例(开发机能跑 / 生产挂的环境差异 — 最隐蔽,本地全绿)**:`a546f16` 修了三处"开发机 CLI 跑得通、宝塔 FPM 生产挂"的探测假设 —— ① `open_basedir` 非空时 Symfony `ExecutableFinder` **强制只在 open_basedir 内目录找命令**,宝塔站点必不含 `/usr/bin` → FPM 下永远 miss、CLI 又被候选路径覆盖,留着只让差异被偷偷接住(删 `ExecutableFinder`,让 FPM/CLI 走完全一致路径);② openssl 探测参数 `--version` 在 **OpenSSL 3.0.x 不识别**(3.2+ 才加),Ubuntu 24.04 默认 3.0.13 在 FPM 下报"openssl 不可用",改 `version` 子命令(1.x/2.x/3.x 全系列支持);③ 宝塔 FPM `clear_env=yes` + `env[PATH]` 默认注释 → 子 `sh -c 'command -v'` 拿不到 PATH 必 miss,须显式注入 `SHELL_FALLBACK_PATH` 并返回绝对路径,不依赖调用方 env PATH。
**检查动作**:`git grep -n "ExecutableFinder" backend/app | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#)'` 应为空(排除解释为何不用它的注释行);`git grep -n '> /dev/null 2>&1' backend/app` 应为空(丢 stderr 是静默降级帮凶);二进制探测参数选**全版本工具都支持**的形式(`openssl version` 而非 `--version`);探测 / exec 子进程不依赖父进程 `env[PATH]`(显式注入或返绝对路径);测试**不得用 `markTestSkipped` 兜底吞"工具找不到"**(否则再次让生产 bug 静默,见反模式 14)。

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
**检查动作**:`grep -rn "fake()->\(jobTitle\|sentence\|word\|text\|paragraph\|catchPhrase\)" backend/database/factories` 命中字段若喂业务校验即改固定值;`grep -n "parent::tearDown" tests/TestCase.php` 确认前置清理被 try/finally 包住;`grep -rn "\->skip(" tests | grep -v "fn ()"`;`grep -rn "\btime()\b" backend/app/Http/Middleware backend/app/Services`。细节见 `skills/backend/core.md` `## 测试`。

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
- **改密所有入口吊销旧会话**:每个写 `password` 的方法必须**同事务** bump `token_version` + `logout_at` + 删 refresh token —— `dc97990` 只修 `updatePassword`、漏 `resetPassword`(账号已失陷的高危入口),`76a2f58` 才补齐;第三例 `f9e44805`:Admin updatePassword **有**删 refresh token(deleteTokenByAdminId)但漏 bump token_version——部分吊销(缺三件套任一)= 等于没吊销,人肉 grep 看到"有吊销动作"即误判通过。已收敛 `User::revokeAllSessions` / `Admin::revokeAllSessions` 单点,改密/重置/全设备登出入口统一调用,禁止散落直写三件套。
- **凭据不进 URL / 不回显**:长效 token 不拼进前端 URL(用短时签名 URL);admin 详情接口 `makeHidden` 他人 token 明文、编辑留空不覆盖原 token。**签名 / `withoutMiddleware` 路由要核对 Controller 构造函数 / 父类没抢先做登录校验**(否则签名形同虚设,见反模式 2 第二例)。
- **鉴权配置双空 fail-close**:回调等端点 token 与 IP 白名单**双空时必须拒绝**,不能默默放行(出厂双空裸奔被刷 sync / 探测 api_id)。

**真实案例**:`39cd024`(验证码限流 + reset 去 exists 防枚举)/ `c81ce00`(DeployToken/Callback 隐藏 token + 回调双空拒绝 + 登录限流 account 归一化)/ `dc97990`(改密撤销 token)/ `f58320c`+`76a2f58`(签名 URL + 构造函数放行)/ `0d91294`(easy/invoice 端点补限流)。
**检查动作**:grep 免登录路由组逐条核对限流中间件;改密吊销两条机器检查 —— ① `git grep -nE 'token_version[[:space:]]*(=[^=>]|\+\+)' backend/app plugins | grep -vE 'Models/(User|Admin)\.php'` 应 0 命中(三件套禁散落直写,必须走 revokeAllSessions 单点;`[^=>]` 排除 JWT claims 数组的 `'token_version' =>` 形态),② `git grep -n -- '->password = ' backend/app plugins` 命中清单逐条核对同方法/同事务内有 revokeAllSessions(创建/注册流程豁免);grep `access_token` 是否进前端 URL;**实际发一次绕过请求**验证签名 / 限流真生效。细节见 `skills/backend/auth.md` `## 安全补强` + `### 凭据不进 URL`。

---

## 反模式 18: 外部输入下载 / 解压的纵深防御(SSRF / 供应链)

下载可执行载荷(升级包 / 插件包)和解压外部归档是高危面,缺一层即可被供应链投毒 / SSRF:

- **sha256 fail-closed**:校验值缺失即**拒绝**(不是空串跳过)。插件侧 verify-if-present 是过渡例外。
- **SSRF 用白名单制,不用黑名单**:明文 http 只放行 RFC1918 私网 + loopback,**显式拒 169.254 link-local(云元数据 169.254.169.254)/ CGNAT / 0.0.0.0**。禁用 `FILTER_FLAG_NO_RES_RANGE` 这类黑名单过滤(169.254 落在 reserved 内会被当"可放行")。
- **重定向收敛协议**:`curl --proto-redir =https --max-redirs 5` / Guzzle `allow_redirects.protocols=['https']` —— 堵"https 预校验过 → 302 降级到内网 http"。
- **最终下载 URL 再校验**:来自可被篡改的 `releases.json`(`browser_download_url`)的最终 URL 必须**再校验一次**,不能只校验 Admin 填的配置基址。
- **解压走 ArchiveGuard**:zip-slip / 符号链接统一防护,备份恢复与插件 / 升级包解压共用。

**真实案例**:`39cd024`(升级包 sha256 fail-closed + 强制 HTTPS,但 169.254 半修)/ `dc97990`(插件 sha256 + SSRF 双重收敛 + ArchiveGuard)/ `76a2f58`(补 link-local 拒绝 + 重定向限 https)。
**检查动作**:`grep -rn "FILTER_FLAG_NO_RES_RANGE" backend/app`(用了即黑名单制,改白名单——**两种语义勿混用**:下载"明文 http 仅放行私网"场景用 `isPrivateOrLoopbackIp`(PluginManager/ReleaseClient);出站回调"私网必须拒绝"场景用 `IpUtil::isPrivateOrReserved`(ActionCallbackTrait,拒私网+loopback+link-local+CGNAT+多播+benchmark 等全部保留段,直接复用 isPrivateOrLoopbackIp 会放行 169.254 云元数据));grep 下载点是否有 `--proto-redir` / `allow_redirects.protocols`;sha256 缺失是抛异常还是跳过;下载入口(非仅配置入口)是否再校验 URL。细节见 `skills/plugins/lifecycle.md` `## 安全机制` + `skills/backend/auth.md` `### 归档解压统一防护`。

---

## 反模式 19: 敏感数据落库与跨域 / 响应头基线

- **携密 / 安全字段走专用 Builder,不回落 Default**:`DefaultNotificationBuilder` 把 context 明文直通 `notifications.data` 列。携初始密码走 `NotificationPayload.transient`(仅渲染入邮件、不入库);安全事件走白名单字段的专用 Builder,**显式不回落 Default**(防调用方误塞敏感字段被直通)。**第二落库面是队列表**:Job 构造参数序列化进 `jobs`(执行前窗口)/`failed_jobs`(长期)表——构造参数携敏感 context 的 Job 必须 `implements ShouldBeEncrypted`(`d8f75e63`);通道附件(私钥 ZIP)须在 handle 末尾统一清理 cleanup_paths(含失败路径)。
- **CORS 单一白名单**:不 reflect 任意 Origin、下载直出流不回落 `*`。
- **用户可控字节下载**:非图片默认 `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`;确需内联的类型必须**白名单 + 兜底 CSP**(现行:图片与 PDF inline,PDF 加 `Content-Security-Policy: sandbox`,`b6017ff5`),禁止白名单外新增 inline 出口。
- **收紧方向镜像**:收紧响应头 / Content-Disposition / CORS 前必须**枚举既有合法消费方**(grep 前端 iframe / window.open / responseType 对该端点的引用)实走主路径——`b6017ff5` 的根因正是"非图片一律 attachment"打断了前端 iframe PDF 预览;reviewer 同时抽查测试 diff 是否把"被收紧前的合法行为"改写成断言(测试锁回归 = 假绿,见反模式 15)。
- **部署脚本外部值**:走 env + `getenv` 不插值进 PHP / shell 字符串(防注入);`curl -k` 仅限 loopback;composer 等下载校验 SHA384。

**真实案例**:`0d91294`(user_created 密码走 transient)/ `3f716ec`(security 专用 Builder 白名单 + 不回落 Default)/ `dc97990`(CORS 白名单 + nosniff attachment + 部署脚本 URL env 传参 + composer SHA384)/ `d8f75e63`(NotificationJob ShouldBeEncrypted + 多通道附件清理)/ `b6017ff5`(PDF inline 白名单 + CSP sandbox,修"收紧打断 iframe 预览"回归)。
**检查动作**:grep 新增通知 code 是否注册专用 Builder(携密 / 安全字段绝不回落 Default);diff 涉及 `backend/app/Jobs/` 时跑 `git grep -L 'ShouldBeEncrypted' backend/app/Jobs/*.php` 列出未加密 Job,逐个核对**构造参数**是否携密(handle() 内运行时读 config 的凭据不进 payload,不算携密);`grep -rn "Access-Control-Allow-Origin.*\*" backend`;`git grep -n "'inline'" backend/app | grep -vE 'isImage|isPdf'` 应 0 命中(新增 inline 出口必过白名单);部署脚本 `php -r "...$VAR..."` 插值。细节见 `skills/backend/notification.md`。

---

## 反模式 20: 并发 check-then-act 与死事务续写

(与反模式 10 互补:10 防死锁发生、20 防把偶发竞态放大成持续故障)

- **check-then-act 必须原子化**:防重 / 节流用 `Cache::get` 判断 + `Cache::set` 写入,两步间窗口被并发击穿(重复打上游 sync/pay/commit、超发验证码)。改 `Cache::add`(SETNX 原子占位)。占位提交点要在**业务前置校验之后、副作用之前**(否则对象不存在也因占位变 success);**占位成功后任何副作用失败必须 catch + `Cache::forget`(占位) + rethrow**,否则防抖窗口内重试命中占位直接返 success,把"实际未同步"的失败伪装成成功(`47925d9b`;`1bac8241` 的 `VerifyCodeHelper::releaseSendCooldown` 是既有正确范式);失败/null 结果不写正向缓存(`47925d9b` directory_url);catch 降级方向按场景区分 —— 资金类 `add` 异常 fail-open 放行(靠 DB 唯一索引兜底),已知重复的 `get` 失败 fail-closed 不放行,两个相反方向用注释固化。
- **防重占位 ≠ 互斥锁,API 不可混用**:防重去重占位可用裸 `Cache::add`;**互斥锁**(需主动释放 / 覆盖长任务)必须 `Cache::lock` 带属主 token,禁 `Cache::add` + finally `Cache::forget`(无属主释放会误删他人锁——本实例超时后另一实例接管,本实例跑完删掉的是别人的锁),锁续期禁 `Cache::put`(redis 驱动会 serialize owner、破坏 RedisLock 的原始 owner 比对致 release 失效)(`5a36d599`,旧实现 `db5e0974` 正是按"改 Cache::add"处方写出的互斥+心跳)。同文件出现 `Cache::add` + finally `Cache::forget` 同 key = 互斥锁味道,必须改 `Cache::lock`。
- **锁内守卫的判定对象必须 === 写回对象(读=写同一行)**:写回目标行若在事务外慢 IO(如上游 get)之前捕获(sync 的 `$cert`),锁内守卫不得经 `order->latestCert` 等外键关系导航取"当前行"判定——慢 IO 期间并发重签会切换 `latest_cert_id`,判定对象(新 cert B)≠ 写回对象(旧 cert A),漏判终态 → 上游滞后 status 复活 A + 误删 B 的延时 commit task。锁内须按写回行自身 id 重读权威状态(参照 `Order/Action.php` sync 的 `latest_cert_id === $cert->id` 分支;`f94f28ac`,复现用例 SyncConcurrentReissueTest)。锁内无慢 IO 间隔、判定与写回同为当次锁定行的既有范式(`$order->latestCert->update([...])`)不在此列。
- **死锁不可吞、不可在回滚事务上续写**:InnoDB 死锁(1213/1205/序列化失败)被 `catch(Throwable)` 当普通业务异常吞掉,再在**已被 MySQL 整体回滚的连接上** `$task->update()` → `PDOException: no active transaction`,job 失败被 queue 无脑重试 → 雪崩。并发错误必须**重抛交 queue 错峰重试**,`failed()` 钩子守卫兜底标记;手写 `begin/commit/rollback` 改 `DB::transaction` 闭包(嵌套死锁时手写 `DB::rollback` 抛 1305 淹没原异常);事务级 `attempts>1` 仅当上游 HTTP 调用在事务外(`sync` 可重试、`commit` 下单在事务内不可)。

**真实案例**:`1bac824`(Order checkDuplicate / Acme sync 占位 / VerifyCode 冷却统一 `Cache::add`)/ `19384ef`(TaskJob 死锁重抛 + 死事务续写致 no active transaction 雪崩)/ `47925d9b`(占位失败不回滚被防抖伪装成功)/ `5a36d599`(互斥锁误用 add/forget/put 三连)/ `f94f28ac`(sync 终态守卫读=写不同行)。
**检查动作**:`grep -rn "Cache::get\|Cache::has" backend/app | grep -iE "重复|节流|cooldown|sync|防重"` 看是否紧跟 `Cache::set`(TOCTOU);`git grep -n 'Cache::add(' backend/app | grep -iE 'lock|mutex|互斥'` 应 0 命中(互斥语义必须 Cache::lock);`git grep -nE "Cache::forget\((self::)?\\\$?[A-Za-z_]*LOCK" backend/app plugins` 应 0 命中(LOCK 风格常量的 forget 释放 = 无属主释放);diff 同时出现"锁外捕获的模型 + 事务外上游调用 + 锁内守卫/写回"三要素时,逐处核对判定 status 的来源行 id 与 update() 目标行 id 是否同一行;`grep -rn "catch.*Throwable" backend/app/Jobs backend/app/Services` 看 catch 体是否对并发错误分流;`grep -rn "DB::beginTransaction\|DB::rollback" backend/app/Services` 应趋零。细节见 `skills/backend/order-fund.md` `## tasks 死锁防护与并发错误处理` + `skills/backend/auth.md` `### 节流统一 Cache::add 原子占位`。

---

## 反模式 21: 部署 / 迁移静默失败("绿了但线上没生效" — 最阴险)

CI / `migrate` 显示成功,但生产实际没生效,是最难发现的一类:

- **迁移内 `SHOW INDEX/COLUMNS/TABLES` 禁带 `?` 占位符**:这类 SHOW 语句不支持服务端 prepared 参数绑定,`EMULATE_PREPARES=false`(Laravel 默认)下抛 1064;若该异常落在 try / 早退分支被"幂等跳过"吃掉 → migration 记录入表(看似成功)但索引 / 列**根本没建**。一律全量 `SHOW INDEX` 取回 + Collection 过滤。
- **结构校验要比 indexes,不止 columns**:`db:structure --check` 只 diff columns 会漏报索引差异(后台 manual_actions 报警但 `--check` 绿)。
- **增量迁移回灌建表迁移**:`add_*/drop_*` 加的列 / 索引必须同步回 `create_*_table`,否则干净库 `migrate:fresh` 与线上结构漂移。
- **升级同步用动态发现,非硬编码目录白名单**:后台升级硬编码同步白名单漏新增顶层目录(`resources` 被漏 → 对外 API 文档 yaml 不部署 → 端点 404);改 `File::directories()` 动态发现 + skip storage/vendor,同步范围与可写预检对齐。

**真实案例**:`7fda164`(`SHOW INDEX WHERE ?` 抛 1064,迁移看似成功但 code 唯一索引没升级 + 结构校验漏 `modified_indexes`)/ `1050297`(增量列/索引回灌建表迁移)/ `0c97e50`(升级漏 resources 目录致文档 404)。
**检查动作**:`git grep -n "SHOW INDEX\|SHOW COLUMNS\|SHOW TABLES" backend | grep "?" | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#)'` 必须为空(排除 docblock 说明行);含 `add_*/drop_*` 迁移 → 改结构后**实跑 `php artisan migrate` 再 `db:structure --check` 验证确已生效**(不能只信 migration 入表);`migrate:fresh` + `--check` 双跑验建表同步;`grep -rn "\['app'.*'config'.*'database'" backend/app/Services/Upgrade` 硬编码目录列表应消除。细节见 `skills/backend/database.md` `### 数据库结构校验` + `## 迁移规范`。

---

## 反模式 22: 前端生命周期 / 轮询失控

前端反复出现的两类回归(前后端同标准,真实回归同样入册):

- **组件级轮询未上提父级**:列表/详情页 N 个卡片组件各自起轮询 → 并发风暴打满后端;轮询应上提到父级统一调度、子组件纯展示。
- **副作用未在卸载时清理**:`setInterval` / `addEventListener` / `mitt.on` 未在 `onBeforeUnmount` 清理 → 路由切换后泄漏继续跑;事件监听显式声明 `passive` 意图。

**真实案例**:`30d122a5`(Order 详情聚合页轮询上提父级,消除多卡片并发风暴)/ `43acaa88`(同一问题在 ACME 页二次出现 = 同类扩散,后抽 shared composable `59e70a25`)/ `e1e27952`(lay-tag wheel 监听显式 passive:false)/ `095e08fe`(Dashboard 折线图首帧空数据)。
**检查动作**:新增轮询/定时器时先 grep `frontend/shared/composables` 是否已有可复用 composable(反模式 6 同理);`grep -rn "setInterval\|addEventListener\|mitt.on" <改动组件>` 逐处核对 onBeforeUnmount 清理。细节见 `skills/frontend/ui.md` 轮询与视口懒加载章节。

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

| 维度                                                                    | 当前改动   | 对端需要同步?                                                                                                                                                      |
| ----------------------------------------------------------------------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| backend ↔ shell                                                         | (具体什么) | (是/否/已对齐)                                                                                                                                                     |
| admin ↔ user                                                            | ...        | ...                                                                                                                                                                |
| 同文件已有范式                                                          | ...        | (列出参考函数)                                                                                                                                                     |
| 非标准响应形态(文件流/纯文本/重定向) ↔ 横切中间件消费方(日志/限流/CORS) | ...        | 新增此类端点时列出途经的横切中间件并逐个确认假设成立;日志 status 必须带端到端断言 `api_logs.status=1`(范式:`0def3b83` 的"纯 PEM 文本响应在 api_logs 记为成功"用例) |

---

# Reviewer Subagent 任务模板(单一权威)

> 这是 `/finish-check` §8 派 reviewer 时使用的 prompt 模板。**主智能体直接复制本章节填空**,不要在别处再写一份(避免漂移)。

## 模板正文(复制到 Agent prompt)

```
你是带着怀疑的独立 reviewer,目标是找毛病而非确认正确。

## 改动范围
- diff：<由主智能体填**精确可复跑的 diff 命令/ref 范围**（如 `git diff <base>..HEAD`；提交前场景填 `git diff` + `git diff --cached`），禁止只贴 --stat/文件名清单替代>
- 主要功能背景：<由主智能体填，1-3 句话说明这次 PR 想解决什么>
- 本轮报告写入：<由主智能体填 `.superpowers/reviews/<本次 run 目录>/round-<N>.md` 的路径；reviewer 必须把完整报告原样写入该文件，最终回复正文 = 同一报告>
- finish-check 运行目录：<由主智能体填 `.superpowers/finish-check-runs/<本次 run>/`；reviewer 实跑命令须通过 `skills/scripts/finish-check-exec.py run` 写入同一账本>
- 当前 generation / fingerprint：<由主智能体从运行目录 `state.json` 填；报告签字前再次核对，漂移即本轮无效>
- 相关 plan 文档（无则填'无'）：<由主智能体填 `.superpowers/plans/<filename>.md` 的相对路径；reviewer 可选阅读以理解设计决策；本次改动确实无 plan 时显式填"无"，需补充上下文可附若干 commit SHA 供 reviewer 用 `git show` 翻历史。**收到"无"时 reviewer 必须反核**：跑 `ls .superpowers/plans/ .superpowers/*.md` 按本次 diff 涉及的模块/功能名匹配；发现疑似候选先打开核对内容——若 plan 描述的正是本次 diff 的改动（而非历史已完成功能的旧 plan），报 high（设计期清单被绕过）；报告留一行痕迹：`ls plans → 无匹配项` 或 `匹配到 <file>，核对为历史旧 plan，不对应本次 diff`>
- 相关 spec/brainstorm 文档（如果有）：<由主智能体填 `.superpowers/specs/<filename>.md` 的路径；同上>

## 已知 review 历史（避免重复报告，但已修项必须复验）
- 上一轮 critical/high 列表：<由主智能体填，如 "P1.1 机制失效（已修待复验，commit abc1234）/ P1.2 数据丢失（已修，第 2 轮复验通过）" 或 "无 — 首轮"；上一轮报告文件路径一并附上供 reviewer 自读>
- 上一轮 medium 用户决议为'当场修'的项：<由主智能体填，同上格式；用户选 follow-up/接受 的不列>
- 当前是第 N 轮 / 共 5 轮：<由主智能体填，如 "第 2 轮 / 共 5 轮"；临近第 5 轮除非 critical/high 否则应倾向 REVIEW_PASS>
- 已修项不要**原样重复报告**，但每个标注"已修待复验"的项必须**复验是否真在运行路径生效**：读修复 commit 实际代码而非 commit message；鉴权/签名/限流类必须实际制造一次绕过请求（反模式 2/15）。复验不通过 → 按新 critical/high 报告，不受"已知问题"豁免；复验通过 → 在报告中标注，主智能体下一轮才可改填"已修，第 N 轮复验通过"真正豁免。主智能体的"已修"标注**不构成验证**——半修（声称修了实际没生效）正是 `76a2f58` 三处教训的根因
- Round 1 必须完整审查。Round 2 起仅在 finish-check §8.3 累计覆盖条件成立时允许增量复验：记录整体/逐文件 hash，并完整审查变化文件及其直接调用方/被调用方、对称副本、相关测试/plan、上一轮发现调用链。安全、资金、迁移、并发/异步、打包部署、跨模块契约或依赖变化一律退回完整审查。hash 只能证明未变，不能证明语义影响面已覆盖

## 必查反模式清单
读 `skills/review-checklist.md` "反模式分级" 章节 + 设计期清单，按本次改动确定本轮扫描范围（核心层 6 条必扫 + 条件层按改动目录触发）。当前项目特有反模式由该文件保持单一来源。
- 反模式 4 特别强调：遇到"对称副本"模式（多处保留同名函数 / 同语义算法）→ 必须验证是否有 build 时 grep 等价校验或运行时输出等价测试；只靠注释提示同步 = 报 high
- 反模式 2 + 15 特别强调：对任何"声称有鉴权/签名/限流/回调校验"的防御机制，必须**实际制造一次绕过请求**（无 token 的 signed 请求 / 169.254 下载 / 改密后用旧 token）确认真被拦——静态读到"加了防御代码"≠ 生效；测试只断 `assertOk` / 把应拒输入放进放行集 = 伪绿。这是上一批 `76a2f58` 三处"半修"的根因，命中报 high
- 反模式 21 特别强调：改了 migrations / 数据库结构 → 不能只信 CI 绿 / migration 入表，必须**实跑 `migrate` 再 `db:structure --check`** 验证索引/列确已生效（`SHOW INDEX WHERE ?` 静默失败曾让索引"看似升级实则没建"）

## 必须实际跑(不只是静态推理!)
以下命令必须通过 `python3 skills/scripts/finish-check-exec.py run --run-dir <finish-check 运行目录> --name reviewer-rN-<gate> [资源锁] -- <命令>` 执行并写入账本。Pint、PHPStan、Laravel 测试和运行时失败场景加 `--lock backend-runtime:exclusive`；数据库命令再加 `--lock db:exclusive`。mutation 运行期间只允许源码阅读与其重叠，PHP 命令等待锁，不得靠人工约定并行。

0. **实跑"改动范围"给出的 diff 命令获取全文 patch**(非 --stat)，每条发现须引用 diff 中的具体 hunk(文件:行)；若按所给范围取不到全文 → 最后一行输出 `REVIEW_FAIL: diff 不可获取，无法审查`
1. `cd backend && ./vendor/bin/pint --test`(PHP 格式)
2. `cd backend && ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G`(静态分析)
3. `bash -n` 改过的 .sh 文件 + `shfmt -d` 看格式
4. `cd backend && php artisan test --filter=<改动相关>`(改动涉及的测试集)
5. **至少 1 个失败场景 / 绕过请求模拟**(关键 — 静态推理 ≠ 实际验证):
   - 删一个新引入的配置文件 / 给个非法输入 / mock 命令失败
   - 安全机制必做:发一次**绕过请求**(无 token 的 signed 请求 / 169.254 下载 / 改密后用旧 token / 免登录端点超频)看是否真被拦
   - 这条曾漏掉"防御机制完全失效"(产出物没打进包,整套机制等于不存在)与三处"半修"(`76a2f58`:签名被构造函数挡死 / SSRF 黑名单漏保留段 / 改密只修一个入口),都是"代码加了 + 测试绿"的伪装
6. **反向断言验证 3-5 个 §2.5-§2.7 / §1.5 复选框，选取规则固定**:先由 reviewer 自行从 diff 判定触发了哪些条件层高危维度(资金路径 / migrations / 安全面 / 外部命令 / 并发事务，口径同本文件"反模式分级"条件层)，**触发的每个维度至少抽 1 条对应复选框**(如资金 → "CAS UPDATE 完整字段"、安全面 → "改密所有入口吊销会话")，再补足到 3-5 条;均未触发时才可自由挑选。每条抽查必须贴出验证用的实际命令 / grep 输出(截断规则同 finish-check §1.5)，"已核对"式声称不算证据。证据不足或与主智能体声称不符 → 报 critical

## 输出格式
- 按 **Critical / High / Medium / Low/Nit** 分级
- 每条必须附 `confidence: NN`（0-100），**仅 ≥ 80 才列出**（过滤理论问题，避免噪音）
- 每条带 `文件:行` + 真实案例锚定（参照 review-checklist.md 反模式格式）
- **评级锚定（防降级规避）**：发现命中以下情形最低评级 High，不得标 Medium/Low —— 反模式 1（失败路径可致数据丢失）/ 2 第二例（防御机制实际不可达或可绕过）/ 9（资金四道网任一缺失）/ 15（伪绿测试锁死漏洞）/ 17/18（安全绕过可复现）。若 reviewer 认为某条虽锚定上述编号但不构成上述后果，必须在该条目内附一句"降级理由"，否则按 High 处理
- 主智能体侧机械检查（替代主观 spot-check）：① Medium/Low 项凡引用上述编号且无"降级理由" → 退回重评；② Medium/Low 项缺反模式锚定（违反"每条带真实案例锚定"）→ 退回

## 证据回执（签字前必附 — 固定段标题，主智能体 grep 核验）

报告倒数第二段必须是 `## 证据回执` 固定标题段，逐行列出"必须实际跑"各项的执行凭据：
- 第 0-4 项各一行：`<命令> → 退出码 N`
- 第 5 项一行：`发了什么绕过请求 → 响应码/是否被拦`
- 第 6 项一行：`抽查了哪 3-5 个复选框 → 各自结论`
- plan 反核一行（plan 字段为"无"时）：`ls plans → 无匹配项` 或核对结论
- finish-check 证据一行：`generation=<N> fingerprint=<sha256> → 与 state.json 及 reviewer 命令账本一致`

回执行从简，不计入报告字数预算。**缺"证据回执"段 = 主智能体按 REVIEW_FAIL 处理退回重派。**

## 退出签字（必须 — 机器可 grep 前缀验证）

完整报告（含证据回执与签字行）**必须原样写入"改动范围"段指定的 round 文件**，最终回复正文与文件内容一致。签字前重新计算源码 fingerprint；与任务字段或账本 generation 不一致时必须 `REVIEW_FAIL`，不得复用旧命令证据。最后一行必须是下面两种之一，**前缀（含冒号）必须原样**，前缀后附回执字段与简短说明：

- critical/high 清零 → 最后一行写：
  `REVIEW_PASS: round=N findings=C0/H0/M_/L_ — critical/high 已清零（medium/low 列于上方供用户决议）`
- critical/high > 0 → 列完所有问题后，最后一行写：
  `REVIEW_FAIL: round=N findings=C_/H_/M_/L_ — 发现 N 个 critical/high 问题需修复`

主智能体会对**落盘的 round 文件**执行 `grep -F "REVIEW_PASS:"` / `grep -F "REVIEW_FAIL:"` 仅匹配前缀验证。任何近义句（如"看起来通过"、"没有新问题"）**不被接受**；未写入 round 文件 = 流程未完成。

报告控制在 1500 字内（证据回执不计入）。
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

- 是项目实际遭遇过的回归(不写假想)，**前后端同标准**
- 修复带 commit 锚定
- 能抽象成 1-2 句话的"反模式"
- **准入前置**:新条目先回答"是否为既有条目的新实例/新变体?"——是 → 并入该条目作第 N 例(先例:反模式 2/4/12/17/19/20 均多例),不开新条(控制条目熵增;`check-review-checklist-staleness.sh` 会在条目数 >25 时告警提示做合并 pass)
- **入册即清仓**:新条目(或新增检查动作)入清单的**同一 PR**,必须对全仓(含 `plugins/*/backend`、`deploy/`)实跑该条目的检查动作并按 finish-check §1.5 截断规则贴实际输出;存量违例当场修,或列 follow-up 并在条目内标注"已知存量违例 + 链接"。教训:反模式 17 入册 6 天后 Admin 侧同族漏洞(`f9e44805`)仍存活——入册时实跑 grep 即可提前抓到;硬零断言入册前必须在当前树实跑为空,否则就是下一个"恒红被驯化成噪音"的坏检查

每条 review checklist 都应有真实案例,案例腐烂后(代码已删除 / 路径已变)及时更新或下线。
