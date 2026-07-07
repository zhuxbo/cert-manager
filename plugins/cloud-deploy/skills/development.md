# cloud-deploy 开发规范

证书自动推送各大云平台。certimate 式封装：每个 `(provider, product)` 一个 deployer，schema 驱动前端表单 + 后端校验，凭证脱敏，插件独立 vendor（不 scoping，不入库，安装/更新时主系统 composer install）。所有路径相对 `plugins/cloud-deploy/backend/`。

## 一、核心架构速览

### 抽象分层（`Deployers/Contracts/`）

- **`DeployerInterface`** + **`AbstractDeployer`**（公共基类，所有 deployer `extends`）：
  - 标识方法 `provider()` / `product()` / `label()`。
  - **`configSchema()`** — `list<{key,label,type?,required?,options?}>`，前端表单 + 后端校验**单一来源**。
  - **`usesRemoteCertStore(): bool`** — 区分「证书服务型」（先上传换 id 再绑）vs「内联型」（直灌 PEM 三元组）。默认 false。
  - **`certUploader(array $config = []): ?CertUploaderInterface`** — 证书服务型返回对应 uploader（region 型据 `$config['region']` 构造）。默认 null。
  - **`bind(string|array $certRef, array $credentials, array $config): void`** — 真正执行绑定。内联端点 `$certRef` 是 `{cert,key,chain}` 数组；证书服务型是云端证书 id 字符串。
- **`ProviderInterface`**（`AliyunProvider` / `TencentProvider`）：`key()` / `label()` / `credentialSchema()` 描述云账号凭证（AK/SK 等）。
- **`CertUploaderInterface`**：`upload($certPem,$keyPem,$chainPem,$credentials): string`（调云证书服务上传 API 返回云端 id）+ `storeKind(): string`（去重隔离键）。
- **`Registry`**（`Deployers/Registry.php`，单例）：聚合所有 provider + deployer。按 provider 拆 `Deployers/registry/{aliyun,tencent}.php`，**各返回 `Closure(Registry)`**——新增端点只改对应文件，避免并行开发冲突。`Registry::catalog()` 输出 `{providers:[{key,label,credentialSchema,products:[{product,label,configSchema}]}]}` 供前端渲染。

### 注入缝 + 配置记账

- **`makeClient(string $kind, array $credentials): object`**（abstract）：deployer 内**唯一** new SDK client 的地方，按 `match($kind)` 返回对应 client（证书服务型至少 `upload`/`bind` 两 kind，内联型常一个）。**deployer 业务方法不得直接 `new` client**——测试靠 override `makeClient` 注入 mock。
- **`requireConfig(array $config, string $key): mixed`**：从 config 取必填值，**自动累加 `touchedConfigKeys`**。缺键走 `fail()` 抛业务错误。`ConfigSchemaContractTest` 据此断言 `configSchema()` ⊇ `touchedConfigKeys`（非反射，靠 requireConfig 真实记录）。

### uploader 4 类策略

上传职责剥离到 `CertUploaderInterface`，`usesRemoteCertStore()` 区分。已有三件套可直接复用：

1. **阿里 CAS**（`AliyunCasUploader`）：`UploadUserCertificate`→CertId，`storeKind=cas`（全局）。
2. **阿里 SLB 服务证书**（`AliyunSlbUploader`）：`UploadServerCertificate`→ServerCertificateId，`storeKind=slb:{region}`（**region 维度隔离**，按 config.region 构造）。
3. **腾讯 SSL**（`TencentSslUploader`）：`UploadCertificate`→CertificateId，`storeKind=tencent_ssl`。
4. **内联 PEM**：无 uploader，`usesRemoteCertStore()=false`，`bind` 直灌三元组。

`RemoteCertStore::ensure`（`Services/RemoteCertStore.php`）按 **`(access_id, store_kind, fingerprint)`** 去重，DB 唯一索引兜底并发（catch 1062 回查复用）。同一证书在同账号同存储空间只上传一次。

### 脱敏体系（三层，防 AK/SK/PEM 泄露）

- **`AbstractDeployer::guardSdk(callable): mixed`** — 包裹**所有** SDK 调用，`catch (Throwable $e)` 重建干净 `RuntimeException`（仅 code + 脱敏 message，**绝不挂 `previous`**）。原因：挂 previous 会让 `getTraceAsString()` 带出含 AK/SK/请求体的 SDK 帧或 Guzzle 签名 URI。
- **`AliyunErrorSanitizer` / `TencentErrorSanitizer`**（各 provider 目录）：多分支提取厂商错误码（不同 SDK 异常结构不同）。deployer 的 `sanitize(Throwable): string` 通常 `return <Provider>ErrorSanitizer::sanitize($e);`。
- **`CredentialScrubber`**（`Deployers/Contracts/`）：兜底扫描字符串里的 AK/SK/PEM 子串并打码。

### 独立 vendor（运行时安装，不 scoping，不入库）

