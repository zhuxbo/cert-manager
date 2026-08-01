<?php

declare(strict_types=1);

namespace App\Services\Order\Utils;

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Traits\ApiResponseStatic;
use Illuminate\Support\Facades\File;

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

        if ($encryption['alg'] == 'sm2') {
            return self::generateSM2($info);
        }

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
     * 生成 SM2 国密 CSR + 私钥。
     *
     * PHP openssl 扩展不支持 SM2，走 BinaryLocator::gmOpenssl()（系统 OpenSSL 3，default provider 原生支持 SM2）命令行生成。
     * 临时文件 + finally 强制清理：私钥含敏感数据，绝不留盘（参考实现漏清致私钥明文堆积）。
     * gmOpenssl 不可用时 fail-closed 报错，绝不静默回落普通 openssl 签出非 SM2 证书。
     */
    protected static function generateSM2(array $info): array
    {
        try {
            $openssl = app(BinaryLocator::class)->gmOpenssl();
        } catch (BinaryNotFoundException $e) {
            self::error('国密 openssl 不可用，无法生成 SM2 证书（需 openssl 能签 id-ecPublicKey 标准编码，OpenSSL ≥3.0.13 实测可用；3.0.0~3.0.12 等早期版本编码非标准会被 CA 拒）：'.$e->getMessage());
        }

        empty($info['commonName']) && self::error('SM2 CSR 缺少 Common Name');
        $subject = self::buildSm2Subject($info);

        $tempDir = storage_path('app/sm2/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($tempDir, 0700);
        $keyFile = $tempDir.'/sm2.key';
        $csrFile = $tempDir.'/sm2.csr';

        try {
            // 1. 生成 SM2 私钥
            $genCmd = escapeshellarg($openssl).' ecparam -genkey -name SM2 -out '.escapeshellarg($keyFile);
            @exec($genCmd.' 2>&1', $genOut, $genCode);
            ($genCode !== 0 || ! is_file($keyFile)) && self::error('SM2 私钥生成失败');

            // 2. 生成 CSR：-sm3 摘要 + distid sigopt（GM/T 国密标准用户标识）
            $csrCmd = escapeshellarg($openssl).' req -new'
                .' -key '.escapeshellarg($keyFile)
                .' -out '.escapeshellarg($csrFile)
                .' -sm3 -sigopt distid:1234567812345678'
                .' -utf8'
                .' -subj '.escapeshellarg($subject);
            @exec($csrCmd.' 2>&1', $csrOut, $csrCode);
            ($csrCode !== 0 || ! is_file($csrFile)) && self::error('SM2 CSR 生成失败');

            $csr = file_get_contents($csrFile);
            $key = file_get_contents($keyFile);
            (! $csr || ! $key) && self::error('SM2 CSR/私钥读取失败');

            // 剥离 ecparam -genkey 附带的 EC/SM2 PARAMETERS 块，只留纯私钥块（自含曲线 OID）；
            // 部分国密 nginx 只认纯私钥块。CSR 已用含 params 的 key 生成，剥离不影响。
            $key = preg_replace('/-----BEGIN (?:EC|SM2) PARAMETERS-----.*?-----END (?:EC|SM2) PARAMETERS-----\s*/s', '', $key) ?? $key;

            return [
                'csr' => str_replace("\r\n", "\n", trim($csr)),
                'private_key' => str_replace("\r\n", "\n", trim($key)),
            ];
        } finally {
            // 私钥敏感，无论成功/失败都清理临时目录
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * 构建 openssl -subj 主题串，转义 / 与 \ 分隔符。
     */
    protected static function buildSm2Subject(array $info): string
    {
        $fields = [
            'CN' => $info['commonName'] ?? '',
            'C' => $info['countryName'] ?? 'CN',
            'ST' => $info['stateOrProvinceName'] ?? '',
            'L' => $info['localityName'] ?? '',
            'O' => $info['organizationName'] ?? '',
        ];

        $subject = '';
        foreach ($fields as $key => $value) {
            if ($value !== '') {
                $value = str_replace(['\\', '/'], ['\\\\', '\\/'], $value);
                $subject .= "/$key=$value";
            }
        }

        return $subject !== '' ? $subject : '/CN=';
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

        $encryption['alg'] = in_array($alg, ['rsa', 'ecdsa', 'sm2'])
            ? $alg
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

        // SM2 固定使用 SM2 曲线（国密标准）
        if ($encryption['alg'] == 'sm2') {
            $encryption['curve'] = 'SM2';
        }

        $encryption['digest_alg'] = in_array($digestAlg, ['sha256', 'sha384', 'sha512', 'sm3'])
            ? $digestAlg
            : self::DEFAULT_DIGEST_ALGORITHM;

        // SM2 必须配 SM3 摘要
        if ($encryption['alg'] == 'sm2') {
            $encryption['digest_alg'] = 'sm3';
        }

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
