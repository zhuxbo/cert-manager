<?php

declare(strict_types=1);

namespace App\Services\Order\Utils;

use App\Traits\ApiResponseStatic;

class CsrUtil
{
    use ApiResponseStatic;

    const string DEFAULT_ENCRYPTION_ALGORITHM = 'rsa';

    const int DEFAULT_BITS = 2048;

    const string DEFAULT_CURVE = 'prime256v1';

    const string DEFAULT_DIGEST_ALGORITHM = 'sha256';

    /**
     * 自动生成CSR
     */
    public static function auto($params): array
    {
        // 判断是否为 S/MIME、Code Signing 或 Document Signing 产品（不需要域名检查）
        $productType = $params['product']['product_type'] ?? '';
        $isNonSslProduct = in_array($productType, ['smime', 'codesign', 'docsign']);

        if ($params['csr_generate'] ?? 0) {
            $result = self::generate($params);
            $params['csr'] = $result['csr'];
            $params['private_key'] = $result['private_key'];
        } else {
            // 用户提交 CSR 时，CSR 不能为空
            empty($params['csr']) && self::error('CSR不能为空');

            // S/MIME 和 Code Signing 不需要检查域名匹配
            if (! $isNonSslProduct) {
                self::checkDomain($params['csr'], explode(',', $params['domains'])[0]);
            }

            if (isset($params['private_key'])) {
                self::matchKey($params['csr'], $params['private_key']) || self::error('CSR and private key do not match');
            }

            if (isset($params['organization']['organization'])) {
                self::checkOrganization($params['csr'], $params['organization']['organization']);
            }
        }

        return $params;
    }

    /**
     * 生成CSR
     */
    public static function generate(array $params): array
    {
        $encryption = self::getEncryptionParams($params);
        $info = self::getInfoParams($params);

        if ($encryption['alg'] == 'rsa') {
            $pkeyEncryption = [
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => $encryption['bits'],
            ];
        } elseif ($encryption['alg'] == 'ecdsa') {
            $pkeyEncryption = [
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => $encryption['curve'],
            ];
        }

        $pkey = openssl_pkey_new($pkeyEncryption ?? []);
        ($pkey === false) && self::error('Failed to generate private key');

        $csr = openssl_csr_new($info, $pkey, ['digest_alg' => $encryption['digest_alg']]);
        ($csr === false) && self::error('Failed to generate CSR');

        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($pkey, $keyOut);
        (! $csrOut || ! $keyOut) && self::error('Failed to export CSR or private key');

        $data['csr'] = str_replace("\r\n", "\n", trim($csrOut));
        $data['private_key'] = str_replace("\r\n", "\n", trim($keyOut));

        return $data;
    }

    /**
     * 获取 SMIME 产品类型标记
     * 从产品 code 中提取类型标记（优先使用 code，api_id 作为后备）
     *
     * @return string mailbox|individual|sponsor|organization|unknown
     */
    public static function getSMIMEType(array $product): string
    {
        // 优先使用 code，api_id 作为后备
        $code = strtolower($product['code'] ?? $product['api_id'] ?? '');

        if (str_contains($code, 'mailbox')) {
            return 'mailbox';
        }
        if (str_contains($code, 'individual')) {
            return 'individual';
        }
        if (str_contains($code, 'sponsor')) {
            return 'sponsor';
        }
        if (str_contains($code, 'organization')) {
            return 'organization';
        }

        return 'unknown';
    }

    /**
     * 获取加密参数
     */
    public static function getEncryptionParams(array $params = []): array
    {
        $alg = strtolower($params['encryption']['alg'] ?? '');
        $bits = intval($params['encryption']['bits'] ?? 0);
        $digestAlg = strtolower($params['encryption']['digest_alg'] ?? '');
        $productType = $params['product']['product_type'] ?? 'ssl';

        $encryption['alg'] = in_array($alg, ['rsa', 'ecdsa'])
            ? $params['encryption']['alg']
            : self::DEFAULT_ENCRYPTION_ALGORITHM;

        if ($encryption['alg'] == 'rsa') {
            // CodeSign/DocSign 产品强制使用 4096 位密钥
            if (in_array($productType, ['codesign', 'docsign'])) {
                $encryption['bits'] = 4096;
            } else {
                $encryption['bits'] = in_array($bits, [2048, 4096]) ? $bits : self::DEFAULT_BITS;
            }
        }

        if ($encryption['alg'] == 'ecdsa') {
            $allowedCurves = [256 => 'prime256v1', 384 => 'secp384r1', 521 => 'secp521r1'];
            $encryption['curve'] = $allowedCurves[$bits] ?? self::DEFAULT_CURVE;
        }

        $encryption['digest_alg'] = in_array($digestAlg, ['sha256', 'sha384', 'sha512'])
            ? $digestAlg
            : self::DEFAULT_DIGEST_ALGORITHM;

        return $encryption;
    }

