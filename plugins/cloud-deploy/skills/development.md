# cloud-deploy 开发规范

证书自动推送各大云平台。certimate 式封装：每个 `(provider, product)` 一个 deployer，schema 驱动前端表单 + 后端校验，凭证脱敏，插件独立 vendor（不 scoping，不入库，随发布包交付）。所有路径相对 `plugins/cloud-deploy/backend/`。

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

### 独立 vendor（发布包携带，不 scoping，不入库）

插件依赖阿里/腾讯官方云 SDK。**vendor 不入 git，但进入发布 zip**：发布脚本执行生产 `composer install`，写入 `vendor/composer/.ssl-manager-lock.sha256`；插件的 `post-autoload-dump` 钩子复用主系统唯一的 `backend/scripts/write-composer-lock-marker.php`，在手工 `composer install`、`update` 或 `dump-autoload` 后为插件 Composer 项目原子刷新该标记，避免主系统与插件各自维护实现而漂移。主系统安装/更新时校验标记与 lock 后直接使用，`PluginComposerRunner` 仅作为历史不带 vendor 插件包的兼容回落。

**对目标机要求**：新发布包无需 Composer/Packagist/GitHub 网络。只有安装历史不带 vendor 的插件包时，才需 Composer 和 PHP CLI 子进程能力。

`CloudDeployServiceProvider::register()` `require` 插件 vendor autoload 后，把插件 `ClassLoader` `unregister()` 再 `register(false)` **挂 SPL 自动加载栈尾**——共享类（GuzzleHttp/Psr 等）回落主系统版本、插件独有类（AlibabaCloud/TencentCloud 等主 loader `findFile` 返回 false）从插件 vendor 解析。**不做 Strauss scoping**（实测不 scoping 正常、scoping 反 fatal）。幂等守护，缺 vendor 不 fatal（is_file 守卫 + `CloudDeployJob::guardSdk` 把缺 SDK 转 per-target 失败日志）。

**跨大版本共享依赖必须 `replace`（挂栈尾兜不住）**：挂栈尾只对**同大版本**共享依赖可靠（API 兼容、回落主系统无害）。**跨大版本**冲突在 PHP-FPM 多 worker + opcache 下挂栈尾隔离会失效——某 worker 把插件旧版类解析进主系统、与主系统新版编译时签名不兼容 fatal → **全站 500**（连 login 都崩）。0.0.1 首发踩此坑：古董 `baidubce/bce-sdk-php`（`php>=5.3.3`）拖入 `psr/log 1.x`（vs 主系统 3.x，`LoggerInterface::log` 无类型 → 主系统 Monolog 3.x 的 `emergency()` 签名不兼容）+ `guzzle/guzzle 3.x`（再拖 `symfony/event-dispatcher 2.x` vs 主系统 7.x）。**根治**：插件 `composer.json` 用 `"replace": {"psr/log":"*","guzzle/guzzle":"*","symfony/event-dispatcher":"*"}` 把这些古董挡在插件 vendor 外、运行时回落主系统版本（百度 SDK 仅 type-hint `psr/log`，3.x 超集兼容）；`BaiduRestClient` 改用主系统 `GuzzleHttp\Client` 发请求、**弃用 SDK 自带 `BceHttpClient`**（底层古董 Guzzle 3.x），仅复用 `BceV1Signer` 签名（纯算法、无第三方依赖）。守护测试 `VendorCoexistenceTest`「插件 vendor 不得携带跨大版本冲突共享依赖」遍历插件↔主系统重叠包断言无 major 冲突、防复发。**改 `replace` 后移除被锁定依赖必须 `composer update -W` 全量重解析**（定向 update 对 replaced 包无效），`-W` 会重开 guzzlehttp 历史公告，故 `composer.json` 设 `policy.advisories.block=false`（仅影响本地生成 lock；生产 `PluginComposerRunner` 走 `composer install` 按 lock 不检查公告，当前 Guzzle 锁定 7.15.2）。

