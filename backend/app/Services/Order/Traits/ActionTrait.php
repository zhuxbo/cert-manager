<?php

declare(strict_types=1);

namespace App\Services\Order\Traits;

use App\Bootstrap\ApiExceptions;
use App\Exceptions\ApiResponseException;
use App\Jobs\TaskJob;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\Order\Utils\CsrUtil;
use App\Services\Order\Utils\DomainUtil;
use App\Services\Order\Utils\FilterUtil;
use App\Services\Order\Utils\FindUtil;
use App\Services\Order\Utils\OrderUtil;
use App\Services\Order\Utils\ValidatorUtil;
use App\Utils\Random;
use App\Utils\SnowFlake;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

trait ActionTrait
{
    /**
     * 缓存上次动作时间 限制 $expire 秒内不能重复该动作 返回剩余时间
     */
    protected function checkDuplicate(string $action, array $params, int $expire = 60): int
    {
        $paramsMd5 = md5(json_encode($params));
        $cacheKey = $action.'_'.$paramsMd5;

        // 获取上次缓存的时间戳
        $lastTime = Cache::get($cacheKey);

        // 提示在缓存剩余时间内不能重复提交
        if ($lastTime) {
            $remainingTime = $lastTime + $expire - time();

            // 确保返回值在 0-$expire 之间
            return max(0, min($remainingTime, $expire));
        }

        // 更新缓存时间
        try {
            Cache::set($cacheKey, time(), $expire);
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);
        }

