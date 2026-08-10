<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Validation\Validator;

class UpdateRequest extends BaseProductRequest
{
    public function rules(): array
    {
        $productId = $this->route('id', 0);

        return [
            'code' => 'nullable|string|max:100|unique:products,code,'.$productId,
            'name' => 'string|max:100',
            'api_id' => 'string|max:128',
            'source' => 'string|max:20',
            'brand' => 'string|max:50',
            'ca' => 'string|max:200',
            'product_type' => 'string|in:ssl,codesign,smime,docsign,acme',
            'warranty_currency' => 'string|max:10',
            'warranty' => 'numeric|min:0',
            'server' => 'integer|min:0',
            'encryption_standard' => 'string|max:50',
            'encryption_alg' => 'array',
            'encryption_alg.*' => 'in:rsa,ecdsa,sm2',
            'signature_digest_alg' => 'array',
            'signature_digest_alg.*' => 'in:sha256,sha384,sha512,sm3',
            'validation_type' => 'string|max:50',
            'common_name_types' => 'array',
            'common_name_types.*' => 'in:standard,wildcard,ipv4,ipv6,email,organization',
            'alternative_name_types' => 'array',
            'alternative_name_types.*' => 'in:standard,wildcard,ipv4,ipv6',
            'validation_methods' => 'nullable|array',
            'periods' => 'array',
            'periods.*' => 'integer|in:1,3,6,12,24,36,48,60,72,84,96,108,120',
            'standard_min' => 'integer|min:0',
            'standard_max' => 'integer|min:0',
            'wildcard_min' => 'integer|min:0',
            'wildcard_max' => 'integer|min:0',
            'total_min' => 'integer|min:0',
            'total_max' => 'integer|min:0',
            'add_san' => 'boolean',
            'replace_san' => 'boolean',
            'reissue' => 'boolean',
            'renew' => 'boolean',
            'reuse_csr' => 'boolean',
            'gift_root_domain' => 'boolean',
            'refund_period' => 'integer|min:0',
            'remark' => 'nullable|string|max:255',
            'weight' => 'integer|min:0',
            'status' => 'integer|in:0,1',
        ];
    }

    /**
     * 获取验证后的数据，自动为非 SSL 产品设置默认值
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null) {
            return $data;
        }

        // ACME 产品：保留域名数量字段，清除 SSL 专用字段
        if ($this->isAcme()) {
            $this->setAcmeDefaults($data);
        } elseif (! $this->isSSL()) {
            // 其他非 SSL 产品，清除所有域名相关字段
            $this->setNonSSLDefaults($data);
        }

        // 代码签名产品，设置加密/签名/重签/CSR相关字段的默认值
        if ($this->isCodeSign()) {
            $this->setCodeSignDefaults($data);
        }

        return $data;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();
            $productId = $this->route('id', 0);

            // 检查 source 和 api_id 组合的唯一性（排除当前记录）
            if (isset($data['source']) && isset($data['api_id'])) {
                $exists = Product::where('source', $data['source'])
                    ->where('api_id', $data['api_id'])
                    ->where('id', '!=', $productId)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('api_id', 'source 和 api_id 的组合必须唯一');
                }
            }

            // 仅在传入域名相关字段时才校验
            $hasDomainFields = isset($data['standard_max']) || isset($data['wildcard_max'])
                || isset($data['standard_min']) || isset($data['wildcard_min'])
                || isset($data['total_min']) || isset($data['total_max']);
            if ($hasDomainFields && $this->needsDomainConfig()) {
                $this->validateSSLProductDomains($validator, $data);
            }
        });
    }
}