**本地开发**：vendor 不入库，clone 后需先 `composer install -d plugins/cloud-deploy/backend` 把 SDK 拉下来再跑插件测试（CI 同此，已在 `ci-job-snippet.yml` 加 plugin composer install 步骤）。

### 数据模型 + Job

4 表：`cloud_deploy_accesses`（云凭证，credentials 加密）/ `cloud_deploy_targets`（部署目标 = order+access+product+config，同一用户下相同 access+product+config 只能绑定一个订单）/ `cloud_deploy_remote_certs`（已上传云端证书去重）/ `cloud_deploy_logs`（部署历史）。`CloudDeployJob`（**onQueue tasks + afterCommit**）负责：freeze 守卫 / 租户隔离 / 缺链回填 / 幂等 / force 重推。续期换 order，target 迁移跟随。

### 主系统足迹

插件功能侧主系统 backend **0 改动**，仅复用既有 widget 插槽 2 个（`admin-order-detail-ssl-actions` / `user-order-detail-ssl-actions`，order 详情 SSL 卡片显示「云部署」区域 + 当前订单目标状态）。schema 驱动 → 新增端点前端零改动。（包内 vendor 校验及历史包 Composer 回落所需的 `PluginManager` hook 是**通用主系统能力**，非本插件专属代码，见上「独立 vendor」节。）

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
git add plugins/cloud-deploy/backend/composer.{json,lock}   # 仅提交 composer.json/lock；vendor 不入库，由发布脚本打包
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

### 私钥交付格式

`CloudDeployJob` 在调用 uploader / inline deployer 前，通过 `TraditionalPrivateKey` 把系统证书私钥的交付副本统一规范化为传统 PEM：RSA 使用 PKCS#1（`BEGIN RSA PRIVATE KEY`），ECDSA 使用 SEC1（`BEGIN EC PRIVATE KEY`）。这是系统证书的统一交付基线，不表示每家云服务的官方接口都逐项声明支持这两种编码。

选择统一基线而不维护“部署器 → 私钥格式”映射，是为了对齐 Certimate v0.4.30 的实际处理方式：

- ACME 签发结果在证书来源层统一转换为传统格式，然后保存并交给后续部署器；
- 用户上传的已有证书只解析私钥并校验证书匹配，原始私钥编码保持不变；
- 部署请求只传递 `CertificatePEM` / `PrivateKeyPEM`，provider 注册信息和 158 个部署器中均没有 `traditional`、`pkcs1` 或 `pkcs8` 能力标记；
- AWS ACM 等 PEM 接口直接接收上游私钥；Azure Key Vault、S3、SSH 等需要 PFX/JKS 的路径在各自部署器内重新封装目标容器格式。

因此，不能根据 provider 名称或 PEM 头动态推断目标接口需要哪种编码，也不能从 Certimate 导入一份不存在的格式清单。本插件对系统证书采用与 Certimate ACME 链路相同的传统格式；如果以后有经官方契约或真实请求验证、明确只接受其他编码的具体端点，应在该端点增加有测试覆盖的显式例外，并在本节记录依据，不能通过失败后换格式重试（首次请求可能已经产生云端副作用）。

转换只在内存中进行，不回写 `certs.private_key`。私钥无法解析或算法不支持时，`TraditionalPrivateKey` 必须抛 `DeployBusinessException`，由 `CloudDeployJob` 记录确定性业务终态并停止，不进入队列异常重试。新增端点不得绕过该 Job 边界，也不得在单个 deployer 内无依据地重复转换。

### Step 4 — 注册

在 `Deployers/registry/<provider>.php` 加一行 `$registry->registerDeployer('<provider>', '<product>', fn () => new <Provider><Product>Deployer);`。新 provider 则先 `$registry->registerProvider(new <Provider>Provider);`，并在 `CloudDeployServiceProvider::register()` 的 `foreach (['aliyun','tencent', ...])` 数组加 provider 名。