插件依赖阿里/腾讯官方云 SDK（约 80M）。**vendor 不入 git、不进发布 zip**——改为**运行时安装**：主系统 `PluginManager` 安装/更新本插件时，检测到 `backend/composer.json` 即自动 `composer install --no-dev`（封装在 `App\Services\Plugin\PluginComposerRunner`，复用 `BinaryLocator::composer()` + `UpgradePreflight` 探测 + 阿里云镜像自动切换）。发布包只含 `backend/composer.json` + `backend/composer.lock`（`build.json` 的 `exclude` 排除 `backend/vendor/`，保留 composer.{json,lock}）。

**对目标机要求**：composer 可执行 + PHP CLI 未禁 `proc_open`/`exec` + 能访问 packagist（GitHub 不可达自动切阿里云镜像）。不满足则安装失败并提示（install 清半装目录、update 回滚备份）。

`CloudDeployServiceProvider::register()` `require` 插件 vendor autoload 后，把插件 `ClassLoader` `unregister()` 再 `register(false)` **挂 SPL 自动加载栈尾**——共享类（GuzzleHttp/Psr 等）回落主系统版本、插件独有类（AlibabaCloud/TencentCloud 等主 loader `findFile` 返回 false）从插件 vendor 解析。**不做 Strauss scoping**（实测不 scoping 正常、scoping 反 fatal）。幂等守护，缺 vendor 不 fatal（is_file 守卫 + `CloudDeployJob::guardSdk` 把缺 SDK 转 per-target 失败日志）。

**跨大版本共享依赖必须 `replace`（挂栈尾兜不住）**：挂栈尾只对**同大版本**共享依赖可靠（API 兼容、回落主系统无害）。**跨大版本**冲突在 PHP-FPM 多 worker + opcache 下挂栈尾隔离会失效——某 worker 把插件旧版类解析进主系统、与主系统新版编译时签名不兼容 fatal → **全站 500**（连 login 都崩）。0.0.1 首发踩此坑：古董 `baidubce/bce-sdk-php`（`php>=5.3.3`）拖入 `psr/log 1.x`（vs 主系统 3.x，`LoggerInterface::log` 无类型 → 主系统 Monolog 3.x 的 `emergency()` 签名不兼容）+ `guzzle/guzzle 3.x`（再拖 `symfony/event-dispatcher 2.x` vs 主系统 7.x）。**根治**：插件 `composer.json` 用 `"replace": {"psr/log":"*","guzzle/guzzle":"*","symfony/event-dispatcher":"*"}` 把这些古董挡在插件 vendor 外、运行时回落主系统版本（百度 SDK 仅 type-hint `psr/log`，3.x 超集兼容）；`BaiduRestClient` 改用主系统 `GuzzleHttp\Client` 发请求、**弃用 SDK 自带 `BceHttpClient`**（底层古董 Guzzle 3.x），仅复用 `BceV1Signer` 签名（纯算法、无第三方依赖）。守护测试 `VendorCoexistenceTest`「插件 vendor 不得携带跨大版本冲突共享依赖」遍历插件↔主系统重叠包断言无 major 冲突、防复发。**改 `replace` 后移除被锁定依赖必须 `composer update -W` 全量重解析**（定向 update 对 replaced 包无效），`-W` 会重开 guzzlehttp 历史公告，故 `composer.json` 设 `policy.advisories.block=false`（仅影响本地生成 lock；生产 `PluginComposerRunner` 走 `composer install` 按 lock 不检查公告，guzzle 仍锁 7.10 最新已修复版）。

**本地开发**：vendor 不入库，clone 后需先 `composer install -d plugins/cloud-deploy/backend` 把 SDK 拉下来再跑插件测试（CI 同此，已在 `ci-job-snippet.yml` 加 plugin composer install 步骤）。

### 数据模型 + Job

4 表：`cloud_deploy_accesses`（云凭证，credentials 加密）/ `cloud_deploy_targets`（部署目标 = order+access+product+config，同一用户下相同 access+product+config 只能绑定一个订单）/ `cloud_deploy_remote_certs`（已上传云端证书去重）/ `cloud_deploy_logs`（部署历史）。`CloudDeployJob`（**onQueue tasks + afterCommit**）负责：freeze 守卫 / 租户隔离 / 缺链回填 / 幂等 / force 重推。续期换 order，target 迁移跟随。

### 主系统足迹

插件功能侧主系统 backend **0 改动**，仅复用既有 widget 插槽 2 个（`admin-order-detail-ssl-actions` / `user-order-detail-ssl-actions`，order 详情 SSL 卡片显示「云部署」区域 + 当前订单目标状态）。schema 驱动 → 新增端点前端零改动。（vendor 运行时安装所需的 `PluginManager` composer hook 是**通用主系统能力**，非本插件专属代码，见上「独立 vendor」节。）

---

## 二、「新增一个部署端点」操作模板

按目标端点的 **uploader 类型** 选「照抄哪个现成 deployer」，照葫芦画瓢最省心。

### Step 0 — 选模板 deployer（按 uploader 策略）

