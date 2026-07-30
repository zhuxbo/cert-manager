<?php

declare(strict_types=1);

namespace App\Services\Order\Traits;

use App\Http\Middleware\DynamicCors;
use App\Models\Order;
use App\Models\Product;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Notification\Exceptions\TransientBuildException;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

trait ActionFileTrait
{
    use ApiResponse;

    /**
     * 批量下载证书
     */
    public function download(int|string|array $orderIds, string $type = 'all'): void
    {
        $archive = $this->buildDownloadArchive($orderIds, $type);
        try {
            $this->downFlow($archive['zipPath'], $archive['tempDir']);
        } finally {
            File::deleteDirectory($archive['tempDir']);
        }
    }

    /**
     * 构建证书下载归档，不输出响应也不退出进程；调用方负责成功结果的 tempDir 生命周期。
     *
     * @return array{zipPath:string,tempDir:string,downloadName:string}
     */
    public function buildDownloadArchive(int|string|array $orderIds, string $type = 'all'): array
    {
        $orderIds = is_array($orderIds) ? $orderIds : explode(',', (string) $orderIds);
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        $orders = Order::with(['latestCert', 'product'])
            ->whereHas('latestCert', fn ($query) => $query->where('status', 'active'))
            ->whereIn('id', $orderIds)
            ->get();

        if ($orders->isEmpty()) {
            $this->error('没有可下载的证书');
        }

        $this->validateDownloadType($orders->pluck('product.product_type')->all(), $type);

        // SSL 等存量产品继续沿用“缺中间链则过滤”策略；S/MIME 必须进入打包层明确失败，
        // 防止 mixed batch 静默返回部分成功包。
        $orders = $orders->filter(fn (Order $order): bool => $order->product->product_type === Product::TYPE_SMIME
            || ! empty($order->latestCert->intermediate_cert));
        if ($orders->isEmpty()) {
            $this->error('没有可下载的证书');
        }

        $tempDir = $this->makeArchiveRootDir();
        $zip = $this->makeDownloadZip();
        $suffix = $type === 'all' ? '' : '_'.$type;
        $downloadName = count($orders) === 1
            ? $this->safeCertificateName((string) $orders->first()->latestCert->common_name, $orders->first()->latestCert->id).$suffix.'.zip'
            : 'certs-'.count($orders).'-'.basename($tempDir).$suffix.'.zip';
        $zipPath = $tempDir.'/'.$downloadName;

        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new TransientBuildException('创建证书压缩包失败');
            }

            $archiveNames = [];
            foreach ($orders as $order) {
                $archiveNames[] = $this->addCertToZip($order, $zip, $tempDir, $archiveNames, $type);
            }

