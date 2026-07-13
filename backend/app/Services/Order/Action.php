<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Exceptions\ApiResponseException;
use App\Http\Requests\Product\ImportCaProductRequest;
use App\Http\Requests\Product\UpdateRequest;
use App\Models\Callback;
use App\Models\Cert;
use App\Models\Chain;
use App\Models\DomainValidationRecord;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\Acme\Api\Api as AcmeApi;
use App\Services\Delegation\AutoDcvTxtService;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Api\Api;
use App\Services\Order\Traits\ActionBatchTrait;
use App\Services\Order\Traits\ActionCallbackTrait;
use App\Services\Order\Traits\ActionDocumentTrait;
use App\Services\Order\Traits\ActionFileTrait;
use App\Services\Order\Traits\ActionTrait;
use App\Services\Order\Utils\ChainVerifier;
use App\Services\Order\Utils\FindUtil;
use App\Services\Order\Utils\OrderUtil;
use App\Services\Order\Utils\VerifyUtil;
use App\Support\MutexLock;
use App\Traits\ApiResponse;
use App\Traits\RunsTaskMutationTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class Action
{
    use ActionBatchTrait;
    use ActionCallbackTrait;
    use ActionDocumentTrait;
    use ActionFileTrait;
    use ActionTrait;
    use ApiResponse;
    use MutexLock;
    use RunsTaskMutationTransaction;

    protected mixed $api;

    public function __construct()
    {
        $this->api = app(Api::class);
    }

    /**
     * 导入产品
     */
    public function importProduct(string $source = '', string $brand = '', string $api_id = '', string $type = 'new', bool $resilient = false): void
    {
        if ($resilient) {
            // resilient（cron）模式：本次运行前清空逐产品失败收集
            $this->importIssues = [];
        }

        $allProducts = [];

        // 查询传统 Order 产品
        try {
            $orderProducts = $this->api->getProducts($source, $brand, $api_id);
            if ($orderProducts['code'] === 1 && ! empty($orderProducts['data'])) {
                $allProducts = array_merge($allProducts, $orderProducts['data']);
            }
        } catch (ApiResponseException) {
            // 源不存在于 Order API，忽略
        }

        // 查询 ACME 产品
        try {
            $acmeProducts = (new AcmeApi)->getProducts($source, $brand, $api_id);
            if ($acmeProducts['code'] === 1 && ! empty($acmeProducts['data'])) {
                // ACME 端点返回的产品隐含 product_type=acme，注入默认值
                $acmeData = array_map(function (array $item) {
                    $item['product_type'] = $item['product_type'] ?? 'acme';
                    $item['validation_type'] = $item['validation_type'] ?? 'dv';

                    return $item;
                }, $acmeProducts['data']);
                $allProducts = array_merge($allProducts, $acmeData);
            }
        } catch (ApiResponseException) {
            // 源不存在于 ACME API，忽略
        }

        if (empty($allProducts)) {
            // resilient（cron）：空结果不抛异常（成功路径不产生异常，命令侧 catch 不会误捕），记 warning 返回
            if ($resilient) {
                Log::warning('[import_product] 未获取到产品，跳过来源', ['source' => $source]);

                return;
            }

            $this->error('没有获取到产品');
        }

        // 按 code（api_id）去重，后出现的覆盖前面的
        $unique = [];
        foreach ($allProducts as $item) {
            $unique[$item['code']] = $item;
        }

        $products = ['code' => 1, 'data' => array_values($unique)];

        foreach ($products['data'] as $item) {
            // resilient（cron）：单产品校验失败不中断整来源——收集 msg + Log::warning + continue
            // （反模式16：ApiResponseException::getMessage() 恒空，取 getApiResponse()['msg']）
            if ($resilient) {
                try {
                    $this->importProductItem($item, $source, $type);
                } catch (ApiResponseException $e) {
                    $this->importIssues[] = $e->getApiResponse()['msg'] ?? '';
                    Log::warning('[import_product] 单产品同步失败，跳过', [
                        'source' => $source,
                        'code' => $item['code'] ?? '',
                        'msg' => $e->getApiResponse()['msg'] ?? '',
                    ]);
                }

                continue;
            }

            $this->importProductItem($item, $source, $type);
        }

        // resilient 终态直接 return，不调 success()：success() 抛 code=1 异常，
        // 命令侧 catch(Throwable) 会把成功运行误报为失败（I1）
        if ($resilient) {
            return;
        }

        $this->success();
    }

    /**
     * resilient 模式下逐产品失败收集（供 ImportProductCommand 汇总 admin 告警）。
     *
     * @var array<int, string>
     */
    protected array $importIssues = [];

    /**
     * resilient 导入的逐产品失败摘要（人工路径不产生、恒为空）。
     *
     * @return array<int, string>
     */
    public function getImportIssues(): array
    {
        return $this->importIssues;
    }

    /**
     * 单产品导入处理体（update/create 分支），供人工路径与 resilient cron 复用（单一源，反模式4/6）。
     *
     * 校验失败经 $this->error() 抛 ApiResponseException：人工路径直接冒泡中断整来源；
     * resilient 路径由 importProduct 循环 catch 收集后 continue，不中断其余产品。
     *
     * @param  array<string, mixed>  $item  上游产品项（含 code）
     */
    protected function importProductItem(array $item, string $source, string $type): void
    {
        $item['source'] = $source;

        if (empty($item['code'])) {
            $this->error('产品 code 不能为空', $item);
        }

        $item['api_id'] = strval($item['code']);
        unset($item['code']);

        // 根据 api_id 查询产品
        $product = Product::where('source', $source)->where('api_id', $item['api_id'])->first();
        if ($product) {
            if ($type === 'update' || $type === 'all') {
                // 使用 UpdateRequest 验证规则
                $updateRequest = new UpdateRequest;
                $updateRequest->setProductId($product->id);
                $updateRequest->skipSslDomainValidation();

                // 过滤 null 值，避免上游未设置的字段覆盖本地数据
                $item = array_filter($item, fn ($value) => $value !== null);

                // 将 $item 数据合并到请求中，以便 rules() 能正确判断产品类型
                $updateRequest->merge($item);

                $validator = Validator::make($item, $updateRequest->rules());
                $validator->after(function ($validator) use ($updateRequest) {
                    $updateRequest->setValidator($validator);
                    $updateRequest->withValidator($validator);
                });

                if ($validator->fails()) {
                    $this->error('产品数据验证失败', $validator->errors()->toArray());
                }

                // 保留本地的 delegation 验证方法（上游 API 不包含此方法）
                if (isset($item['validation_methods']) && is_array($item['validation_methods'])) {
                    $localMethods = $product->validation_methods ?? [];
                    if (in_array('delegation', $localMethods) && ! in_array('delegation', $item['validation_methods'])) {
                        $item['validation_methods'][] = 'delegation';
                    }
                }

                // 保留本地已有的名称和备注，不被导入数据覆盖
                if (! empty($product->name)) {
                    unset($item['name']);
                }
                if (! empty($product->remark)) {
                    unset($item['remark']);
                }
                // 本地权重已人工设置（非默认 0）时，同步不覆盖
                if ((int) $product->weight !== 0) {
                    unset($item['weight']);
                }

                $product->update($item);
            }
        } else {
            if ($type === 'new' || $type === 'all') {
                $importRequest = new ImportCaProductRequest;
                $importRequest->merge($item);

                $validator = Validator::make($item, $importRequest->rules());
                $validator->after(function ($validator) use ($importRequest) {
                    $importRequest->setValidator($validator);
                    $importRequest->withValidator($validator);
                });

                if ($validator->fails()) {
                    $this->error('产品数据验证失败', $validator->errors()->toArray());
                }

                $item = $importRequest->prepareForCreate($item);
                Product::create($item);
            }
        }
    }

    /**
     * 申请证书
     *
     * @throws Throwable
     */
    public function new(array $params): void
    {
        $later = $this->checkDuplicate('new', [$params], 10);
        $later && $this->error('参数重复，请在 '.$later.' 秒后再提交申请');

        $params = $this->initParams($params);

        $orderData = $this->getOrder($params);
        $latestCert = $this->getCert($params);
        $orderData['amount'] = $latestCert['amount'] = OrderUtil::getLatestCertAmount($orderData, $latestCert, $params['product']);

        // 内层事务 attempts 固定 1：本闭包经 AutoRenew O1 / V1V2 一条龙外层事务嵌套为 savepoint，
        // 嵌套死锁不可事务级重试（同 commitLocked 先例——非 checkDuplicate，它在事务外）。
        // 有意不套 order_mutate 互斥：事务内零上游调用（上游 commit 由事务外承载）、与 commit/cancel
        // 状态互斥（renew 要求源证书 active，commit 要求 pending、cancel 要求 cancelling，同订单不可能同时满足），
        // 源订单行锁 + affected-rows 守卫即保证正确性（极窄竞态下至多一方拿到 active，另一方被拒）。
        $orderId = null;
        DB::transaction(function () use ($params, $orderData, $latestCert, &$orderId) {
            // renew：先锁源订单行，串行化毫秒级并发双开；plain new（无源订单）不锁、行为不变。
            if (($latestCert['action'] ?? '') === 'renew') {
                $sourceOrder = Order::whereHas('latestCert')->lock()->find($params['order_id']);
                $sourceOrder || $this->error('订单或相关数据不存在');
            }

            $order = Order::create($orderData);
            $latestCert['order_id'] = $order->id;

            if (($latestCert['action'] ?? '') === 'renew') {
                // 前驱翻转 CAS：保留 WHERE status='active' 取影响行数。命中 0 行 = 源证书已被并发
                // 续费/重签/取消抢先翻走 → 重读源证书权威状态分三态 error 后回滚（此时 pay 尚未执行、扣费从未发生）。
                $affected = Cert::where(['status' => 'active', 'order_id' => $params['order_id']])
                    ->update(['status' => 'renewed']);

                if ($affected === 0) {
                    $sourceStatus = Cert::where('id', $params['last_cert_id'] ?? 0)->value('status');
                    $this->error(match ($sourceStatus) {
                        'renewed' => '订单已续费',
                        'reissued' => '订单已重签',
                        'cancelled' => '订单已取消',
                        default => '订单状态已变化，请刷新重试',
                    });
                }
            }

            $cert = Cert::create($latestCert);
            $order->update(['latest_cert_id' => $cert->id]);

            $orderId = $order->id;
        }, 1);

        // success 抛 ApiResponseException 会触发回滚 → 必须在事务闭包外
        $this->success(['order_id' => $orderId]);
    }

    /**
     * 批量创建证书订单
     *
     * @throws Throwable
     */
    public function batchNew(array $params): void
    {
        $later = $this->checkDuplicate('batchNew', [$params], 10);
        $later && $this->error('参数重复，请在 '.$later.' 秒后再提交批量申请');

        $domains = explode(',', $params['domains'] ?? '');

        // SM2 能力探测前移到事务前（与 new/renew/reissue 对称）：批量同一 alg，探测一次即可
        // （BinaryLocator singleton，与 initParams 内探测共享缓存、零重复 fork）
        $this->guardSm2Capable($params['encryption']['alg'] ?? null);

        $orderIds = [];
        DB::beginTransaction();
        try {
            foreach ($domains as $item) {
                $params['domains'] = $item;

                $params = $this->initParams($params);

                $orderData = $this->getOrder($params);
                $latestCert = $this->getCert($params);

                $orderData['amount'] = $latestCert['amount'] = OrderUtil::getLatestCertAmount($orderData, $latestCert, $params['product']);

                $order = Order::create($orderData);
                $latestCert['order_id'] = $order->id;

                $cert = Cert::create($latestCert);
                $order->update(['latest_cert_id' => $cert->id]);

                $orderIds[] = $order->id;
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        $this->success(['order_ids' => $orderIds]);
    }

    /**
     * 续费
     *
     * @throws Throwable
     */
    public function renew(array $params): void
    {
        $later = $this->checkDuplicate('renew', [$params], 10);
        $later && $this->error('参数重复，请在 '.$later.' 秒后再提交续费');

        $this->new($params);
    }

    /**
     * 重签
     *
     * @throws Throwable
     */
    public function reissue(array $params): void
    {
        $later = $this->checkDuplicate('reissue', [$params], 10);
        $later && $this->error('参数重复，请在 '.$later.' 秒后再提交重签');

        $params = $this->initParams($params);

        // 锁前只读：amount 预检 + 产品禁用校验（位置不变）。organization 覆盖捕获后带入锁内持久化，
        // 不写回本无锁 $order（避免 stale 写）。
        $order = Order::find($params['order_id']);
        $order->organization = $params['organization'] ?? $order->organization;
        $organization = $order->organization;
        $latestCert = $this->getCert($params);

        $amount = OrderUtil::getLatestCertAmount($order->toArray(), $latestCert, $params['product']);

        // 产品禁用后 重签不能增加域名个数
        if (bccomp($amount, '0', 2) === 1) {
            $product = FindUtil::Product((int) $order->product_id);
            if ($product->status == 0) {
                $this->error('此订单重签不能增加域名个数');
            }
        }

        // 内层事务 attempts 固定 1：本闭包经 AutoRenew O1 / V1V2 一条龙外层事务嵌套为 savepoint，
        // 嵌套死锁不可事务级重试（同 commitLocked 先例——非 checkDuplicate，它在事务外）。
        // 有意不套 order_mutate 互斥：事务内零上游调用、与 commit/cancel 状态互斥（reissue 要求源证书
        // active/expired），本订单行锁 + affected-rows 守卫即够；reissue 源=本订单，与取消路径先锁同一 order 行天然串行。
        $orderId = null;
        DB::transaction(function () use ($params, $latestCert, $amount, $organization, &$orderId) {
            // 锁本订单行
            $order = Order::whereHas('latestCert')->lock()->find($params['order_id']);
            $order || $this->error('订单或相关数据不存在');

            // 锁内重读 latest_cert_id，与 initParams 捕获的基线 last_cert_id 比对：不等 = 并发 reissue 已推进接替，拒绝
            if ((int) $order->latest_cert_id !== (int) ($params['last_cert_id'] ?? 0)) {
                $this->error('订单已重签');
            }

            // 前驱翻转 CAS：WHERE id=前驱 AND status IN('active','expired') 取影响行数。命中 0 行 = 被并发抢先 →
            // 重读前驱权威状态分三态 error 后回滚（此时 pay 尚未执行、扣费从未发生）。
            $affected = Cert::where('id', $params['last_cert_id'] ?? 0)
                ->whereIn('status', ['active', 'expired'])
                ->update(['status' => 'reissued']);

            if ($affected === 0) {
                $predecessorStatus = Cert::where('id', $params['last_cert_id'] ?? 0)->value('status');
                $this->error(match ($predecessorStatus) {
                    'renewed' => '订单已续费',
                    'reissued' => '订单已重签',
                    'cancelled' => '订单已取消',
                    default => '订单状态已变化，请刷新重试',
                });
            }

            $order->organization = $organization;
            $latestCert['order_id'] = $order->id;
            $latestCert['amount'] = $amount;
            $latestCert['status'] = 'unpaid';

            // certs.last_cert_id UNIQUE 是物理底线：双开第二个 INSERT（last_cert_id 撞已占槽位）触 1062 回滚
            $cert = Cert::create($latestCert);
            $order->latest_cert_id = $cert->id;
            $order->save();

            // 删除旧的域名验证记录：reissue 复用同一 order_id，旧记录 created_at 为原签发时间，
            // 会让 ValidateCommand 的验证节奏（以 created_at 为锚）直接落 12 小时档。删除后
            // ValidateCommand 在新 cert 进 processing 时重建 created_at=now 的记录，恢复快档。
            // 落服务层单点覆盖 HTTP/API/Deploy/auto-reissue 全入口，与 OrderController::revalidate/updateDCV
            // 的重置语义对称；事务内删除，reissue 失败 rollback 一并回滚，无孤儿。
            DomainValidationRecord::where('order_id', $order->id)->delete();

            $orderId = $order->id;
        }, 1);

        // success 抛 ApiResponseException 会触发回滚 → 必须在事务闭包外
        $this->success(['order_id' => $orderId]);
    }

    /**
     * 支付订单
     *
     * @throws Throwable
     */
    public function pay(int|string|array $orderIds, bool $commit = true, bool $issueVerify = false): void
    {
        $orderIds = is_array($orderIds) ? $orderIds : explode(',', (string) $orderIds);
        $orderIds = array_map('intval', $orderIds);

        $maxUpstream = (int) config('batch.max_upstream');
        count($orderIds) > $maxUpstream && $this->error("订单数量不能超过{$maxUpstream}");

        $issueVerify && VerifyUtil::issueVerify($orderIds);

        if (count($orderIds) === 1) {
            $charge = $this->charge($orderIds[0], false);
            $charge['status'] === 'failed' && $this->error($charge['msg'], $charge['errors'] ?? null);

            // 只有一个订单的时候，支付成功后立即提交
            $commit && $this->commit($orderIds[0]);
        } else {
            $result = [];
            foreach ($orderIds as $key => $orderId) {
                $charge = $this->charge($orderId, $commit);
                $charge['status'] === 'failed' && $result[$key] = $charge;
            }

            $result && $this->error('批量支付失败', $result);
        }

        $this->success();
    }

    /**
     * 提交
     *
     * @throws Throwable
     */
    public function commit(int $orderId): void
    {
        // order 级互斥（方案 C）：同一订单 commit/cancel 串行，抢不到立即抛 MutationBusyException，
        // 不进 DB 行锁等待队列 —— 把「持锁久 + 并发排队 > innodb_lock_wait_timeout(50s) → 1205」根治
        $this->withMutex("order_mutate_$orderId", fn () => $this->commitLocked($orderId));
    }

    /**
     * 提交（锁内实现）—— 必须经 commit() 持有 order_mutate_{id} 互斥锁后调用
     *
     * @throws Throwable
     */
    private function commitLocked(int $orderId): void
    {
        $order = null;
        $result = null;

        // 用 DB::transaction 闭包而非手写 begin/commit/rollback：经 TaskJob 调用时本方法是嵌套事务，
        // 手写 catch 内的 DB::rollback() 在 1213 死锁（InnoDB 已释放所有 savepoint）时会抛 1305
        // 「SAVEPOINT does not exist」淹没原始死锁异常，致 TaskJob 识别不到并发错误、连接事务计数漂移、雪崩。
        // 闭包形态交 Laravel 统一处理：嵌套死锁直接抛 DeadlockException 到最外层（与 sync 一致）。
        // attempts 固定 1：上游下单 $this->api->$action() 在事务内，绝不能事务级重试（重复下单）；
        // 死锁牺牲点 lock() 在上游调用之前，job 级重试由 status!='pending' 守卫防重
        // （上游已建单后 save() 死锁的极窄窗口属传统 Order 既有行为，不在本次范围）。
        DB::transaction(function () use ($orderId, &$order, &$result) {
            // 事务查询不锁定产品
            $order = Order::with(['latestCert'])
                ->whereHas('user')
                ->whereHas('latestCert')
                ->lock()
                ->find($orderId);

            if (! $order) {
                $this->error('订单或相关数据不存在');
            }

            $order->latestCert->status != 'pending' && $this->error('订单状态不是待提交');

            $product = FindUtil::Product($order->product_id);

            $action = $order->latestCert->action;
            $data = $order->latestCert->toArray();
            $data['product_api_id'] = $product->api_id;
            $data['source'] = $product->source;
            $data['product_type'] = $product->product_type ?? 'ssl';
            $data['period'] = $order->period;
            $data['plus'] = $order->plus;
            $data['contact'] = $order->contact;
            $data['csr'] = $order->latestCert->csr;

            if ($data['product_type'] === 'smime') {
                $data['email'] = $order->latestCert->email;
            }

            if ($product->validation_type != 'dv') {
                $data['organization'] = $order->organization;
            }

            if ($order->latestCert->last_cert_id) {
                $lastCert = Cert::where('id', $order->latestCert->last_cert_id)->first();
                $data['last_api_id'] = $lastCert->api_id;

                // 上个证书的域名列表 用于重签时去除已有域名 部分 CA 重签仅接收新域名
                $data['last_cert'] = $lastCert->cert;
                $data['last_alternative_names'] = $lastCert->alternative_names;
            }

            $result = $this->api->$action($data);
            $apiId = $result['data']['api_id'] ?? '';

            // 返回 code 不等于 1 或者 api_id 为空
            if ($result['code'] !== 1 || ! $apiId) {
                $this->error($result['msg'] ?? '提交失败', $result['errors'] ?? null);
            }

            $order->latestCert->api_id = $apiId;
            $order->latestCert->cert_apply_status = $result['data']['cert_apply_status'] ?? 0;
            $order->latestCert->dcv = $this->mergeDcv($result['data']['dcv'] ?? null, $order->latestCert->dcv);
            $order->latestCert->validation = isset($result['data']['validation'])
                ? $this->mergeValidation($result['data']['validation'], $order->latestCert->validation ?? [])
                : $order->latestCert->validation;
            $order->latestCert->status = 'processing';
            $order->latestCert->save();
        }, 1);

        $this->success([
            'order_id' => $orderId,
            'cert_apply_status' => $result['data']['cert_apply_status'] ?? 0,
            'dcv' => $order->latestCert->dcv,
            'validation' => $order->latestCert->validation,
        ]);
    }

    /**
     * 同步证书信息
     */
    public function sync(int $orderId, bool $force = false, bool $suppressCallback = false): void
    {
        // 10秒内仅请求一次 API 避免重复请求
        if ($this->checkDuplicate('sync', [$orderId], 10)) {
            if ($force) {
                return;
            } else {
                $this->success();
            }
        }

        $order = Order::with(['user', 'product', 'latestCert'])
            ->whereHas('user')
            ->whereHas('product')
            ->whereHas('latestCert')
            ->find($orderId);

        if (! $order) {
            $this->error('订单或相关数据不存在');
        }

        $user = $order->user;
        $cert = $order->latestCert;

        $cert->status == 'unpaid' && $this->error('订单未支付');
        $cert->status == 'pending' && $this->error('订单未提交');

        if ($force) {
            $result = $this->api->get($orderId);

            // 这些订单状态可以强制更新 但状态不能改变, cancelling 可以改变
            if (in_array($cert->status, ['cancelled', 'revoked', 'renewed', 'reissued', 'failed'])) {
                unset($result['data']['status']);
            }
        } else {
            if (! in_array($cert->status, ['processing', 'approving', 'active'])) {
                $this->error('只有订单状态为待验证、待批准、已签发才能同步');
            }

            $result = $this->api->get($orderId);
        }

        $data = $result['data'] ?? [];

        // 合并 dcv（保留委托验证标记）
        $data['dcv'] = $this->mergeDcv($data['dcv'] ?? null, $cert->dcv);

        // 合并 validation
        $data['validation'] = isset($data['validation'])
            ? $this->mergeValidation($data['validation'], $cert->validation ?? [])
            : $cert->validation;

        is_array($data['contact'] ?? null) && $order->contact = array_merge($order->contact ?? [], $data['contact']);
        is_array($data['organization'] ?? null) && $order->organization = array_merge($order->organization ?? [], $data['organization']);
        unset($data['contact'], $data['organization']);

        if ($data['alternative_names'] ?? false) {
            // 重新获取证书域名个数
            $sanCount = OrderUtil::getSansFromDomains($data['alternative_names'], $order->product->gift_root_domain);

            $data['standard_count'] = $sanCount['standard_count'] ?? 0;
            $data['wildcard_count'] = $sanCount['wildcard_count'] ?? 0;

            // 如果是导入 初始化已购域名个数
            ! $order->purchased_standard_count && $order->purchased_standard_count = $data['standard_count'];
            ! $order->purchased_wildcard_count && $order->purchased_wildcard_count = $data['wildcard_count'];
        }

        if (! empty($data['cert'])) {
            // 解析证书
            $data = array_merge($data, $this->parseCert($data['cert']));
        }

        // 如果是新订单，设置订单有效期
        if (! $order->period_from && ($data['issued_at'] ?? null) && ($data['expires_at'] ?? null)) {
            // 即使传递的是时间戳 赋值给模型属性后会转换为时间格式
            $order->period_from = $data['issued_at'];
            $plus = ($order->product->product_type ?? '') === 'ssl' ? (int) $order->plus : 0;
            $periodTill = $this->calculatePeriodTill((int) $data['issued_at'], (int) $order->period, $plus);
            $order->period_till = max($data['expires_at'], $periodTill);
        }

        // 状态是否变化（事务外粗筛，仅用于决定是否进入 refundForSyncedCancel 分支；
        // 该分支自身锁 order 行并在锁内二次校验四条件，外层粗筛不会造成误退款）
        $hasStatusChanged = isset($data['status']) && $data['status'] !== $cert->status;

        // 同步退款分支：上游 cancelled + 过渡态 + new/renew + 开关开 → 专用 helper 处理退款
        if ($hasStatusChanged
            && ($data['status'] ?? null) === 'cancelled'
            && in_array($cert->status, ['processing', 'approving', 'cancelling'])
            && in_array($cert->action, ['new', 'renew'])
            && get_system_setting('site', 'autoRefundOnSync')
        ) {
            // helper 内自锁 order 行完成 cert.update / order.save / callback / deleteTask 所有副作用，提前结束 sync
            $this->refundForSyncedCancel($order, $data, $suppressCallback);
            // force 模式（V1/V2 ApiController::get 无 try-catch 直调 sync）必须沿用"不抛 success"契约，
            // 否则 success() 抛 ApiResponseException 会打断 get 使其返回空 {code:1}，而非订单数据；
            // 且无论是否 force 都要 return，避免 fall through 到下方第二个事务重复加锁处理已 cancelled 订单。
            $force || $this->success();

            return;
        }

        // F2-4 证书链签名校验门禁（锁外，openssl exec 绝不落 DB 行锁内）：
        // 上游返回 cert + intermediate + issuer 时，校验 intermediate 确实签发了 leaf，
        // 防坏链静默入 chains 表致严格客户端 TLS 握手失败。裁决作用于 $data，锁内 :630-633 写回照旧、无新增 exec。
        // 钉在 refundForSyncedCancel 分支（含 early-return）之后、runTaskMutationTransaction 之前：cancel 路径零 exec；
        // 门禁输入（cert/intermediate_cert/issuer/encryption_alg）在 parseCert 后已就位；锁内终态守卫只 unset
        // status+ENC_FIELDS（不含 intermediate_cert/issuer），裁决忠实存活到写回，TOCTOU 无洞。
        $this->guardIntermediateChain($order, $data);

        // 锁内重取 + 终态守卫 + 写回：慢 IO（上游 get）已在锁外完成，此事务只包状态判定副作用 + 写回。
        // 锁序 task→order：与 commitCancel(active)/revokeCancel 统一。controller 直调 sync 时无前置 task 锁，
        // 必须在锁 order 前先按 task→order 顺序锁住本订单的 commit/sync/revalidate 任务（与下面 deleteTask 删除范围一致），
        // 否则与 commitCancel(锁 sync,revalidate→order)/refundForSyncedCancel 反序，task 集合相交触发 InnoDB 死锁。
        // 经 TaskJob 调用时 TaskJob 已先持本 task 行锁（同事务 lockForUpdate 可重入），叠加后整体仍是 task→order，不反序。
        // 杀手场景：并发 cancel 在锁内退款并置 cancelled，本 sync 若用上游滞后的 active 覆盖会让已退款订单复活。
        $this->runTaskMutationTransaction(function () use ($orderId, $order, $cert, $user, $data, $suppressCallback) {
            // 锁顺序 1：先锁 commit/sync/revalidate task（与 deleteTask 删除范围、commitCancel 的 task→order 顺序一致）
            Task::lockForMutation($orderId, ['commit', 'sync', 'revalidate'])->get();

            // 锁顺序 2：再锁 order。
            $lockedOrder = Order::with(['latestCert'])
                ->whereHas('latestCert')
                ->lock()
                ->find($orderId);

            // 终态守卫必须按【写回目标 cert（外层 $cert，即本次 sync 的写回对象）自身】的权威 status 判定，
            // 而非 order 当前 latestCert：上游 get 是事务外慢 IO，期间并发重签会把 order.latest_cert_id
            // 切到新 cert B(pending)，若用 lockedOrder->latestCert(=B) 判定，旧 cert A 已 reissued 却被漏判，
            // 导致上游滞后 status 复活 A、并误删 B 的延时 commit task（重签静默失败）。
            // 故：order 当前 latestCert 仍是 A → 复用其锁内重载状态；已被切走 → 按 $cert->id 重读 A 自身权威状态。
            $lockedStatus = ($lockedOrder && (int) $lockedOrder->latest_cert_id === (int) $cert->id)
                ? $lockedOrder->latestCert->status
                : (Cert::where('id', $cert->id)->value('status') ?? $cert->status);

            // 终态守卫（泛化到所有路径）：本地已是终态时拒绝上游 status 覆盖，防滞后 active 复活已退款/已重签订单
            if (in_array($lockedStatus, ['cancelled', 'revoked', 'renewed', 'reissued', 'failed'], true)) {
                unset($data['status']);
                // 终态订单拒绝 enc 回写：上游滞后返回的 enc 不落已终结证书（防御纵深，避免死敏感数据）
                foreach (Cert::ENC_FIELDS as $encField) {
                    unset($data[$encField]);
                }
            }

            // 用锁内权威 status 重算状态变化，后续通知/回调/deleteTask 均以此为准
            $hasStatusChanged = isset($data['status']) && $data['status'] !== $lockedStatus;

            // 证书签发后发送通知邮件
            if ($hasStatusChanged && $data['status'] === 'active' && $user->email) {
                app(NotificationCenter::class)->dispatch(new NotificationIntent(
                    'cert_issued',
                    'user',
                    $user->id,
                    [
                        'order_id' => $order->id,
                        'email' => $user->email,
                    ]
                ));
            }

            // 签发 取消 吊销 发起回调（suppressCallback=true 跳过：下游经 V1/V2 get 主动 pull 触发同步，
            // get 已把新状态同步返回，无需再异步回调下游；deleteTask 不受影响，照常清理）
            if ($hasStatusChanged && in_array($data['status'] ?? '', ['active', 'cancelled', 'revoked'], true)) {
                if (! $suppressCallback) {
                    $callback = Callback::where('user_id', $order->user_id)->where('status', 1)->first();
                    $callback && $this->createTask($orderId, 'callback');
                }
                // 删除相关任务
                $this->deleteTask($orderId, 'commit,sync,revalidate');
            }

            $order->save();
            // 先把 issuer 落到模型，确保随后 update 触发 setIntermediateCertAttribute 时 issuer 已就位，
            // 非空 intermediate_cert 在本轮即写入 chains。fill 顺序不保证 issuer 早于 intermediate_cert，
            // 若 intermediate_cert 先 fill 则 mutator 见空 issuer 跳过写链，故此处强制 issuer 先落，签发轮确定性入 chains
            // （Cert::retrieved 缺链降级 approving→次轮补写对全算法适用、无 enc_cert 门控，是兜底而非本轮依赖；
            // 坏链已在上方锁外门禁 unset intermediate_cert，此处不会写入未过签名校验的链）。
            // 放在终态守卫之后安全：chains 是 CA 公共数据，setIntermediateCertAttribute 仅在该 issuer 无行时 create，
            // 不复活订单状态、不影响终态守卫语义。
            ! empty($data['issuer']) && $cert->issuer = $data['issuer'];
            // 写回目标 cert A（外层 $cert 即 A 同一行）：终态时上面已 unset $data['status']，
            // 故并发重签下不会复活 A；$cert 在 sync 内未被改动，update 仅写 $data 键，无 stale 回写风险。
            $cert->update($data);
        }); // attempts=3：controller 直调时本事务为最外层，死锁/锁超时自动重试（上游 get 在事务外，重试只重跑锁+写回，安全）；
        // 经 TaskJob 调用时为嵌套事务，Laravel 直接抛 DeadlockException 到外层，由 TaskJob job 级重试兜底

        // 强制更新不返回提示（success 抛 ApiResponseException 须在事务闭包外）
        $force || $this->success();
    }

    /**
     * F2-4 证书链签名校验门禁（sync 锁外调用）。
     *
     * 上游返回 cert + intermediate + issuer 时，校验 intermediate 确实签发了 leaf（唯一自动写链点是
     * Cert::setIntermediateCertAttribute mutator，无签名校验）。裁决作用于 $data（引用传入），
     * 锁内 $cert->update($data) 写回照旧、不新增任何 exec/慢 IO。fail-open：
     *  - 已有该 issuer 链 → 短路跳过 exec（已验过/人工管理，稳态命中率≈100%）。
     *  - 'ok'          → 不动 $data，锁内照常写链。
     *  - 'bad'         → unset intermediate_cert（落既有缺链→approving→重 sync 自愈闭环）+ Log::error + 告警。
     *  - 'unverifiable' → 放行写链 + Log::error + 告警（响亮暴露环境问题，不硬阻塞交付）。
     *
     * 告警携密安全：context 白名单仅 order_id + issuer(CN) + reason，绝不携 PEM；openssl 原始输出只进 Log::error。
     * 新增自动写链路径必须先经本门禁。
     *
     * @param  array<string, mixed>  $data  上游同步数据（引用：'bad' 时 unset intermediate_cert）
     */
    private function guardIntermediateChain(Order $order, array &$data): void
    {
        if (empty($data['cert']) || empty($data['intermediate_cert']) || empty($data['issuer'])) {
            return;
        }

        // 已有该 issuer 链（已验过/人工管理）→ 短路跳过 exec
        if (Chain::where('common_name', $data['issuer'])->exists()) {
            return;
        }

        $verifier = app(ChainVerifier::class);
        $verdict = $verifier->verifyIssued($data['cert'], $data['intermediate_cert'], $data['encryption_alg'] ?? '');

        if ($verdict === 'ok') {
            return;
        }

        $issuer = (string) $data['issuer'];

        if ($verdict === 'bad') {
            // 验签否决：拒写坏链，落缺链自愈路径（retrieved→approving→重 sync）
            Log::error('证书链签名校验失败：中间证书未签发叶证书，拒写 chains', [
                'order_id' => $order->id,
                'issuer' => $issuer,
                'openssl_output' => $verifier->lastOutput(),
            ]);
            unset($data['intermediate_cert']);

            app(SystemAlert::class)->send(
                'chain_verify',
                '证书链签名校验失败（坏链已拒写）',
                "订单 #{$order->id} 上游返回的中间证书未签发叶证书，已拒绝写入 chains，订单将转 approving 等待重新同步。",
                ['order_id' => $order->id, 'issuer' => $issuer, 'reason' => 'chain_verify_failed'],
                'chain_bad:'.$issuer,
                24,
                'bad'
            );

            return;
        }

        // 'unverifiable'：运行性失败（openssl 不可用/输出不可解析）→ fail-open 放行写链 + 响亮告警
        Log::error('证书链签名校验无法执行（openssl 不可用或输出不可解析），已 fail-open 放行写链', [
            'order_id' => $order->id,
            'issuer' => $issuer,
            'openssl_output' => $verifier->lastOutput(),
        ]);

        app(SystemAlert::class)->send(
            'chain_verify',
            '证书链签名校验无法执行（已放行写链）',
            "订单 #{$order->id} 的证书链签名校验无法执行（openssl 不可用或输出异常），已按 fail-open 放行写入 chains。"
                .'故障期间新写入的证书链建议人工复核（Admin 链管理）。',
            ['order_id' => $order->id, 'issuer' => $issuer, 'reason' => 'openssl_unavailable'],
            'chain_unverifiable',
            24,
            'unavailable'
        );
    }

    /**
     * 过户
     */
    public function transfer(array $params): void
    {
        $params = OrderUtil::convertNumericValues($params);

        FindUtil::User((int) $params['user_id'], true);

        $order = Order::find($params['order_id']);
        $order->user_id = $params['user_id'];
        $order->save();

        $this->success();
    }

    /**
     * 导入证书 必须先导入新证书，再导入替换或重签的证书
     *
     * @throws Throwable
     */
    public function input(array $params): void
    {
        $params = OrderUtil::convertNumericValues($params);

        FindUtil::User((int) $params['user_id'], true);

        $product = FindUtil::Product((int) $params['product_id']);
        in_array($params['period'], $product->periods) || $this->error('有效期错误');

        $certData['api_id'] = $params['api_id'] ?? '';
        $certData['action'] = $params['action'] ?? 'new';
        $certData['channel'] = $params['channel'] ?? 'admin';
        $certData['common_name'] = $params['common_name'] ?? '';
        $certData['csr'] = $params['csr'] ?? null;
        $certData['private_key'] = $params['private_key'] ?? null;
        $certData['status'] = 'approving';

        if (in_array($certData['action'], ['new', 'renew'])) {
            $orderData['user_id'] = (int) $params['user_id'];
            $orderData['product_id'] = (int) $params['product_id'];
            $orderData['period'] = (int) $params['period'];
            $orderData['brand'] = $product->brand;
        }

        if ($certData['action'] == 'reissue') {
            $certData['order_id'] = $params['order_id'] ?? null;
        }

        DB::beginTransaction();
        try {
            $cert = Cert::where('api_id', $params['api_id'])->first();
            if ($cert) {
                $cert->fill($certData);
                $cert->save();
                $order = FindUtil::Order($cert->order_id);
                if (! empty($orderData)) {
                    $order->fill($orderData);
                    $order->save();
                }
            } else {
                $cert = Cert::create($certData);

                if (isset($orderData)) {
                    $order = Order::create($orderData);
                } else {
                    $order = FindUtil::Order($cert->order_id);
                    // 导入订单只有 reissue 必需 last_cert_id
                    $cert->last_cert_id = $order->latest_cert_id;
                }

                $cert->order_id = $order->id;
                $cert->save();

                $order->latest_cert_id = $cert->id;
                $order->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        isset($order->id) && $this->sync($order->id, true);
        $this->success();
    }

    /**
     * 重新验证
     */
    public function revalidate(int $orderId): void
    {
        $later = $this->checkDuplicate('revalidate', [$orderId]);
        $later && $this->error('请在 '.$later.' 秒后再提交验证');

        $order = FindUtil::Order($orderId);
        $cert = $order->latestCert;
        $cert->status != 'processing' && $this->error('订单状态只有是待验证才能重新验证');

        // 对于需要验证内容的方法，优先检查 validation 是否就绪
        $method = $cert->dcv['method'] ?? '';
        if (in_array($method, ['txt', 'cname', 'file', 'http', 'https'], true)) {
            if (! $this->isValidationReady($cert->validation ?? null, $method)) {
                $this->sync($orderId, true);
                $cert->refresh();

                // 同步后仍未就绪则报错
                if (! $this->isValidationReady($cert->validation ?? null, $method)) {
                    $this->error('验证记录同步中，请稍后再试');
                }
            }
        }

        // 创建 delegation 任务处理 TXT 记录写入（SMIME/CodeSign/DocSign 没有 DCV）
        if (isset($cert->dcv['method']) && $cert->dcv['method'] === 'txt' && ($cert->dcv['is_delegate'] ?? false)) {
            // 检测 validation 是否为空
            $isEmpty = empty($cert->validation);

            if (! $isEmpty) {
                // 检测是否需要处理委托
                $autoDcvService = new AutoDcvTxtService;
                $shouldProcessDelegation = $autoDcvService->shouldProcessDelegation($order);

                // 创建委托任务
                $shouldProcessDelegation && $this->createTask($orderId, 'delegation');
            }
        }

        $this->createTask($orderId, 'sync', 30);

        $this->api->revalidate($orderId);

        $this->success();
    }

    /**
     * 处理委托解析（自动写入TXT记录）
     */
    public function delegation(int $orderId): void
    {
        $order = FindUtil::Order($orderId);

        // 使用 AutoDcvTxtService 处理委托解析
        $autoDcvService = new AutoDcvTxtService;
        $isDelegated = $autoDcvService->handleOrder($order);

        if (! $isDelegated) {
            $this->error("订单 #$orderId 委托解析处理失败或未命中配置");
        }

        $this->success();
    }

    /**
     * 修改验证方法
     */
    public function updateDCV(int $orderId, string $method): void
    {
        $later = $this->checkDuplicate('updateDCV', [$orderId]);
        $later && $this->error('请在 '.$later.' 秒后再提交修改');

        $order = FindUtil::Order($orderId);
        $cert = $order->latestCert;

        // 验证域名和验证方法的兼容性
        $this->validateDomainValidationCompatibility($cert->alternative_names, $method);

        if (in_array($cert->status, ['unpaid', 'pending'])) {
            $cert->dcv = $this->generateDcv($order->product->ca, $method, $cert->csr, $cert->unique_value ?? '');
            $cert->validation = $this->generateValidation($cert->dcv, $cert->alternative_names, $order->user_id);
        } elseif ($cert->status === 'processing') {
            // 如果从 delegation 切换到其他方法，需要重新生成本地 dcv（会更新 is_delegate）
            $newDcv = $this->generateDcv($order->product->ca, $method, $cert->csr, $cert->unique_value ?? '');
            // 传递给上游 API 的方法应该是 txt 而不是 delegation（上游不认识 delegation）
            $apiMethod = $method === 'delegation' ? 'txt' : $method;
            $result = $this->api->updateDCV($orderId, $apiMethod);
            // 使用新生成的 dcv（包含正确的 is_delegate 标记），然后合并 API 返回的 dns/file 信息
            $cert->dcv = $this->mergeDcv($result['data']['dcv'] ?? null, $newDcv);
            // 优先使用 API 返回的 validation（多域名场景每个域名有独立 token），合并本地委托字段
            $localValidation = $this->generateValidation($cert->dcv, $cert->alternative_names, $order->user_id) ?? [];
            $cert->validation = isset($result['data']['validation'])
                ? $this->mergeValidation($result['data']['validation'], $localValidation)
                : $localValidation;
        } else {
            $this->error('此订单状态不支持修改验证方法，请刷新页面查看');
        }

        $cert->save();

        // 切换到委托验证时，创建 delegation 任务写入 TXT 记录
        if (($cert->dcv['is_delegate'] ?? false) && $cert->status === 'processing') {
            $autoDcvService = new AutoDcvTxtService;
            if ($autoDcvService->shouldProcessDelegation($order)) {
                $this->createTask($orderId, 'delegation');
            }
        }

        // $result 仅在 processing 分支赋值，其他分支由 ?? 兜底
        $this->success([
            'dcv' => $result['data']['dcv'] ?? $cert->dcv,
            'validation' => $result['data']['validation'] ?? $cert->validation,
        ]);
    }

    /**
     * 提交取消
     *
     * 并发安全：processing/approving/active 分支在事务内持 order 行级锁，
     * 与 cancel TaskJob / revokeCancel / batchCommitCancel 串行化；锁内二次
     * 校验 latestCert.status，避免"双重 cancelling"或"撤回竞争"产生的脏状态。
     * unpaid/pending 分支委派给 delete/cancelPending，其自身已持锁。
     *
     * @throws Throwable
     */
    public function commitCancel(int $orderId): void
    {
        $order = FindUtil::Order($orderId);
        $product = FindUtil::Product($order->product_id);

        // 待支付 待提交 订单不限制取消时间
        $status = $order->latestCert->status;
        $status === 'unpaid' && $this->delete($orderId);
        $status === 'pending' && $this->cancelPending($orderId);

        $status === 'cancelled' && $this->error('订单已取消');
        $status === 'expired' && $this->error('订单已过期');
        $status === 'renewed' && $this->error('订单已续期');
        $status === 'reissued' && $this->error('订单已重签');
        $status === 'cancelling' && $this->error('订单取消中');
        $status === 'revoked' && $this->error('订单已吊销');
        $status === 'failed' && $this->error('订单已失败');

        if (in_array($status, ['processing', 'approving', 'active'])) {
            $this->runTaskMutationTransaction(function () use ($orderId, $product) {
                // 锁顺序 1：先锁 sync/revalidate task（与 TaskJob::handle 的 task→order 顺序一致，避免死锁）
                Task::lockForMutation($orderId, ['sync', 'revalidate'])->get();

                // 锁顺序 2：再锁 order
                $order = Order::with(['latestCert'])
                    ->whereHas('latestCert')
                    ->lock()
                    ->find($orderId);

                if (! $order) {
                    $this->error('订单或相关数据不存在');
                }

                // 锁内二次校验状态，拦住并发 commitCancel / revokeCancel 竞争
                $lockedStatus = $order->latestCert->status;
                in_array($lockedStatus, ['processing', 'approving', 'active'])
                || $this->error('订单状态不是可取消状态');

                $refundPeriod = $product->refund_period ?? 0;
                $order->created_at->timestamp < time() - 86400 * $refundPeriod
                && $this->error("订单已超过 $refundPeriod 天不能取消");

                // 2分钟后取消
                $order->latestCert->update(['status' => 'cancelling']);
                $this->deleteTask($orderId, 'sync,revalidate');
                $this->createTask($orderId, 'cancel');
            });
        }

        $this->success();
    }

    /**
     * 手工标记订单为「已续费」（renewed 终态）
     *
     * 用于用户在别处已续费、不想再被本系统自动续费/到期提醒的场景。
     * renewed 是终态：标记后该订单不再自动续费、不再到期提醒；sync 终态守卫（::577）
     * 防止上游滞后状态把已 renewed 的订单复活为 active。
     *
     * 并发安全：与 commitCancel/cancel/sync 串行化（共用 order 行锁），防止
     * 「标记 renewed 时订单正被 sync/cancel 改状态」的并发错乱。本路径不涉及资金流水
     * （不建 Transaction、不改 balance），故无需锁 user 行，仅锁 order/cert。
     *
     * 校验全部放在【锁内二次校验】（锁外校验会被并发绕过）：
     *   - 仅 active 证书可标记（须有一张签发成功的当前证书）；
     *   - 仅【订单】到期前 30 天内且未过期可标记 —— 按 orders.period_till 判定，
     *     与手工续费 gate（ActionTrait 的 period_till>now+30 报错）及前端 gate 对齐。
     *     语义：用户另开新订单续了证书 → 标旧订单 renewed 止到期通知；"原订单内重签"
     *     靠重签后 expires_at 推远自动止通知、无需本操作。不用 cert.expires_at：多年期/
     *     中途重签订单证书将到期但订单未到期，会被自动重签接管（ExpireCommand 已排除其
     *     到期通知），不应允许标记。
     */
    public function markRenewed(int $id): void
    {
        DB::transaction(function () use ($id) {
            // 锁 order（同 commitCancel 的项目约定：whereHas('latestCert')->lock()）。
            // UserScope 全局作用域在此生效：User 端非本人订单会被滤掉 → find 返回 null。
            $order = Order::with(['latestCert'])
                ->whereHas('latestCert')
                ->lock()
                ->find($id);

            if (! $order) {
                $this->error('订单不存在或无权操作');
            }

            // 锁内二次校验，拦住并发改状态（sync/cancel）后的窗口竞争
            $cert = $order->latestCert;
            $cert->status !== 'active' && $this->error('仅签发成功的证书可标记为已续费');

            // 按【订单】到期时间 period_till 判定（非单张证书 expires_at）：与手工续费窗口一致
            $periodTill = $order->period_till;
            if (! $periodTill || $periodTill->isPast() || $periodTill->gt(now()->addDays(30))) {
                $this->error('仅订单到期前 30 天内且未过期可标记为已续费');
            }

            $cert->update(['status' => 'renewed']);
        });

        // success 必须在事务闭包之外：它抛 ApiResponseException 会触发回滚
        $this->success();
    }

    /**
     * 撤回取消
     *
     * 设计说明：状态统一恢复为 approving，同时创建 sync 任务，
     * 同步一次即可从上游恢复正确状态（processing/approving/active）
     *
     * 并发安全：按 "task → order" 的统一锁顺序拿锁（与 TaskJob::handle 一致），避免死锁。
     * 若 TaskJob 正在 cancel 内，此处 task lockForUpdate 会阻塞至 TaskJob 提交，
     * 拿到 task 锁后再锁 order，此时 latestCert.status 已非 cancelling，校验报错退出。
     * 避免"撤回成功 + 钱已退 + 上游已吊销"的资金/状态三重损害与 InnoDB 死锁回滚。
     */
    public function revokeCancel(int $orderId): void
    {
        $this->runTaskMutationTransaction(function () use ($orderId) {
            // 锁顺序 1：先锁 task（与 TaskJob 一致，避免 task↔order 循环等待死锁）
            Task::lockForMutation($orderId, ['cancel'])->get();

            // 锁顺序 2：再锁 order
            $order = Order::with(['latestCert'])
                ->whereHas('latestCert')
                ->lock()
                ->find($orderId);

            if (! $order) {
                $this->error('订单或相关数据不存在');
            }

            $order->latestCert->status !== 'cancelling' && $this->error('订单不在取消中状态');

            $this->deleteTask($orderId, 'cancel');
            $order->latestCert->update(['status' => 'approving']);
            $this->createTask($orderId, 'sync');
        });

        $this->success();
    }

    /**
     * 取消证书
     *
     * @throws Throwable
     */
    public function cancel(int $orderId): void
    {
        // order 级互斥（方案 C）：与 commit 共用 order_mutate_{id}，使 commit 执行期 cancel 抢不到
        // 立即失败、不排队 —— 既根治 1205，又让 commit/cancel 应用层串行（配合锁内原子性防孤儿单）
        $this->withMutex("order_mutate_$orderId", fn () => $this->cancelLocked($orderId));
    }

    /**
     * 取消（锁内实现）—— 必须经 cancel() 持有 order_mutate_{id} 互斥锁后调用
     *
     * @throws Throwable
     */
    private function cancelLocked(int $orderId): void
    {
        // 同 commit：改 DB::transaction 闭包，避免手写 rollback 在嵌套死锁时抛 1305 淹没死锁异常 / 计数漂移。
        // attempts 固定 1：上游 cancel + 退款 Transaction::create 在事务内，绝不能事务级重试（重复取消/退款）；
        // 死锁牺牲点 lock() 在上游调用之前，job 级重试由锁内 status 校验 + transactions 唯一索引兜底防重。
        DB::transaction(function () use ($orderId) {
            // 事务查询不锁定产品
            $order = Order::with(['latestCert'])
                ->whereHas('user')
                ->whereHas('latestCert')
                ->lock()
                ->find($orderId);

            if (! $order) {
                $this->error('订单或相关数据不存在');
            }

            $product = FindUtil::Product($order->product_id);

            // 退款期严格以 created_at 计算，超过则不退款（卡 cancelling 为预期行为）
            $order->created_at->timestamp < time() - 86400 * $product->refund_period
            && $this->error('订单已超过'.$product->refund_period.'天');

            $order->latestCert->status === 'cancelled' && $this->error('订单已取消');
            $order->latestCert->status != 'cancelling' && $this->error('订单状态不是取消中');

            $cert = $order->latestCert;
            $isReissue = $cert->action === 'reissue';

            // F1+F4 前置只读预检（reissue 专属，必须在 api->cancel 之前：失败即回滚且上游从未被调用）。
            // 挡住"二次 reissue-cancel 在上游取消成功后撞 cancel 唯一索引 → 卡 cancelling 无退款"形态。
            $lastTransaction = $isReissue ? $this->prepareReissueRefund($order, $cert) : null;

            try {
                $this->api->cancel($orderId);
            } catch (ApiResponseException $e) {
                $errors = $e->getApiResponse()['errors'] ?? null;
                $msg = $e->getApiResponse()['msg'] ?: 'CA取消失败';
                $this->error($msg, $errors);
            }

            if ($isReissue) {
                // 语义1：增量退款——对所有 reissue 取消入口生效（Purge/手动 commitCancel/batchCommitCancel）。
                // reissue 只更 cert.amount 不更 order.amount、交易含 原始new+reissue增量 两笔，若走
                // getCancelTransaction 求和会超退原始全额（latent over-refund）。改增量口径只退当次 reissue。
                // $lastTransaction=null（amount=0）跳过退款块。
                $this->applyReissueIncrementRefund($order, $cert, $lastTransaction);

                // 语义2：订单终结——已提交上游（processing/approving，含已签发 active）取消一律不恢复前驱。
                // 上游各家取消政策不一，恢复前驱 active 存在「被上游 supersede 后本地状态与实际不符」风险，
                // 故 reissue cert 置 cancelled、前驱不恢复/不回切/不删（前驱保持 reissued 终态）。
                // last_cert_id 保留不置 null：订单经 latestCert=cancelled 终结（重签/续费/取消前置门齐闭）后
                // 该 UNIQUE 槽位对前驱 inert——无任何路径能再指向它，保留以维持「cancelled 接替 → reissued 前驱」取证链。
                // 恢复窗口仅剩 unpaid（delete）/pending（cancelPending，恒未签发）；此处不再按 issued_at 分恢复分支。
                $cert->update(['status' => 'cancelled']);
                $order->cancelled_at = now();
                $order->save();
            } else {
                // new/renew：原逻辑逐字不变（getCancelTransaction 求和单笔口径，触点唯一、零影响）
                //
                // 获取交易信息
                $transaction = OrderUtil::getCancelTransaction($order->toArray());

                // 创建交易记录并退款
                //
                // 防双退底线（与 refundForSyncedCancel 注释互引，二者协作不可单独删除）：
                //   - 锁内 status 校验：上面「status===cancelled → error('订单已取消')」是第一道。
                //     refundForSyncedCancel 已退款并置 cancelled 后刻意保留的残留 cancel task，被
                //     TaskJob 唤醒调用本方法时，会在锁内撞上该校验抛错回滚，不会走到此处二次退款。
                //   - DB 唯一索引 transactions_dedup_unique（type,transaction_id WHERE type!='order'，
                //     迁移 2026_05_07_120000）是物理底线：即便锁校验被某条并发路径绕过，此 INSERT 也会
                //     因唯一冲突抛错回滚。应用层校验仅是预检，删除唯一索引会破坏底线。
                Transaction::create($transaction);

                // 更新订单状态
                $order->latestCert->update(['status' => 'cancelled']);

                // 保存取消时间
                $order->update(['cancelled_at' => now()]);
            }

            // 接替单（续费/重签，last_cert_id 非空）取消后：前驱证书（renewed/reissued 终态）就此脱离
            // cert_expire / AutoRenew / cert_renew_stalled 三重监控——原证书物理上仍在有效期却不再收到任何
            // 续期/到期提醒。发一次性通知告知用户「接替单已取消、原证书不再受续期监控，如需继续使用请手动续期」。
            // plain new（last_cert_id=null）无前驱，不发。afterCommit 由 NotificationCenter 内部
            // NotificationJob->afterCommit() 保证（本闭包经 TaskJob 外层事务嵌套时同样只在最外层提交后入队）。
            if ($cert->last_cert_id) {
                $this->dispatchRenewCancelledNotification($order, $cert);
            }
        }, 1);

        $this->success();
    }

    /**
     * 接替单取消一次性通知（cert_renew_cancelled，renew+reissue 对称）。
     *
     * 携密纪律：context 仅白名单标量（前驱域名 / 到期日 / 订单号 / 动作类型中文文案），绝不 toArray 整包；
     * 收件人 = 订单所属 user。前驱终态不再变动，故取消现场直接读值塞入、Builder 事件驱动无需重查。
     */
    private function dispatchRenewCancelledNotification(Order $order, Cert $cert): void
    {
        $predecessor = Cert::where('id', $cert->last_cert_id)->first();
        if (! $predecessor) {
            return;
        }

        app(NotificationCenter::class)->dispatch(new NotificationIntent(
            'cert_renew_cancelled',
            'user',
            (int) $order->user_id,
            [
                'common_name' => (string) $predecessor->common_name,
                'expires_at' => $predecessor->expires_at?->format('Y-m-d') ?? '',
                'order_id' => (int) $order->id,
                'action' => $cert->action === 'renew' ? '续费' : '重签',
            ]
        ));
    }

    /**
     * 同步发现上游已取消时的退款入口
     *
     * 调用前提：sync 已校验触发四条件（status=cancelled + 过渡态 + new/renew + 开关开）。
     * 与 cancel() 的区别：不调用上游 api->cancel（上游已是 cancelled 态）；不检查 refund_period（以上游状态为权威）。
     *
     * 锁序 task→order：与 commitCancel(active)/revokeCancel/sync 统一。本方法由 sync 调用，
     * 同样要删除 commit/sync/revalidate task，故在锁 order 前先按 task→order 顺序锁住这批 task
     * （与下面 deleteTask 删除范围一致），避免与 commitCancel 反序触发 InnoDB 死锁。
     *
     * cancel task 残留说明：当 cert.status=cancelling 时可能存在 cancel task。
     * 此处仍不主动删除 cancel task（仅删 commit/sync/revalidate），与既有行为保持一致，
     * 不扩大本次修复范围。安全性：残留的 cancel task 被 TaskJob 调用 Action::cancel() 时，
     * 锁内检查 status===cancelled 会抛错回滚，不会重复退款。
     *
     * @throws Throwable
     */
    private function refundForSyncedCancel(Order $order, array $certData, bool $suppressCallback = false): void
    {
        $this->runTaskMutationTransaction(function () use ($order, $certData, $suppressCallback) {
            // 锁顺序 1：先锁 commit/sync/revalidate task（与 deleteTask 删除范围、commitCancel 的 task→order 顺序一致）
            Task::lockForMutation($order->id, ['commit', 'sync', 'revalidate'])->get();

            // 锁顺序 2：再锁 order 行（防并发 sync 同时进入）
            $order = Order::with(['latestCert'])
                ->whereHas('latestCert')
                ->lock()
                ->find($order->id);

            if (! $order) {
                return;
            }

            $cert = $order->latestCert;

            // 锁内二次校验四条件（并发情况下第二个拿到锁后看到最新状态）
            if (! in_array($cert->status, ['processing', 'approving', 'cancelling'])) {
                return;
            }
            if (! in_array($cert->action, ['new', 'renew'])) {
                return;
            }
            if (! get_system_setting('site', 'autoRefundOnSync')) {
                return;
            }

            // 防双退两道协作（详见下方 cancel() 注释，二者必须同时保留）：
            //   ① 此处应用层 exists 仅作预检，避免无谓的 getCancelTransaction 计算；它不是物理底线
            //      —— 锁外 exists+INSERT 非原子，并发 sync 仍可能两条都通过。
            //   ② 物理底线 = transactions 表 DB 唯一索引 transactions_dedup_unique（虚拟列
            //      dedup_key=CONCAT(type,':',transaction_id) WHERE type!='order'，迁移
            //      2026_05_07_120000_add_fund_transaction_unique_indexes）+ Transaction::creating
            //      钩子的二次 exists——任一并发漏过预检，唯一索引会让第二条 INSERT 抛错回滚。
            // 重构者注意：删除应用层 exists 不会双退（索引兜底），但删除唯一索引会破坏物理底线。
            $alreadyRefunded = Transaction::where('type', 'cancel')
                ->where('transaction_id', $order->id)
                ->exists();

            if (! $alreadyRefunded) {
                $transaction = OrderUtil::getCancelTransaction($order->toArray());
                // amount=0 时 Transaction::creating 钩子返回 false 短路，不创建记录
                Transaction::create($transaction);
            }

            // 更新 cert（合并上游数据 + 强制 status=cancelled + cancelled_at）
            $certData['status'] = 'cancelled';
            $certData['cancelled_at'] = now();
            $cert->update($certData);

            // 记录取消时间
            $order->cancelled_at = now();
            $order->save();

            if ($cert->last_cert_id) {
                $this->dispatchRenewCancelledNotification($order, $cert);
            }

            // 副作用：发起回调 + 清理相关 task
            // TaskJob::dispatch 内部已加 ->afterCommit()，事务安全
            // suppressCallback=true（下游 pull 触发）跳过回调：get 已同步返回 cancelled 状态，无需再异步回调
            if (! $suppressCallback) {
                $callback = Callback::where('user_id', $order->user_id)->where('status', 1)->first();
                if ($callback) {
                    $this->createTask($order->id, 'callback');
                }
            }
            $this->deleteTask($order->id, 'commit,sync,revalidate');
        }); // attempts=3：与 sync 主事务一致；本事务无上游 HTTP，退款由 transactions 唯一索引保证幂等，重试不双退
    }

    /**
     * 备注
     */
    public function remark(int $orderId, string $remark, string $field = 'remark'): void
    {
        $order = FindUtil::Order($orderId);
        $order->update([$field => $remark]);

        $this->success();
    }
}