### Step 5 — 测试

写 `tests/Unit/Deployers/<Provider>/<Product>DeployerTest.php`：子类 **override `makeClient`** 按 `match($kind)` 注入对应 Mockery mock，断言 ① 上传（证书服务型）调对 API 返回 id ② bind 把 id/PEM 传给资源 SDK ③ SDK 抛含 AK 异常时 message 脱敏。**无需手写** configSchema 契约 / 凭证泄露测试——`RegistryCompletenessTest` / `ConfigSchemaContractTest` / `CredentialLeakScanTest` 遍历所有 deployer 自动覆盖（新端点注册后即纳入）。

### Step 6 — 验证 + commit

跑插件测试（`php artisan test plugins/cloud-deploy/backend/tests`）+ 独立 PHPStan 0 errors，commit。`Registry::resolveDeployer()` 返回公共 `DeployerInterface`，需要续查等可选能力时用 `instanceof` 收窄，不要把调用方参数误写成具体基类联合类型；返回 Eloquent 查询的辅助方法用 `Builder<Model>` PHPDoc 保留模型泛型。前端无需改（schema 驱动，catalog 自动带出新端点供表单渲染）。

---

## 三、Provider 实现状态总览（已对齐 certimate 149 端点 / 55 provider）

**已对齐 certimate 全部部署端点：149 个已实现（certimate 共 152，ssh/ftp/local 经产品决策不实现，见本节末）。** 下方按 provider 列「SDK/签名 + 关键 API + uploader 策略」作实现参考与新增端点模板；**权威清单以 `Deployers/registry/*.php` 为准**（每个 registry 文件 = 一个 provider 的全部 product 注册）。官方 PHP SDK：aliyun/tencent/aws（v3）/qiniu/baidu/s3(aws S3)；其余 40+ provider 经各自 `<Provider>RestClient` 手写签名（HMAC-SHA256 各家变体 / JWT / OAuth2 / OCI HTTP Signatures / EOP 三级派生 / QY / TC3 等）调 REST，GuzzleHttp 来自主 vendor，不为单个 provider 另增依赖；插件已锁定的 phpseclib 同时用于签名与私钥格式转换。下列条目即便文字描述为「新建」也均**已落地**。

### AWS ✅（已实现，8 端点：acm/iam/alb/nlb/clb/cloudfront/amplify/apigateway）

