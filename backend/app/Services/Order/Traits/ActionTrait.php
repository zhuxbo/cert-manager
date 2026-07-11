<?php

declare(strict_types=1);

namespace App\Services\Order\Traits;

use App\Bootstrap\ApiExceptions;
use App\Exceptions\ApiResponseException;
use App\Jobs\TaskJob;
use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Delegation\DelegationDnsService;
use App\Services\Order\Utils\CsrUtil;
use App\Services\Order\Utils\DomainUtil;
use App\Services\Order\Utils\FilterUtil;
use App\Services\Order\Utils\FindUtil;
use App\Services\Order\Utils\OrderUtil;
use App\Services\Order\Utils\ValidatorUtil;
use App\Utils\Random;
use App\Utils\SnowFlake;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ActionTrait
{
    /**
     * 缓存上次动作时间 限制 $expire 秒内不能重复该动作 返回剩余时间
     */
    protected function checkDuplicate(string $action, array $params, int $expire = 60): int
    {
        $cacheKey = $action.'_'.md5(json_encode($params));

        // 原子占位：Cache::add 仅在 key 不存在时写入（SETNX 语义），并发下只有一个请求抢占成功，
        // 避免 get 判断 + set 写入之间的 check-then-act 窗口被并发击穿
        try {
            if (Cache::add($cacheKey, time(), $expire)) {
                return 0; // 抢占成功 → 放行
            }
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);

            return 0; // 缓存故障降级放行，不阻塞业务
        }

        // 未抢到（add 返回 false = key 已存在、有占位、疑似重复）：读剩余秒数仅为友好提示。
        // 此处 Cache::get 故意不 try-catch：add 已证明有占位，get 若失败应让异常抛出走 fail-closed（拒绝），
        // 绝不可 catch 后 return 0 放行——那会放行已知重复。与上方 add 抛异常的 fail-open 方向相反
        // （add 挂 = 是否重复未知 → 放行不阻塞业务，资金安全由 DB 唯一索引/CAS/锁兜底）。
        $lastTime = Cache::get($cacheKey);

        return $lastTime ? max(0, min($lastTime + $expire - time(), $expire)) : 0;
    }

    /**
     * 初始化参数
     */
    protected function initParams(array $params): array
    {
        $params = OrderUtil::convertNumericValues($params);
        $params = FilterUtil::filterParamsField($params);

        $params['params'] = $params;

        // SM2 下单前探测国密 openssl 能力（fail-closed 后端兜底；不可用即拒，绝不静默签错）
        $this->guardSm2Capable($params['encryption']['alg'] ?? null);

        // 续费/重签从原证书继承的加密算法（在 validate 之后注入，见下方）
        $inheritedEncryption = null;

        if ($params['action'] == 'new') {
            $params['user_id'] = (int) ($params['user_id'] ?? 0);
            FindUtil::User($params['user_id'], true);

            $product = FindUtil::Product((int) ($params['product_id'] ?? 0), true);
            $product->product_type === Product::TYPE_ACME && $this->error('此产品不支持通过传统订单流程申请');
            $productType = $product->product_type ?? 'ssl';

            // SMIME/CodeSign 不支持批量申请
            if (in_array($productType, ['smime', 'codesign']) && ($params['is_batch'] ?? false)) {
                $this->error('此产品类型不支持批量申请');
            }

            if ($params['is_batch'] ?? false) {
                ($product->total_max > 1) && $this->error('多域名证书不能批量申请');

                // 批量申请必须自动生成 CSR
                $params['csr_generate'] = 1;
            }
        } else {
            isset($params['order_id']) || $this->error('订单ID不能为空');

            $orderId = $params['order_id'];

            $order = Order::with(['product', 'latestCert'])
                ->whereHas('user')
                ->whereHas('product')
                ->whereHas('latestCert')
                ->where('id', $orderId)
                ->first();

            $order || $this->error('订单或相关数据不存在');

            // 证书状态为 active 和 expired 都可以重签 只要订单没过期
            if (! in_array($order->latestCert->status, ['active', 'expired']) && $params['action'] == 'reissue') {
                $this->error('订单状态错误');
            }

            // 只有 active 可以续费
            if ($params['action'] == 'renew') {
                if ($order->latestCert->status != 'active') {
                    $this->error('订单状态错误');
                }

                // 订单到期前30天内才能续费
                if ($order->period_till > now()->addDays(30)) {
                    $this->error('订单到期前30天内才能续费');
                }
            }

            // 订单已过期不可重签或续费
            $order->period_till < now() && $this->error('订单已过期');

            $params['user_id'] = $order->user_id;
            $params['product_id'] = $order->product_id;
            $params['last_cert_id'] = $order->latestCert->id;
            $params['last_cert'] = $order->latestCert->toArray();

            // 续费/重签未显式指定算法时，从原证书继承（防止 reuse_csr=0 重新生成 CSR 时
            // getEncryptionParams 回落默认 RSA，导致原 ECDSA/SM2 证书静默降级为 RSA）。
            // 仅记录、不立即写入 $params['encryption']：继承的是已签发证书的原算法，应在
            // validate 之后再注入，避免被当前产品 encryption_alg 菜单校验阻断存量证书续签。
            if (empty($params['encryption']['alg'])) {
                $inheritedEncryption = $this->inheritEncryptionFromLastCert($order->latestCert);
                // 继承出 SM2 但国密 openssl 不可用 → 早报错（决策①：保持 SM2，绝不静默降级 RSA）
                $this->guardSm2Capable($inheritedEncryption['alg'] ?? null);
            }

            // 续费默认继承旧订单的自动续费/重签设置（除非显式传入）
            if ($params['action'] === 'renew') {
                if (! array_key_exists('auto_renew', $params)) {
                    $params['auto_renew'] = $order->auto_renew;
                }
                if (! array_key_exists('auto_reissue', $params)) {
                    $params['auto_reissue'] = $order->auto_reissue;
                }
            }

            CsrUtil::matchKey($params['csr'] ?? '', $order->latestCert->private_key ?? '')
            && $params['private_key'] = $order->latestCert->private_key;

            $product = $order->product;

            if ($params['action'] == 'renew' && $product->renew == 0) {
                $this->error('产品不支持续费');
            }

            if ($params['action'] == 'renew' && $product->status == 0) {
                $this->error('产品已禁用');
            }

            if ($params['action'] == 'reissue' && $product->reissue == 0) {
                $this->error('产品不支持重新签发');
            }
        }

        $params['product'] = $product->toArray();

        $params = $this->getApplyInformation($params);

        ValidatorUtil::validate($params);

        // 继承的原算法在 validate 之后注入：仅供 getCert 生成 CSR 用，不受产品菜单校验
        // （显式传入的 encryption 已在上方 validate 把关；此处仅注入"缺省时从原证书继承"的值）
        if ($inheritedEncryption !== null) {
            $params['encryption'] = $inheritedEncryption;
        }

        return $params;
    }

    /**
     * 国密(SM2)能力探测 gate：alg=sm2 时探测国密 openssl 是否可用，不可用即 fail-closed 拒绝。
     * 替代旧的 gmEnabled 业务开关——本机能否生成 SM2 CSR 由探测决定，不留人工开关、不留半残环境。
     * 早 gate（拦显式 SM2 入参）与继承后（拦从原证书继承出的 SM2）共用此单点，均在事务前。
     */
    protected function guardSm2Capable(?string $alg): void
    {
        if (strtolower((string) $alg) !== 'sm2') {
            return;
        }

        try {
            app(BinaryLocator::class)->gmOpenssl();
        } catch (BinaryNotFoundException $e) {
            $this->error('国密(SM2)环境不可用（需 openssl 能签 id-ecPublicKey 标准编码，OpenSSL ≥3.0.13 实测可用）：'.$e->getMessage());
        }
    }

    /**
     * 续费/重签从原证书继承加密算法（alg/bits/digest）。
     * 证书列存大写（SM2/RSA/SHA256），统一 strtolower 归一；
     * bits/digest 的合法性与 SM2 强制（SM2/sm3/256）交给 CsrUtil::getEncryptionParams。
     */
    protected function inheritEncryptionFromLastCert(Cert $lastCert): array
    {
        return [
            'alg' => strtolower((string) $lastCert->encryption_alg),
            'bits' => (int) $lastCert->encryption_bits,
            'digest_alg' => strtolower((string) $lastCert->signature_digest_alg),
        ];
    }

    /**
     * 获取订单信息
     */
    protected function getOrder(array $params): array
    {
        $order['brand'] = $params['product']['brand'] ?? '';
        $order['user_id'] = (int) ($params['user_id'] ?? 0);
        $order['product_id'] = (int) ($params['product_id'] ?? 0);
        $order['plus'] = (int) ($params['plus'] ?? 1);
        $order['period'] = (int) ($params['period'] ?? 0);
        $order['contact'] = $params['contact'] ?? null;
        isset($params['organization']) && $order['organization'] = $params['organization'];
        // 如果请求包含自动续费/重签标记，则持久化到新订单
        array_key_exists('auto_renew', $params) && $order['auto_renew'] = $params['auto_renew'];
        array_key_exists('auto_reissue', $params) && $order['auto_reissue'] = $params['auto_reissue'];

        return $order;
    }

    /**
     * 获取证书信息
     */
    protected function getCert(array $params): array
    {
        $productType = $params['product']['product_type'] ?? 'ssl';

        // 公共字段
        $cert['params'] = $params['params'];
        $cert['action'] = $params['action'] ?? 'new';
        $cert['last_cert_id'] = is_int($params['last_cert_id'] ?? null) ? $params['last_cert_id'] : null;
        $cert['channel'] = $params['channel'] ?? 'admin';
        $cert['refer_id'] = is_string($params['refer_id'] ?? null)
            ? $params['refer_id']
            : str_replace('-', '', Random::uuid());

        // 根据产品类型处理不同逻辑
        if ($productType === 'smime') {
            $cert['email'] = $params['email'] ?? '';
            $cert['dcv'] = ['method' => 'email'];
        }

        if ($productType === 'codesign' || $productType === 'docsign') {
            $cert['dcv'] = null;
        }

        if ($productType === 'smime') {
            // SMIME: 根据产品 code 中的标记确定 commonName
            $smimeType = CsrUtil::getSMIMEType($params['product'] ?? []);
            $cert['common_name'] = match ($smimeType) {
                'mailbox' => $params['email'] ?? '',  // mailbox 使用邮箱地址
                'individual', 'sponsor' => trim(($params['contact']['first_name'] ?? '').' '.($params['contact']['last_name'] ?? '')),
                'organization' => $params['organization']['name'] ?? '',
                default => $params['email'] ?? '',  // 默认使用邮箱地址
            };

            $cert['alternative_names'] = $cert['common_name'];
            $cert['standard_count'] = 0;
            $cert['wildcard_count'] = 0;

            // CSR 处理
            $params = CsrUtil::auto($params);

            // 如果产品不支持重用 CSR，则检查 CSR 是否已经使用过
            if (! ($params['product']['reuse_csr'] ?? 0)) {
                Cert::where('csr_md5', md5($params['csr']))->first() && $this->error('CSR已使用');
            }

            $cert['csr'] = $params['csr'];
            $cert['private_key'] = $params['private_key'] ?? null;

            $cert['validation'] = null;
        }

        if ($productType === 'codesign' || $productType === 'docsign') {
            // CodeSign/DocSign: 使用组织名称作为 commonName
            $cert['common_name'] = $params['organization']['name'] ?? '';

            $cert['alternative_names'] = $cert['common_name'];
            $cert['standard_count'] = 0;
            $cert['wildcard_count'] = 0;

            // CSR 处理
            $params = CsrUtil::auto($params);

            // 如果产品不支持重用 CSR，则检查 CSR 是否已经使用过
            if (! ($params['product']['reuse_csr'] ?? 0)) {
                Cert::where('csr_md5', md5($params['csr']))->first() && $this->error('CSR已使用');
            }

            $cert['csr'] = $params['csr'];
            $cert['private_key'] = $params['private_key'] ?? null;

            $cert['validation'] = null;
        }

        if ($productType === 'ssl') {
            // SSL：处理域名、CSR、DCV 验证
            $params['domains'] ??= '';
            // 域名大小写不敏感，先 CA 无关地统一小写归一——否则经 API/Deploy 入口提交的
            // 混合大小写域名会在 replace_san=0 合并时与旧证书小写值 array_unique 去重不掉，
            // 产生幽灵 SAN 多扣费，并把混合大小写外发上游/CA。
            $params['domains'] = DomainUtil::lowercaseDomains($params['domains']);
            // 仅 Certum 产品把 punycode 域名转回中文（Unicode）；其他 CA 保持原样（punycode）
            if (($params['product']['ca'] ?? '') === 'certum') {
                $params['domains'] = DomainUtil::convertToUnicodeDomains($params['domains']);
            }

            if ($params['product']['gift_root_domain'] ?? 0) {
                $cert['alternative_names'] = DomainUtil::addGiftDomain($params['domains']);
                // 自动生成CSR还需要调用domains参数
                $params['domains'] = $cert['alternative_names'];
            } else {
                $cert['alternative_names'] = $params['domains'];
            }

            $cert['common_name'] = explode(',', $cert['alternative_names'])[0];

            $san_count = OrderUtil::getSansFromDomains($cert['alternative_names'], $params['product']['gift_root_domain'] ?? 0);

            $cert['standard_count'] = $san_count['standard_count'] ?? 0;
            $cert['wildcard_count'] = $san_count['wildcard_count'] ?? 0;

            if (in_array($cert['action'], ['renew', 'reissue'])) {
                // 如果产品不支持增加 SAN，则检查 SAN 是否已经超过原证书的数量
                if (! ($params['product']['add_san'] ?? 0)) {
                    $cert['standard_count'] > $params['last_cert']['standard_count']
                    && $this->error('标准域名数量超过原证书');
                    $cert['wildcard_count'] > $params['last_cert']['wildcard_count']
                    && $this->error('通配符域名数量超过原证书');
                }

                // 如果产品不支持替换 SAN，则将原证书中 SAN 添加到当前证书中，重新检查 SAN 数量是否已经超过产品限制, 重新获取 SAN 数量
                if (! ($params['product']['replace_san'] ?? 0)) {
                    $cert['alternative_names'] = $cert['alternative_names'].','.$params['last_cert']['alternative_names'];

                    // 去除重复域名
                    $cert['alternative_names'] = implode(',', array_unique(explode(',', trim($cert['alternative_names'], ','))));

                    // 重新验证 SAN 数量
                    $validation_result = ValidatorUtil::validateSansMaxCount($params['product'], $cert['alternative_names']);
                    empty(array_filter($validation_result)) || $this->error('SAN数量超过产品限制');

                    // 去除旧证书的域名 然后获取 SAN 数量
                    $add_domains = array_diff(explode(',', $cert['alternative_names']), explode(',', $params['last_cert']['alternative_names']));
                    $add_sans = OrderUtil::getSansFromDomains(implode(',', $add_domains), $params['product']['gift_root_domain'] ?? 0);

                    // 重新设置证书 SAN 数量为 新增的数量 + 旧证书的数量
                    $cert['standard_count'] = $add_sans['standard_count'] + $params['last_cert']['standard_count'];
                    $cert['wildcard_count'] = $add_sans['wildcard_count'] + $params['last_cert']['wildcard_count'];
                }
            }

            $params = CsrUtil::auto($params);

            // 如果产品不支持重用 CSR，则检查 CSR 是否已经使用过
            if (! ($params['product']['reuse_csr'] ?? 0)) {
                Cert::where('csr_md5', md5($params['csr']))->first() && $this->error('CSR已使用');
            }

            $cert['csr'] = $params['csr'];
            $cert['private_key'] = $params['private_key'] ?? null;

            if ($params['product']['ca'] === 'sectigo') {
                $cert['unique_value'] = is_string($params['unique_value'] ?? null)
                    ? $params['unique_value']
                    : 'cn'.SnowFlake::generateParticle();
            }

            $cert['dcv'] = $this->generateDcv(
                $params['product']['ca'] ?? '',
                $params['validation_method'],
                $cert['csr'],
                $cert['unique_value'] ?? ''
            );

            $cert['validation'] = $this->generateValidation(
                $cert['dcv'],
                $cert['alternative_names'],
                $params['user_id'] ?? null
            );

            // 如果是委托验证，尝试写入 TXT 记录
            if ($cert['dcv']['is_delegate'] ?? false) {
                $cert['validation'] = $this->writeDelegationTxtRecords($cert['validation']);
            }
        }

        return $cert;
    }

    /**
     * 验证域名和验证方法的兼容性
     * 提交申请参数已经校验 所以此方法只在 updateDCV 中调用
     *
     * @param  string  $alternativeNames  域名列表，逗号分隔
     * @param  string  $method  验证方法
     */
    protected function validateDomainValidationCompatibility(string $alternativeNames, string $method): void
    {
        $domainList = explode(',', trim($alternativeNames, ','));
        $fileValidationMethods = ['http', 'https', 'file'];

        foreach ($domainList as $domain) {
            $domain = trim($domain);
            if (empty($domain)) {
                continue;
            }

            $type = DomainUtil::getType($domain);

            // 检查是否为通配符域名
            if ($type == 'wildcard') {
                // 通配符域名不能用文件验证
                if (in_array($method, $fileValidationMethods)) {
                    $this->error("通配符域名 $domain 不能使用文件验证方法");
                }
            }

            // 检查是否为IP地址（IPv4或IPv6）
            if ($type == 'ipv4' || $type == 'ipv6') {
                // IP地址只能用文件验证
                if (! in_array($method, $fileValidationMethods)) {
                    $this->error("IP地址 $domain 只能使用文件验证方法");
                }
            }
        }
    }

    /**
     * 生成 DCV
     */
    protected function generateDcv(string $ca, string $method, string $csr, string $unique_value): array
    {
        $method = strtolower($method);

        // delegate 方法转换为 txt，并标记为委托验证
        $isDelegate = $method === 'delegation';
        if ($isDelegate) {
            $method = 'txt';
        }

        // site.sectigoDcv 开关：默认关闭，关闭时 Sectigo 走与其他 CA 相同的降级路径
        // （仅返回 method，dns/file 字段由上游 API 回填，再经 mergeDcv 合并）
        if (strtolower($ca) === 'sectigo'
            && in_array($method, ['cname', 'http', 'https'])
            && get_system_setting('site', 'sectigoDcv', false)) {
            $dcv = $this->generateSectigoDcv($method, $csr, $unique_value);
        } else {
            $dcv = ['method' => $method];
        }

        // 标记委托验证和 CA 信息
        if ($isDelegate) {
            $dcv['is_delegate'] = true;
            $dcv['ca'] = strtolower($ca); // 保存 CA 用于确定委托前缀
        }

        return $dcv;
    }

    /**
     * 合并 DCV 数据，根据用户当前选择决定委托验证标记
     *
     * @param  array|null  $apiDcv  从 API 返回的新 DCV 数据
     * @param  array|null  $newDcv  根据用户选择生成的 DCV 数据（包含 is_delegate 和 ca）
     */
    protected function mergeDcv(?array $apiDcv, ?array $newDcv): ?array
    {
        if ($apiDcv === null) {
            return $newDcv;
        }

        if ($newDcv === null) {
            return $apiDcv;
        }

        // 从新 DCV 保留委托验证标记（根据用户当前选择生成）
        if (! empty($newDcv['is_delegate'])) {
            $apiDcv['is_delegate'] = true;
            if (! empty($newDcv['ca'])) {
                $apiDcv['ca'] = $newDcv['ca'];
            }
        }

        return $apiDcv;
    }

    /**
     * 生成 Sectigo DCV
     */
    protected function generateSectigoDcv(string $method, string $csr, string $unique_value): array
    {
        $random = bin2hex(random_bytes(4));
        $tempDir = storage_path('temp-certs/'.$random);
        mkdir($tempDir, 0755, true);

        $csrPemFile = $tempDir.'/csr.pem';
        file_put_contents($csrPemFile, $csr);

        $csrDerFile = $tempDir.'/csr.der';

        // openssl 不可用时降级返回仅含 method 的数组，保持与 $der === null 分支一致；DCV 单点失败不阻断订单流程
        try {
            $openssl = app(BinaryLocator::class)->openssl();
            $cmd = escapeshellarg($openssl).' req -in '.escapeshellarg($csrPemFile).' -outform der -out '.escapeshellarg($csrDerFile);
            // 捕获 stderr（不再 > /dev/null 丢弃）：best-effort 语义不变，失败记日志留排障痕迹
            $output = [];
            @exec("$cmd 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                Log::warning('CSR 转 DER 失败，Sectigo DCV 降级为仅 method', [
                    'returnCode' => $returnCode,
                    'output' => implode("\n", $output),
                ]);
            }
            $der = file_exists($csrDerFile) ? file_get_contents($csrDerFile) : null;
        } catch (BinaryNotFoundException $e) {
            Log::warning('openssl 不可用，无法生成 Sectigo DCV', ['diagnose' => $e->diagnose()]);
            $der = null;
        }

        // 使用 Laravel File 方法清理，更可靠
        if (file_exists($csrPemFile)) {
            File::delete($csrPemFile);
        }
        if (file_exists($csrDerFile)) {
            File::delete($csrDerFile);
        }
        if (is_dir($tempDir)) {
            @rmdir($tempDir);
        }

        if ($der) {
            $md5 = md5($der);
            $sha256 = hash('sha256', $der);
            $cnameValue1 = substr($sha256, 0, 32);
            $cnameValue2 = substr($sha256, 32, 32);

            $unique_value = empty($unique_value) ? 'cn'.SnowFlake::generateParticle() : $unique_value;

            $dcv['method'] = $method;
            $dcv['dns']['host'] = '_'.strtolower($md5);
            $dcv['dns']['type'] = 'CNAME';
            $dcv['dns']['value'] = strtolower($cnameValue1.'.'.$cnameValue2.'.'.$unique_value.'.sectigo.com');
            $dcv['file']['name'] = strtoupper($md5).'.txt';
            $dcv['file']['path'] = '/.well-known/pki-validation/'.$dcv['file']['name'];
            $dcv['file']['content'] = strtoupper($sha256).PHP_EOL.'sectigo.com'.PHP_EOL.strtolower($unique_value);
        }

        return $dcv ?? ['method' => $method];
    }

    /**
     * 生成验证信息
     *
     * @param  array  $dcv  DCV 信息
     * @param  string  $domains  域名列表（逗号分隔）
     * @param  int|null  $userId  用户ID（委托验证时需要）
     */
    protected function generateValidation(array $dcv, string $domains, ?int $userId = null): ?array
    {
        $method = strtolower($dcv['method']);
        $isDelegate = $dcv['is_delegate'] ?? false;
        $domains = explode(',', trim($domains, ','));

        // 委托验证时需要查找委托记录
        $delegationService = $isDelegate && $userId ? app(CnameDelegationService::class) : null;

        foreach ($domains as $k => $domain) {
            if (! $domain) {
                continue;
            }

            $validation[$k] = ['domain' => $domain, 'method' => $method];

            // 标记委托验证
            if ($isDelegate) {
                $validation[$k]['is_delegate'] = true;

                // 查找或创建委托记录（全 ca_map 驱动，无 prefix 推断）
                if ($delegationService) {
                    $ca = $dcv['ca'] ?? '';

                    // 根据 CA 确定委托前缀（不同 CA 使用不同的验证前缀）
                    $prefix = CnameDelegationService::getDelegationPrefixForCa($ca);

                    // 查找委托记录（行为由 ca 决定：exact 精确匹配 / 非 exact 子域优先+回落根域）
                    $delegation = $delegationService->findDelegation($userId, $domain, $ca);

                    // 找不到则自动创建（zone 由 ca 派生：exact 精确域名 / 非 exact 根域）
                    if (! $delegation) {
                        $zone = $delegationService->resolveZone($domain, $ca);
                        $delegation = $delegationService->createOrGet($userId, $zone, $prefix);
                    }

                    // 始终保存委托信息（即使 valid=false）
                    $validation[$k]['delegation_id'] = $delegation->id;
                    $validation[$k]['delegation_target'] = $delegation->target_fqdn;
                    $validation[$k]['delegation_valid'] = $delegation->valid;
                    $validation[$k]['delegation_zone'] = $delegation->zone;
                }
            }

            if (($method == 'cname' || $method == 'txt') && isset($dcv['dns']['value'])) {
                $validation[$k]['host'] = $dcv['dns']['host'];
                $validation[$k]['value'] = $dcv['dns']['value'];
            }

            if (($method == 'http' || $method == 'https' || $method == 'file') && isset($dcv['file']['content'])) {
                $validation[$k]['name'] = $dcv['file']['name'];
                $validation[$k]['content'] = $dcv['file']['content'];
                $protocol = $method == 'file' ? '//' : $method.'://';
                $validation[$k]['link'] = $protocol.$domain.$dcv['file']['path'];
            }

            if (in_array($method, ['admin', 'administrator', 'webmaster', 'hostmaster', 'postmaster'])) {
                $validation[$k]['email'] = $method.'@'.DomainUtil::getRootDomain($domain);
            }
        }

        return $validation ?? null;
    }

    /**
     * 判断验证记录是否准备就绪
     *
     * 仅用于判断是否可以开始验证，不依赖 dcv.dns.value
     *
     * @param  array|null  $validation  验证信息数组
     * @param  string|null  $method  验证方法（txt/cname/http/https/file）
     */
    public function isValidationReady(?array $validation, ?string $method): bool
    {
        if (empty($validation)) {
            return false;
        }

        $method = strtolower((string) $method);

        // txt/cname 验证依赖 value
        if (in_array($method, ['txt', 'cname'], true)) {
            foreach ($validation as $item) {
                if (empty($item['value'] ?? '')) {
                    return false;
                }
            }

            return true;
        }

        // http/https/file 验证依赖 content
        if (in_array($method, ['http', 'https', 'file'], true)) {
            foreach ($validation as $item) {
                if (empty($item['content'] ?? '')) {
                    return false;
                }
            }

            return true;
        }

        // 其他验证方式只要有 validation 即可
        return true;
    }

    /**
     * 写入委托验证的 TXT 记录
     *
     * @param  array  $validation  验证信息数组
     * @return array 更新后的验证信息数组
     */
    protected function writeDelegationTxtRecords(array $validation): array
    {
        $dnsService = app(DelegationDnsService::class);

        // 按 delegation_id 分组收集 tokens
        $tokensByDelegation = [];
        foreach ($validation as $item) {
            $delegationId = $item['delegation_id'] ?? null;
            $delegationValid = $item['delegation_valid'] ?? false;

            // 跳过无效委托或已写入的
            if (! $delegationId || ! $delegationValid || ($item['auto_txt_written'] ?? false)) {
                continue;
            }

            if (! isset($tokensByDelegation[$delegationId])) {
                $tokensByDelegation[$delegationId] = [
                    'tokens' => [],
                    'delegation' => CnameDelegation::find($delegationId),
                ];
            }

            if (! empty($item['value'])) {
                $tokensByDelegation[$delegationId]['tokens'][] = $item['value'];
            }
        }

        // 批量写入 TXT 记录
        $writtenDelegations = [];
        foreach ($tokensByDelegation as $delegationId => $data) {
            $delegation = $data['delegation'];
            $tokens = array_unique($data['tokens']);

            if (! $delegation || empty($tokens)) {
                continue;
            }

            $isSuccess = $dnsService->setTxtByLabel(
                $delegation->proxy_zone,
                $delegation->label,
                $tokens
            );

            if ($isSuccess) {
                $writtenDelegations[$delegationId] = true;
            }
        }

        // 更新 validation 中的写入标记
        foreach ($validation as &$item) {
            $delegationId = $item['delegation_id'] ?? null;
            if ($delegationId && isset($writtenDelegations[$delegationId])) {
                $item['auto_txt_written'] = true;
                $item['auto_txt_written_at'] = now()->toDateTimeString();
            }
        }

        return $validation;
    }

    /**
     * 合并验证信息
     */
    protected function mergeValidation(array $apiValidation, array $certValidation): array
    {
        $indexed = [];
        foreach ($certValidation as $item) {
            $domain = $item['domain'] ?? '';
            $indexed[$domain] = $item;
        }

        foreach ($apiValidation as &$item) {
            $domain = $item['domain'] ?? '';
            if (isset($indexed[$domain])) {
                $indexedDomain = $indexed[$domain];

                // F2-2 token 轮换检测：API value 与旧 value 均存在且不等 = 上游轮换了 DCV token。
                // 此时不带过旧的 auto_txt_written/auto_txt_written_at（= 清标记），令下轮
                // writeDelegationTxtRecords/collectTxtRecords 重写新 token（upsertTXT append-only，不删旧、不伤兄弟）。
                // delegation_* 保留（委托未变，仅 token 变）。value 相同或任一缺失 → 不剔除（no-op 护栏，
                // 防上游 value 不稳定时每轮误判轮换→每轮 append 致活跃 label TXT 累积至上限）。
                $valueRotated = isset($item['value'], $indexedDomain['value'])
                    && $item['value'] !== $indexedDomain['value'];

                foreach ($indexedDomain as $key => $value) {
                    if ($valueRotated && ($key === 'auto_txt_written' || $key === 'auto_txt_written_at')) {
                        continue;
                    }
                    if (! array_key_exists($key, $item)) {
                        $item[$key] = $value;
                    }
                }
            }

            if (! isset($item['method'])) {
                $item['method'] = 'admin';
            }
        }
        unset($item);

        return $apiValidation;
    }

    /**
     * 获取申请信息
     */
    protected function getApplyInformation(array $params): array
    {
        $userId = $params['user_id'] ?? 0;
        $contact = $params['contact'] ?? null;
        $organization = $params['organization'] ?? null;
        $validationType = $params['product']['validation_type'] ?? 'dv';
        $productType = $params['product']['product_type'] ?? 'ssl';

        // 判断是否需要组织信息
        // SMIME 根据子类型判断：sponsor 和 organization 需要组织
        // CodeSign 和 DocSign 始终需要组织
        // SSL 根据 validation_type 判断：OV/EV 需要组织
        $needOrganization = false;
        $needContact = false;

        if ($productType === 'smime') {
            $smimeType = CsrUtil::getSMIMEType($params['product'] ?? []);
            $needOrganization = in_array($smimeType, ['sponsor', 'organization']);
            // Certum API 要求所有 SMIME（除 mailbox）都需要 requestorInfo（联系人）
            $needContact = in_array($smimeType, ['individual', 'sponsor', 'organization']);
        } elseif (in_array($productType, ['codesign', 'docsign'])) {
            $needOrganization = true;
            $needContact = true;
        } else {
            // SSL: 根据 validation_type 判断
            $needOrganization = $validationType !== 'dv';
            $needContact = $validationType !== 'dv';
        }

        if ($params['action'] !== 'reissue') {
            // 处理组织信息
            if ($needOrganization) {
                // 前端可能传字符串形式的 ID，需要转换
                $orgId = is_numeric($organization) ? (int) $organization : 0;
                if ($orgId > 0) {
                    $orgModel = FindUtil::Organization($orgId, $userId);

                    // 当传了 organization 但没传 contact，自动从企业反查联系人
                    if ($needContact && empty($contact)) {
                        if (empty($orgModel->contact_id)) {
                            $this->error('请先为该企业绑定联系人');
                        }
                        $params['contact'] = $orgModel->contact_id;
                        $contact = $params['contact'];
                    }

                    $params['organization'] = FilterUtil::filterOrganization($orgModel->toArray());
                } elseif (! is_array($organization)) {
                    // 如果需要组织但没有提供，让验证器处理
                    unset($params['organization']);
                }
            } else {
                unset($params['organization']);
            }

            // 处理联系人信息
            if ($needContact) {
                // 前端可能传字符串形式的 ID，需要转换
                $contactId = is_numeric($contact) ? (int) $contact : 0;
                if ($contactId > 0) {
                    $params['contact'] = FindUtil::Contact($contactId, $userId);
                    $params['contact'] = FilterUtil::filterContact($params['contact']->toArray());
                } elseif (! is_array($contact)) {
                    // 如果需要联系人但没有提供，让验证器处理
                    unset($params['contact']);
                }
            } else {
                unset($params['contact']);
            }
        }

        return $params;
    }

    /**
     * 获取域名数量
     */
    protected function getDomainCount(string $domains): int
    {
        if ($domains === '') {
            $this->error('请提供至少一个域名');
        }

        return count(explode(',', trim($domains, ',')));
    }

    /**
     * 未支付订单扣费，返回数组 标记 扣费成功 扣费失败
     * 扣费完成后 更新已购买的域名数量 更新证书状态
     * 已购域名数量 = （已购域名数量，证书包含域名数量，产品最小域名数量） 中的最大值
     *
     * @throws Throwable
     */
    protected function charge(int $order_id, bool $create_commit_task = true): array
    {
        $result = [];
        DB::beginTransaction();
        try {
            // 查询订单并加锁 不查询产品 避免锁定产品
            // user 关系用闭包 FOR UPDATE：同一用户跨订单并发支付时序列化余额校验，
            // 否则 Transaction::creating 只锁 user 扣款不再校验 credit_limit，双笔订单会突破信用额度
            $order = Order::with([
                'user' => fn ($q) => $q->lockForUpdate(),
                'latestCert',
            ])
                ->whereHas('user')
                ->whereHas('latestCert')
                ->lock()
                ->find($order_id);

            if (! $order) {
                $this->error('订单或相关数据不存在');
            }

            $order->latestCert->status != 'unpaid' && $this->error('订单不是未支付状态');

            // 获取交易信息 订单金额为负数
            $transaction = OrderUtil::getOrderTransaction($order->toArray());

            // 管理员支付跳过余额检测，允许欠费支付
            $balance_after = bcadd((string) $order->user->balance, (string) $transaction['amount'], 2);
            if (bccomp($balance_after, (string) $order->user->credit_limit, 2) === -1) {
                Auth::guard('admin')->check() || $this->error('余额不足');
            }

            // 创建交易记录并扣费
            Transaction::create($transaction);

            // 更新已购域名数量 必须在获取交易信息之后执行 因为要根据已购域名数量组合交易备注
            $product = FindUtil::Product($order->product_id);

            $order->purchased_standard_count = max(
                $order->purchased_standard_count,
                $order->latestCert->standard_count,
                $product->standard_min
            );
            $order->purchased_wildcard_count = max(
                $order->purchased_wildcard_count,
                $order->latestCert->wildcard_count,
                $product->wildcard_min
            );
            $order->save();

            // 更新订单状态
            $order->latestCert->update(['status' => 'pending']);

            DB::commit();
            $result['status'] = 'success';
        } catch (ApiResponseException $e) {
            DB::rollback();
            $result['status'] = 'failed';
            $result['msg'] = $e->getApiResponse()['msg'] ?? '扣费失败';
            $errors = $e->getApiResponse()['errors'] ?? null;
            $errors && $result['errors'] = $errors;
        } catch (Throwable $e) {
            // catch Throwable（不是 Exception）确保 PHP Error / TypeError 也能正确 rollback。
            // 否则 TaskJob 外层事务下 SAVEPOINT 残留 → 外层 commit 时一并落库脏账。
            DB::rollback();
            $result['status'] = 'failed';
            $result['msg'] = $e->getMessage();
            if (config('app.debug')) {
                $result['errors'] = $e->getTrace();
            }
        }
        $result['order_id'] = $order_id;
        // 仅扣费成功才入队 commit task；失败时建 task 会让 worker 因 status=unpaid 报错，
        // 产生 failed_jobs 噪声 + 误报 Admin 告警邮件
        $create_commit_task && $result['status'] === 'success' && $this->createTask($order_id, 'commit');

        return $result;
    }

    /**
     * 计算订单周期结束时间
     *
     * >= 12 个月：每年固定 365 天，仅 12 个月且 plus 时额外 +30 天
     * < 12 个月：每月固定 30 天
     */
    protected function calculatePeriodTill(int $timestamp, int $months, int $plus): int
    {
        if ($months >= 12) {
            $days = (int) ($months / 12) * 365;
            if ($months === 12 && $plus) {
                $days += 30;
            }
        } else {
            $days = $months * 30;
        }

        return $timestamp + $days * 86400 - 1;
    }

    /**
     * 解析证书
     */
    protected function parseCert(string $cert): array
    {
        $parsed = openssl_x509_parse($cert);
        $parsed || $this->error('证书解析失败');

        $encryption = explode('-', $parsed['signatureTypeSN'] ?? '');

        // 从证书内容中获取公钥（SM2 在部分老 openssl 上取不到，守护防 openssl_pkey_get_details(false) 报错）
        $pubKeyId = openssl_pkey_get_public($cert);
        $keyDetails = $pubKeyId ? openssl_pkey_get_details($pubKeyId) : [];

        $data['issuer'] = $parsed['issuer']['CN'] ?? '';
        $data['serial_number'] = $parsed['serialNumberHex'] ?? '';
        $data['encryption_alg'] = $encryption[0];
        $data['encryption_bits'] = $keyDetails['bits'] ?? 0;
        $data['signature_digest_alg'] = $encryption[1] ?? '';
        $data['fingerprint'] = openssl_x509_fingerprint($cert) ?: '';
        $data['issued_at'] = $parsed['validFrom_time_t'] ?? 0;
        $data['expires_at'] = $parsed['validTo_time_t'] ?? 0;

        // SM2 国密证书：OpenSSL ≥1.1.1 正常解析即得 SM2-SM3/256；老版可能 signatureTypeSN=UNDEF、
        // 公钥取不到 bits，故 DER OID 兜底确认后固定 SM2/SM3/256（SM2 公钥恒 256 位），避免降级出错值。
        if ($this->isSM2Cert($cert, $data['encryption_alg'])) {
            $data['encryption_alg'] = 'SM2';
            $data['signature_digest_alg'] = 'SM3';
            $data['encryption_bits'] = 256;
        }

        return $data;
    }

    /**
     * 检测 SM2 国密证书：signatureTypeSN 已含 SM2 直接判定；否则解码 DER 查 SM2 签名/公钥 OID 兜底。
     */
    protected function isSM2Cert(string $cert, string $detectedAlg): bool
    {
        if (stripos($detectedAlg, 'SM2') !== false) {
            return true;
        }

        // 性能短路：signatureTypeSN 已明确解析出非 SM2 算法（RSA/ECDSA 等）时直接判否，无需解 DER；
        // 仅 SM2 在老 openssl 上 signatureTypeSN 才会是 UNDEF/空，需走下方 OID 兜底
        if ($detectedAlg !== '' && strcasecmp($detectedAlg, 'UNDEF') !== 0) {
            return false;
        }

        if (! preg_match('/-----BEGIN[^-]+-----(.+?)-----END/s', $cert, $m)) {
            return false;
        }

        $der = (string) base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($der === '') {
            return false;
        }

        // SM2 签名 OID 1.2.156.10197.1.501 / SM2 公钥 OID 1.2.156.10197.1.301
        return str_contains($der, hex2bin('06082A811CCF55018375'))
            || str_contains($der, hex2bin('06082A811CCF5501822D'));
    }

    /**
     * 删除 unpaid 状态的证书 并 恢复 renew,reissue 原证书的状态
     *
     * 并发安全：事务内锁定 order 行 + 锁内二次校验 status=unpaid。原实现事务外读 unpaid 后
     * 并发请求（charge）可把 status 改为 pending（已扣费），事务内仍执行删除 → 余额已扣
     * 但订单消失，留下"transaction_id 指向已删除 order"的资金错乱。
     *
     * @throws Throwable
     */
    public function delete(int $order_id): void
    {
        DB::transaction(function () use ($order_id) {
            $order = Order::with(['latestCert'])
                ->whereHas('latestCert')
                ->lock()
                ->find($order_id);

            if (! $order) {
                $this->error('订单或相关数据不存在');
            }

            $cert = $order->latestCert;
            $cert->status === 'unpaid' || $this->error('只有待支付状态的证书可以删除');

            if ($cert->last_cert_id) {
                // 尝试恢复旧证书状态
                $last_cert = Cert::where('id', $cert->last_cert_id)->first();
                if ($last_cert) {
                    $last_cert->status = 'active';
                    $last_cert->save();
                }

                if ($cert->action == 'reissue') {
                    // 重签：必须有旧证书才能恢复
                    $last_cert || $this->error('未找到上个证书');
                    $order->latest_cert_id = $last_cert->id;
                    $order->save();
                } elseif ($cert->action == 'renew') {
                    // 续费：删除新订单（无论旧证书是否存在）
                    $order->delete();
                }
            } else {
                // 新订单：直接删除
                $order->delete();
            }
            $cert->delete();
        });
    }

    /**
     * reissue 取消退款预检（只读，必须在上游 api->cancel 之前调用）。
     *
     * 两道前置校验，失败点全部前移到上游调用之前（收窄"上游已取消但本地回滚"窗口）：
     *   - F1 fail-safe：唯一索引 (type,transaction_id) WHERE type!='order' 每订单仅一条 cancel 流水。
     *     恢复旧证书 active 打开了"二次 reissue → 二次取消"路径，若不预检会在 api->cancel 成功【之后】
     *     撞唯一冲突 → 回滚 → 上游已取消、本地无退款、卡 cancelling。命中即报错转人工，杜绝该形态。
     *   - F4 金额校验：amount>0 时预取 last_transaction 并断言金额，失败前移到上游调用前。
     *
     * @return Transaction|null amount==0 返回 null（不建 cancel 流水，天然不触发 F1 唯一索引）
     */
    protected function prepareReissueRefund(Order $order, Cert $cert): ?Transaction
    {
        // F1：已存在 cancel 流水即拒绝（挡在 api->cancel 之前，转人工）
        $alreadyRefunded = Transaction::where('type', 'cancel')
            ->where('transaction_id', $order->id)
            ->exists();
        $alreadyRefunded && $this->error('该订单已存在取消退款流水，请人工处理');

        // amount>0：预取并校验上次交易（增量退款依据），失败前移到上游调用前
        if ($cert->amount > 0) {
            $lastTransaction = Transaction::where('transaction_id', $order->id)->orderBy('id', 'desc')->first();
            $lastTransaction || $this->error('未找到上次交易记录');
            bccomp('-'.$cert->amount, (string) $lastTransaction->amount, 2) !== 0
            && $this->error('上次交易记录金额错误');

            return $lastTransaction;
        }

        return null;
    }

    /**
     * reissue 取消增量退款（写）：只退当次 reissue 增量金额、counts 取 -last_transaction（增量非累计）。
     *
     * $lastTransaction=null（amount==0）整体跳过、不建 cancel 流水（外层守卫 `$cert->amount>0` 决定，
     * Transaction::creating 的 amount=0 短路为次层）；purchased_* 内存递减由调用方随分支 save 持久化。
     */
    protected function applyReissueIncrementRefund(Order $order, Cert $cert, ?Transaction $lastTransaction): void
    {
        if (! $lastTransaction) {
            return;
        }

        Transaction::create([
            'user_id' => $order->user_id,
            'type' => 'cancel',
            'transaction_id' => $order->id,
            'amount' => $cert->amount,
            'standard_count' => -$lastTransaction->standard_count,
            'wildcard_count' => -$lastTransaction->wildcard_count,
        ]);

        $order->purchased_standard_count -= $lastTransaction->standard_count;
        $order->purchased_wildcard_count -= $lastTransaction->wildcard_count;
    }

    /**
     * 未签发 reissue 取消的恢复：回切 latest_cert_id + 恢复旧证书 active + 删除 reissue cert。
     *
     * certs.last_cert_id 与 orders.latest_cert_id 均 UNIQUE，删除 reissue cert 释放槽位（标 cancelled
     * 会占死槽位锁死后续 reissue）。$order->save() 一并持久化 applyReissueIncrementRefund 的 purchased_* 内存递减。
     */
    protected function restoreReissuedCert(Order $order, Cert $cert): void
    {
        $order->latest_cert_id = $cert->last_cert_id;
        $order->save();

        $lastCert = Cert::where('id', $cert->last_cert_id)->first();
        $lastCert || $this->error('未找到上个证书');
        $lastCert->status = 'active';
        $lastCert->save();

        $cert->delete();
    }

    /**
     * 取消待提交订单
     *
     * 并发安全：事务内持 order 行级锁，与 commitCancel / batchCommitCancel /
     * V1 / V2 四个调用方通用；锁内重取 order + 二次校验状态，避免双重退费。
     *
     * @throws Throwable
     */
    public function cancelPending(int $order_id): void
    {
        // task → order 锁顺序：先锁 commit task 再锁 order 行，与
        // revokeCancel / commitCancel / TaskJob::handle 的锁顺序统一防死锁。
        // runTaskMutationTransaction 提供 attempts=3 死锁重试：闭包纯本地 task+order/cert 变更、无上游 HTTP；
        // 退款 Transaction::create 随回滚消失且有唯一索引兜底，$this->error() 抛 ApiResponseException（非并发错误）
        // 不被 DB::transaction 重试、直接传播触发回滚，语义与原手写 begin/commit/rollback 等价。
        $this->runTaskMutationTransaction(function () use ($order_id) {
            Task::lockForMutation($order_id, ['commit'])->get();

            $order = Order::with(['latestCert'])
                ->whereHas('latestCert')
                ->lock()
                ->find($order_id);

            if (! $order) {
                $this->error('订单或相关数据不存在');
            }

            $cert = $order->latestCert;

            // 锁内二次校验状态：并发 cancelPending/commitCancel 时第二个请求拿到锁后
            // 必须看到最新 status，否则仍会走退款分支产生第二条 cancel 交易
            if ($cert->status !== 'pending') {
                $this->error('订单状态不是待提交');
            }

            if ($cert->action === 'reissue') {
                // 与 cancelLocked reissue 分支共享同一组 helper（反模式 4/6 消对称副本）：
                // 增量退款口径 + 恢复旧证书。pending 恒未签发（未提交上游、api_id=null）→ 走恢复分支。
                // 重构后已覆盖路径行为不变 + 对称获得 F1 fail-safe：二次 reissue-cancel 一律转人工
                // （含 amount=0 —— exists() 预检先于 amount 守卫，无退款流水的二次取消同样报错，fail-safe 收紧）。
                $lastTransaction = $this->prepareReissueRefund($order, $cert);
                $this->applyReissueIncrementRefund($order, $cert, $lastTransaction);
                $this->restoreReissuedCert($order, $cert);
            } elseif ($cert->action === 'renew') {
                // renew 取消时恢复上个订单的证书状态
                if ($cert->last_cert_id) {
                    $last_cert = Cert::where('id', $cert->last_cert_id)->first();
                    if ($last_cert) {
                        $last_cert->status = 'active';
                        $last_cert->save();
                    }
                }
                // 获取交易信息并创建取消记录
                $transaction = OrderUtil::getCancelTransaction($order->toArray());
                Transaction::create($transaction);
                // 标记证书为 cancelled，保留 order 和 cert；
                // last_cert_id 置 null 释放 UNIQUE 槽位，否则源证书无法再次发起续费
                $cert->update(['status' => 'cancelled', 'last_cert_id' => null]);
            } else {
                // 获取交易信息
                $transaction = OrderUtil::getCancelTransaction($order->toArray());

                // 创建交易记录并退款
                Transaction::create($transaction);

                $cert->update(['status' => 'cancelled']);
                $order->update(['cancelled_at' => now()]);
            }

            // 事务内、task 锁保护下 DELETE，避免与 TaskJob::handle 竞争
            $this->deleteTask($order_id, 'commit');
        });
    }

    /**
     * 创建任务  (延迟 $later 秒)
     */
    public function createTask(int|string|array $orderIds, string $action, int $later = 0): void
    {
        $orderIds = is_array($orderIds) ? $orderIds : explode(',', (string) $orderIds);
        $orderIds = array_map('intval', $orderIds);

        $later = $action == 'cancel' ? max(120, $later) : $later;

        $data['action'] = $action;
        $data['started_at'] = now()->addSeconds($later);
        $data['status'] = 'executing';
        $data['source'] = getControllerCategory();

        foreach ($orderIds as $orderId) {
            // 检查是否已存在相同的执行中任务，避免重复创建
            $existingTask = Task::where('order_id', $orderId)
                ->where('action', $action)
                ->whereIn('status', ['executing'])
                ->first();

            if ($existingTask) {
                continue; // 跳过已存在的任务
            }

            $data['order_id'] = $orderId;
            $task = Task::create($data);
            // afterCommit 防止 worker 在外层事务提交前消费 job 导致 task 查无记录静默丢失
            // （默认 after_commit=false，配合 Redis 队列会让 revokeCancel/batchRevokeCancel 的 sync 任务丢失）
            try {
                if ($later > 0) {
                    // 队列定时比可执行时间多3秒 避免任务在可执行时间之前执行
                    TaskJob::dispatch(['id' => $task->id])->afterCommit()->delay(now()->addSeconds($later + 3))->onQueue(config('queue.names.tasks'));
                } else {
                    TaskJob::dispatch(['id' => $task->id])->afterCommit()->onQueue(config('queue.names.tasks'));
                }
            } catch (Throwable $e) {
                // T4：最小 Log 留痕。afterCommit 把 push 推迟到 commit 后回调执行，同步 try/catch 捕不到事务内
                // push 失败——本 catch 仅覆盖无事务上下文的同步 dispatch 失败；事务内遗留的 orphan executing task
                // 权威兜底 = T1 sweep-stale-tasks（30min 后重派）。不删 task、不改状态、不 rethrow。
                Log::error('createTask dispatch 失败', [
                    'order_id' => $orderId,
                    'task_action' => $action,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 删除任务
     */
    public function deleteTask(int|string|array $orderIds, string|array $action = ''): void
    {
        $orderIds = is_array($orderIds) ? $orderIds : explode(',', (string) $orderIds);
        $orderIds = array_map('intval', $orderIds);

        $action = is_array($action) ? $action : explode(',', $action);

        Task::whereIn('status', ['executing', 'stopped'])
            ->whereIn('order_id', $orderIds)
            ->when(! empty($action), function ($query) use ($action) {
                return $query->whereIn('action', $action);
            })
            ->delete();
    }
}