| 端点类型                    | 照抄模板             | uploader             | 关键差异                                                                                               |
| --------------------------- | -------------------- | -------------------- | ------------------------------------------------------------------------------------------------------ |
| 内联 PEM（直灌证书三元组）  | `AliyunCdnDeployer`  | 无（返回 null）      | `usesRemoteCertStore()=false`；`bind` 内 `$certRef` 是 `{cert,key,chain}` 数组，直接传 SDK             |
| 阿里 CAS 证书服务型（全局） | `AliyunDcdnDeployer` | `AliyunCasUploader`  | `usesRemoteCertStore()=true`；`certUploader()` 返回 CAS uploader；`bind` 收 CertId 字符串              |
| 阿里 SLB 服务证书（region） | `AliyunClbDeployer`  | `AliyunSlbUploader`  | uploader 按 `config.region` 构造，`storeKind=slb:{region}`；`certUploader(array $config)` 透传 region  |
| 腾讯 SSL 证书服务型         | `TencentCdnDeployer` | `TencentSslUploader` | `storeKind=tencent_ssl`；`bind` 收 CertificateId                                                       |
| 腾讯异步部署（轮询）        | `TencentCosDeployer` | `TencentSslUploader` | `bind` 调 SSL `DeployCertificateInstance` + `TencentDeployRecordPoller::poll(deployRecordId)` 轮询终态 |

### Step 1 — 加 SDK（仅当 product 用新 SDK 包时）

```
composer require <vendor/sdk> -d plugins/cloud-deploy/backend   # 例：alibabacloud/esa-20240910
# composer install 已随 require 跑，本地 vendor 就位供测试
git add plugins/cloud-deploy/backend/composer.{json,lock}   # 仅提交 composer.json/lock；vendor 不入库（运行时安装）
```

阿里各产品 SDK 包形如 `alibabacloud/<product>-<version>`；腾讯统一 `tencentcloud/tencentcloud-sdk-php`（一个包含全产品 client，多数新端点**无需** require）。

### Step 2 — 写 deployer

复制 Step 0 选定的模板文件，改类名为 `<Provider><Product>Deployer`，实现：

1. `provider()` / `product()` / `label()` — 标识 + 中文展示名。
2. `configSchema()` — `list<{key,label,type?,required?,options?}>`。对照 certimate `pkg/core/deployer/providers/<provider>-<product>/` 的入参确定字段（domain / region / instanceId / listenerId 等）。
3. `makeClient(string $kind, array $credentials): object` — 按 `match($kind)` new 对应 SDK client。**deployer 内不得直接 new client**。
4. `bind(string|array $certRef, array $credentials, array $config): void` — `requireConfig($config, 'xxx')` 取值（自动记 touchedConfigKeys），`guardSdk(fn)` 包 SDK 调用：内联端点把 `$certRef` 数组的 cert/key/chain 灌入；证书服务端点把 `$certRef` 字符串 id 绑定到资源。异步端点 bind 内轮询。
5. 证书服务型还要 override `usesRemoteCertStore(): bool { return true; }` + `certUploader(array $config = [])` 返回对应 uploader（region 型据 `$config['region']` 构造）。
6. `sanitize(Throwable $e): string` — 通常 `return <Provider>ErrorSanitizer::sanitize($e);`，无需自写。

### Step 3 — 若 uploader 不存在则新建

仅当引入了**新的证书存储空间**（如对接华为 SCM / AWS ACM）才写 `<Provider><Svc>Uploader implements CertUploaderInterface`：`upload($certPem,$keyPem,$chainPem,$credentials): string`（调云证书服务上传 API 返回云端 id）+ `storeKind(): string`（隔离去重键，region 维度返回 `kind:{region}`）。已有 cas/slb/tencent_ssl 三个可直接复用。

### Step 4 — 注册

在 `Deployers/registry/<provider>.php` 加一行 `$registry->registerDeployer('<provider>', '<product>', fn () => new <Provider><Product>Deployer);`。新 provider 则先 `$registry->registerProvider(new <Provider>Provider);`，并在 `CloudDeployServiceProvider::register()` 的 `foreach (['aliyun','tencent', ...])` 数组加 provider 名。

### Step 5 — 测试

写 `tests/Unit/Deployers/<Provider>/<Product>DeployerTest.php`：子类 **override `makeClient`** 按 `match($kind)` 注入对应 Mockery mock，断言 ① 上传（证书服务型）调对 API 返回 id ② bind 把 id/PEM 传给资源 SDK ③ SDK 抛含 AK 异常时 message 脱敏。**无需手写** configSchema 契约 / 凭证泄露测试——`RegistryCompletenessTest` / `ConfigSchemaContractTest` / `CredentialLeakScanTest` 遍历所有 deployer 自动覆盖（新端点注册后即纳入）。

### Step 6 — 验证 + commit

跑插件测试（`php artisan test plugins/cloud-deploy/backend/tests`）+ phpstan 绿，commit。前端无需改（schema 驱动，catalog 自动带出新端点供表单渲染）。

---

## 三、Provider 实现状态总览（已对齐 certimate 149 端点 / 55 provider）

