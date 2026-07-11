# 国密 (SM2) 证书

主系统国密证书（签名证书用户密钥对 + 加密证书 CA/KGC 托管下发的双证书体系）的下单、CSR 生成、存储、解析、下载与前后端 gate。

**核心原则**：能否签 SM2 由本机 openssl 能力**探测**决定（非人工开关）；不达标一律 **fail-closed 拒下单，绝不静默降级 RSA**。CA 对接在 gateway（不在主系统），manager 走 `default` source 透传。

## 能力 gate（探测，非开关）

- 下单 `Order\ActionTrait::initParams` 的 `guardSm2Capable` 对 `alg=sm2` 探测 `BinaryLocator::gmOpenssl()`，不可用即事务前拒绝（后端兜底防绕过、统一拦所有 SM2 含 reuse_csr=1、不留半残环境）。已移除 `site.gmEnabled` 业务开关与 `site.gmOpensslPath` 设置项——能否签 SM2 由本机 openssl 能力决定，不靠人工开关

## 国密 openssl（须签 id-ecPublicKey 标准编码，运维下限 OpenSSL 3.0.13）

- PHP openssl 扩展不支持 SM2，CSR 生成走 `BinaryLocator::gmOpenssl()`。**`probeSm2` 探测不只验「能签 SM2」，还实签一张 CSR 校验 SPKI 是 id-ecPublicKey 标准编码**——OpenSSL 3.0.0~3.0.12 能签 SM2 但把公钥 algorithm 写成 SM2 曲线 OID（dual-sm2），被国密 CA（如 Keeptrust）拒为「csr 解析失败」；官方 **3.0.13 / 3.2.1 起 restore** 回 id-ecPublicKey（commit `f2db052` 在 3.0 引入 dual-sm2）。**功能探测而非版本号比较**——3.1.0~3.2.0 版本号高于 3.0.13 但仍 dual-sm2 且已 EOL，数字比较会误放行；`csrUsesStandardEcPublicKey` 校验 CSR DER 含 id-ecPublicKey OID（`2a8648ce3d0201`）。`gmOpenssl()` 与系统 `openssl()` 同源（共用候选 + shell 兜底），不接独立国密二进制；不达标即 fail-closed 拒下单、绝不静默降级 RSA。RSA/ECDSA 走 PHP openssl 扩展不受影响。运维下限 **OpenSSL ≥3.0.13**（Ubuntu 24.04 自带；22.04 升 3.0.14；或 3.2.1+），**已彻底移除独立国密二进制的硬编码候选与镜像编译**（勿再加回）

## 双证书 + 存储

- 签名证书（用户密钥对，manager 本地 `CsrUtil::generateSM2` 生成 SM2 CSR，临时文件 finally 强清不留盘）+ 加密证书（CA/KGC 托管下发）。`enc_cert`/`enc_key`/`enc_key2` 存 `certs` 表真实列（**每张证书独立**，不入按 issuer 聚合的 `chains` 表，否则同 CA 多证书互相覆盖加密私钥），跟随 `private_key` 暴露策略

## 多级代理透传

