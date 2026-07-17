<?php

namespace Tests\Traits;

use App\Services\Binary\BinaryLocator;
use Illuminate\Support\Facades\File;

/**
 * 证书链测试 fixture 生成器（F2-4 ChainVerifier 硬门用）。
 *
 * RSA/ECDSA 链走 PHP openssl_* 现场生成；SM2 链走 gmOpenssl CLI 现场生成
 * （PHP openssl 扩展不支持 SM2，且 SM2 签名 ZA 摘要绑定 GM 标准用户标识 distid=1234567812345678，
 * CA 自签 / CA 签 leaf / verify 三处 distid 必须一致，否则 fixture 畸形使双向硬门失真）。
 * CI/容器 gmOpenssl 可用已被 BinaryLocatorTest 钉死（反模式 15：不 skip，缺失即 FAIL）。
 */
trait GeneratesCertChains
{
    /**
     * 生成 RSA CA→leaf 真实签发关系链。
     *
     * @return array{ca:string, leaf:string, issuer:string} ca=中间证书 PEM, leaf=叶证书 PEM, issuer=CA 的 CN
     */
    protected function makeRsaChain(string $leafCn = 'leaf.example.com', ?string $caCn = null): array
    {
        $caCn ??= 'Test RSA CA '.bin2hex(random_bytes(4));
        [$caCert, $caKey, $caPem] = $this->makeRsaCa($caCn);

        $leafKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $leafCsr = openssl_csr_new(['commonName' => $leafCn], $leafKey, ['digest_alg' => 'sha256']);
        $leafCert = openssl_csr_sign($leafCsr, $caCert, $caKey, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($leafCert, $leafPem);

        return ['ca' => $caPem, 'leaf' => $leafPem, 'issuer' => $caCn];
    }

    /**
     * 生成一个独立的自签 RSA CA（同 CN 可复用于错配测试：CN 相同、密钥不同 → 签名校验必失败）。
     *
     * @return array{0:\OpenSSLCertificate, 1:\OpenSSLAsymmetricKey, 2:string} [证书对象, 私钥, PEM]
     */
    protected function makeRsaCa(string $caCn): array
    {
        $caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $caCsr = openssl_csr_new(['commonName' => $caCn], $caKey, ['digest_alg' => 'sha256']);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 3650, ['digest_alg' => 'sha256']);
        openssl_x509_export($caCert, $caPem);

        return [$caCert, $caKey, $caPem];
    }

    /**
     * 多级 bundle：把签发 CA 排在 bundle 末尾（前面塞一张无关自签根），
     * 验证 openssl verify -CAfile <bundle> 顺序无关（catch I1：签发 inter 不在首位仍应 ok）。
     *
     * @return array{ca:string, leaf:string, issuer:string} ca=多证书 bundle PEM
     */
    protected function makeRsaChainWithBundle(string $leafCn = 'leaf.example.com'): array
    {
        $chain = $this->makeRsaChain($leafCn);
        [, , $unrelatedRootPem] = $this->makeRsaCa('Unrelated Root '.bin2hex(random_bytes(4)));

        // bundle = [无关根, 签发 CA]，签发 CA 不在首位
        $chain['ca'] = rtrim($unrelatedRootPem)."\n".$chain['ca'];

        return $chain;
    }

    /**
     * gmOpenssl 现场生成 SM2 CA→leaf 真实签发关系链（distid 三处一致）。
     *
     * @return array{ca:string, leaf:string, issuer:string}
     */
    protected function makeSm2Chain(string $leafCn = 'sm2leaf.example.com', ?string $caCn = null): array
    {
        $caCn ??= 'Test SM2 CA '.bin2hex(random_bytes(4));

        return $this->buildSm2Chain($caCn, $leafCn, signWithSeparateCa: false);
    }

    /**
     * SM2 破坏链：leaf 由 CA-A 签发，但返回的 intermediate 是同 CN 的 CA-B（不同密钥）→ 签名必不过。
     * 反向硬门，防 distid 参数使有效/无效两方向任一出假象。
     *
     * @return array{ca:string, leaf:string, issuer:string} ca=错配 CA-B 的 PEM
     */
    protected function makeSm2MismatchedChain(string $leafCn = 'sm2leaf.example.com'): array
    {
        $caCn = 'Test SM2 CA '.bin2hex(random_bytes(4));

        return $this->buildSm2Chain($caCn, $leafCn, signWithSeparateCa: true);
    }

    /**
     * SM2 链生成内核。signWithSeparateCa=true 时另造一张同 CN 的 CA-B 作为返回的 intermediate（错配）。
     *
     * @return array{ca:string, leaf:string, issuer:string}
     */
    private function buildSm2Chain(string $caCn, string $leafCn, bool $signWithSeparateCa): array
    {
        $openssl = app(BinaryLocator::class)->gmOpenssl();
        $distid = '1234567812345678';
        $dir = storage_path('app/test-sm2/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dir, 0700);

        $q = fn (string $s): string => escapeshellarg($s);
        $run = function (string $args) use ($openssl, $q): void {
            exec($q($openssl).' '.$args.' 2>&1', $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException("SM2 fixture 生成失败(rc=$code): openssl $args\n".implode("\n", $out));
            }
        };

        try {
            // 签发 CA（真正签 leaf 的那把）
            $run('ecparam -genkey -name SM2 -out '.$q("$dir/ca.key"));
            $run('req -new -x509 -key '.$q("$dir/ca.key").' -sm3 -sigopt distid:'.$distid
                .' -subj '.$q("/CN=$caCn").' -days 3650 -addext '.$q('basicConstraints=critical,CA:TRUE')
                .' -out '.$q("$dir/ca.crt"));

            // leaf key + CSR
            $run('ecparam -genkey -name SM2 -out '.$q("$dir/leaf.key"));
            $run('req -new -key '.$q("$dir/leaf.key").' -sm3 -sigopt distid:'.$distid
                .' -subj '.$q("/CN=$leafCn").' -out '.$q("$dir/leaf.csr"));

            // CA 签 leaf（sigopt+vfyopt 双 distid 一致）
            $run('x509 -req -in '.$q("$dir/leaf.csr").' -CA '.$q("$dir/ca.crt").' -CAkey '.$q("$dir/ca.key")
                .' -CAcreateserial -sm3 -sigopt distid:'.$distid.' -vfyopt distid:'.$distid
                .' -days 365 -out '.$q("$dir/leaf.crt"));

            $leafPem = (string) file_get_contents("$dir/leaf.crt");

            if ($signWithSeparateCa) {
                // 另造同 CN 不同密钥的 CA-B 作错配 intermediate
                $run('ecparam -genkey -name SM2 -out '.$q("$dir/cb.key"));
                $run('req -new -x509 -key '.$q("$dir/cb.key").' -sm3 -sigopt distid:'.$distid
                    .' -subj '.$q("/CN=$caCn").' -days 3650 -addext '.$q('basicConstraints=critical,CA:TRUE')
                    .' -out '.$q("$dir/cb.crt"));
                $caPem = (string) file_get_contents("$dir/cb.crt");
            } else {
                $caPem = (string) file_get_contents("$dir/ca.crt");
            }

            return ['ca' => $caPem, 'leaf' => $leafPem, 'issuer' => $caCn];
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
