<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Exceptions\ApiResponseException;
use App\Http\Requests\Product\ImportCaProductRequest;
use App\Http\Requests\Product\UpdateRequest;
use App\Models\Callback;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\Acme\Api\Api as AcmeApi;
use App\Services\Delegation\AutoDcvTxtService;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Api\Api;
use App\Services\Order\Traits\ActionBatchTrait;
use App\Services\Order\Traits\ActionCallbackTrait;
use App\Services\Order\Traits\ActionDocumentTrait;
use App\Services\Order\Traits\ActionFileTrait;
use App\Services\Order\Traits\ActionTrait;
use App\Services\Order\Utils\FindUtil;
use App\Services\Order\Utils\OrderUtil;
use App\Services\Order\Utils\VerifyUtil;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
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

    protected mixed $api;

    public function __construct()
    {
        $this->api = app(Api::class);
    }

    /**
     * 导入产品
     */
    public function importProduct(string $source = '', string $brand = '', string $api_id = '', string $type = 'new'): void
    {
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
            $this->error('没有获取到产品');
        }

        // 按 code（api_id）去重，后出现的覆盖前面的
        $unique = [];
        foreach ($allProducts as $item) {
            $unique[$item['code']] = $item;
        }

        $products = ['code' => 1, 'data' => array_values($unique)];

        foreach ($products['data'] as $item) {
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

        $this->success();
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

        DB::beginTransaction();
        try {
            $order = Order::create($orderData);
            $latestCert['order_id'] = $order->id;

            if ($latestCert['action'] == 'renew') {
                Cert::where(['status' => 'active', 'order_id' => $params['order_id']])->update(['status' => 'renewed']);
            }

            $cert = Cert::create($latestCert);
            $order->update(['latest_cert_id' => $cert->id]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        $this->success(['order_id' => $order->id]);
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

        $order = Order::find($params['order_id']);

        $order->organization = $params['organization'] ?? $order->organization;
        $latestCert = $this->getCert($params);

        $amount = OrderUtil::getLatestCertAmount($order->toArray(), $latestCert, $params['product']);

        // 产品禁用后 重签不能增加域名个数
        if (bccomp($amount, '0', 2) === 1) {
            $product = FindUtil::Product((int) $order->product_id);
            if ($product->status == 0) {
                $this->error('此订单重签不能增加域名个数');
            }
        }

        DB::beginTransaction();
        try {
            Cert::where('id', $order->latest_cert_id)->update(['status' => 'reissued']);

            $latestCert['order_id'] = $order->id;
            $latestCert['amount'] = $amount;
            $latestCert['status'] = 'unpaid';

            $cert = Cert::create($latestCert);
            $order->latest_cert_id = $cert->id;
            $order->save();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        $this->success(['order_id' => $order->id]);
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
    public function sync(int $orderId, bool $force = false): void
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
            $this->refundForSyncedCancel($order, $data);
            // force 模式（V1/V2 ApiController::get 无 try-catch 直调 sync）必须沿用"不抛 success"契约，
            // 否则 success() 抛 ApiResponseException 会打断 get 使其返回空 {code:1}，而非订单数据；
            // 且无论是否 force 都要 return，避免 fall through 到下方第二个事务重复加锁处理已 cancelled 订单。
            $force || $this->success();

            return;
        }

        // 锁内重取 + 终态守卫 + 写回：慢 IO（上游 get）已在锁外完成，此事务只包状态判定副作用 + 写回。
        // 锁序 task→order：与 commitCancel(active)/revokeCancel 统一。controller 直调 sync 时无前置 task 锁，
        // 必须在锁 order 前先按 task→order 顺序锁住本订单的 commit/sync/revalidate 任务（与下面 deleteTask 删除范围一致），
        // 否则与 commitCancel(锁 sync,revalidate→order)/refundForSyncedCancel 反序，task 集合相交触发 InnoDB 死锁。
        // 经 TaskJob 调用时 TaskJob 已先持本 task 行锁（同事务 lockForUpdate 可重入），叠加后整体仍是 task→order，不反序。
        // 杀手场景：并发 cancel 在锁内退款并置 cancelled，本 sync 若用上游滞后的 active 覆盖会让已退款订单复活。
        DB::transaction(function () use ($orderId, $order, $cert, $user, $data) {
            // 锁顺序 1：先锁 commit/sync/revalidate task（与 deleteTask 删除范围、commitCancel 的 task→order 顺序一致）
            Task::where('order_id', $orderId)
                ->whereIn('action', ['commit', 'sync', 'revalidate'])
                ->whereIn('status', ['executing', 'stopped'])
                ->lockForUpdate()
                ->get();

            // 锁顺序 2：再锁 order。锁内重读权威 status（重取 order 带 latestCert 重新加载），重取失败回落到外层陈旧值
            $lockedOrder = Order::with(['latestCert'])
                ->whereHas('latestCert')
                ->lock()
                ->find($orderId);
            $lockedStatus = $lockedOrder?->latestCert->status ?? $cert->status;

            // 终态守卫（泛化到所有路径）：本地已是终态时拒绝上游 status 覆盖，防滞后 active 复活已退款/已重签订单
            if (in_array($lockedStatus, ['cancelled', 'revoked', 'renewed', 'reissued', 'failed'], true)) {
                unset($data['status']);
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

            // 签发 取消 吊销 发起回调
            if ($hasStatusChanged && in_array($data['status'] ?? '', ['active', 'cancelled', 'revoked'], true)) {
                $callback = Callback::where('user_id', $order->user_id)->where('status', 1)->first();
                $callback && $this->createTask($orderId, 'callback');
                // 删除相关任务
                $this->deleteTask($orderId, 'commit,sync,revalidate');
            }

            $order->save();
            $cert->update($data);
        }, 3); // attempts=3：controller 直调时本事务为最外层，死锁/锁超时自动重试（上游 get 在事务外，重试只重跑锁+写回，安全）；
        // 经 TaskJob 调用时为嵌套事务，Laravel 直接抛 DeadlockException 到外层，由 TaskJob job 级重试兜底

        // 强制更新不返回提示（success 抛 ApiResponseException 须在事务闭包外）
        $force || $this->success();
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
            DB::transaction(function () use ($orderId, $product) {
                // 锁顺序 1：先锁 sync/revalidate task（与 TaskJob::handle 的 task→order 顺序一致，避免死锁）
                Task::where('order_id', $orderId)
                    ->whereIn('action', ['sync', 'revalidate'])
                    ->whereIn('status', ['executing', 'stopped'])
                    ->lockForUpdate()
                    ->get();

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
        DB::transaction(function () use ($orderId) {
            // 锁顺序 1：先锁 task（与 TaskJob 一致，避免 task↔order 循环等待死锁）
            Task::where('order_id', $orderId)
                ->where('action', 'cancel')
                ->whereIn('status', ['executing', 'stopped'])
                ->lockForUpdate()
                ->get();

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

            try {
                $this->api->cancel($orderId);
            } catch (ApiResponseException $e) {
                $errors = $e->getApiResponse()['errors'] ?? null;
                $msg = $e->getApiResponse()['msg'] ?: 'CA取消失败';
                $this->error($msg, $errors);
            }

            // 获取交易信息
            $transaction = OrderUtil::getCancelTransaction($order->toArray());

            // 创建交易记录并退款
            //
            // 防双退底线（与 refundForSyncedCancel 注释互引，二者协作不可单独删除）：
            //   - 锁内 status 校验：上面 L926「status===cancelled → error('订单已取消')」是第一道。
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
        }, 1);

        $this->success();
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
    private function refundForSyncedCancel(Order $order, array $certData): void
    {
        DB::transaction(function () use ($order, $certData) {
            // 锁顺序 1：先锁 commit/sync/revalidate task（与 deleteTask 删除范围、commitCancel 的 task→order 顺序一致）
            Task::where('order_id', $order->id)
                ->whereIn('action', ['commit', 'sync', 'revalidate'])
                ->whereIn('status', ['executing', 'stopped'])
                ->lockForUpdate()
                ->get();

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

            // 副作用：发起回调 + 清理相关 task
            // TaskJob::dispatch 内部已加 ->afterCommit()，事务安全
            $callback = Callback::where('user_id', $order->user_id)->where('status', 1)->first();
            if ($callback) {
                $this->createTask($order->id, 'callback');
            }
            $this->deleteTask($order->id, 'commit,sync,revalidate');
        }, 3); // attempts=3：与 sync 主事务一致；本事务无上游 HTTP，退款由 transactions 唯一索引保证幂等，重试不双退
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