**已对齐 certimate 全部部署端点：149 个已实现（certimate 共 152，ssh/ftp/local 经产品决策不实现，见本节末）。** 下方按 provider 列「SDK/签名 + 关键 API + uploader 策略」作实现参考与新增端点模板；**权威清单以 `Deployers/registry/*.php` 为准**（每个 registry 文件 = 一个 provider 的全部 product 注册）。官方 PHP SDK：aliyun/tencent/aws（v3）/qiniu/baidu/s3(aws S3)；其余 40+ provider 经各自 `<Provider>RestClient` 手写签名（HMAC-SHA256 各家变体 / JWT / OAuth2 / OCI HTTP Signatures / EOP 三级派生 / QY / TC3 等）调 REST，GuzzleHttp 来自主 vendor、零额外 composer（phpseclib 等未引入）。下列条目即便文字描述为「新建」也均**已落地**。

### AWS ✅（已实现，8 端点：acm/iam/alb/nlb/clb/cloudfront/amplify/apigateway）

- **包**：`aws/aws-sdk-php` ^3.0（实锁 3.337.3，单包全服务，v3 `src/` 布局）。client 构造 `new Aws\Acm\AcmClient(['version'=>'latest','region'=>$r,'credentials'=>['key'=>,'secret'=>]])`，调用 `$c->importCertificate([...])` 返回 `Aws\Result`（数组式）。
- **composer 公告坑（已解，记录避免重复）**：composer 2.10 默认 `policy.advisories.block=true` 挡住 aws v3 全版本。已在 `composer.json` 加 `config.policy.advisories.ignore-id` 忽略 3 个**本插件不可达**公告：PKSA-4t1p（CloudFront 签名 URL/Policy 注入，只用 UpdateDistribution 不生成签名）、PKSA-dxyf（S3 加密客户端，完全不用 S3）、PKSA-mnyp（jmespath <2.9.1 表达式编译注入，仅 aws-sdk 内部静态表达式、不传用户输入）。原策略**不带 `-W`**（保持 guzzle 7.10 锁定不重开 guzzle 公告），composer 自动回溯到 aws 3.337.3 + jmespath 2.7（阿里云镜像有）。**注：0.0.1 修复引入 `replace`（挡 psr/log 等跨大版本古董，见「独立 vendor」节末）后，移除 replaced 包必须 `-W` 全量重解析、会重开 guzzlehttp 公告，故改用 `policy.advisories.block=false`**——guzzle 仍锁 7.10（最新已修复版），block 仅影响本地生成 lock、生产 install 按 lock 不检查。
- **两个上传器**：`AwsAcmUploader`（ImportCertificate→CertificateArn，storeKind=`acm:{region}`，region 隔离）、`AwsIamUploader`（UploadServerCertificate→Arn，storeKind=`iam`，ELB 系传 Path=/elb/）。均返回 **ARN** 作 remote_cert_id（AWS 所有绑定点吃 ARN，单值契约统一）。
- **绑定**：cloudfront→getDistributionConfig+updateDistribution(ViewerCertificate.ACMCertificateArn, IfMatch=ETag, 证书须 us-east-1)；alb/nlb→ELBv2 modifyListener/addListenerCertificates(校验 Type)；clb→经典 ELB setLoadBalancerListenerSSLCertificate；amplify→updateDomainAssociation(certificateSettings 小驼峰)；apigateway→ApiGatewayV2 updateDomainName。`acm`/`iam` 纯上传 no-op。
- **偏差**：cloudfront 仅 ACM 源（IAM 源需 ServerCertificateId≠ARN，单值契约取舍）；alb/nlb/clb 保留 ACM/IAM 双源（`certificate_source` 默认 ACM，皆用 ARN）；仅 exact 绑定。

### 华为云

- **包**：`huaweicloud/huaweicloud-sdk-php`
- **SCM 证书服务**：`ImportCertificate`/`PushCertificate`→证书 id（uploader **新建** `HuaweiScmUploader`，storeKind=`scm`）
- **CDN**：`UpdateDomainFullConfig` 绑证书
- **ELB**：`CreateCertificate`（ELB 自有证书）/ listener 绑定
- **WAF**：`CreateCertificate` + 域名绑定
- **OBS**：自定义域名绑证书（OBS SDK 独立）

### Cloudflare

- **包**：HTTP REST（无官方 PHP SDK，照 certimate 用 Guzzle 直调；GuzzleHttp 来自主 vendor）
- **关键 API**：`PUT /zones/{zone}/ssl/certificate_packs` 或 Custom Hostnames `PATCH /custom_hostnames/{id}`（内联 PEM，**uploader 无**，`usesRemoteCertStore()=false`）

### 七牛 Qiniu ✅（已实现，cdn/kodo/pili）

- **包**：`qiniu/php-sdk`（仅 `Qiniu\Auth` + `Qiniu\Http\Client`，无高层 manager）。新建 `QiniuRestClient` 薄封装 `Auth::authorizationV2`（= Go `SignRequestV2`，Qiniu V2 管理凭证签名）+ `Http\Client` 直调 REST，作 `makeClient('api')` 唯一产物。
- **上传器** `QiniuSslUploader`（storeKind=`qiniu`）：`POST /sslcert`→certID。
- **复合 remote_cert_id**：cdn/kodo 绑定要 certID、pili 要 certName，上传器返回 `"{certID}|{certName}"`，各 deployer 经 `ParsesQiniuCertRef` trait 拆。
- cdn（sslize/httpsconf 三分支）、kodo（`/cert/bind`）、pili（`/domains/{d}/cert` 用 certName）。仅 exact 域名匹配。

