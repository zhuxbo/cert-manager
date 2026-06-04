# Review Checklist — 反模式清单(单一来源)

> 这份清单来自项目实际遭遇过的回归。每条都有真实案例锚定,不是泛泛工程"最佳实践"。
>
> **两处共同引用**:
>
> - `/finish-check` 阶段 8 Review 循环 — reviewer subagent 必查清单(反模式 1-13)
> - plan / 设计阶段 — 写实现前的"杀手场景 + 对端检查"两栏(设计期清单章节)
>
> **维护原则**:每次真实回归后,把根因抽象成"反模式"加进来,带案例锚定;不写空泛规则。

---

## 反模式分级（reviewer 应用范围）

- **核心层**（每轮 reviewer 必扫，与改动无关）：反模式 1（失败路径数据安全）、2（端到端机制可达性）、3（对称性）、6（现有正确范式优先）、9（资金路径四道网）
- **条件层**（按改动目录/文件类型触发）：
  - 改 backend → 加 10（锁顺序）、11（afterCommit）、13（Transaction 事务）
  - 改 .sh / 部署脚本 → 加 4（同类扩散）、7（set -e 笔误）
  - 改外部命令调用 → 加 12（BinaryLocator）
  - 改公开 API / 新增 public 方法 → 加 5（新方法边界测试）、8（数组键类型混淆）

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

---

## 反模式 13: `Transaction::create` 在 `DB::transaction` 外

`Transaction::creating` 钩子内不再开自己的嵌套事务 / savepoint — 直接使用外层事务保证 `balance` 修改与 INSERT 的原子性。**非事务内调用会抛异常**,但新增资金路径若漏写 `DB::transaction` 闭包,会在生产命中异常导致请求 500。

**真实案例**:commit `3dd44b84` 把 `Transaction::creating` / `Fund::createRecord` 内嵌事务移除,改为强制调用方在 `DB::transaction` 内调用;同时新增异常提示"`Transaction::create` 必须在 `DB::transaction` 内调用(防止 balance 修改与 INSERT 非原子)"。
**修复**:所有 `Transaction::create` 调用前确认包在 `DB::transaction(fn)` 闭包内;`Fund::updating` 同理。资金事务优先用 `DB::transaction(fn)` 闭包(Laravel 自动管 commit/rollback),避免"`$row=null` 控制流穿透"导致的事务计数器漂移;如必须手写 `DB::beginTransaction` + try/catch,所有控制流分支必须 commit 或 rollback(含 no-row、early-return、异常路径),并补单元测试覆盖这些分支。

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

| 维度            | 当前改动   | 对端需要同步?      |
| --------------- | ---------- | ------------------ |
| backend ↔ shell | (具体什么) | (是/否/已对齐) |
| admin ↔ user    | ...        | ...                |
| 同文件已有范式  | ...        | (列出参考函数)     |

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
读 `skills/review-checklist.md` "反模式分级" 章节 + 设计期清单，按本次改动确定本轮扫描范围（核心层 5 条必扫 + 条件层按改动目录触发）。当前项目特有反模式由该文件保持单一来源。
- 反模式 4 特别强调：遇到"对称副本"模式（多处保留同名函数 / 同语义算法）→ 必须验证是否有 build 时 grep 等价校验或运行时输出等价测试；只靠注释提示同步 = 报 high

## 必须实际跑(不只是静态推理!)
1. `cd backend && ./vendor/bin/pint --test`(PHP 格式)
2. `cd backend && ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G`(静态分析)
3. `bash -n` 改过的 .sh 文件 + `shfmt -d` 看格式
4. `cd backend && php artisan test --filter=<改动相关>`(改动涉及的测试集)
5. **至少 1 个失败场景模拟**(关键 — 静态推理 ≠ 实际验证):
   - 删一个新引入的配置文件 / 给个非法输入 / mock 命令失败
   - 看防御机制是否真的拦住
   - 这条曾经漏掉过"防御机制完全失效"(产出物没打进包,整套机制等于不存在)
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