- manager 走 `default` source 调上游 `{ca.url}/get`（=对端 V2 get），CA 对接在 gateway（不在主系统）。上游 `get` 响应须带 `enc_cert`/`enc_key`/`enc_key2`（契约，键名=列名），sync 的 `$data=$result['data']` 透传 + `$cert->update($data)` 靠 fillable 自动写入（**sync 并发零改动**）。**manager 作上游时其 `V2 get` 也须透传 enc**（latestCertFields 加 enc + 非空透传/空 unset，同 `private_key` 策略；Deploy get 亦透传），否则多级 manager 链路下游写不进 enc。**sync 终态守卫**：本地终态时连同 status 一并 unset enc，拒上游滞后 enc 回写已终结证书。**中间证书入 chains**：sync 写回前先 `! empty($data['issuer']) && $cert->issuer = $data['issuer']` 落位，确保 `$cert->update($data)` 触发 `setIntermediateCertAttribute` 时 issuer 已就位、非空 `intermediate_cert` 在签发轮即入 chains（Eloquent fill 顺序不保证 issuer 早于 intermediate_cert，与上游 gateway 对称）。`Cert::retrieved` 缺链降级 approving→次轮重 sync 补写**对全算法适用、无 enc_cert 门控**（判定仅 `status==='active' && issuer 非空`；旧「国密因 enc 短路 retrieved」论断陈旧已废弃，该钩子是兜底而非本轮依赖）。**链写入前有 F2-4 签名校验门禁**（`Order\Action::guardIntermediateChain`，sync 锁外 exec、已有该 issuer 链短路跳过稳态零 exec）：三态 'ok' 照常写 / 'bad' unset `intermediate_cert`（落缺链→approving→重 sync 自愈闭环）+ 告警 / 'unverifiable' 放行写链 + 告警；判据**否决优先**（`verification failed`/`error N at M depth` 先于 `': OK'`，openssl 失败回显 subject DN 可含 `": OK"` 会误判）。**SM2 链校验必须带 `-vfyopt distid:1234567812345678`**（与 CSR 签发 `-sigopt` 同源）——不带则有效 SM2 链被 openssl 误判签名失败 → 假 'bad' → fail-closed 卡签发，distid 是 load-bearing 参数

## 证书解析

- `ActionTrait::parseCert` 对 SM2 用 PHP `openssl_x509_parse`（OpenSSL ≥1.1.1 原生识别 `signatureTypeSN=SM2-SM3`、公钥 256 位）+ `isSM2Cert` DER OID 兜底，固定 `encryption_alg=SM2`/`signature_digest_alg=SM3`/`encryption_bits=256`；`isSM2Cert` 对已明确解析出非 SM2 算法（signatureTypeSN 非 UNDEF/空）短路、不对每张 RSA/ECDSA 跑 DER

## 下载

- 国密只出 nginx 双证书包（`_sign.crt`/`_sign.key`/`_enc.crt`/`_enc.key`/`_enc_gmt0009.key`/`_enc_gmt0016.key`/`_sign_ca.crt` + 说明.txt），`ActionFileTrait::addCertToZip` 按 `encryption_alg='sm2'` 走 `addSm2CertToZip`（**与前端 isSM2/Deploy gate 同口径、不按 enc_cert**——enc 空的 SM2 也强制国密包，不掉进普通 PKCS12 逻辑丢签名私钥/iis 报错）；**加密文件需 enc_cert + enc_key 成对才出**（三列独立 nullable、无成对到达约束，缺任一即整组降级仅签名 + 提示，杜绝"有证书无私钥/有私钥无证书"残缺包）

## 前端 gate

- `install.vue` 两端国密只显 Nginx + `enc_cert`/`enc_key` 任一空即置灰（`encMissing` 与后端成对守卫对齐）；`process.vue` 两端隐藏自动部署；算法字典 `dictionary.ts` 已含 sm2/sm3（无需改）；申请表单 `action.vue` 两端选产品自动校正加密选项（**不兼容才切**：当前算法不在产品 `encryption_alg` 菜单内才切到首选并复用 `handleAlgChange` 联动 bits，摘要同理校正到 `signature_digest_alg` 菜单内，SM2 强制 256+SM3、`keyBitsOptions` 增 SM2 分支只显 256；**仅 apply/batchApply**，续费/重签由 `loadOrderInfo` 回填原算法、不在此覆盖以防静默降级）

## Deploy API gate

- `query` 的 `field=certificate|private_key` 拉取拒绝国密（防 certimate 单证书残缺自动部署）；`getOrderData` 国密 active 附 `enc_certificate`/`enc_private_key`/`enc_private_key_gmt0009` + `encryption_alg=sm2` 标记