- **包**：`aws/aws-sdk-php` ^3.0（实锁 3.337.3，单包全服务，v3 `src/` 布局）。client 构造 `new Aws\Acm\AcmClient(['version'=>'latest','region'=>$r,'credentials'=>['key'=>,'secret'=>]])`，调用 `$c->importCertificate([...])` 返回 `Aws\Result`（数组式）。
- **composer 公告坑（已解，记录避免重复）**：composer 2.10 默认 `policy.advisories.block=true` 挡住 aws v3 全版本。已在 `composer.json` 加 `config.policy.advisories.ignore-id` 忽略 3 个**本插件不可达**公告：PKSA-4t1p（CloudFront 签名 URL/Policy 注入，只用 UpdateDistribution 不生成签名）、PKSA-dxyf（S3 加密客户端，完全不用 S3）、PKSA-mnyp（jmespath <2.9.1 表达式编译注入，仅 aws-sdk 内部静态表达式、不传用户输入）。原策略**不带 `-W`**（保持 Guzzle 锁定不重开历史公告），composer 自动回溯到 aws 3.337.3 + jmespath 2.7（阿里云镜像有）。**注：0.0.1 修复引入 `replace`（挡 psr/log 等跨大版本古董，见「独立 vendor」节末）后，移除 replaced 包必须 `-W` 全量重解析、会重开 guzzlehttp 公告，故改用 `policy.advisories.block=false`**——当前 Guzzle 锁定 7.15.2，block 仅影响本地生成 lock、生产 install 按 lock 不检查。
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
- **上传器** `QiniuSslUploader`（storeKind=`qiniu`）：按 Certimate 当前实现以 Qiniu V2 鉴权 `POST https://api.qiniu.com/sslcert`→certID；响应体成功码兼容 `0` / `200`。
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
- **上游确定性错误用结构化字段分类**：`guardSdk` 先通过 `sanitize()` 生成安全文案，再调用端点的 `isTerminalSdkError(Throwable)` 钩子；钩子只能检查原始 SDK 异常的类型、结构化错误码或 HTTP 状态，禁止匹配 message 文本。命中后公共边界重建无 previous 的 `DeployBusinessException`，否则仍重建普通 `RuntimeException` 交给队列重试。默认钩子返回 false，新增规则必须限定到具体端点并有正反用例。例如 Aliyun FC 仅把服务端结构化 `InvalidArgument` 判为确定性参数错误；同样文本若没有结构化错误码仍不得终止重试。网络错误、限流、5xx 始终保留重试。
- **新增 ShouldQueue 别用 `tries=1`**：升级 freeze 中间件 `SkipWhenUpgradeFrozen` 对 Job `release(60)` 会计入 attempts，`tries=1` 被 freeze release 一次即在第二次 pop 被 MaxAttemptsExceeded 误杀、handle 永不执行。编排 Job（`CloudDeployTriggerJob`/`CloudChainBackfillJob`）用 `tries=5` + `maxExceptions=1`（吸收 freeze release，业务异常仍只一次）；`CloudDeployJob` 走 `tries=5`（**不加 maxExceptions**——见下 G2）。守门 `tests/Unit/Jobs/CloudJobsFreezeConfigTest`；机理详见 `skills/backend/upgrade.md` 升级冻结契约。
- **CloudDeployJob 的 `$timeout=55` × `tries=5`（无 maxExceptions）× 长轮询预算（G2）**：worker `--timeout 60`（`deploy/scripts/bt-install.sh:1143,1234`）经 pcntl SIGALRM handler 优雅退出（非 SIGKILL，依赖 pcntl 扩展，与既有 `--timeout 60` 同前提）；被 alarm 杀的 job 不走 backoff，保持 reserved 至默认 `retry_after=900`（`config/queue.php`）后复投，`failed()` 仅末次 attempt 兑现。Job 设 `$timeout=55`（< 60、< 900）保证优雅退避而非静默 reserved。**长轮询 deployer 超窗改抛 `DeployPollPendingException`（重试通道 + 携 jobId）**，故 tries 3→5 覆盖云端异步落地 + 吸收 freeze release；**不加 maxExceptions**——maxExceptions 会在首个 pending 异常终结重试链（旧 tries=3 成型于「超窗不重试」旧语义，语义已变）。运维约束：`QUEUE_RETRY_AFTER` 必须 > `$timeout`。
- **长轮询 deployer 分型 + jobId 续查（G2）**：全仓 6 个长轮询 deployer 分两型——**job-id 型 4 个**（Aliyun CAS 托管 / Wangsu CDN Pro / Tencent COS / Tencent ssl-deploy，每次 bind 新建一次性云端任务再轮询它）实现 `ResumesRemoteJob`：bind 短窗首查（`maxPollAttempts` 次）未终态即抛 `DeployPollPendingException`（携 jobId、**必在 guardSdk 之外**），`CloudDeployJob` 把当前任务保存在 target 数据库记录的单槽 `pending_job` 中，有效期 10 天；新版本成功写入该字段后，常规 `cache:clear`/`optimize:clear` 不会丢失它，重试和 sweep-B 先 `resumePoll` 续查**同一** jobId（不重建任务）→ 消除「每 attempt 重建云端任务→旧任务终态永不被观察」的慢性误报。`resumePoll` 再次 pending 会复用 jobId 并续期；`failed()` 重试耗尽和瞬态异常保留有效 pending，resume/bind 成功或业务终态失败清理，内容损坏、证书不匹配、过期或 deployer 不支持续查时先清理再 bind。`force=true` 手动重推遇有效 pending 时**仍走 resumePoll 续查旧任务**——语义等价（云端任务仍在跑，重建只会堆积重复任务），非 bug。**状态轮询型 2 个**（Zenlayer CDN/GA 轮询域名/加速器 configStatus）仅压窗、重试自续观察同一资源收敛，无需 jobId。新增轮询端点按此判据选型（一次性任务 id → job-id 型 + `ResumesRemoteJob`；资源状态轮询 → 压窗即可）。所有轮询循环「末次不 sleep」回收预算。边界：不迁移旧 Cache pending，不覆盖远端任务已创建但数据库写入前崩溃或写失败的恢复，也不把 Cache 锁描述为清缓存期间的并发强保证。
- **官方 SDK / RestClient 必设显式 connect/read timeout（G3）**：darabonba/aws-sdk/腾讯 SDK 默认无读超时 → TCP 黑洞无限挂起（挂到 `$timeout=55` 被 SIGALRM 杀 + reserved 600s，非优雅退避）。Aliyun 一律经 `BuildsAliyunConfig::aliyunConfig()`（守门：`Deployers/Aliyun` 下 `new Config(` 仅 trait 一处）、AWS 一律经 `BuildsAwsClientConfig::awsClientConfig()`（8 个 makeClient 全改，遍历守门防单点假绿）、手写 `<Provider>RestClient` 的 Guzzle 设 `connect_timeout`+`timeout`。**长轮询端点单次调用最坏墙钟 T 收至 10s**（Wangsu/Zenlayer RestClient TIMEOUT 常量、Tencent COS/ssl-deploy `CLIENT_TIMEOUT_SECONDS`；**Aliyun 的 T = readTimeout+connectTimeout 之和**——darabonba 把 Guzzle 总 timeout 设为二者之和（`vendor/alibabacloud/darabonba/src/Dara.php:368`），经 `BuildsAliyunConfig::aliyunCallBudgetSeconds()` 派生，当前 7+3=10s），须满足预算算式 `(N_upload+N_pre+N_iter)×T + (N_iter-1)×interval ≤ 50`（`HasPollBudget::pollBudget()` 声明，`CloudDeployPollBudgetTest` 计算断言锁死，改常量即红）；非轮询腾讯端点**有意保持** `setReqTimeout(15)`（单调用 ≪55，注释写明防误报）。qiniu 静态 SDK 仅增加测试请求注入缝，生产超时仍由 `$timeout` 兜底（单调用型）。
- **超窗归类逐 deployer（G2/G5）**：Aliyun CAS/Wangsu 的**窗口耗尽**改抛 `DeployPollPendingException`（原误分类为 `DeployBusinessException` 不重试）；真失败分支（Aliyun `editing`/'' 空态、Wangsu `status=failed`、Tencent 失败子任务）保留/改为 `DeployBusinessException`（终态失败 → 清 pending + 通知）。Zenlayer 超窗抛 `ZenlayerApiException('DeployTimeout')` 在 **guardSdk 闭包内**被 `AbstractDeployer::guardSdk` 重包装为脱敏通用 `RuntimeException`（可重试，非原类型直接冒泡）——测试断 `RuntimeException` 且非 `DeployBusinessException`，**禁断 `ZenlayerApiException`**。
- **纯上传端点允许空 config**：`AliyunCasDeployer`、`TencentSslDeployer` 等 `configSchema()=[]` 的产品无资源配置，新增 target 时 `config` 字段必须存在但允许空数组；`TargetStoreRequest` 用 `present|array`，不要改回 `required|array`，否则 Laravel 会把空数组判成缺失。
- **schema 新增 required 字段 = 端点 breaking change**：`configSchema` 加 required 字段会让存量 target（未存该字段）部署时 `requireConfig` 失败。新字段尽量 optional + 代码内默认值；确需 required 视为该端点破坏性变更，需迁移存量 config。
- **云端证书只增不删，会撞配额**：每次续期向 CAS/腾讯 SSL/SLB 上传新证书，`cloud_deploy_remote_certs` 亦只增。腾讯 SSL/阿里 CAS 有证书数量配额，报“超限”时需人工清理云端旧证书（本插件不 GC，与 certimate 同）。
- **failed() 副作用，测试需 mock NotificationCenter**：`CloudDeployJob::failed()` 重试耗尽会派 `cloud_deploy_failed` 通知。测 `failed()` 落库的用例须在 `beforeEach` 默认 `app()->instance(NotificationCenter::class, Mockery::mock(...)->shouldIgnoreMissing())`，否则 dispatch 真跑会撞被 mock 的 Log facade / 真发邮件。

