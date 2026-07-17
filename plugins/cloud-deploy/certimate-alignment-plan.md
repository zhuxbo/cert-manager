# cloud-deploy 对齐 Certimate 端点的工程化方案

## 范围

- 修改范围限定在 `plugins/cloud-deploy/`。
- 目标是让 cloud-deploy 的云部署端点集合与 Certimate 可机器比对，不依赖人工或模型审核。
- 本阶段补工程守门与方案，不新增云厂商实现。

## 当前证据

- Certimate 上游取样：`certimate-go/certimate` main，commit `6e27a09b6a1a52d7bb5dbaf4771869a1d3e11658`，提交时间 `2026-07-03T17:57:43+08:00`。
- 上游 `pkg/core/deployer/providers` 目录数：152。
- 上游 `internal/domain/provider.go` 的 `DeploymentProviderType` 常量数：152。
- 上游 `internal/certmgmt/deployers/sp_*.go` 文件数：152。
- 本插件 registry 注册端点数：149。
- 临时脚本按显式 alias 归一化后，本插件 149 个端点与 Certimate core 目录扣除 `ssh`、`ftp`、`local` 后差集为 0。
- 已用 mock 跑通两个代表端点：
  - `AliyunCdnDeployerTest.php`：内联 PEM 直灌。
  - `TencentCdnDeployerTest.php`：证书服务上传后绑定。
  - 结果：`12 passed (53 assertions)`。
- 当前 `RegistryCompletenessTest.php` 通过：说明本地 registry 自洽。
- 已新增 `CertimateCatalogAlignmentTest.php`：用 fixture 机械比对 Certimate core 目录、provider.go、sp 文件和本插件真实 registry。
- 已新增 `DeployerTestCoverageTest.php`：机械断言每个注册 deployer 在本 provider 目录下有 mock 单测覆盖，并断言所有 registry 文件都被 ServiceProvider 接入真实 catalog。

## 已发现并处理的问题

1. `RegistryCompletenessTest.php` 的期望集是本仓库手写表，只能证明本地自洽，不能证明对齐 Certimate。
2. Certimate 自身有多套 slug 表达，直接读单一文件会误判：
   - `provider.go`：`aliyun-casdeploy`、`aliyun-esasaas`、`tencentcloud-ssldeploy`、`tencentcloud-sslupdate`、`ucloud-pathx`、`ctcccloud-ldvn`。
   - `pkg/core/deployer/providers`：`aliyun-cas-deploy`、`aliyun-esa-saas`、`tencentcloud-ssl-deploy`、`tencentcloud-ssl-update`、`ucloud-upathx`、`ctcccloud-lvdn`。
   - `sp_*.go` 还存在 `sp_kubernetes_secret.go` 对应 core `k8s-secret`。
3. 端点 alias 和不实现清单没有集中成契约，后续容易把 Certimate 的内部拼写差异误当成要改插件 slug。已通过 fixture 的 `provider_aliases` / `plugin_slug_aliases` / `source_slug_aliases` 固化。
4. `ConfigSchemaContractTest.php` 和 `CredentialLeakScanTest.php` 注释与工厂矩阵仍写着“全部 25 端点”，实际插件已有 149 个端点。已修正为“早期阿里/腾讯通用矩阵”，全量覆盖由各 provider 单测 + `DeployerTestCoverageTest.php` 承接。
5. `CloudDeployServiceProvider.php` 里 provider 文件列表与 `Deployers/registry/*.php` 是两份信息，新增 provider 时仍可能漏接线。已通过 `DeployerTestCoverageTest.php` 的 registry 文件接线断言兜底。

## 杀手场景

Certimate 新增 `newcloud-cdn` 或调整某个部署目录，cloud-deploy 没跟进，但本地手写 `expectedCloudDeployCatalog()` 没变，所有现有测试仍绿；PR 审核只看模型总结或人工列表，最终用户看不到新端点。

反向场景：有人为了“对齐 provider.go”把 `tencent.ssl-update` 改成 `sslupdate`，测试未暴露 alias 语义，导致前端既有 target 配置和 API product key 破坏性变更。

## 对端检查

