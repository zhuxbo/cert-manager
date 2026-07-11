<?php

declare(strict_types=1);

namespace App\Services\Order\Utils;

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Illuminate\Support\Facades\File;

/**
 * 证书链签名校验（F2-4 P1-8，服务端侧）：校验 intermediate 确实签发了 leaf，
 * 防上游偶发坏链/缺链静默入 chains 表致严格客户端 TLS 握手失败。
 *
 * 三态门禁（fail-open）：
 *  - 'ok'         ：openssl 可用且签名校验通过 → 照常写链。
 *  - 'bad'        ：openssl 可用且明确验签否决 → 拒写坏链（落既有缺链→approving→重 sync 自愈闭环）+ 告警。
 *  - 'unverifiable'：openssl 不可用 / 运行性失败 / 输出不可解析 → fail-open 放行写链 + 响亮告警。
 *
 * 命令（BinaryLocator + escapeshellarg，临时文件 0700 + finally 强清，母本 CsrUtil::generateSM2）：
 *   openssl verify -partial_chain -no_check_time [-vfyopt distid:...(仅 SM2)] -CAfile <inter> <leaf>
 *  - -partial_chain：把 intermediate（bundle 全部证书，顺序无关）当信任锚，一步绑定 issuer DN + 签名。
 *  - -no_check_time：只验签发关系、不验有效期（否则 import 历史证书/时钟偏移会 false-bad）。
 *  - SM2 -vfyopt distid:1234567812345678：SM2 签名 ZA 摘要绑定 GM 标准用户标识（与 CsrUtil 生成侧一致）；
 *    实测校准（OpenSSL 3.0.13+）：不带此项有效 SM2 链会假失败 → SM2 fail-CLOSED brick；带此项有效链 OK、
 *    错配链仍正确 bad（distid 不制造假 OK）。
 */
class ChainVerifier
{
    /**
     * GM/T 国密标准用户标识（与 CsrUtil::generateSM2 的 -sigopt distid 生成侧同值）。
     */
    private const SM2_DISTID = '1234567812345678';

    /**
     * 最近一次 openssl 原始输出（供调用方 Log::error 记录精确原因；
     * 携密安全：只进 Log，绝不进 system_alert / notifications.data）。
     */
    private string $lastOutput = '';

    public function __construct(private readonly BinaryLocator $binaryLocator) {}

    /**
     * 校验 intermediate 是否签发了 leaf。
     *
     * @param  string  $leafPem  叶证书 PEM
     * @param  string  $intermediatePem  中间证书 PEM（可为多证书 bundle）
     * @param  string  $alg  签名算法（parseCert 的 encryption_alg；'SM2' 走 gmOpenssl + distid）
     * @return string 'ok' | 'bad' | 'unverifiable'
     */
    public function verifyIssued(string $leafPem, string $intermediatePem, string $alg): string
    {
        $this->lastOutput = '';

        $isSm2 = strtoupper($alg) === 'SM2';

        // 解析二进制：SM2 走 gmOpenssl，其余走系统 openssl；不可用 → unverifiable（fail-open）
        try {
            $openssl = $isSm2 ? $this->binaryLocator->gmOpenssl() : $this->binaryLocator->openssl();
        } catch (BinaryNotFoundException $e) {
            $this->lastOutput = 'openssl 不可用: '.$e->getMessage();

            return 'unverifiable';
        }

        $tempDir = storage_path('app/chain-verify/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($tempDir, 0700);
        $leafFile = $tempDir.'/leaf.pem';
        $interFile = $tempDir.'/inter.pem';

        try {
            if (@file_put_contents($leafFile, $leafPem) === false
                || @file_put_contents($interFile, $intermediatePem) === false) {
                $this->lastOutput = '临时文件写入失败';

                return 'unverifiable';
            }

            $cmd = escapeshellarg($openssl).' verify -partial_chain -no_check_time';
            if ($isSm2) {
                $cmd .= ' -vfyopt distid:'.self::SM2_DISTID;
            }
            $cmd .= ' -CAfile '.escapeshellarg($interFile).' '.escapeshellarg($leafFile);

            // exec 无显式超时（观察项）：openssl verify 是纯本地毫秒级操作（无网络，-no_check_time
            // 亦不拉 CRL/OCSP），挂死概率极低；且门禁在 DB 锁外，TaskJob 路径有 worker --timeout、
            // FPM 路径有 request_terminate_timeout 外层兜底，不会无限持锁/挂死。
            $output = [];
            $code = null;
            @exec($cmd.' 2>&1', $output, $code);
            $text = trim(implode("\n", $output));
            $this->lastOutput = $text;

            // 三态判定（不以 exit code 为准：OpenSSL ≤1.0.x verify 失败仍 exit 0，字符串判据对任何版本都不出假 ok）。
            // 可识别否决必须先于 ok 判据：verify 失败时回显 leaf subject DN，若 subject 恰含 ": OK" 字样
            // （如 O=Acme: OK Ltd），ok 判据先行会被 subject 回显抢先、坏链误判通过；
            // 而成功输出仅一行 "<file>: OK"、不回显 subject，否决判据先行不会误伤正链。
            if (str_contains($text, 'verification failed') || preg_match('/error \d+ at \d+ depth/', $text) === 1) {
                return 'bad';
            }

            if (str_contains($text, ': OK')) {
                return 'ok';
            }

            // 未知输出（空 / 加载失败 / exec 未跑起来）一律 unverifiable → fail-open
            return 'unverifiable';
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * 最近一次 openssl 原始输出（调用方 Log::error 用；不得进 system_alert）。
     */
    public function lastOutput(): string
    {
        return $this->lastOutput;
    }
}
