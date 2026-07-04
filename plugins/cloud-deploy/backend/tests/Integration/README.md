# cloud-deploy 集成测试（真打云端）

这些测试**真实调用阿里云 / 腾讯云 API** 把证书部署到真实加速域名，属端到端验证（e2e），**默认全部 skip、不进 CI**。
缺任一必需 env 凭证即 `markTestSkipped`，所以在没有凭证的环境（含 CI、本地常规 `php artisan test`）下它们安全跳过、不发外部请求。

这是「除 e2e 外测试」的边界声明：单元/Feature 测试用注入缝 + mock 覆盖逻辑（378 个，进 CI）；
真实云端连通性只能靠这里的真打验证，需手动设 env 触发。

## 为什么单独放 Integration/

`php artisan test ../plugins/cloud-deploy/backend/tests` 会连同 `Integration/` 一起发现，但因默认 skip 不产生外部调用。
CI 片段（`plugins/cloud-deploy/ci-job-snippet.yml`）跑的就是这条命令——Integration 自动 skip，不影响门禁。

## 运行方式

需要：一个真实的加速域名 + 该域名已签发的证书（cert / key / chain，PEM）+ 对应云账号 AK/SK，且域名已在云控制台接入对应产品（CDN）。

### 阿里云 CDN

```bash
export CLOUDDEPLOY_ALIYUN_AK=你的AccessKeyId
export CLOUDDEPLOY_ALIYUN_SK=你的AccessKeySecret
export CLOUDDEPLOY_ALIYUN_CDN_DOMAIN=cdn.example.com           # 已在阿里云 CDN 接入的加速域名
export CLOUDDEPLOY_CERT_PEM=/abs/path/cert.pem                  # 证书（叶子）
export CLOUDDEPLOY_KEY_PEM=/abs/path/key.pem                    # 私钥
export CLOUDDEPLOY_CHAIN_PEM=/abs/path/chain.pem               # 中间证书链（可为空文件）

# 容器内（与 make test 同环境）：
docker compose exec -T app php artisan test ../plugins/cloud-deploy/backend/tests/Integration/AliyunCdnIntegrationTest.php
```

### 腾讯云 CDN

```bash
export CLOUDDEPLOY_TENCENT_SECRET_ID=你的SecretId
export CLOUDDEPLOY_TENCENT_SECRET_KEY=你的SecretKey
export CLOUDDEPLOY_TENCENT_CDN_DOMAIN=cdn.example.com           # 已在腾讯云 CDN 接入的加速域名
export CLOUDDEPLOY_CERT_PEM=/abs/path/cert.pem
export CLOUDDEPLOY_KEY_PEM=/abs/path/key.pem
export CLOUDDEPLOY_CHAIN_PEM=/abs/path/chain.pem

docker compose exec -T app php artisan test ../plugins/cloud-deploy/backend/tests/Integration/TencentCdnIntegrationTest.php
```

> `docker compose exec` 默认不透传 host 环境变量到容器。要么在 `compose.yaml` 的 `app.environment` 临时加这些变量，
> 要么用 `docker compose exec -e CLOUDDEPLOY_ALIYUN_AK=... -e ...` 逐个透传，或在容器内 export 后再跑。

## 验证点

- 阿里云 CDN：`bind()` 调 `SetCdnDomainSSLCertificate`（certType=upload 内联上传），无异常即证书已绑定到域名。
- 腾讯云 CDN：`certUploader()->upload()` 调 SSL `UploadCertificate` 拿 `CertificateId`，再 `bind()` 调 `UpdateDomainConfig`
  设 `Https.CertInfo.CertId` 绑定；返回非空 CertId + bind 无异常即成功。

跑完后请到对应云控制台确认证书已生效（真打会改动线上域名配置）。