---

## 六、运维

- **卸载数据语义**：`PluginManager::uninstall($name, $removeData)`。`remove_data=false`（默认）→ 保留 `cloud_deploy_*` 4 表、通知模板及**加密的 AK/SK 凭证**；`remove_data=true` → 对插件迁移目录跑 `migrate:reset`，逐批执行全部 `down`（各表迁移 down 已 dropIfExists），再执行 `PluginSeeder::clear()` 删除 `cloud_deploy_failed` 模板。迁移或 Seeder 清理失败都会中止卸载并保留插件目录，不能把未清理误报为成功；卸载带凭证插件建议确认是否清数据。
- **APP_KEY 轮换即废所有凭证**：`cloud_deploy_accesses.credentials` 用 `encrypted:array`（绑 APP_KEY）。轮换 APP_KEY 后所有已存云凭证无法解密，需用户重新录入。本插件是全系统最大加密凭证存量方，轮换前务必周知。
- **失败通知（G5）**：证书签发后推送瞬态失败、重试（`tries=5`）耗尽 → 邮件通知 target 所属 user（`cloud_deploy_failed` 模板）；模板由插件 `NotificationTemplateSeeder` 幂等补齐并实现 `ProvidesNotificationTemplateDefaults`，供管理页按原 ID 精准重置，其 `variables` 必须与 Builder 白名单 4 字段 `product/domain/access_name/error_code` 一致，普通卸载保留模板、完全清除由 `PluginSeeder::clear()` 删除。**业务终态失败也发通知**（`missing_private_key`/`unsupported_algorithm`(SM2)/`DeployBusinessException`，经私有 `notifyBusinessFailure` context 白名单 4 字段，**不放 deployer message 原文**防 AK 外溢）；**`missing_chain` 除外**（pending 态等 `CloudChainBackfillJob` 补链，非终态）。**节奏 = 每次终态失败发一次**：确定性不可修复者（如自带 CSR 无私钥）经 sweep-B 每 7 天复扫重推 → 再失败再发一次（**有界**，与 `failed()` 重试耗尽同 cadence，非「仅一次」永久去重——测试断「复扫再失败再发」，勿误改成永久去重）。`poll_pending` 耗尽发通知时 error_code 用 `poll_pending`（区别 `retries_exhausted`，文案表明「任务已提交云端待确认」降误报感）。**依赖 queue worker 常驻 + schedule:run cron**。
- **对账 sweep（`cloud-deploy:reconcile`，每日 04:30，self-register via `callAfterResolving(Schedule)`）**：补三类——A 漏推（`last_cert_id != latest` 或 NULL，每天）OR B 失败节流（`last_status=failed` 且 `last_deployed_at` 超 7 天）OR **C 续费链 fallback**（`CloudDeployTriggerJob` 迁移单点失败时 target 仍锚旧订单，旧 cert 已 `renewed` 非 active，A/B 被 join `certs.status=active` 挡死 → 永久盲区）。C 从 `cloud_deploy_targets` **小表驱动**回溯续费链（`target → o_old → c_old(renewed) → c_new(active,action=renew,last_cert_id=c_old.id) → o_new`，全走索引），越权守卫 `o_new.user_id = target.user_id`，按 `c_new` 去重派 `CloudDeployTriggerJob`（错峰 `($i%60)*30`，$i 与 A/B 共享递增）。**所有失败路径**（前置拒绝缺私钥/SM2/缺链 + 业务错误 + 瞬态 `catch(Throwable)` + `poll_pending` + 重试耗尽 `failed()`）都更新 `last_cert_id=当前 cert` **且写 `last_deployed_at`**（含 `failed()`——G4：`failed()` 仅末次 attempt 兑现，不补写则新 target 的 NULL 落 A/B 双盲区），使"已针对当前证书处理过"的 target 走 B（7 天节流）、真漏推走 A 每天。`poll_pending` 的数据库 `pending_job` **在 `failed()` 不清**（留给 sweep-B `resumePoll` 续查同一 jobId 收敛）。`CloudDeployJob` 加 `WithoutOverlapping(targetId)->dontRelease()` 防 sweep×trigger 并发双推。
- **order 锚点 + 三 Job failed() 对称**：target 锚定订单。续费/重签经 `CloudDeployTriggerJob` 自动迁移 target 到新订单（迁移带**跨用户守卫** `->where('user_id', $newUserId)`，防 `last_cert_id` 链跨用户脏数据把 A 的凭证 target 迁到 B 的订单）；另开新订单换证书需用户重新配置目标。三个 Job 均有 `failed()` 兜底：`CloudDeployJob`→通知 + `Log::error`；编排 `CloudDeployTriggerJob`/`CloudChainBackfillJob`→`Log::error`（`getMessage() ?: $e::class` 兜底空 message；系统性可见依赖 E5 failed_jobs 阈值告警 + sweep 每日收敛，不新增邮件 code）。
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
  - `TargetMutationService` 统一 user/admin create/update：校验 access/order/user 一致，create 必须候选订单，update 改 `order_id` 时重新检查候选；续费迁移也必须经该服务同步纯上传 scoped hash，禁止只批量改 `order_id`。新订单已存在相同纯上传目标时保留新目标、停用旧目标，避免重复推送与 reconcile 重试。
  - target 的唯一推送目标由 `CloudDeployTarget::configHash()` 规范化 `config` 后写入 `config_hash`；资源部署直接使用 config hash，`UploadOnlyDeployerInterface` 纯上传使用 `config + order_id` 的 scoped hash，并由 DB 唯一索引 `(user_id, access_id, product, config_hash)` 兜底。服务层先给友好错误，唯一键负责并发最终防线；无 config 的新端点必须在 registry 完备性测试中明确归为纯上传或固定资源例外。
  - 只要 `access_id/order_id/product/config` 任一结构字段变化，清空 `pending_job/last_cert_id/last_status/last_error/last_deployed_at`，避免新配置续查旧任务或沿用旧推送状态；只切换 `enabled` 保留 pending。
  - access 的 `user_id/provider` 创建后不可修改；编辑凭证时 `credentials` 留空或 null 表示保留原凭证。
