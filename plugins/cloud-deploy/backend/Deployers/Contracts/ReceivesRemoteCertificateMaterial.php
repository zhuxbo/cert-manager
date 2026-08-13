<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 可选能力：证书服务型 deployer 的 bind 需要当前证书材料做资源匹配。
 *
 * 实现此 marker 后，CloudDeployJob 向 bind 传入：
 * `{remote_cert_id:string,cert:string,chain:string}`。其中 cert 仅为当前 leaf PEM（SAN
 * 应从这里解析），chain 为独立的中间证书链 PEM；私钥不会进入该上下文。
 * 未实现的证书服务型 deployer 仍只收到 remote_cert_id 字符串，保持向后兼容。
 */
interface ReceivesRemoteCertificateMaterial {}