- 以 Certimate `pkg/core/deployer/providers/*` 作为部署实现端点的主来源。
- 同时采集 `internal/domain/provider.go` 与 `internal/certmgmt/deployers/sp_*.go`，只用于发现上游命名不一致并维护 alias 表。
- 明确排除 `ssh`、`ftp`、`local`，原因沿用插件文档里的产品决策与安全边界。
- alias 表必须显式列出，并在测试失败信息里显示：
  - provider alias：`baidu -> baiducloud`、`tencent -> tencentcloud`、`onepanel -> 1panel`、`k8s.secret -> k8s-secret`。
  - product alias：`aliyun.casdeploy -> aliyun-cas-deploy`、`aliyun.esasaas -> aliyun-esa-saas`、`ucloud.pathx -> ucloud-upathx`、`cdnfly.cdn -> cdnfly` 等。
  - panel/no-suffix alias：`baotapanel.site -> baotapanel`、`ratpanel.site -> ratpanel`、`kong.certificate -> kong` 等。

## 落地计划

1. 新增可刷新快照脚本  
   在 `plugins/cloud-deploy/tools/` 下新增脚本，例如 `sync-certimate-catalog.php`。输入为本地 Certimate checkout 路径，输出 fixture JSON，包含 `source_sha`、`source_time`、`core_dirs`、`provider_go_values`、`sp_files`、`unsupported`、`known_aliases`。脚本不在 CI 中联网，CI 只读仓库内 fixture。

2. 新增对齐测试  
   新建 `plugins/cloud-deploy/backend/tests/Unit/Deployers/CertimateCatalogAlignmentTest.php`。测试读取 fixture，遍历真实 `app(Registry::class)->allDeployers()`，通过集中 alias 表归一化为 Certimate slug，断言：
   - `normalized_plugin_slugs == core_dirs - unsupported`。
   - 插件没有多余 slug。
   - `unsupported` 仅允许 `ssh`、`ftp`、`local`。
   - provider.go/sp/core 的已知差异必须命中 alias 表，新增差异直接红。

3. 补端点测试覆盖守门  
   新建 `DeployerTestCoverageTest.php`，用 reflection 把每个注册 deployer 的类名映射到 `tests/Unit/Deployers/<Provider>/<ClassName>Test.php`，断言 149 个注册端点都有对应 mock 单测文件。这个测试不替代单测本身，只防新增端点漏 mock 文件。

4. 修正现有契约测试表述和边界  
   把 `ConfigSchemaContractTest.php`、`CredentialLeakScanTest.php` 的“全部 25 端点”改成真实边界：这是早期阿里/腾讯通用契约矩阵，其他 124 个端点由各自 `*DeployerTest.php` 和新增覆盖守门承接。若要升级成全量矩阵，需要另开专项，因为不同 SDK client/final class/REST client 的注入缝差异较大。

5. 消除 provider 接线双份来源  
   在不改变对外行为的前提下，把 `CloudDeployServiceProvider` 的 provider 列表改为从 `Deployers/registry/*.php` 扫描并排序加载，或至少新增测试断言“所有 registry 文件都被 ServiceProvider 加载”。优先选测试守门，避免动态扫描改变启动行为。

6. 验证命令  
   先跑小闭环：
   - `docker compose exec -T app php artisan test /var/plugins/cloud-deploy/backend/tests/Unit/Deployers/Aliyun/AliyunCdnDeployerTest.php /var/plugins/cloud-deploy/backend/tests/Unit/Deployers/Tencent/TencentCdnDeployerTest.php`
   - `docker compose exec -T app php artisan test /var/plugins/cloud-deploy/backend/tests/Unit/Deployers/RegistryCompletenessTest.php`
   - 新增 `CertimateCatalogAlignmentTest.php` 与 `DeployerTestCoverageTest.php`

   再跑插件完整后端测试：
   - `docker compose exec -T app php artisan test /var/plugins/cloud-deploy/backend/tests`

## 落地状态

- `plugins/cloud-deploy/tools/sync-certimate-catalog.php` 已新增。
- `plugins/cloud-deploy/backend/tests/Fixtures/certimate-deployer-catalog.json` 已新增，来源为 Certimate commit `6e27a09b6a1a52d7bb5dbaf4771869a1d3e11658`。
- `plugins/cloud-deploy/backend/tests/Unit/Deployers/CertimateCatalogAlignmentTest.php` 已新增。
- `plugins/cloud-deploy/backend/tests/Unit/Deployers/DeployerTestCoverageTest.php` 已新增。

## 验收标准

- 对齐测试可在无网络 CI 中稳定运行。
- 刷新 fixture 时必须显式传入 Certimate 本地 checkout，避免测试阶段联网。
- 缺少 Certimate 新端点、插件多注册端点、alias 未登记、误把 `ssh/ftp/local` 加回来，都会红。
- 至少两个 mock 端点测试继续通过，覆盖内联 PEM 与证书服务型两条核心路径。