- **admin 三列表搜索（`CloudDeployController` targets/logs/accesses，裸 `$request->input()` 不经 validated）**：
  - **列名差异**：targets 状态列是 `last_status`（特殊值 `unpushed` → `whereNull`）、logs 是 `status`；logs 全为快照列（provider/product/resource_summary/access_name 不 join 主系统）。
  - **provider 经 access join**：targets 表无 provider 列，用 `whereHas('access', fn ($q) => $q->where('provider', $v))`。
  - **域名搜索**：`whereHas('order.latestCert', fn ($q) => $q->where('common_name', 'like', ...))`，依赖 `CloudDeployTarget::order()` 关系；**禁 raw join**（targets/orders 都有 user_id，raw join 报 1052 列名歧义）。logs 域名搜 `resource_summary` 快照（仅 domain 型 deployer 非空）。
  - **username 经 `whereExists` join users**（admin 全量按用户名 LIKE）；user_id 等值带 `cloud_deploy_targets.`/`cloud_deploy_logs.` 表前缀防与子查询歧义。
  - **quickSearch**：`where(fn)` 分组包 orWhere（订单号精确 + 域名 + 凭证名 + 用户名），不破坏外层 AND。
- **config 逐键脱敏（方案①，`redactConfig(Registry, CloudDeployTarget)`）**：admin 跨用户列 target 会序列化任意用户 config（webhook 含 Authorization 等）。**不能 select 排除整个 config 列**（domain 等资源键会一起丢）。逐键：① `hasDeployer(provider, product)` 守卫先于 `resolveDeployer`（未注册组合 resolveDeployer 抛 `InvalidArgumentException` 会 500 整个列表）；② 命中→按 `configSchema()` 把 `secret=true` 键打 `******`、保留非 secret 键；③ 未命中（陈旧/改名/脏数据）→ fail-safe 打码除 `domain` 外全部键、不抛。`provider` 用 `$target->getAttribute('access')` + `instanceof CloudDeployAccess` 取（绕 larastan「belongsTo 必非 null」乐观推断，保留 access 悬空 → fail-safe 健壮性）。admin 编辑 target 时走 `GET target/{id}` 取完整 config；不要用列表行的 `******` 回写。
- **`attachUsernames(Collection $items)`**：targets/logs/accesses 表只存 user_id、模型无 user() 关系，单次 `whereIn` 查 username 拍平为顶层 `username` 字段供前端「用户」列；`User::withoutGlobalScopes()`（admin 跨用户）。`targets()` 因脱敏走内联分页（不经通用 `respondPaginated`），须显式调一次 + 顶层 provider 拍平（`with('access:id,provider')` eager load）。docblock 用 `@template TModel of Model` 避免 `Collection<int,Model>` invariant TValue 协变报错。
- **前端**：系统设置 tab 顺序固定为「部署目标 / 云凭证 / 部署历史」。user/admin 订单详情和系统设置都支持部署目标新增、编辑、删除、启停、推送、记录；订单详情表单隐藏订单字段并隐式提交当前订单，admin 订单详情使用订单 `user_id` 限定凭证并对齐 user 订单详情的表格样式/记录弹窗；该表单的云凭证选择可清空，右侧直接显示状态化内联按钮：未选凭证显示「新增」，已选凭证显示「编辑」，保存后刷新列表，新建凭证自动回填。系统设置表单用 `ReRemoteSelect` 远程搜索订单。admin 系统设置表单先远程选择用户，再按该用户限定凭证和订单；凭证选项显示 `凭证名(云平台) · 用户名`。admin 部署目标列表只展示组合搜索（订单号/域名/用户名/凭证名）以及状态/启用筛选，结构化单项筛选可以保留在接口契约中供外部调用。部署目标表单核心实现统一放在 `plugins/cloud-deploy/frontend/shared/TargetForm.vue`，user/admin 端 `src/components/TargetForm.vue` 只做 API 适配和模式透传，禁止再复制一份表单状态/校验/提交逻辑。schema 字段标签统一使用共享 `SchemaFieldLabel.vue`：只有文本宽度实际溢出时才启用完整标签 tooltip，字段帮助继续由右侧说明图标独立显示。当前 `plugin.json` 未声明双端 CSS，关键布局样式必须保留内联；新增 `<style>` 时须同步声明 `user_css/admin_css` 并实测加载，不能只以 Vite 生成 CSS 文件作为通过。推送记录弹窗 `is_final` 默认传 `1`——非 JS `true`，qs 序列化 `true`→`"true"` 不被 Laravel `boolean` 规则接受会 422。插件前端无 prettier/lint，靠 `build.sh {user,admin}` vite 编译为门。
