# Review Checklist — 反模式清单(单一来源)

> 这份清单来自项目实际遭遇过的回归。每条都有真实案例锚定,不是泛泛工程"最佳实践"。
>
> **两处共同引用**:
>
> - `/finish-check` 阶段 8 Review 循环 — reviewer subagent 必查清单(反模式 1-8)
> - plan / 设计阶段 — 写实现前的"杀手场景 + 对端检查"两栏(设计期清单章节)
>
> **维护原则**:每次真实回归后,把根因抽象成"反模式"加进来,带案例锚定;不写空泛规则。

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
| backend ↔ shell | (具体什么) | (是 / 否 / 已对齐) |
| admin ↔ user    | ...        | ...                |
| 同文件已有范式  | ...        | (列出参考函数)     |

---

# Reviewer Subagent 任务模板(单一权威)

> 这是 `/finish-check` §8 派 reviewer 时使用的 prompt 模板。**主智能体直接复制本章节填空**,不要在别处再写一份(避免漂移)。

## 模板正文(复制到 Agent prompt)

```
你是带着怀疑的独立 reviewer,目标是找毛病而非确认正确。

## 改动范围
- diff:<由主智能体填,如 `git diff <base>..HEAD` 输出>
- 主要功能背景:<由主智能体填,1-3 句话说明这次 PR 想解决什么>

## 已知 review 历史(避免重复报告)
- 上一轮发现:<由主智能体填,如"P1.1 机制失效 / P1.2 数据丢失"或"无 — 首轮">
- 决议:<由主智能体填,如"P1.1 已修 / P1.2 已修 / M4 follow-up">
- 重要:同一处已修复的问题不要重复报告

## 必查反模式清单
读 `skills/review-checklist.md` 反模式 1-8 + 设计期清单。当前项目特有反模式由该文件保持单一来源。

## 必须实际跑(不只是静态推理!)
1. `cd backend && ./vendor/bin/pint --test`(PHP 格式)
2. `cd backend && ./vendor/bin/phpstan analyse --level=5 --memory-limit=2G`(静态分析)
3. `bash -n` 改过的 .sh 文件 + `shfmt -d` 看格式
4. `cd backend && php artisan test --filter=<改动相关>`(改动涉及的测试集)
5. **至少 1 个失败场景模拟**(关键 — 静态推理 ≠ 实际验证):
   - 删一个新引入的配置文件 / 给个非法输入 / mock 命令失败
   - 看防御机制是否真的拦住
   - 这条曾经漏掉过"防御机制完全失效"(产出物没打进包,整套机制等于不存在)

## 输出格式
- 按 **Critical / High / Medium / Low/Nit** 分级
- **置信度 ≥ 80 才报告**(过滤理论问题,避免噪音)
- 每条带 `文件:行` + 真实案例锚定

## 退出签字(必须 — 机器可 grep 验证)

最后一行必须是下面两种之一,**前缀必须原样**:

- 有新发现 → 列完所有问题后,最后一行写:
  `REVIEW_FAIL: 发现 <N> 个 critical/high 问题需修复`
- 无新发现 → 最后一行写:
  `REVIEW_PASS: 未发现新 critical/high 问题`

主智能体会 `grep -F "REVIEW_PASS:"` 验证。任何近义句(如"看起来通过"、"没有新问题")**不被接受**,流程会卡住。

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
