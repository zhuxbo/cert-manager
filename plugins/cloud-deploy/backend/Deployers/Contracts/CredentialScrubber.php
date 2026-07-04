<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 凭证子串兜底扫描（纵深防御，Phase 3 审核建议）。
 *
 * 两个 Sanitizer（Aliyun/Tencent）已按各自威胁模型精确脱敏（只放行响应体的错误码 + 描述，
 * 其余仅暴露类名），理论上输出不含凭证。本类是**最后一道兜底**：对脱敏后的最终输出再扫一遍
 * AK/SK/签名/私钥常见 pattern，命中即替换为 `[redacted]`。这样即使未来某条异常 message 意外把凭证
 * 带进了「放行分支」（威胁模型边界被打破，如阿里 SDK 改版把请求 URI 拼进了响应体 Message），
 * 也能拦下，绝不让凭证经异常 message → trace → cloud_deploy_logs / target.last_error 外泄。
 *
 * 命中 pattern（覆盖阿里 AK/签名查询串、腾讯 SecretId/SecretKey、PEM 私钥头）：
 *   - AKIA + 16 位大写字母数字（阿里/AWS 风格 AccessKeyId 字面量）
 *   - LTAI + 后续字母数字（阿里云 AccessKeyId 实际前缀）
 *   - AccessKeyId= / Signature= / OSSAccessKeyId= 形式的查询串键值（连同其值一并 redact）
 *   - secret_id / secret_key / SecretId / SecretKey 紧跟分隔符与值
 *   - PEM 头 `-----BEGIN ... -----`（私钥/证书材料绝不该出现在错误文案里）
 */
class CredentialScrubber
{
    /**
     * 每条 pattern 都用一个安全占位整体替换命中段（含「键=值」一并抹掉，避免只删值留键仍暴露语义）。
     *
     * @var list<string>
     */
    private const PATTERNS = [
        // 阿里/AWS 风格 AccessKeyId 字面量（AKIA + 16 位）
        '/AKIA[0-9A-Z]{16}/',
        // 阿里云真实 AccessKeyId 前缀 LTAI（长度不定，取到非字母数字为止；至少 6 位防误伤普通词）
        '/LTAI[0-9A-Za-z]{6,}/',
        // 查询串/键值对里的凭证键（连同其值 redact）：AccessKeyId / OSSAccessKeyId / AccessKeySecret / Signature
        '/(?:OSS)?AccessKey(?:Id|Secret)\s*[=:]\s*[^\s&"\']+/i',
        '/Signature\s*[=:]\s*[^\s&"\']+/i',
        // 腾讯 SecretId / SecretKey（下划线或驼峰写法）紧跟分隔符与值
        '/secret_?(?:id|key)\s*[=:]\s*[^\s&"\']+/i',
        // PEM 材料头（私钥/证书绝不该进错误文案）
        '/-----BEGIN[^-]*-----/',
    ];

    /** 兜底扫描：命中任一凭证 pattern 即替换为占位。无命中时原样返回。 */
    public static function scrub(string $message): string
    {
        return (string) preg_replace(self::PATTERNS, '[redacted]', $message);
    }
}