        return 0;
    }

    /**
     * 初始化参数
     */
    protected function initParams(array $params): array
    {
        $params = OrderUtil::convertNumericValues($params);
        $params = FilterUtil::filterParamsField($params);

        $params['params'] = $params;

        if ($params['action'] == 'new') {
            $params['user_id'] = $this->userId ?: (int) ($params['user_id'] ?? 0);
            FindUtil::User($params['user_id'], true);

            $product = FindUtil::Product((int) ($params['product_id'] ?? 0), true);
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

            $whereUser = $this->userId ? ['user_id' => $this->userId] : [];
            $orderId = $params['order_id'];

            $order = Order::with(['product', 'latestCert'])
                ->whereHas('user')
                ->whereHas('product')
                ->whereHas('latestCert')
                ->where($whereUser)
                ->where('id', $orderId)
                ->first();

            $order || $this->error('订单或相关数据不存在');

            // active 和 expired 都可以重签
            if (! in_array($order->latestCert->status, ['active', 'expired']) && $params['action'] == 'reissue') {
                $this->error('订单状态错误');
            }

            // 只有 active 可以续费
            if ($order->latestCert->status != 'active' && $params['action'] == 'renew') {
                $this->error('订单状态错误');
            }

            // 订单已过期不可重签或续费
            $order->period_till < now() && $this->error('订单已过期');

            $params['user_id'] = $order->user_id;
            $params['product_id'] = $order->product_id;
            $params['last_cert_id'] = $order->latestCert->id;
            $params['last_cert'] = $order->latestCert->toArray();

            // 续费默认继承旧订单的自动续费设置（除非显式传入）
            if ($params['action'] === 'renew' && ! array_key_exists('auto_renew', $params)) {
                $params['auto_renew'] = (bool) $order->auto_renew;
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

        return $params;
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
        // 如果请求包含自动续费标记，则持久化到新订单
        array_key_exists('auto_renew', $params) && $order['auto_renew'] = (bool) $params['auto_renew'];

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
            // 转换域名为Unicode
            $params['domains'] = DomainUtil::convertToUnicodeDomains($params['domains'] ?? '');

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

            $cert['validation'] = $this->generateValidation($cert['dcv'], $cert['alternative_names']);
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

        if (strtolower($ca) === 'sectigo' && in_array($method, ['cname', 'http', 'https'])) {
            $dcv = $this->generateSectigoDcv($method, $csr, $unique_value);
        } else {
            $dcv = ['method' => $method];
        }

        return $dcv;
    }

    /**
     * 生成 Sectigo DCV
     */
    protected function generateSectigoDcv(string $method, string $csr, string $unique_value): array
    {
        $random = sprintf('%04x%04x', mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
        $tempDir = storage_path('temp-certs/'.$random);
        mkdir($tempDir, 0755, true);

        $csrPemFile = $tempDir.'/csr.pem';
        file_put_contents($csrPemFile, $csr);

        $csrDerFile = $tempDir.'/csr.der';

        // 构建 OpenSSL 命令行命令
        $cmd = 'openssl req -in '.escapeshellarg($csrPemFile).' -outform der -out '.escapeshellarg($csrDerFile);
        @exec($cmd.' > /dev/null 2>&1');

        $der = file_exists($csrDerFile) ? file_get_contents($csrDerFile) : null;

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
     */
    protected function generateValidation(array $dcv, string $domains): ?array
    {
        $method = strtolower($dcv['method']);
        $domains = explode(',', trim($domains, ','));

        foreach ($domains as $k => $domain) {
            if (! $domain) {
                continue;
            }

            $validation[$k] = ['domain' => $domain, 'method' => $method];

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
                foreach ($indexedDomain as $key => $value) {
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
                    $params['organization'] = FindUtil::Organization($orgId, $userId);
                    $params['organization'] = FilterUtil::filterOrganization($params['organization']->toArray());
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
            $whereUser = $this->userId ? ['orders.user_id' => $this->userId] : [];

            // 查询订单并加锁 不查询产品 避免锁定产品
            $order = Order::with(['user', 'latestCert'])
                ->whereHas('user')
                ->whereHas('latestCert')
                ->where($whereUser)
                ->lock()
                ->find($order_id);

            if (! $order) {
                $this->error('订单或相关数据不存在');
            }

            $order->latestCert->status != 'unpaid' && $this->error('订单不是未支付状态');

            // 获取交易信息 订单金额为负数
            $transaction = OrderUtil::getOrderTransaction($order->toArray());

            // 会员提交时验证余额是否足够
            $balance_after = bcadd((string) $order->user->balance, (string) $transaction['amount'], 2);
            if (bccomp($balance_after, (string) $order->user->credit_limit, 2) === -1) {
                $this->userId && $this->error('余额不足');
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
        } catch (Exception $e) {
            DB::rollback();
            $result['status'] = 'failed';
            $result['msg'] = $e->getMessage();
            if (config('app.debug')) {
                $result['errors'] = $e->getTrace();
            }
        }
        $result['order_id'] = $order_id;
        $create_commit_task && $this->createTask($order_id, 'commit');

        return $result;
    }

    /**
     * 获取证书有效期
     */
    protected function addMonths(int $timestamp, int $months): int
    {
        $date = new DateTime;
        $date->setTimestamp($timestamp);
        try {
            $date->modify("+$months months");
        } catch (Exception $e) {
            app(ApiExceptions::class)->logException($e);
            $this->error('证书有效期计算失败');
        }

        return $date->getTimestamp() - 1;
    }

    /**
     * 解析证书
     */
    protected function parseCert(string $cert): array
    {
        $parsed = openssl_x509_parse($cert);
        $parsed || $this->error('证书解析失败');

        $encryption = $parsed['signatureTypeSN'] ?? '';
        $encryption = explode('-', $encryption);

        // 从证书内容中获取公钥
        $pubKeyId = openssl_pkey_get_public($cert);
        // 从公钥中获取详细信息
        $keyDetails = openssl_pkey_get_details($pubKeyId);

        $data['issuer'] = $parsed['issuer']['CN'] ?? '';
        $data['serial_number'] = $parsed['serialNumberHex'] ?? '';
        $data['encryption_alg'] = $encryption[0];
        $data['encryption_bits'] = $keyDetails['bits'] ?? 0;
        $data['signature_digest_alg'] = $encryption[1] ?? '';
        $data['fingerprint'] = openssl_x509_fingerprint($cert) ?: '';
        $data['issued_at'] = $parsed['validFrom_time_t'] ?? 0;
        $data['expires_at'] = $parsed['validTo_time_t'] ?? 0;

        return $data;
    }

    /**
     * 删除 unpaid 状态的证书 并 恢复 renew,reissue 原证书的状态
     *
     * @throws Throwable
     */
    public function delete(int $order_id): void
    {
        $order = Order::with(['latestCert'])->whereHas('latestCert')->find($order_id);

        if (! $order) {
            $this->error('订单或相关数据不存在');
        }

        $cert = $order->latestCert;
        $cert->status === 'unpaid' || $this->error('只有待支付状态的证书可以删除');

        DB::beginTransaction();
        try {
            if ($cert->last_cert_id) {
                $last_cert = Cert::where('id', $cert->last_cert_id)->first();
                if ($last_cert) {
                    $last_cert->status = 'active';
                    $last_cert->save();
                    if ($cert->action == 'reissue') {
                        $order->latest_cert_id = $last_cert->id;
                        $order->amount = bcsub((string) $order->amount, $cert->amount, 2);
                        $order->save();
                    }
                    if ($cert->action == 'renew') {
                        $order->delete();
                    }
                }
            } else {
                $order->delete();
            }
            $cert->delete();
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 取消待支付订单
     *
     * @throws Throwable
     */
    public function cancelPending(int $order_id): void
    {
        $order = Order::with(['latestCert'])->whereHas('latestCert')->find($order_id);

        if (! $order) {
            $this->error('订单或相关数据不存在');
        }

        $cert = $order->latestCert;

        DB::beginTransaction();
        try {
            if ($cert->action === 'reissue') {
                if ($cert->amount > 0) {
                    $last_transaction = Transaction::where('transaction_id', $order_id)->orderBy('id', 'desc')->first();
                    $last_transaction || $this->error('未找到上次交易记录');
                    bccomp('-'.$cert->amount, (string) $last_transaction->amount, 2) !== 0
                    && $this->error('上次交易记录金额错误');

                    $transaction = [
                        'user_id' => $order->user_id,
                        'type' => 'cancel',
                        'transaction_id' => $order_id,
                        'amount' => $cert->amount,
                        'standard_count' => -$last_transaction->standard_count,
                        'wildcard_count' => -$last_transaction->wildcard_count,
                    ];
                    Transaction::create($transaction);
                    $order->amount = bcsub((string) $order->amount, (string) $cert->amount, 2);
                    $order->purchased_standard_count -= $last_transaction->standard_count;
                    $order->purchased_wildcard_count -= $last_transaction->wildcard_count;
                }

                // latestCert恢复为上个证书
                $order->latest_cert_id = $cert->last_cert_id;
                $order->save();

                $last_cert = Cert::where('id', $cert->last_cert_id)->first();
                $last_cert || $this->error('未找到上个证书');

                // 恢复上个证书的状态
                $last_cert->status = 'active';
                $last_cert->save();

                // 删除当前证书
                $cert->delete();
            } else {
                // 获取交易信息
                $transaction = OrderUtil::getCancelTransaction($order->toArray());

                // 创建交易记录并退款
                Transaction::create($transaction);

                $cert->update(['status' => 'cancelled']);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        $this->deleteTask($order_id, 'commit');
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
            if ($later > 0) {
                // 队列定时比可执行时间多3秒 避免任务在可执行时间之前执行
                TaskJob::dispatch(['id' => $task->id])->delay(now()->addSeconds($later + 3))->onQueue(config('queue.names.tasks'));
            } else {
                TaskJob::dispatch(['id' => $task->id])->onQueue(config('queue.names.tasks'));
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