    /**
     * 获取信息参数
     */
    public static function getInfoParams(array $params = []): array
    {
        $organization = $params['organization'] ?? [];
        $contact = $params['contact'] ?? [];
        $email = $params['email'] ?? '';  // SMIME 邮箱地址
        $productType = $params['product']['product_type'] ?? 'ssl';

        $info['organizationName'] = $organization['name'] ?? '';

        // 根据产品类型获取 commonName
        if ($productType === 'smime') {
            // SMIME: 根据产品 code 中的标记确定 commonName
            $smimeType = self::getSMIMEType($params['product'] ?? []);
            $info['commonName'] = match ($smimeType) {
                'mailbox' => $email,  // mailbox 使用邮箱地址
                'individual', 'sponsor' => trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? '')),
                'organization' => $organization['name'] ?? '',
                default => $email,  // 默认使用邮箱地址
            };
        } elseif ($productType === 'codesign') {
            // CodeSign: 使用组织名称作为 commonName
            $info['commonName'] = $organization['name'] ?? '';
        } elseif ($productType === 'docsign') {
            // DocSign: 使用组织名称作为 commonName
            $info['commonName'] = $organization['name'] ?? '';
        } else {
            // SSL: 使用域名作为 commonName
            $info['commonName'] = explode(',', $params['domains'] ?? '')[0];
        }

        // commonName 不能超过 64个字符
        strlen($info['commonName']) > 64 && self::error('The Common Name (CN) for the certificate CSR cannot exceed 64 characters');

        $info['countryName'] = $organization['country'] ?? 'CN';
        $info['stateOrProvinceName'] = $organization['state'] ?? 'Shanghai';
        $info['localityName'] = $organization['city'] ?? 'Shanghai';

        // 仅 Certum 品牌 EV 证书
        if (
            ! empty($organization)
            && strtolower($params['product']['brand'] ?? '') == 'certum'
            && strtolower($params['product']['validation_type'] ?? '') == 'ev'
        ) {
            $info['jurisdictionCountryName'] = $organization['country'] ?? 'CN';  // 可选，注册地所在国家，适用于EV证书
            $info['jurisdictionStateOrProvinceName'] = $organization['state'] ?? 'Shanghai';  // 可选，注册地所在州，适用于EV证书
            $info['jurisdictionLocalityName'] = $organization['city'] ?? 'Shanghai';  // 可选，注册地所在城市，适用于EV证书
            $info['businessCategory'] = $organization['category'] ?? 'Private Organization';  // 可选，业务类别
            $info['serialNumber'] = $organization['registration_number'] ?? '';  // 可选，组织机构代码或工商注册号
        }

        return array_filter($info);
    }

    /**
     * 检查域名
     */
    public static function checkDomain(string $csr, string $domain): void
    {
        $info = self::parseCsr($csr);

        ! isset($info['commonName']) && self::error('CSR does not contain a Common Name (CN)');
        ($info['commonName'] != $domain) && self::error('CSR Common Name does not match the Cert Common Name');
    }

    /**
     * 检查组织
     */
    public static function checkOrganization(string $csr, array $organizationName): void
    {
        $info = self::parseCsr($csr);

        (($info['organizationName'] ?? '') != $organizationName) && self::error('CSR organization name does not match the params organization name');
    }

    /**
     * 匹配私钥
     */
    public static function matchKey(string $csr, string $key): bool
    {
        $privateKey = openssl_pkey_get_private($key);
        $publicKey = openssl_csr_get_public_key($csr);

        if ($privateKey === false || $publicKey === false) {
            return false;
        }

        $privateKeyDetails = openssl_pkey_get_details($privateKey);
        $publicKeyDetails = openssl_pkey_get_details($publicKey);

        return $privateKeyDetails['bits'] === $publicKeyDetails['bits']
            && $privateKeyDetails['key'] === $publicKeyDetails['key'];
    }

    /**
     * 解析CSR
     */
    protected static function parseCsr(string $csr): array
    {
        $csr || self::error('CSR is empty');

        $info = openssl_csr_get_subject($csr, false);

        $info || self::error('CSR parse error');

        return $info;
    }
}
