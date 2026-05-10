# Manager e2e 测试套件

本地可跑的端到端契约测试，覆盖**重构后的核心契约链**：

- 供应链完整性（sha256 强校验、awk 多行 `releases.json` 解析）
- build 产物 ↔ install / upgrade 兼容性（zip 命名、unzip 解压、目录结构）
- bt-install.sh BT_KEY / SITE_DOMAIN 多来源（env / 文件 / 交互 / 拒绝明文）

> 已移除 Docker 部署，e2e 套件仅覆盖宝塔（BT）契约。

## 用法

```bash
# 跑全部 case
bash deploy/test/e2e/run.sh

# 跑指定 case（部分名称匹配）
bash deploy/test/e2e/run.sh case-03
bash deploy/test/e2e/run.sh bt

# 单独跑某个 case 看详细输出
bash deploy/test/e2e/case-03-bt-install-interactive.sh
```

## 依赖

仅需：`bash 4+`、`sha256sum/shasum/openssl`、`zip/unzip`、`curl`、`grep/awk/sed`

## Case 清单

| Case                                | 验证内容                                                                                                                                                                                                                                                                           |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `case-03-bt-install-interactive.sh` | bt-install.sh 拒绝 `--bt-key=` 明文；`--bt-key-file=` 销毁；`bt_resolve_key` 4 级探测；P1 域名采集前移（`try_bt_automation` 不再二次询问）                                                                                                                                         |
| `case-04-bt-install-db-driver.sh`   | bt-install.sh mysql 单驱动 + 连接信息 + .env 生成；`select_db_driver` / `collect_db_credentials` / `generate_env_file` / `run_artisan_install` / `apply_admin_password` 5 函数；main() 调用顺序符合契约                                                                            |
| `case-05-bt-with-key.sh`            | **BT 安装"有 BT_KEY"路径全自动契约**：`try_bt_automation` 在 `has_bt_key=true` 时调 `bt_create_site` / `bt_inject_vhost_include` / `bt_ensure_supervisor_plugin` / `bt_add_supervisor_process` / `bt_add_crontab`；抓包字段对齐；`$PHP_CMD` 绝对路径；`expected_root` 路径错位防御 |
| `case-06-bt-without-key.sh`         | **BT 安装"无 BT_KEY"路径解耦契约**：vhost 注入独立运行（不依赖 KEY）；supervisor / cron 由 `has_bt_key=true` 守护；三段手工提示                                                                                                                                                    |

### BT 安装两条路径（case-05 / case-06）

旧版 `try_bt_automation` 在 BT_KEY 缺失时**整体跳过**所有自动化（包括 vhost 注入），用户只能全手工。
P3 解耦后，BT_KEY 与各步骤解耦：

| 步骤                                                        | 依赖 BT_KEY | 缺 KEY 时行为                                         |
| ----------------------------------------------------------- | ----------- | ----------------------------------------------------- |
| `bt_create_site`（建站）                                    | ✅ 必需     | 跳过 + 提示去面板手工建站                             |
| `bt_inject_vhost_include`（vhost 注入）                     | ❌ 不依赖   | **照常运行**（直接读写 vhost 文件 + nginx -s reload） |
| `bt_ensure_supervisor_plugin` + `bt_add_supervisor_process` | ✅ 必需     | 跳过 + 打印手工配置提示（含完整命令）                 |
| `bt_add_crontab`                                            | ✅ 必需     | 跳过 + 打印手工配置提示                               |

case-05 验证"有 KEY"时 4 个 BT API 调用全部命中、字段与抓包对齐；
case-06 验证"无 KEY"时 vhost 注入独立运行 + supervisor/cron 走手工提示，且无旧 bug 残留。

## CI 集成

PR 时跑 `run.sh`：本地 `bash` 工具链 5 分钟内完成。

```yaml
# 示例 CI 步骤
- name: e2e contract tests
  run: bash deploy/test/e2e/run.sh
```

## 退出码

- `0`：全部 case 通过
- `1`：至少 1 个 case 失败

每个 case 内部用 `[PASS]` / `[FAIL]` 输出，末尾打印 `结果: N passed / M failed`。