### 百度智能云 Baidu ✅（已实现，cdn/blb/appblb/cert）

- **包**：`baidubce/bce-sdk-php`（仅 Bos/Lss/Media/Sts/Vod，无 cert/cdn/blb client）。新建 `BaiduRestClient` 薄封装 SDK 的 `BceHttpClient` + `BceV1Signer`（BCE V1 签名，签名在 Authorization 头不泄 URL）直调 REST，按 host 切端点。
- **上传器** `BaiduCertUploader`（storeKind=`baidu_cert`）：`POST /v1/certificate`→certId。
- cert（纯上传 no-op）、cdn（内联 PEM `PUT /v2/{domain}/certificates`）、blb/appblb（证书服务型，loadbalancer/listener 双目标 + SNI additionalCertDomains）。仅 exact 域名匹配。

### 火山引擎 Volcengine

- **包**：`volcengine/volc-sdk-php`
- **证书中心**：`ImportCertificate`→证书 id（uploader **新建** `VolcCertCenterUploader`，storeKind=`volc_certcenter`）
- **CDN / DCDN**：域名绑证书 id
- **CLB / ALB**：listener 绑证书 id
- **TOS**：对象存储自定义域名绑证书

### Azure

- **包**：`microsoft/microsoft-graph` / Azure REST（OAuth2 + Guzzle）
- **Key Vault**：`ImportCertificate`→证书标识（uploader **新建** `AzureKeyVaultUploader`，storeKind=`keyvault`）
- **CDN / Front Door**：关联 Key Vault 证书

### GCP

- **包**：`google/cloud` / Certificate Manager REST
- **Certificate Manager**：`CreateCertificate`→证书资源名（uploader **新建** `GcpCertManagerUploader`，storeKind=`gcp_certmanager`）
- **Load Balancer**：target proxy 绑证书

### 又拍云 Upyun

- **包**：HTTP REST（`upyun/upyun` 或 Guzzle 直调）
- **SSL**：`POST /https/certificate`→证书 id（uploader **新建** `UpyunSslUploader`，storeKind=`upyun`）+ 域名绑定

### 非云目标

- ✅ **Kubernetes Secret**（key `k8s` / product `secret`）：`GET/POST/PUT /api/v1/namespaces/{ns}/secrets/{name}`，data.tls.crt/tls.key base64；凭证 `server`+`token`+`ca_cert`（=解析后的 kubeconfig 等价产物，纯 REST 无需 YAML 解析）。内联。
- ✅ **Webhook**（key/product `webhook`）：任意 URL + GET/POST/PUT/PATCH/DELETE × json/form/multipart + `${CERTIMATE_DEPLOYER_*}` 变量替换。内联。
- ⛔ **ssh / ftp / local 不实现**（产品决策，2026-06）：管理端已有独立的本地证书安装工具，无需这三类。技术上亦有阻碍——**ssh** 需 phpseclib，但镜像可得最新版 3.0.48 仍被 2 个 high 公告覆盖（修复版 3.0.50+ 镜像未同步），crypto 库供应链风险不宜静默忽略；**ftp** 需 `ext-ftp`（容器未装、不可经 composer 安装）且明文协议；**local** 在管理机本机执行用户配置的 shell 命令，多租户下是 RCE 提权面。若未来要做：local/ssh 的写文件/执行命令必须走主系统 `BinaryLocator` + `escapeshellarg`（不裸 exec），且 local 须管理员限定。

---

## 四、阿里 / 腾讯长尾端点

已对齐 certimate 全部阿里/腾讯端点，除 ga2 外均已实现（provider 已就绪、uploader 多可复用）：

- ✅ **阿里 cas**（`AliyunCasDeployer`）：纯上传到 CAS 证书服务，bind no-op（usesRemoteCertStore=true + 复用 `AliyunCasUploader`）。
- ✅ **阿里 casdeploy**（`AliyunCasDeployDeployer`）：CAS 托管批量部署 `CreateDeploymentJob` + 轮询 `DescribeDeploymentJob`；未填 contact_ids 时 `ListContact` 取首个。CertIds 用拆出的数字 certId。
- ✅ **阿里 esasaas**（`AliyunEsaSaasDeployer`）：`ListCustomHostnames` 分页找 SaaS 域名 → `UpdateCustomHostname`（CertType=cas, CasId 数字 certId, CasRegion）。仅 exact 匹配（未做 wildcard/certsan）。
- ✅ **腾讯 ssl**（`TencentSslDeployer`）：纯上传到 SSL 服务，bind no-op（复用 `TencentSslUploader`）。
- ✅ **腾讯 ssl-update**（`TencentSslUpdateDeployer`）：上传新证书 → `UpdateCertificateInstance`（OldCertificateId 旧 + 新 CertificateId + ResourceTypes + 按白名单 ResourceTypesRegions）。略 isReplaced/完成度轮询。
- ✅ **腾讯 tse**（`TencentTseDeployer`，内联型）：云原生网关。create 走 SSL 上传 + `CreateCloudNativeAPIGatewayCertificate`（BindDomains 取 config 或证书 SAN）；replace（填 certificate_id）走 `ModifyCloudNativeAPIGatewayCertificate` 直灌 PEM + CertSource=native。
- ✅ **腾讯 ga2**（`TencentGa2Deployer`，全球加速 GA2 v20250115）：用已装 `tencentcloud/common` 的 `CommonClient`（泛型 TC3-HMAC-SHA256）调 ga2 `DescribeListeners`/`ModifyListener` + `tencentcloud/ssl` 上传去重，复用 `TencentSslUploader`。

