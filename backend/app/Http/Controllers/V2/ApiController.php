<?php

namespace App\Http\Controllers\V2;

use App\Exceptions\ApiResponseException;
use App\Http\Controllers\Controller;
use App\Http\Traits\OrderIdCompatTrait;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Product;
use App\Services\Order\Action;
use App\Services\Order\Utils\OrderUtil;
use DB;
use Exception;
use Illuminate\Auth\TokenGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

class ApiController extends Controller
{
    use OrderIdCompatTrait;

    protected Order $model;

    protected Action $action;

    protected int $user_id;

    protected TokenGuard $guard;

    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
        // @phpstan-ignore assign.propertyType
        $this->guard = Auth::guard('api');

        /** @var ApiToken $apiToken */
        $apiToken = $this->guard->user();

        $this->user_id = $apiToken->user_id;
        $this->model = new Order;
        $this->action = app(Action::class);
    }

    /**
     * 获取产品列表
     */
    public function getProducts(): void
    {
        $brand = $this->request->input('brand', '');
        $code = $this->request->input('code', '');

        $where = [];
        // brand 统一小写匹配（saving 钩子小写化；兼容 case-sensitive driver）
        $brand && $where[] = ['brand', '=', strtolower((string) $brand)];
        $code && $where[] = ['code', 'like', '%'.$code.'%'];
        $where[] = ['status', '=', 1];
        $where[] = ['product_type', '!=', Product::TYPE_ACME];

        $res = Product::where($where)->orderBy('weight', 'asc')->get();
        $res->makeHidden(['id', 'api_id', 'cost', 'status', 'created_at', 'updated_at']);

        // 遍历查询结果并获取会员价格
        $data = [];
        foreach ($res as $item) {
            $cost = [];
            $skipProduct = false; // 标记是否跳过当前产品

            /** @var int $period */
            foreach ($item->periods as $period) {
                $minPrice = OrderUtil::getMinPrice($this->user_id, $item->id, (int) $period);

                if (empty($minPrice)) {
                    $skipProduct = true; // 如果任意 period 没有价格，标记跳过整个产品
                    break; // 跳出 period 循环
                }

                $period = (string) $period;

                $cost['price'][$period] = $minPrice['price'];

                if (in_array('standard', $item->alternative_name_types)) {
                    $cost['alternative_standard_price'][$period] = $minPrice['alternative_standard_price'];
                }

                if (in_array('wildcard', $item->alternative_name_types)) {
                    $cost['alternative_wildcard_price'][$period] = $minPrice['alternative_wildcard_price'];
                }
            }

            // 如果有任意 period 没有价格，跳过当前产品
            if ($skipProduct) {
                continue;
            }

            $item = $item->toArray();
            // API 不暴露 delegation 验证方法
            if (isset($item['validation_methods'])) {
                $item['validation_methods'] = array_values(
                    array_diff($item['validation_methods'], ['delegation'])
                );
            }
            $item['periods'] = array_map('intval', $item['periods']);
            $item['cost'] = $cost;
            $data[] = $item;
        }

        $this->success($data);
    }

    public function getOrders(): void
    {
        $page = max((int) ($this->request->input('page', 1) ?? 1), 1);
        $pageSize = min(max((int) ($this->request->input('page_size', 100) ?? 100), 1), 100);
        $status = $this->request->input('status', 'all') ?? 'all';

        $whereStatus = [];
        $status !== 'all' && $whereStatus[] = ['status', '=', $status];

        $query = Order::with([
            'product' => function ($query) {
                $query->select(['id', 'code']);
            },
            'latestCert' => function ($query) {
                $query->select([
                    'id',
                    'order_id as api_id',
                    'vendor_id',
                    'issuer',
                    'action',
                    'common_name',
                    'alternative_names',
                    'issued_at',
                    'expires_at',
                    'status',
                ]);
            },
        ])
            ->whereHas('latestCert', fn ($query) => $query->where($whereStatus))
            ->select([
                'id',
                'product_id',
                'latest_cert_id',
                'brand',
                'period',
                'period_from',
                'period_till',
                'cancelled_at',
                'created_at',
                'updated_at',
            ])
            ->where('user_id', '=', $this->user_id)
            ->orderBy('id', 'desc');

        $total = $query->count();
        $res = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->get();

        $data = [];
        /** @var Order $item */
        foreach ($res as $item) {
            $cert = $item->latestCert->toArray();
            unset($cert['id'], $cert['intermediate_cert']);
            $product = $item->product?->toArray(); // @phpstan-ignore nullsafe.neverNull
            if ($product) {
                unset($product['id']);
            }
            $order = $item->toArray();
            unset($order['id'], $order['product_id'], $order['latest_cert_id'], $order['product'], $order['latest_cert']);

            $data[] = [
                'order' => $order,
                'cert' => $cert,
                'product' => $product,
            ];
        }

        $this->success([
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'data' => $data,
        ]);
    }

    /**
     * 申请
     * [(string)refer_id,plus,product_code,period,csr_generate,encryption,csr,issue_verify,
     *  validation_method,domains,contact,organization]
     *
     * @throws Throwable
     */
    public function new(): void
    {
        $params = $this->request->all();

        // API 不支持委托验证方法
        $validationMethod = $params['validation_method'] ?? '';
        if ($validationMethod === 'delegation') {
            $this->error('API 不支持委托验证方法');
        }

        $this->checkReferId($params['refer_id'] ?? '');

        $product = Product::where('code', $params['product_code'] ?? null)->where('status', 1)->first();
        if (! $product) {
            $this->error('Product not found');
        }
        if ($product->product_type === Product::TYPE_ACME) {
            $this->error('ACME products are not supported via this API');
        }
        $params['product_id'] = $product->id;

        $params['user_id'] = $this->user_id;
        $params['action'] = 'new';
        $params['channel'] = 'api';

        try {
            DB::beginTransaction();

            $result = $this->getData('new', [$params]);

            $order_id = $result['data']['order_id'] ?? null;

            $this->getData('pay', [$order_id, true, boolval($params['issue_verify'] ?? 0)]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $order = Order::with(['latestCert'])->where('orders.id', $order_id)->first();

        $cleaned = $this->cleanDcvAndValidation(
            $order->latestCert->dcv ?? null,
            $order->latestCert->validation ?? null,
        );

        $this->success([
            'order_id' => $order_id,
            'cert_apply_status' => $order->latestCert->cert_apply_status ?? 0,
            ...$cleaned,
        ]);
    }

    /**
     * 续费
     * [(string)refer_id,plus,order_id,period,csr_generate,encryption,csr,issue_verify,
     *  validation_method,domains,contact,organization]
     *
     * @throws Throwable
     */
    public function renew(): void
    {
        $params = $this->request->all();

        // API 不支持委托验证方法
        $validationMethod = $params['validation_method'] ?? '';
        if ($validationMethod === 'delegation') {
            $this->error('API 不支持委托验证方法');
        }

        $this->checkReferId($params['refer_id'] ?? '');

        // 处理order_id参数兼容
        $this->processOrderIdParamInArray($params);

        $params['action'] = 'renew';
        $params['channel'] = 'api';

        try {
            DB::beginTransaction();

            $result = $this->getData('renew', [$params]);

            $order_id = $result['data']['order_id'] ?? '';

            $this->getData('pay', [$order_id, true, boolval($params['issue_verify'] ?? 0)]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $order = Order::with(['latestCert'])->where('orders.id', $order_id)->first();

        $cleaned = $this->cleanDcvAndValidation(
            $order->latestCert->dcv ?? null,
            $order->latestCert->validation ?? null,
        );

        $this->success([
            'order_id' => $order_id,
            'cert_apply_status' => $order->latestCert->cert_apply_status ?? 0,
            ...$cleaned,
        ]);
    }

    /**
     * 重签
     * [(string)refer_id,order_id,csr_generate,encryption,csr,issue_verify,
     *  validation_method,domains,organization]
     *
     * @throws Throwable
     */
    public function reissue(): void
    {
        $params = $this->request->all();

        // API 不支持委托验证方法
        $validationMethod = $params['validation_method'] ?? '';
        if ($validationMethod === 'delegation') {
            $this->error('API 不支持委托验证方法');
        }

        $this->checkReferId($params['refer_id'] ?? '');

        // 处理order_id参数兼容
        $this->processOrderIdParamInArray($params);

        $params['action'] = 'reissue';
        $params['channel'] = 'api';

        try {
            DB::beginTransaction();

            $result = $this->getData('reissue', [$params]);

            $order_id = $result['data']['order_id'] ?? '';

            $order = Order::with(['latestCert'])->where('orders.id', $order_id)->where('user_id', $this->user_id)->first();

            if (! $order) {
                throw new Exception('Order not found');
            }

            $this->getData('pay', [$order_id, true, boolval($params['issue_verify'] ?? 0)]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $order = Order::with(['latestCert'])->where('orders.id', $order_id)->first();

        $cleaned = $this->cleanDcvAndValidation(
            $order->latestCert->dcv ?? null,
            $order->latestCert->validation ?? null,
        );

        $this->success([
            'order_id' => $order_id,
            'cert_apply_status' => $order->latestCert->cert_apply_status ?? 0,
            ...$cleaned,
        ]);
    }

    /**
     * 获取订单ID
     */
    public function getOrderIdByReferId(): void
    {
        $refer_id = $this->request->input('refer_id', '');

        $order = $this->model
            ->whereHas('latestCert', function ($query) use ($refer_id) {
                $query->where('refer_id', $refer_id);
            })
            ->where('user_id', $this->user_id)
            ->with(['latestCert'])
            ->first();

        if ($order) {
            $this->success(['order_id' => $order->id]);
        } else {
            $this->error('Refer id not found');
        }
    }

    /**
     * 获取订单
     *
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function get(): void
    {
        $order_id = $this->processOrderIdParam();

        $latestCertFields = [
            'id',
            'order_id',
            'vendor_id',
            'common_name',
            'alternative_names',
            'dcv',
            'validation',
            'csr',
            'private_key',
            'cert',
            'issuer',
            'issued_at',
            'expires_at',
            'cert_apply_status',
            'domain_verify_status',
            'org_verify_status',
            'status',
        ];

        $orderFields = ['latest_cert_id', 'organization', 'contact', 'period_from', 'period_till'];

        $order = $this->model
            ->with(['latestCert' => fn ($query) => $query->select($latestCertFields)])
            ->whereHas('latestCert')
            ->where('orders.id', $order_id)
            ->where('user_id', $this->user_id)
            ->select($orderFields)
            ->first();

        if (! $order) {
            $this->error('Order not found');
        }

        $cacheKey = 'api_get_'.$order_id;
        // 原子占位：Cache::add（SETNX）保证并发下只放一个请求进 sync/pay/commit，防击穿重复调上游。
        // 保守 10s 占位；末尾按最终状态刷新滑动窗口（签发 120s / 其他 10s）
        if (Cache::add($cacheKey, time(), 10)) {
            // 待验证、待审批、已签发的订单同步
            if (in_array($order->latestCert->status, ['processing', 'approving', 'active'])) {
                $this->action->sync($order_id, true);
            }

            // 未支付订单支付
            if ($order->latestCert->status === 'unpaid') {
                try {
                    $this->action->pay($order_id);
                } catch (ApiResponseException) {
                }
            }

            // 待提交的订单提交
            if ($order->latestCert->status === 'pending') {
                try {
                    $this->action->commit($order_id);
                } catch (ApiResponseException) {
                }
            }

            // 重新查询
            $order = $this->model
                ->with(['latestCert' => fn ($query) => $query->select($latestCertFields)])
                ->whereHas('latestCert')
                ->select($orderFields)
                ->where('orders.id', $order_id)
                ->where('user_id', $this->user_id)
                ->first();
        }

        // 更新缓存时间
        $cacheTime = $order->latestCert->status === 'active' ? 120 : 10;
        Cache::set($cacheKey, time(), $cacheTime);

        // 未支付 和 待提交 的订单状态改为处理中再返回
        if (in_array($order->latestCert->status, ['unpaid', 'pending'])) {
            $order->latestCert->status = 'processing';
        }

        $result = array_merge($order->toArray(), $order->latestCert->toArray());

        // 清理字段 避免覆盖
        unset($result['latest_cert_id'], $result['latest_cert'], $result['id'], $result['order_id'], $result['issuer']);

        // 避免返回空的 key
        if (empty($result['private_key'])) {
            unset($result['private_key']);
        }

        // 清理 dcv/validation 中不可跨级传递的内部字段
        $cleaned = $this->cleanDcvAndValidation($result['dcv'] ?? null, $result['validation'] ?? null);
        $result = array_merge($result, $cleaned);

        $this->success($result);
    }

    /**
     * 取消订单
     *
     * @throws Throwable
     */
    public function cancel(): void
    {
        $order_id = $this->processOrderIdParam();

        $order = Order::with(['latestCert', 'product'])
            ->where('orders.id', $order_id)
            ->where('user_id', $this->user_id)
            ->first();

        if (! $order) {
            $this->error('Order not found');
        }

        // 待支付订单删除
        if ($order->latestCert->status === 'unpaid') {
            try {
                $this->action->delete($order_id);
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if ($result['code'] === 0) {
                    $this->error($result['msg'], $result['errors'] ?? null);
                }
            }
        }

        // 待提交的订单取消
        if ($order->latestCert->status === 'pending') {
            // 取消前删除提交任务
            $this->action->deleteTask($order_id, 'commit');
            try {
                $this->action->cancelPending($order_id);
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if ($result['code'] === 0) {
                    $this->error($result['msg'], $result['errors'] ?? null);
                }
            }
        }

        // 重新查询 如果订单不存在或为已取消状态直接返回成功 否测继续取消
        $order = Order::with(['latestCert', 'product'])
            ->where('orders.id', $order_id)
            ->where('user_id', $this->user_id)
            ->first();

        if (! $order || $order->latestCert->status === 'cancelled') {
            $this->success();
        }

        $status = $order->latestCert->status;
        $refund_period = $order->product->refund_period ?? 0;

        if ($order->created_at->timestamp < time() - 86400 * $refund_period) {
            $this->error("Order cannot be cancelled after $refund_period days");
        }

        if ($status === 'expired') {
            $this->error('Order has expired');
        }
        if ($status === 'renewed') {
            $this->error('Order has been renewed');
        }
        if ($status === 'reissued') {
            $this->error('Order has been reissued');
        }
        if ($status === 'revoked') {
            $this->error('Order has been revoked');
        }
        if ($status === 'failed') {
            $this->error('Order has failed');
        }

        if (in_array($status, ['processing', 'approving', 'active', 'cancelling'])) {
            // 取消前删除相关任务
            $this->action->deleteTask($order_id, 'sync,revalidate,cancel');

            // 立即取消
            $order->latestCert->update(['status' => 'cancelling']);
            $this->action->cancel($order_id);
        } else {
            $this->error('Order cannot be cancelled');
        }
    }

    /**
     * 重新验证
     */
    public function revalidate(): void
    {
        $order_id = $this->processOrderIdParam();
        $this->action->revalidate($order_id);
    }

    /**
     * 更新 DCV
     */
    public function updateDCV(): void
    {
        $order_id = $this->processOrderIdParam();
        $method = $this->request->input('method', '');

        // API 不支持委托验证方法
        if ($method === 'delegation') {
            $this->error('API 不支持委托验证方法');
        }

        $this->action->updateDCV($order_id, $method);
    }

    /**
     * 下载证书
     */
    public function download(): void
    {
        $order_id = $this->processOrderIdParam();
        $type = $this->request->input('type', 'all') ?? 'all';

        $this->action->download($order_id, $type);
    }

    /**
     * 检测重复 refer_id
     */
    private function checkReferId(string $refer_id): void
    {
        if ($refer_id) {
            $order = $this->model
                ->whereHas('latestCert', function ($query) use ($refer_id) {
                    $query->where('refer_id', $refer_id);
                })
                ->where('user_id', $this->user_id)
                ->with(['latestCert'])
                ->first();

            if ($order) {
                $this->error('Refer id already exists');
            }
        }
    }

    /**
     * 获取数据
     *
     * @throws Exception
     */
    private function getData(string $action, array $params): array
    {
        try {
            $this->action->$action(...$params);
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            if ($result['code'] === 0) {
                $this->error($result['msg'], $result['errors'] ?? null);
            }
        }

        return $result ?? [];
    }

    /**
     * 从 dcv/validation 中提取可跨级传递的字段（白名单）
     * delegation 内部标记不应暴露给下级系统
     */
    private function cleanDcvAndValidation(?array $dcv, ?array $validation): array
    {
        if ($dcv) {
            $dcv = array_filter([
                'method' => $dcv['method'] ?? null,
                'dns' => $dcv['dns'] ?? null,
                'file' => $dcv['file'] ?? null,
            ], fn ($v) => $v !== null);
        }

        if ($validation) {
            $validation = array_map(fn (array $item) => array_filter([
                'domain' => $item['domain'] ?? null,
                'method' => $item['method'] ?? null,
                'verified' => $item['verified'] ?? null,
                'host' => $item['host'] ?? null,
                'value' => $item['value'] ?? null,
                'name' => $item['name'] ?? null,
                'content' => $item['content'] ?? null,
                'link' => $item['link'] ?? null,
                'path' => $item['path'] ?? null,
                'email' => $item['email'] ?? null,
                'error' => $item['error'] ?? null,
                'expires_date' => $item['expires_date'] ?? null,
            ], fn ($v) => $v !== null), $validation);
        }

        return ['dcv' => $dcv, 'validation' => $validation];
    }

    /**
     * 上传文档（接收下游 base64 文档）
     */
    public function uploadDocument(): void
    {
        $orderId = (int) $this->request->input('order_id');
        $type = $this->request->input('type', '');
        $fileName = $this->request->input('fileName', '');
        $documentContent = $this->request->input('document_content', '');

        ! $orderId && $this->error('order_id is required');
        ! $type && $this->error('type is required');
        ! $fileName && $this->error('fileName is required');
        ! $documentContent && $this->error('document_content is required');

        // 验证订单归属当前用户
        $order = Order::where('id', $orderId)->where('user_id', $this->user_id)->first();
        ! $order && $this->error('Order not found');

        $this->action->uploadDocumentFromBase64($orderId, $type, $fileName, $documentContent);
    }

    /**
     * 健康检查接口
     */
    public function health(): void
    {
        $this->success([
            'status' => 'ok',
            'version' => 'v2',
            'timestamp' => time(),
        ]);
    }
}
