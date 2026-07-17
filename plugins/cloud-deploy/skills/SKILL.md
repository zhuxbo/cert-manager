# cloud-deploy 插件开发规范

> 证书签发/续期后自动推送到各大云平台资源（CDN/负载均衡/WAF/对象存储/函数计算等）。独立 PHP 插件，命名空间 `Plugins\CloudDeploy`，不污染主系统、不 scoping。

详细规范见 `development.md`：

| 章节                  | 内容                                                                         |
| --------------------- | ---------------------------------------------------------------------------- |
| 核心架构速览          | certimate 式封装（Deployer/Provider/Uploader/Registry）、注入缝、脱敏、独立 vendor |
| 「新增一个部署端点」  | 选模板 deployer 照抄 → 加 SDK → 写 deployer → 注册 → mock 测试 → 验证          |
| 其余 provider 任务目录 | AWS / 华为 / Cloudflare / 七牛 / 百度 / 火山 / Azure / GCP / 又拍 / 本地·SSH·K8s |
| 阿里腾讯长尾端点      | 阿里 cas-deploy·esa-saas、腾讯 ssl·ssl-update（provider 已就绪，按需补端点）   |
| 已知陷阱清单          | catch(Throwable)、字段大小写 deserialize 静默丢、测试约定、子包版本对齐等      |