---

## 五、已知陷阱清单

- **SDK 异常常继承基类，必须 `catch (Throwable)`**：各厂商 SDK 异常体系不一致——darabonba `TeaError`、`AlibabaCloud\...\Exception`、OSS `OssException`/`OperationException`（OSS 的 `OperationException` 把底层 Guzzle 异常包进去，message/trace 含签名 URI 的 AK/Signature，且**不是** TeaError）。`guardSdk` 故意 `catch (Throwable $e)` 而非具体类型，新 deployer 的 `sanitize()` 也要按 Throwable 多分支提取错误码。
- **响应字段大小写不一致，deserialize 静默丢**：同一概念在不同阿里 SDK 里大小写不同（如 live 用 `CertName` 非 CAS 体系、vod 用 `CertID`、waf 用 `InstanceID`/`CertIdentifier`）。SDK 的请求/响应模型按属性名严格反序列化——**写错大小写不会报错，字段直接丢成 null**，绑定静默失败。务必对照 certimate 对应 provider 源码确认确切字段名。
- **WAF 等吃完整 CertIdentifier 字符串**：阿里 alb/nlb/waf 把 `remote_cert_id`（`"{certId}-{region}"`）**原样**作 CertId 塞接口，**不**拆 certId+region（对齐 certimate）；vod 等则需 `ParsesCasCertIdentifier` 拆出 certId 再反查 CertName。照模板时确认目标接口要整串还是拆分。
- **插件测试用文件级 `uses(TestCase, RefreshDatabase)`**：Pest 测试在文件顶部声明 `uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);`（插件无独立 Pest.php，复用主系统）。新建测试文件照此声明，否则拿不到 DB / 框架上下文。
- **腾讯子包版本对齐**：腾讯 SDK 拆成多个 `tencentcloud/<product>` 子包，`common` 与各产品包须版本兼容；部分包（如 `tencentcloud/gaap`）在 composer.json **钉死具体版本 `3.0.1291`** 避开破坏性发布，新增腾讯端点时核对子包版本一致。
- **SDK Client 多为 final，用泛型 mock**：阿里/腾讯 SDK 的 Client 类常 `final`，Mockery 无法直接 partial mock。测试里走 `makeClient` 注入缝返回 `Mockery::mock()`（泛型 mock，按方法名打桩），而非 mock 具体 final 类。
- **业务错误别进 `guardSdk`**：`guardSdk` 只包真正的 SDK 网络/API 调用。配置缺失、参数校验等业务错误走 `fail()`（抛业务异常，message 可读不脱敏），别塞进 guardSdk——否则会被当 SDK 异常重建成无 previous 的通用 RuntimeException，丢失可读上下文。
- **新增 ShouldQueue 别用 `tries=1`**：升级 freeze 中间件 `SkipWhenUpgradeFrozen` 对 Job `release(60)` 会计入 attempts，`tries=1` 被 freeze release 一次即在第二次 pop 被 MaxAttemptsExceeded 误杀、handle 永不执行。编排 Job（`CloudDeployTriggerJob`/`CloudChainBackfillJob`）用 `tries=5` + `maxExceptions=1`（吸收 freeze release，业务异常仍只一次）；`CloudDeployJob` 走 `tries=3`。守门 `tests/Unit/Jobs/CloudJobsFreezeConfigTest`；机理详见主系统 `CLAUDE.md` 升级冻结约定。
- **纯上传端点允许空 config**：`AliyunCasDeployer`、`TencentSslDeployer` 等 `configSchema()=[]` 的产品无资源配置，新增 target 时 `config` 字段必须存在但允许空数组；`TargetStoreRequest` 用 `present|array`，不要改回 `required|array`，否则 Laravel 会把空数组判成缺失。
- **schema 新增 required 字段 = 端点 breaking change**：`configSchema` 加 required 字段会让存量 target（未存该字段）部署时 `requireConfig` 失败。新字段尽量 optional + 代码内默认值；确需 required 视为该端点破坏性变更，需迁移存量 config。
- **云端证书只增不删，会撞配额**：每次续期向 CAS/腾讯 SSL/SLB 上传新证书，`cloud_deploy_remote_certs` 亦只增。腾讯 SSL/阿里 CAS 有证书数量配额，报“超限”时需人工清理云端旧证书（本插件不 GC，与 certimate 同）。
- **failed() 副作用，测试需 mock NotificationCenter**：`CloudDeployJob::failed()` 重试耗尽会派 `cloud_deploy_failed` 通知。测 `failed()` 落库的用例须在 `beforeEach` 默认 `app()->instance(NotificationCenter::class, Mockery::mock(...)->shouldIgnoreMissing())`，否则 dispatch 真跑会撞被 mock 的 Log facade / 真发邮件。