            if ($zip->close() !== true) {
                throw new TransientBuildException('写入证书压缩包失败');
            }
        } catch (Throwable $exception) {
            try {
                $zip->close();
            } catch (Throwable) {
                // 清理优先，原异常保留。
            }
            File::deleteDirectory($tempDir);

            throw $exception;
        }

        return [
            'zipPath' => $zipPath,
            'tempDir' => $tempDir,
            'downloadName' => $downloadName,
        ];
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

        // 建包相位 try/finally（同 download）：异常时清理残留 tempDir，正常路径 downFlow 内已删 + exit
        try {
            $zip = new ZipArchive;
            $filename = '订单'.$orderId.'-请放到网站根目录解压.zip';
            $zip->open($tempDir.'/'.$filename, ZipArchive::CREATE);
            $zip->addFromString('.well-known/pki-validation/'.($file['name'] ?? 'error.txt'), $file['content'] ?? '');
            $zip->close();

            $this->downFlow($tempDir.'/'.$filename, $tempDir);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * 添加证书文件到zip
     *
     * @param  list<string>  $domains
     */
    protected function addCertToZip(
        Order $order,
        ZipArchive $zip,
        string $tempDir,
        array $domains = [],
        string $type = 'all'
    ): string {
        $commonName = $order->latestCert->common_name ?? '';
        $cert = $order->latestCert->cert ?? '';
        $privateKey = $order->latestCert->private_key ?? '';
        $intermediateCert = $order->latestCert->intermediate_cert ?? '';

        $uniqueId = $order->latestCert->id ?? $order->id ?? bin2hex(random_bytes(4));
        $certName = $this->safeCertificateName($commonName, $uniqueId);
        $archiveName = $this->uniqueArchiveName($certName, $uniqueId, $domains);
        $certPath = $archiveName.'/';

        if (($order->product->product_type ?? null) === Product::TYPE_SMIME) {
            $this->addSmimeCertToZip($order, $zip, $tempDir, $certPath, $certName, $type);

            return $archiveName;
        }

        $workDir = $this->makeCertificateWorkDir($tempDir);

        // 国密双证书：按 encryption_alg 判定（与前端 isSM2 / Deploy gate 同口径），国密证书一律只出
        // nginx 国密包，绝不走下方普通格式分支 —— 普通分支的 openssl_x509_check_private_key / PKCS12 不支持
        // SM2，会丢签名私钥、且 iis/tomcat 还会硬报错。加密证书/私钥由上游 CA/KGC 经 get 透传，为空
        // （gateway 未就绪）时 addSm2CertToZip 内部降级仅出签名部分 + 提示。
        if (strtolower((string) ($order->latestCert->encryption_alg ?? '')) === 'sm2') {
            $this->addSm2CertToZip($zip, $certPath, $certName, $commonName, $cert, $privateKey, $intermediateCert, $order->latestCert->enc_cert ?? '', $order->latestCert->enc_key ?? '', $order->latestCert->enc_key2 ?? '');

            return $archiveName;
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
                $pfx = $workDir.'/temp.pfx';
                $certFile = $workDir.'/temp.crt';
                $keyFile = $workDir.'/temp.key';
                $chainFile = $workDir.'/temp.chain';
                foreach ([
                    $certFile => $cert,
                    $keyFile => $privateKey,
                    $chainFile => $intermediateCert,
                ] as $path => $contents) {
                    $this->writeTemporaryFile($path, $contents);
                }
                if (! chmod($keyFile, 0600)) {
                    throw new TransientBuildException('设置证书私钥权限失败');
                }

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
                        $jks = $workDir.'/temp.jks';
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
                    $key = $workDir.'/private.key';
                    $this->writeTemporaryFile($key, $privateKey);
                    if (! chmod($key, 0600)) {
                        throw new TransientBuildException('设置 RSA 私钥临时文件失败');
                    }
                    $rsaKey = $workDir.'/private-rsa.key';

                    // 首先尝试使用 -traditional 参数
                    $cmd = escapeshellarg($openssl).' pkcs8 -in '.escapeshellarg($key).' -out '.escapeshellarg($rsaKey).' -nocrypt -traditional';
                    @exec("$cmd 2>&1", $output, $returnCode);

                    // 如果 -traditional 参数失败，使用 RSA 命令转换（捕获 stderr，不再 > /dev/null 丢弃）
                    if ($returnCode !== 0) {
                        $cmd = escapeshellarg($openssl).' rsa -in '.escapeshellarg($key).' -out '.escapeshellarg($rsaKey).' -traditional';
                        $output = [];
                        @exec("$cmd 2>&1", $output, $returnCode);
                    }

                    // 只有转换成功才添加到zip；失败属 best-effort（仅 all 模式附加输出），记日志跳过、留排障痕迹
                    if ($returnCode === 0 && file_exists($rsaKey)) {
                        $zip->addFile($rsaKey, $certPath.'rsa_key/'.$certName.'-rsa.key');
                    } else {
                        Log::info('RSA 传统格式私钥转换失败，跳过 rsa_key 输出', [
                            'returnCode' => $returnCode,
                            'output' => implode("\n", $output),
                        ]);
                    }
                }
            }
        }

        return $archiveName;
    }

    /**
     * @param  list<string>  $usedNames
     */
    protected function uniqueArchiveName(string $certName, int|string $uniqueId, array $usedNames): string
    {
        if (! in_array($certName, $usedNames, true)) {
            return $certName;
        }

        $candidate = $certName.'-'.$uniqueId;
        $collision = 2;
        while (in_array($candidate, $usedNames, true)) {
            $candidate = $certName.'-'.$uniqueId.'-'.$collision;
            $collision++;
        }

        return $candidate;
    }

    /**
     * 为单张证书创建独占工作目录。ZipArchive::addFile 会延迟读取源文件，
     * 因此目录必须保留到整个 ZIP close 后再由顶层统一清理。
     */
    protected function makeCertificateWorkDir(string $tempDir): string
    {
        $workDir = $tempDir.'/work-'.bin2hex(random_bytes(8));
        try {
            File::ensureDirectoryExists($workDir, 0700);
        } catch (Throwable $exception) {
            throw new TransientBuildException('创建证书工作目录失败', 0, $exception);
        }
        if (! chmod($workDir, 0700)) {
            throw new TransientBuildException('设置证书工作目录权限失败');
        }

        return $workDir;
    }

    /**
     * 创建下载归档根临时目录；128 位随机名、0700 权限，碰撞时重新取名。
     */
    protected function makeArchiveRootDir(): string
    {
        $baseDir = storage_path('temp-certs');
        try {
            File::ensureDirectoryExists($baseDir, 0700);
        } catch (Throwable $exception) {
            throw new TransientBuildException('创建证书临时目录失败', 0, $exception);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $tempDir = $baseDir.'/'.$this->makeArchiveToken();
            if (@mkdir($tempDir, 0700)) {
                return $tempDir;
            }
            if (is_dir($tempDir)) {
                continue;
            }

            throw new TransientBuildException('创建证书临时目录失败');
        }

        throw new TransientBuildException('创建证书临时目录失败');
    }

    protected function makeArchiveToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function makeDownloadZip(): ZipArchive
    {
        return new ZipArchive;
    }

    protected function safeCertificateName(string $name, int|string $uniqueId): string
    {
        $safe = str_replace('*', 'STAR', $name);
        $safe = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '-', $safe) ?? '';
        $safe = preg_replace('/(?:^|[.])[.]+(?:$|[.])/', '-', $safe) ?? '';
        $safe = trim($safe, ". \t\n\r\0\x0B-");

        return $safe !== '' ? $safe : 'certificate-'.$uniqueId;
    }

    /**
     * @param  list<string|null>  $productTypes
     */
    protected function validateDownloadType(array $productTypes, string $type): void
    {
        $knownTypes = ['all', 'apache', 'nginx', 'pem', 'iis', 'tomcat', 'txt', 'pfx'];
        if (! in_array($type, $knownTypes, true)) {
            $this->error('不支持的证书下载格式');
        }

        $hasSmime = in_array(Product::TYPE_SMIME, $productTypes, true);
        $hasOther = collect($productTypes)->contains(fn (?string $productType): bool => $productType !== Product::TYPE_SMIME);

        if ($hasSmime && ! $hasOther && ! in_array($type, ['all', 'pem', 'pfx'], true)) {
            $this->error('S/MIME 仅支持 PEM 或 PFX 下载格式');
        }
        if ($hasOther && $type === 'pfx') {
            $this->error('当前产品集合不支持该下载格式');
        }
        if ($hasSmime && $hasOther && ! in_array($type, ['all', 'pem'], true)) {
            $this->error('混合产品仅支持 all 或 pem 下载格式');
        }
    }

    /**
     * 生成 S/MIME PFX 六位导入密码：排除易混字符，并保证至少含一个字母和一个数字。
     */
    protected function generateSmimePfxPassword(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $all = $letters.$digits;

        $characters = [
            $letters[random_int(0, strlen($letters) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        while (count($characters) < 6) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /**
     * 将单张 S/MIME 证书按 PEM/PFX 两种格式写入归档。
     */
    protected function addSmimeCertToZip(
        Order $order,
        ZipArchive $zip,
        string $tempDir,
        string $certPath,
        string $certName,
        string $type,
        #[\SensitiveParameter] ?string $password = null
    ): ?string {
        $certificate = (string) ($order->latestCert->cert ?? '');
        $intermediate = (string) ($order->latestCert->intermediate_cert ?? '');
        $privateKey = (string) ($order->latestCert->private_key ?? '');
        $commonName = (string) ($order->latestCert->common_name ?? '');

        if ($certificate === '' || $intermediate === '') {
            $this->error('S/MIME 证书或中间证书不存在，无法生成证书包');
        }
        $keyMatched = $privateKey !== '' && openssl_x509_check_private_key($certificate, $privateKey);
        if (! $keyMatched) {
            $this->error('S/MIME 证书私钥不存在或不匹配，无法生成 PFX');
        }

        if ($type === 'all' || $type === 'pem') {
            if ($zip->addFromString($certPath.'pem/'.$certName.'.pem', $certificate.PHP_EOL.$intermediate) !== true
                || $zip->addFromString($certPath.'pem/'.$certName.'.key', $privateKey) !== true) {
                throw new TransientBuildException('写入 S/MIME PEM 失败');
            }
        }

        if ($type === 'pem') {
            return null;
        }

        $password ??= $this->generateSmimePfxPassword();
        $workDir = $this->makeCertificateWorkDir($tempDir);
        $certificateFile = $workDir.'/certificate.pem';
        $privateKeyFile = $workDir.'/private.key';
        $chainFile = $workDir.'/chain.pem';
        $pfxFile = $workDir.'/certificate.pfx';

        foreach ([
            $certificateFile => $certificate,
            $privateKeyFile => $privateKey,
            $chainFile => $intermediate,
        ] as $path => $contents) {
            $this->writeTemporaryFile($path, $contents);
        }
        if (! chmod($privateKeyFile, 0600)) {
            throw new TransientBuildException('设置 S/MIME 私钥权限失败');
        }

        try {
            $openssl = app(BinaryLocator::class)->openssl();
        } catch (BinaryNotFoundException $exception) {
            Log::warning('OpenSSL 不可用，无法生成 S/MIME PFX', [
                'diagnose' => $exception->diagnose(),
                'common_name' => $commonName,
            ]);
            $this->error('OpenSSL 不可用，无法生成 S/MIME PFX');
        }

        $command = [
            $openssl,
            'pkcs12',
            '-export',
            '-inkey',
            $privateKeyFile,
            '-in',
            $certificateFile,
            '-certfile',
            $chainFile,
            '-out',
            $pfxFile,
            '-name',
            $commonName,
            '-passout',
            'stdin',
            '-keypbe',
            'PBE-SHA1-3DES',
            '-certpbe',
            'PBE-SHA1-3DES',
            '-macalg',
            'SHA1',
        ];
        $result = $this->runProcess($command, $password);
        $diagnostic = $this->sanitizeProcessDiagnostic($result['stderr'], $password);
        $outputMissing = ! is_file($pfxFile) || filesize($pfxFile) === 0;
        if ($result['exitCode'] !== 0 || $outputMissing) {
            Log::error('S/MIME PFX 生成失败', [
                'returnCode' => $result['exitCode'],
                'common_name' => $commonName,
                'diagnose' => $diagnostic,
            ]);
            if ($outputMissing && $result['exitCode'] === 0
                || $this->isTransientOpenSslIoFailure($result['stderr'])) {
                throw new TransientBuildException('S/MIME PFX 临时文件写入失败');
            }
            $this->error('S/MIME PFX 生成失败，请联系管理员');
        }

        if (! $zip->addFile($pfxFile, $certPath.'pfx/'.$certName.'.pfx')
            || ! $zip->addFromString($certPath.'pfx/password.txt', $password.PHP_EOL)) {
            throw new TransientBuildException('写入 S/MIME PFX 失败');
        }

        return $password;
    }

    /**
     * 完整写入含私钥的临时文件；短写会继续，零进展或关闭/刷新失败按瞬态 IO 处理。
     */
    protected function writeTemporaryFile(string $path, #[\SensitiveParameter] string $contents): void
    {
        $stream = @fopen($path, 'wb');
        if ($stream === false) {
            throw new TransientBuildException('打开证书临时文件失败');
        }

        $exception = null;
        try {
            $this->writeStreamFully($stream, $contents);
            if (! @fflush($stream)) {
                throw new TransientBuildException('刷新证书临时文件失败');
            }
        } catch (Throwable $caught) {
            $exception = $caught;
        }
        $closed = @fclose($stream);

        if ($exception instanceof TransientBuildException) {
            throw $exception;
        }
        if ($exception !== null) {
            throw new TransientBuildException('写入证书临时文件失败', 0, $exception);
        }
        if (! $closed) {
            throw new TransientBuildException('关闭证书临时文件失败');
        }
    }

    /**
     * @param  resource  $stream
     */
    protected function writeStreamFully($stream, #[\SensitiveParameter] string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = $this->writeTemporaryChunk($stream, substr($contents, $offset));
            if ($written === false || $written <= 0 || $written > $length - $offset) {
                throw new TransientBuildException('证书临时文件写入不完整');
            }
            $offset += $written;
        }

        if ($offset !== $length) {
            throw new TransientBuildException('证书临时文件写入不完整');
        }
    }

    /**
     * @param  resource  $stream
     */
    protected function writeTemporaryChunk($stream, #[\SensitiveParameter] string $contents): int|false
    {
        return @fwrite($stream, $contents);
    }

    /**
     * 以参数数组直接启动进程，密码仅写入 stdin，不经过 shell、argv 或环境变量。
     *
     * @param  list<string>  $command
     * @return array{exitCode:int,stdout:string,stderr:string}
     */
    protected function runProcess(array $command, #[\SensitiveParameter] string $stdin): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new TransientBuildException('启动 OpenSSL 进程失败');
        }

        try {
            $this->writeStreamFully($pipes[0], $stdin.PHP_EOL);
            if (! @fclose($pipes[0])) {
                throw new TransientBuildException('关闭 OpenSSL 密码管道失败');
            }
            unset($pipes[0]);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + 60;
            do {
                $stdoutChunk = stream_get_contents($pipes[1]);
                $stderrChunk = stream_get_contents($pipes[2]);
                if ($stdoutChunk === false || $stderrChunk === false) {
                    throw new TransientBuildException('读取 OpenSSL 进程输出失败');
                }
                $stdout .= $stdoutChunk;
                $stderr .= $stderrChunk;

                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    throw new TransientBuildException('OpenSSL 进程执行超时');
                }
                usleep(10_000);
            } while (true);

            $stdoutChunk = stream_get_contents($pipes[1]);
            $stderrChunk = stream_get_contents($pipes[2]);
            if ($stdoutChunk === false || $stderrChunk === false) {
                throw new TransientBuildException('读取 OpenSSL 进程输出失败');
            }
            $stdout .= $stdoutChunk;
            $stderr .= $stderrChunk;
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            $pipes = [];

            $closeCode = proc_close($process);
            $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $closeCode;

            return [
                'exitCode' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (Throwable $exception) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            if (is_resource($process)) {
                @proc_terminate($process);
                @proc_close($process);
            }
            if ($exception instanceof TransientBuildException) {
                throw $exception;
            }

            throw new TransientBuildException('OpenSSL 进程通信失败', 0, $exception);
        }
    }

    protected function sanitizeProcessDiagnostic(string $stderr, #[\SensitiveParameter] string $secret): string
    {
        $sanitized = str_replace($secret, '[REDACTED]', $stderr);
        $lines = preg_split('/\R/u', trim($sanitized)) ?: [];

        return implode("\n", array_slice($lines, -3));
    }

    protected function isTransientOpenSslIoFailure(string $stderr): bool
    {
        return preg_match(
            '/no space left on device|disk quota exceeded|input\/output error|read-only file system|permission denied|error writing|failed to write|unable to write|broken pipe/i',
            $stderr
        ) === 1;
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
