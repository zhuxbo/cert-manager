<?php

declare(strict_types=1);

namespace App\Services\Order\Traits;

use App\Http\Middleware\DynamicCors;
use App\Models\Order;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

trait ActionFileTrait
{
    use ApiResponse;

    /**
     * 批量下载证书
     */
    public function download(int|string|array $orderIds, string $type = 'all'): void
    {
        $type = in_array($type, ['all', 'apache', 'nginx', 'pem', 'iis', 'tomcat', 'txt']) ? $type : 'all';
        $orderIds = is_array($orderIds) ? $orderIds : explode(',', (string) $orderIds);
        $orderIds = array_map('intval', $orderIds);

        $orders = Order::with(['latestCert'])
            ->whereHas('latestCert', fn ($query) => $query->where('status', 'active'))
            ->whereIn('id', $orderIds)
            ->get();

        // 如果中间证书不存在，则过滤掉
        $orders = $orders->filter(function ($order) {
            return ! empty($order->latestCert->intermediate_cert);
        });

        // 如果为空则退出 因为在下载流程中 不能用 error 返回
        if ($orders->isEmpty()) {
            exit;
        }

        $random = sprintf('%04x%04x', mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
        $tempDir = storage_path('temp-certs/'.$random);

        mkdir($tempDir, 0755, true);

        $zip = new ZipArchive;
        $suffix = $type == 'all' ? '' : '_'.$type;
        $filename = count($orders) == 1
            ? str_replace('*', 'STAR', $orders[0]->latestCert->common_name).$suffix.'.zip'
            : 'certs-'.count($orders).'-'.$random.$suffix.'.zip';

        $zip->open($tempDir.'/'.$filename, ZipArchive::CREATE);

        $commonNames = [];
        foreach ($orders as $order) {
            $this->addCertToZip($order, $zip, $tempDir, $commonNames, $type);
            $commonNames[] = $order->latestCert->common_name;
        }

        $zip->close();

        $this->downFlow($tempDir.'/'.$filename, $tempDir);
    }

    /**
     * 下载验证文件
     */
    public function downloadValidateFile(int $orderId): void
    {
        $order = Order::with(['latestCert'])
            ->whereHas('latestCert', fn ($query) => $query->whereIn('status', ['unpaid', 'pending', 'processing']))
            ->where('id', $orderId)
            ->first();
        if (empty($order->latestCert->dcv['file'])) {
            $this->error('没有可以下载的验证文件');
        }

        $file = $order->latestCert->dcv['file'];

        $random = sprintf('%04x%04x', mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
        $tempDir = storage_path('temp-certs/'.$random);
        mkdir($tempDir, 0755, true);

        $zip = new ZipArchive;
        $filename = '订单'.$orderId.'-请放到网站根目录解压.zip';
        $zip->open($tempDir.'/'.$filename, ZipArchive::CREATE);
        $zip->addFromString('.well-known/pki-validation/'.($file['name'] ?? 'error.txt'), $file['content'] ?? '');
        $zip->close();

        $this->downFlow($tempDir.'/'.$filename, $tempDir);
    }

    /**
     * 添加证书文件到zip
     */
    protected function addCertToZip(
        Order $order,
        ZipArchive $zip,
        string $tempDir,
        array $domains = [],
        string $type = 'all'
    ): void {
        $commonName = $order->latestCert->common_name ?? '';
        $cert = $order->latestCert->cert ?? '';
        $privateKey = $order->latestCert->private_key ?? '';
        $intermediateCert = $order->latestCert->intermediate_cert ?? '';

        $certName = str_replace('*', 'STAR', $commonName);
        $random = sprintf('%04x%04x', mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
        $certPath = in_array($commonName, $domains) ? $certName.'-'.$random.'/' : $certName.'/';

        // 国密双证书：按 encryption_alg 判定（与前端 isSM2 / Deploy gate 同口径），国密证书一律只出
        // nginx 国密包，绝不走下方普通格式分支 —— 普通分支的 openssl_x509_check_private_key / PKCS12 不支持
        // SM2，会丢签名私钥、且 iis/tomcat 还会硬报错。加密证书/私钥由上游 CA/KGC 经 get 透传，为空
        // （gateway 未就绪）时 addSm2CertToZip 内部降级仅出签名部分 + 提示。
        if (strtolower((string) ($order->latestCert->encryption_alg ?? '')) === 'sm2') {
            $this->addSm2CertToZip($zip, $certPath, $certName, $commonName, $cert, $privateKey, $intermediateCert, $order->latestCert->enc_cert ?? '', $order->latestCert->enc_key ?? '', $order->latestCert->enc_key2 ?? '');

            return;
        }

        $password = '123456';
        $keyMatched = $privateKey && openssl_x509_check_private_key($cert, $privateKey);

        if ($type == 'all' || $type == 'apache') {
            $zip->addFromString($certPath.'apache/'.$certName.'.crt', $cert);
            $zip->addFromString($certPath.'apache/'.$certName.'-ca-bundle.crt', $intermediateCert);
            $keyMatched && $zip->addFromString($certPath.'apache/'.$certName.'.key', $privateKey);
        }

        if ($type == 'all' || $type == 'nginx') {
            $zip->addFromString($certPath.'nginx/'.$certName.'.crt', $cert.PHP_EOL.$intermediateCert);
            $keyMatched && $zip->addFromString($certPath.'nginx/'.$certName.'.key', $privateKey);
        }

        if ($type == 'all' || $type == 'pem') {
            $zip->addFromString($certPath.'pem/'.$certName.'.pem', $cert.PHP_EOL.$intermediateCert);
            $keyMatched && $zip->addFromString($certPath.'pem/'.$certName.'.key', $privateKey);
        }

        if ($type == 'iis' || $type == 'tomcat') {
            $privateKey || $this->error('私钥不存在');
            $keyMatched || $this->error('私钥与证书不匹配');
        }

        if (($type == 'all' || $type == 'iis' || $type == 'tomcat') && $privateKey && $keyMatched) {
            // openssl 解析失败：tomcat/iis 显式请求则硬错，all 模式静默跳过 PFX/IIS/JKS 三个分支
            $openssl = null;
            try {
                $openssl = app(BinaryLocator::class)->openssl();
            } catch (BinaryNotFoundException $e) {
                if ($type == 'iis' || $type == 'tomcat') {
                    Log::warning('openssl 不可用，无法生成 PFX/JKS', ['diagnose' => $e->diagnose()]);
                    $this->error('OpenSSL 不可用：'.$e->getMessage());
                } else {
                    Log::info('openssl 不可用，跳过 PFX/IIS/JKS 输出', ['diagnose' => $e->diagnose()]);
                }
            }

            // tomcat 模式预先校验 keytool；all 模式延后到 JKS 子块再判定
            $keytool = null;
            if ($openssl !== null && ($type == 'all' || $type == 'tomcat')) {
                try {
                    $keytool = app(BinaryLocator::class)->keytool();
                } catch (BinaryNotFoundException $e) {
                    if ($type == 'tomcat') {
                        Log::warning('keytool 不可用，无法生成 JKS', ['diagnose' => $e->diagnose()]);
                        $this->error('JDK 未安装：'.$e->getMessage());
                    } else {
                        Log::info('keytool 不可用，跳过 JKS 输出', ['diagnose' => $e->diagnose()]);
                    }
                }
            }

            if ($openssl !== null) {
                $pfx = $tempDir.'/temp.pfx';
                $certFile = $tempDir.'/temp.crt';
                $keyFile = $tempDir.'/temp.key';
                $chainFile = $tempDir.'/temp.chain';
                file_put_contents($certFile, $cert);
                file_put_contents($keyFile, $privateKey);
                file_put_contents($chainFile, $intermediateCert);

                // 显式 PBE-SHA1-3DES + HMAC-SHA1 生成 PFX，兼容 Windows Server 2008+ 全系列。
                // PHP openssl_pkcs12_export 在 OpenSSL 3.x 默认 AES-256/PBKDF2-SHA256，老 Windows 报"密码错误"无法导入。
                // 3DES 在 OpenSSL 3.x default provider / 1.x 原生可用，无需 -legacy：-legacy 是 3.0 新增选项，在
                // 1.x、LibreSSL 上会报 Unrecognized flag 反需回落兜底，且 3.x 上显式指定 3DES 时它是空操作——故去掉，
                // 单条命令全版本一次成功（RC2-40 才需 legacy provider，本系统不用）。
                $cmd = escapeshellarg($openssl).' pkcs12 -export'
                    .' -inkey '.escapeshellarg($keyFile)
                    .' -in '.escapeshellarg($certFile)
                    .' -certfile '.escapeshellarg($chainFile)
                    .' -out '.escapeshellarg($pfx)
                    .' -name '.escapeshellarg($commonName)
                    .' -password '.escapeshellarg("pass:$password")
                    .' -keypbe PBE-SHA1-3DES -certpbe PBE-SHA1-3DES -macalg SHA1';

                // 捕获 stderr（不再 > /dev/null 丢弃）：FIPS / no-des 环境下 PBE-SHA1-3DES 必失败，
                // 显式请求 iis/tomcat 时必须报错 + 记日志（与 binary 缺失路径对称），不能静默给残缺包。
                $output = [];
                @exec("$cmd 2>&1", $output, $returnCode);

                if ($returnCode !== 0 || ! file_exists($pfx)) {
                    // 显式单格式（iis/tomcat）失败必须硬报错 + Log::error；all 模式 PFX 可选，静默降级跳过
                    if ($type == 'iis' || $type == 'tomcat') {
                        Log::error('PFX 生成失败', [
                            'returnCode' => $returnCode,
                            'output' => implode("\n", $output),
                            'common_name' => $commonName,
                        ]);
                        $this->error('PFX 生成失败，请联系管理员检查 OpenSSL 是否支持 PBE-SHA1-3DES（FIPS / no-des 环境会失败）');
                    } else {
                        Log::info('PFX 生成失败，跳过 IIS/JKS 输出', ['returnCode' => $returnCode]);
                    }
                }

                if ($returnCode === 0 && file_exists($pfx)) {
                    if ($type == 'all' || $type == 'iis') {
                        $zip->addFile($pfx, $certPath.'iis/'.$certName.'.pfx');
                        $zip->addFromString($certPath.'iis/password.txt', $password);
                    }

                    if (($type == 'all' || $type == 'tomcat') && $keytool !== null) {
                        $jks = $tempDir.'/temp.jks';
                        $cmd = escapeshellarg($keytool).' -importkeystore -srckeystore '.escapeshellarg($pfx)." -srcstoretype PKCS12 -srcstorepass $password -deststoretype jks -deststorepass $password -destkeystore ".escapeshellarg($jks);

                        // 捕获 stderr（不再 > /dev/null 丢弃）+ 检查返回码/文件：keytool 环境异常时
                        // 显式请求 tomcat 必须报错 + 记日志（与 PFX 失败路径对称），不能静默给空 jks。
                        $jksOutput = [];
                        @exec("$cmd 2>&1", $jksOutput, $jksReturnCode);

                        if ($jksReturnCode !== 0 || ! file_exists($jks)) {
                            // 显式 tomcat 失败硬报错 + Log::error；all 模式 jks 可选，静默降级跳过
                            if ($type == 'tomcat') {
                                Log::error('JKS 生成失败', [
                                    'returnCode' => $jksReturnCode,
                                    'output' => implode("\n", $jksOutput),
                                    'common_name' => $commonName,
                                ]);
                                $this->error('JKS 生成失败，请联系管理员检查 keytool（JDK）是否可用');
                            } else {
                                Log::info('JKS 生成失败，跳过 tomcat 输出', ['returnCode' => $jksReturnCode]);
                            }
                        } else {
                            $zip->addFile($jks, $certPath.'tomcat/'.$certName.'.jks');
                            $zip->addFromString($certPath.'tomcat/password.txt', $password);
                        }
                    }
                }
            }
        }

        if ($type == 'all' || $type == 'txt') {
            $zip->addFromString($certPath.'txt/nginx/'.$certName.'.crt.txt', $cert.PHP_EOL.$intermediateCert);
            $keyMatched && $zip->addFromString($certPath.'txt/nginx/'.$certName.'.key.txt', $privateKey);
            $zip->addFromString($certPath.'txt/apache/'.$certName.'.crt.txt', $cert);
            $zip->addFromString($certPath.'txt/apache/'.$certName.'-ca-bundle.crt.txt', $intermediateCert);
            $keyMatched && $zip->addFromString($certPath.'txt/apache/'.$certName.'.key.txt', $privateKey);
        }

        // 生成 RSA 传统格式私钥，兼容不同 OpenSSL 版本；openssl 不可用时静默跳过
        if ($type == 'all' && $keyMatched) {
            $keyDetails = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));
            if (isset($keyDetails['type']) && ($keyDetails['type'] === OPENSSL_KEYTYPE_RSA)) {
                try {
                    $openssl = app(BinaryLocator::class)->openssl();
                } catch (BinaryNotFoundException $e) {
                    Log::info('openssl 不可用，跳过 RSA 传统格式输出', ['diagnose' => $e->diagnose()]);
                    $openssl = null;
                }

                if ($openssl !== null) {
                    $key = $tempDir.'/'.$certName.'.key';
                    $keyFile = fopen($key, 'w', true);
                    fwrite($keyFile, $privateKey);
                    fclose($keyFile);
                    $rsaKey = $tempDir.'/'.$certName.'-rsa.key';

                    // 首先尝试使用 -traditional 参数
                    $cmd = escapeshellarg($openssl).' pkcs8 -in '.escapeshellarg($key).' -out '.escapeshellarg($rsaKey).' -nocrypt -traditional';
                    @exec("$cmd 2>&1", $output, $returnCode);

                    // 如果 -traditional 参数失败，使用 RSA 命令转换
                    if ($returnCode !== 0) {
                        $cmd = escapeshellarg($openssl).' rsa -in '.escapeshellarg($key).' -out '.escapeshellarg($rsaKey).' -traditional';
                        @exec("$cmd > /dev/null 2>&1", $output, $returnCode);
                    }

                    // 只有转换成功才添加到zip
                    if ($returnCode === 0 && file_exists($rsaKey)) {
                        $zip->addFile($rsaKey, $certPath.'rsa_key/'.$certName.'-rsa.key');
                    }
                }
            }
        }
    }

    /**
     * 生成 SM2 国密 nginx 双证书包（签名证书 + 加密证书）。
     *
     * 国密 SSL 双证书：签名证书走用户密钥对，加密证书 + 加密私钥由 CA/KGC 托管下发。
     * 纯 addFromString 无需 openssl。加密私钥为空（gateway 未就绪）时仅出签名部分。
     */
    protected function addSm2CertToZip(
        ZipArchive $zip,
        string $certPath,
        string $certName,
        string $commonName,
        string $cert,
        string $privateKey,
        string $intermediateCert,
        string $encCert,
        string $encKey,
        string $encKey2
    ): void {
        $dir = $certPath.'nginx/';

        $zip->addFromString($dir.$certName.'_sign.crt', $cert);
        $privateKey && $zip->addFromString($dir.$certName.'_sign.key', $privateKey);
        $intermediateCert && $zip->addFromString($dir.$certName.'_sign_ca.crt', $intermediateCert);
        // 加密部分需 enc_cert + enc_key 成对才有效（加密证书 + 对应私钥）；缺任一视为未就绪，降级仅出
        // 签名，绝不写出"有证书无私钥"或"有私钥无证书"的残缺包（上游异步下发 enc、无成对到达约束）
        $encReady = $encCert !== '' && $encKey !== '';
        if ($encReady) {
            $zip->addFromString($dir.$certName.'_enc.crt', $encCert);
            $zip->addFromString($dir.$certName.'_enc.key', $encKey);
            $zip->addFromString($dir.$certName.'_enc_gmt0016.key', $encKey);
            $encKey2 && $zip->addFromString($dir.$certName.'_enc_gmt0009.key', $encKey2);
        }

        $lines = [
            "{$certName}_sign.crt 签名证书",
            "{$certName}_sign.key 签名私钥（与签名证书匹配）",
        ];
        if ($intermediateCert) {
            $lines[] = "{$certName}_sign_ca.crt 证书链";
        }
        if ($encReady) {
            $lines[] = "{$certName}_enc.crt 加密证书";
            $lines[] = "{$certName}_enc.key 加密私钥（GMT-0016 格式，需解密后使用）";
            if ($encKey2) {
                $lines[] = "{$certName}_enc_gmt0009.key 加密私钥（GMT-0009 格式）";
            }
        } else {
            $lines[] = '';
            $lines[] = '注意：加密证书尚未就绪（CA/KGC 下发中），当前仅含签名证书，暂不可用于国密双证书部署，请稍后重新下载完整包。';
        }

        $zip->addFromString($dir.'说明.txt', implode(PHP_EOL, $lines));
    }

    /**
     * 下载流
     */
    private function downFlow(string $zipFile, string $tempDir): void
    {
        if (! file_exists($zipFile)) {
            $this->error('下载失败');
        }

        // 获取文件名
        $filename = basename($zipFile);

        // 跨域支持：本流通过 readfile()+exit 直出，绕过 Symfony Response，
        // 拿不到全局 DynamicCors 中间件设置的 CORS 头，故在此复用同一白名单逻辑手动设置。
        // 仅当 Origin 命中白名单时回显该 Origin，绝不 reflect 任意来源、绝不回落 '*'。
        $origin = request()->header('Origin');
        $allowedOrigins = (string) Config::get('cors.allowed_origins', '');
        if ($origin && DynamicCors::isAllowedOrigin($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: '.$origin);
            header('Vary: Origin');
            if (Config::get('cors.supports_credentials', false)) {
                header('Access-Control-Allow-Credentials: true');
            }
            header('Access-Control-Expose-Headers: Content-Disposition');
        }
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

        // 文件下载头信息
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.urlencode($filename).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: '.filesize($zipFile));

        // 清理输出缓冲并发送文件
        ob_clean();
        flush();
        readfile($zipFile);

        // 清理临时目录
        File::deleteDirectory($tempDir);

        exit;
    }
}