---

## 六、运维

- **卸载数据语义**：`PluginManager::uninstall($name, $removeData)`。`remove_data=false`（默认）→ 保留 `cloud_deploy_*` 4 表，**加密的 AK/SK 凭证残留库中**；`remove_data=true` → 跑 `migrate:rollback` 删表（各迁移 down 已 dropIfExists，通知模板迁移 down 按 code 删模板行）。卸载带凭证插件建议确认是否清数据。
- **APP_KEY 轮换即废所有凭证**：`cloud_deploy_accesses.credentials` 用 `encrypted:array`（绑 APP_KEY）。轮换 APP_KEY 后所有已存云凭证无法解密，需用户重新录入。本插件是全系统最大加密凭证存量方，轮换前务必周知。
- **失败通知**：证书签发后推送瞬态失败、重试（`tries=3`）耗尽 → 邮件通知 target 所属 user（`cloud_deploy_failed` 模板）；确定性失败（缺私钥/SM2）只记日志不发邮件。**依赖 queue worker 常驻 + schedule:run cron**。
- **对账 sweep（`cloud-deploy:reconcile`，每日 04:30，self-register via `callAfterResolving(Schedule)`）**：补两类——A 漏推（`last_cert_id != latest` 或 NULL，每天）OR B 失败节流（`last_status=failed` 且 `last_deployed_at` 超 7 天）。**所有失败路径**（前置拒绝缺私钥/SM2/缺链 + 业务错误 + 瞬态 `catch(Throwable)` + 重试耗尽 `failed()`）都更新 `last_cert_id=当前 cert`，使"已针对当前证书处理过（无论成败）"的 target 走 B（7 天节流）、只有"从没针对当前证书 dispatch 过"的真漏推走 A 每天——防瞬态失败每天 dispatch + 每天发邮件（M1 修复）。`CloudDeployJob` 加 `WithoutOverlapping(targetId)->dontRelease()` 防 sweep×trigger 并发双推。
- **order 锚点**：target 锚定订单。续费/重签经 `CloudDeployTriggerJob` 自动迁移 target 到新订单；另开新订单换证书需用户重新配置目标。
- **i18n**：通知模板 / 前端文案当前全中文，与主系统一致；后续支持多语言需另补插件文案翻译。

---

## 七、双端管理（DeployService 共享推送 + admin 搜索/脱敏）

- **共享 action `Services\DeployService::deploy(?int $orderId, array $targetIds, bool $force, bool $crossUser): int`**：user/admin 控制器各自鉴权后调同一底层，返回 dispatched 计数（仅 active cert 的 target 入队）。
  - **两模式**：`order_id` 模式（`$orderId` 非空、`crossUser=false`）按订单查 `enabled=true` 目标；`target_ids` 模式按 id 直查、**不过滤 enabled**（显式选 target）。
  - **crossUser 语义**：`false`（user）靠调用方 controller 注册的 `CloudDeployTarget` UserScope 收敛本人；`true`（admin）用 `withoutGlobalScopes()` 跨用户直查。admin 系统设置页走 `target_ids`，订单详情页可走 `order_id+user_id` 双键收敛当前订单。
  - **fail-closed 守卫**（admin 入口）：`crossUser=true` 时必须二选一传 `target_ids` 或 `order_id+user_id`；系统设置页走 `target_ids`，订单详情页走 `order_id+user_id`，两者都缺或只传 `order_id` 都抛 `ApiResponseException`（防 withoutGlobalScopes 无 where 群发全部用户 target）。`ApiResponseException` 的 msg 在 `getApiResponse()['msg']`、不在 `getMessage()`，service 层直接 `throw new ApiResponseException($msg, null, null, 0)`。
  - user `DeployController` 委派 `crossUser=false`（构造注册 UserScope）；admin `CloudDeployController::deploy` 委派 `crossUser=true`、`force` 缺省落 `true`（手动点推=显式重推，与 user `?? false` 不对称，有意）：`target_ids` 用于系统设置跨用户直推，`order_id+user_id` 用于订单详情一键推送。
  - 入队前再次用 `TenantConsistency::check(target.user_id, access_id, order_id)` 过滤历史脏 target，`dispatched` 只计真实入队数。
- **订单候选接口（新增/改绑用）**：
  - user/admin 分别提供 `GET /cloud-deploy/order-options` 与 `GET /cloud-deploy/order-options/{id}`；admin 必须带 `user_id`，否则 fail-closed。
  - 列表只返回未取消且 latest cert 状态在 `unpaid/pending/processing/approving/active` 的订单，支持按订单号、主域名和 SAN 搜索。
  - `show` 允许同用户下存量非候选订单回显；真正创建/改绑由 `OrderOptionService::isSelectable()` 收紧。
