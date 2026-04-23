<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseRequest;

abstract class BaseProductRequest extends BaseRequest
{
    /**
     * 判断是否为 SSL 产品
     */
    protected function isSSL(): bool
    {
        $productType = $this->input('product_type', 'ssl');

        return $productType === 'ssl' || empty($productType);
    }

    /**
     * 判断是否为代码签名产品
     */
    protected function isCodeSign(): bool
    {
        return $this->input('product_type') === 'codesign';
    }

    /**
     * 判断是否为 S/MIME 产品
     */
    protected function isSMIME(): bool
    {
        return $this->input('product_type') === 'smime';
    }

    /**
     * 判断是否为文档签名产品
     */
    protected function isDocSign(): bool
    {
        return $this->input('product_type') === 'docsign';
    }

    /**
     * 判断是否为 ACME 产品
     */
    protected function isAcme(): bool
    {
        return $this->input('product_type') === 'acme';
    }

    /**
     * 判断是否需要域名数量配置（SSL 和 ACME 产品）
     */
    protected function needsDomainConfig(): bool
    {
        return $this->isSSL() || $this->isAcme();
    }

    /**
     * 为非 SSL 产品设置默认值并清除不适用字段
     * S/MIME 和 Code Signing 产品不需要域名相关字段
     */
    protected function setNonSSLDefaults(array &$data): void
    {
        $data['warranty_currency'] = '$';
        $data['warranty'] = 0;
        $data['common_name_types'] = [];       // 非 SSL 产品不需要，CommonName 由系统自动生成
        $data['alternative_name_types'] = [];
        $data['validation_methods'] = [];
        $data['standard_min'] = 0;
        $data['standard_max'] = 0;
        $data['wildcard_min'] = 0;
        $data['wildcard_max'] = 0;
        $data['total_min'] = 0;
        $data['total_max'] = 0;
        $data['add_san'] = 0;
        $data['replace_san'] = 0;
        $data['gift_root_domain'] = 0;
        $data['server'] = 0;
    }

    /**
     * 为 ACME 产品设置默认值并清除不适用字段
     * ACME 以 EAB 为交付产物，加密算法/签名摘要/续期/重用CSR 均由 ACME 客户端自行决定
     * common_name_types / alternative_name_types 保留，用于成本页驱动附加域名价格列
     */
    protected function setAcmeDefaults(array &$data): void
    {
        $data['warranty_currency'] = '$';
        $data['warranty'] = 0;
        $data['validation_methods'] = [];
        $data['add_san'] = 0;
        $data['replace_san'] = 0;
        $data['reissue'] = 0;
        $data['gift_root_domain'] = 0;
        $data['server'] = 0;
        $data['encryption_alg'] = [];
        $data['signature_digest_alg'] = [];
        $data['renew'] = 0;
        $data['reuse_csr'] = 0;
    }

    /**
     * 为代码签名产品设置默认值
     * 注意：CodeSign 支持续期和重用CSR，只有重签不支持
     */
    protected function setCodeSignDefaults(array &$data): void
    {
        // 只设置 CodeSign 不支持的功能的默认值
        $data['reissue'] = 0;
    }

    /**
     * SSL 产品的域名数量验证逻辑
     */
    protected function validateSSLProductDomains($validator, array $data): void
    {
        // 检查 standard_max 和 wildcard_max 至少有一个大于等于1
        if (($data['standard_max'] ?? 0) < 1 && ($data['wildcard_max'] ?? 0) < 1) {
            $validator->errors()->add('standard_max', 'standard_max 和 wildcard_max 至少有一个必须大于等于1');
            $validator->errors()->add('wildcard_max', 'standard_max 和 wildcard_max 至少有一个必须大于等于1');
        }

        // 确保 standard_max 大于等于 standard_min
        if (isset($data['standard_min']) && isset($data['standard_max']) && $data['standard_max'] < $data['standard_min']) {
            $validator->errors()->add('standard_max', 'standard_max 必须大于等于 standard_min');
        }

        // 确保 wildcard_max 大于等于 wildcard_min
        if (isset($data['wildcard_min']) && isset($data['wildcard_max']) && $data['wildcard_max'] < $data['wildcard_min']) {
            $validator->errors()->add('wildcard_max', 'wildcard_max 必须大于等于 wildcard_min');
        }

        // 确保 total_max 大于等于 total_min
        if (isset($data['total_min']) && isset($data['total_max']) && $data['total_max'] < $data['total_min']) {
            $validator->errors()->add('total_max', 'total_max 必须大于等于 total_min');
        }
    }
}