- **target/access 写路径**：
  - `TargetMutationService` 统一 user/admin create/update：校验 access/order/user 一致，create 必须候选订单，update 改 `order_id` 时重新检查候选。
  - target 的唯一推送目标由 `CloudDeployTarget::configHash()` 规范化 `config` 后写入 `config_hash`，并由 DB 唯一索引 `(user_id, access_id, product, config_hash)` 兜底；服务层先给友好错误，唯一键负责并发最终防线。
  - 只要 `access_id/order_id/product/config` 任一结构字段变化，清空 `last_cert_id/last_status/last_error/last_deployed_at`，避免新配置沿用旧推送状态。
  - access 的 `user_id/provider` 创建后不可修改；编辑凭证时 `credentials` 留空或 null 表示保留原凭证。
- **admin 三列表搜索（`CloudDeployController` targets/logs/accesses，裸 `$request->input()` 不经 validated）**：
  - **列名差异**：targets 状态列是 `last_status`（特殊值 `unpushed` → `whereNull`）、logs 是 `status`；logs 全为快照列（provider/product/resource_summary/access_name 不 join 主系统）。
  - **provider 经 access join**：targets 表无 provider 列，用 `whereHas('access', fn ($q) => $q->where('provider', $v))`。
  - **域名搜索**：`whereHas('order.latestCert', fn ($q) => $q->where('common_name', 'like', ...))`，依赖 `CloudDeployTarget::order()` 关系；**禁 raw join**（targets/orders 都有 user_id，raw join 报 1052 列名歧义）。logs 域名搜 `resource_summary` 快照（仅 domain 型 deployer 非空）。
  - **username 经 `whereExists` join users**（admin 全量按用户名 LIKE）；user_id 等值带 `cloud_deploy_targets.`/`cloud_deploy_logs.` 表前缀防与子查询歧义。
  - **quickSearch**：`where(fn)` 分组包 orWhere（订单号精确 + 域名 + 凭证名 + 用户名），不破坏外层 AND。
- **config 逐键脱敏（方案①，`redactConfig(Registry, CloudDeployTarget)`）**：admin 跨用户列 target 会序列化任意用户 config（webhook 含 Authorization 等）。**不能 select 排除整个 config 列**（domain 等资源键会一起丢）。逐键：① `hasDeployer(provider, product)` 守卫先于 `resolveDeployer`（未注册组合 resolveDeployer 抛 `InvalidArgumentException` 会 500 整个列表）；② 命中→按 `configSchema()` 把 `secret=true` 键打 `******`、保留非 secret 键；③ 未命中（陈旧/改名/脏数据）→ fail-safe 打码除 `domain` 外全部键、不抛。`provider` 用 `$target->getAttribute('access')` + `instanceof CloudDeployAccess` 取（绕 larastan「belongsTo 必非 null」乐观推断，保留 access 悬空 → fail-safe 健壮性）。admin 编辑 target 时走 `GET target/{id}` 取完整 config；不要用列表行的 `******` 回写。
- **`attachUsernames(Collection $items)`**：targets/logs/accesses 表只存 user_id、模型无 user() 关系，单次 `whereIn` 查 username 拍平为顶层 `username` 字段供前端「用户」列；`User::withoutGlobalScopes()`（admin 跨用户）。`targets()` 因脱敏走内联分页（不经通用 `respondPaginated`），须显式调一次 + 顶层 provider 拍平（`with('access:id,provider')` eager load）。docblock 用 `@template TModel of Model` 避免 `Collection<int,Model>` invariant TValue 协变报错。
- **前端**：系统设置 tab 顺序固定为「部署目标 / 云凭证 / 部署历史」。user/admin 订单详情和系统设置都支持部署目标新增、编辑、删除、启停、推送、记录；订单详情表单隐藏订单字段并隐式提交当前订单，admin 订单详情使用订单 `user_id` 限定凭证并对齐 user 订单详情的表格样式/记录弹窗，系统设置表单用 `ReRemoteSelect` 远程搜索订单。admin 系统设置表单先远程选择用户，再按该用户限定凭证和订单；凭证选项显示 `凭证名(云平台) · 用户名`。admin 部署目标列表只展示组合搜索（订单号/域名/用户名/凭证名）以及状态/启用筛选，结构化单项筛选可以保留在接口契约中供外部调用。部署目标表单核心实现统一放在 `plugins/cloud-deploy/frontend/shared/TargetForm.vue`，user/admin 端 `src/components/TargetForm.vue` 只做 API 适配和模式透传，禁止再复制一份表单状态/校验/提交逻辑。推送记录弹窗 `is_final` 默认传 `1`——非 JS `true`，qs 序列化 `true`→`"true"` 不被 Laravel `boolean` 规则接受会 422。插件前端无 prettier/lint，靠 `build.sh {user,admin}` vite 编译为门。
