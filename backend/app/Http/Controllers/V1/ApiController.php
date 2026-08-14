<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Product;
use App\Services\Order\Action;
use App\Services\Order\OrderCommitResilience;
use App\Services\Order\Utils\OrderUtil;
use DB;
use Exception;
use Illuminate\Auth\TokenGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ApiController extends Controller
{
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

        /** @var ApiToken|null $apiToken */
        $apiToken = $this->guard->user();

        // 构造函数在中间件之前执行，未认证时 $apiToken 为 null
        // 认证由 ApiAuthenticate 中间件保证，此处仅做安全防护
        if ($apiToken) {
            $this->user_id = $apiToken->user_id;
            $this->model = new Order;
            $this->action = app(Action::class);
        }
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

        $res = Product::where($where)->orderBy('weight', 'asc')->get();
        $res->makeHidden(['id', 'api_id', 'status', 'cost', 'created_at', 'updated_at']);

        // 遍历查询结果并获取会员价格
        $data = [];
        foreach ($res as $item) {
            $cost = [];
            foreach ($item->periods as $period) {
                $minPrice = OrderUtil::getMinPrice($this->user_id, $item->id, (int) $period);
                $period = (string) $period;
                $cost['price'][$period] = $minPrice['price'];
                if (in_array('standard', $item->alternative_name_types)) {
                    $cost['alternative_standard_price'][$period] = $minPrice['alternative_standard_price'];
                }
                if (in_array('wildcard', $item->alternative_name_types)) {
                    $cost['alternative_wildcard_price'][$period] = $minPrice['alternative_wildcard_price'];
                }
            }
            $item = $item->toArray();
            $item['periods'] = array_map('intval', $item['periods']);
            $item['cost'] = $cost;
            $data[] = $item;
        }

        $this->success($data);
    }

    /**
     * 申请
     * [(string)refer_id,plus,pid,period,csr_generate,encryption,csr,auto_verify,
     *  validation_method,domains,administrator,organization]
     *
     * @throws Throwable
     */
    public function new(): void
    {
        $params = $this->request->all();

        $this->resolveReferId($params['refer_id'] ?? '');

        $product = Product::where('code', $params['pid'] ?? null)->where('status', 1)->first();
        if (! $product) {
            $this->error('Product not found');
        }
        $params['product_id'] = $product->id;

        // 转换V1参数格式为新系统格式
        $params = $this->convertV1Params($params);

        $params['user_id'] = $this->user_id;
        $params['action'] = 'new';
        $params['channel'] = 'api';

        // 外层事务只包 new + pay(commit=false)：建单 + 扣费落 pending（原子，失败一起回滚不留孤儿）。
        // commit（调上游下单）移到事务外，其失败不回滚已提交的 new+pay —— 订单停 pending、已扣费保留，
        // 靠对账/下游 pull get 自愈，返回既有 processing 展示态（getData('commit') 吞 code=0/忙，见 getData）。
        try {
            DB::beginTransaction();

            $result = $this->getData('new', [$params]);

            $order_id = $result['data']['order_id'] ?? null;

            $this->getData('pay', [$order_id, false, boolval($params['issue_verify'] ?? 0)]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 事务外独立提交上游：超时/失败/抢锁忙被 getData('commit') 吞掉，不影响已落库的 new+pay
        $this->getData('commit', [$order_id]);

        $order = Order::with(['latestCert'])->where('orders.id', $order_id)->first();

        $this->success($this->buildApplyResponse($order));
    }

    /**
     * 续费
     * [(string)refer_id,plus,oid,period,csr_generate,encryption,csr,auto_verify,
     *  validation_method,domains,administrator,organization]
     *
     * @throws Throwable
     */
    public function renew(): void
    {
        $params = $this->request->all();

        $this->resolveReferId($params['refer_id'] ?? '');

        // 处理OID参数转换
        $this->renameOidParam($params);

        // 转换V1参数格式为新系统格式
        $params = $this->convertV1Params($params);

        $params['action'] = 'renew';
        $params['channel'] = 'api';

        // 外层事务只包 renew + pay(commit=false)，commit 移到事务外（同 new，见 new 注释）
        try {
            DB::beginTransaction();

            $result = $this->getData('renew', [$params]);

            $order_id = $result['data']['order_id'] ?? '';

            $this->getData('pay', [$order_id, false, boolval($params['issue_verify'] ?? 0)]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 事务外独立提交上游：超时/失败/抢锁忙被 getData('commit') 吞掉，不影响已落库的 renew+pay
        $this->getData('commit', [$order_id]);

        $order = Order::with(['latestCert'])->where('orders.id', $order_id)->first();

        $this->success($this->buildApplyResponse($order));
    }

    /**
     * 重签
     * [(string)refer_id,oid,csr_generate,encryption,csr,auto_verify,
     *  validation_method,domains,organization]
     *
     * @throws Throwable
     */
    public function reissue(): void
    {
        $params = $this->request->all();

        $this->resolveReferId($params['refer_id'] ?? '');

        // 处理OID参数转换
        $this->renameOidParam($params);

        // 转换V1参数格式为新系统格式
        $params = $this->convertV1Params($params);

        $params['action'] = 'reissue';
        $params['channel'] = 'api';

        // 外层事务只包 reissue + 所有权校验 + pay(commit=false)，commit 移到事务外（同 new，见 new 注释）。
        // 所有权校验保留在事务内：跨用户 order_id 触发 error 抛异常 → 整笔 rollback（reissue 建的证书一起撤销）。
        try {
            DB::beginTransaction();

            $result = $this->getData('reissue', [$params]);

            $order_id = $result['data']['order_id'] ?? '';

            $order = Order::with(['latestCert'])->where('orders.id', $order_id)->where('user_id', $this->user_id)->first();

            if (! $order) {
                $this->error('Order not found');
            }

            $this->getData('pay', [$order_id, false, boolval($params['issue_verify'] ?? 0)]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 事务外独立提交上游：超时/失败/抢锁忙被 getData('commit') 吞掉，不影响已落库的 reissue+pay
        $this->getData('commit', [$order_id]);

        $order = Order::with(['latestCert'])->where('orders.id', $order_id)->first();

        $this->success($this->buildApplyResponse($order));
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
            $this->success(['oid' => $order->id]);
        } else {
            $this->error('Refer id not found');
        }
    }

    /**
     * 获取订单
     *
     * @throws Throwable
     */
    public function get(): void
    {
        $order_id = $this->orderIdFromOid();

        $order = $this->model
            ->with(['latestCert'])
            ->where('orders.id', $order_id)
            ->where('user_id', $this->user_id)
            ->first();

        if (! $order) {
            $this->error('Order not found');
        }

        $cacheKey = 'api_get_'.$order_id;
        // 原子占位：Cache::add（SETNX）保证并发下只放一个请求进 sync/pay/commit，防击穿重复调上游。
        // 保守 10s 占位；末尾按最终状态刷新滑动窗口（签发 120s / 其他 10s）
        if (Cache::add($cacheKey, time(), 10)) {
            // 待验证、待审批、已签发的订单同步（同步失败不影响返回已有数据）
            if (in_array($order->latestCert->status, ['processing', 'approving', 'active'])) {
                // suppressCallback=true：下游主动 pull，get 末尾已重新查询并同步返回新状态，无需再异步回调（避免冗余触发）
                try {
                    $this->action->sync($order_id, true, true);
                } catch (ApiResponseException) {
                    // 上游超时/失败不影响返回本地已有数据，下次 pull 再同步
                }
            }

            // 未支付订单支付
            if ($order->latestCert->status === 'unpaid') {
                try {
                    $this->action->pay($order_id);
                } catch (ApiResponseException|MutationBusyException) {
                }
            }

            // 待提交的订单提交
            if ($order->latestCert->status === 'pending') {
                try {
                    $this->action->commit($order_id);
                } catch (ApiResponseException|MutationBusyException) {
                }
            }

            // 重新查询
            $order = $this->model
                ->with(['latestCert'])
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

        $cert = $order->latestCert;

        // Laravel 不需要 hidden 和 visible，可以用 unset 替代
        $orderArray = $order->toArray();
        $certArray = $cert->toArray();

        // 保留需要的字段
        $orderData = array_intersect_key($orderArray, array_flip(['organization', 'contact', 'period_from', 'period_till']));
        $certData = array_intersect_key($certArray, array_flip([
            'vendor_id',
            'common_name',
            'alternative_names',
            'dcv',
            'validation',
            'documents',
            'csr',
            'cert',
            'intermediate_cert',
            'issued_at',
            'expires_at',
            'cert_apply_status',
            'domain_verify_status',
            'org_verify_status',
            'status',
        ]));

        $result = array_merge($orderData, $certData);

        // 转换为V1 API格式
        if (isset($result['organization']['registration_number'])) {
            $result['organization']['identification_number'] = $result['organization']['registration_number'];
            unset($result['organization']['registration_number']);
        }

        if (isset($result['contact'])) {
            $result['administrator'] = $result['contact'];
            unset($result['contact']);
            $result['administrator']['job'] = $result['administrator']['title'] ?? 'CEO';
            unset($result['administrator']['title']);
        }

        $result['issue_time'] = $result['issued_at'];
        $result['expiry_time'] = $result['expires_at'];

        $result['application_status'] = $this->getProcessStatus($result['cert_apply_status']);
        $result['dcv_status'] = $this->getProcessStatus($result['domain_verify_status']);
        $result['ov_status'] = $this->getProcessStatus($result['org_verify_status']);

        unset($result['issued_at']);
        unset($result['expires_at']);
        unset($result['cert_apply_status']);
        unset($result['domain_verify_status']);
        unset($result['org_verify_status']);

        $result = array_filter(
            $result,
            fn ($v) => $v !== null
        );

        $this->success($result);
    }

    /**
     * 取消订单
     *
     * @throws Throwable
     */
    public function cancel(): void
    {
        $order_id = $this->orderIdFromOid();

        $order = Order::with(['latestCert', 'product'])
            ->where('orders.id', $order_id)
            ->where('user_id', $this->user_id)
            ->first();

        if (! $order) {
            $this->error('Order not found');
        }

        if ($order->latestCert->status === 'cancelled') {
            $this->success();
        }

        $this->action->guardCancelDuplicate($order_id);

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

        if ($order->created_at->timestamp < now()->timestamp - 86400 * $refund_period) {
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
            $alreadyCancelled = $this->action->prepareImmediateCancel($order_id);
            if ($alreadyCancelled) {
                $this->success();
            }

            // 立即取消
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
        $order_id = $this->orderIdFromOid();
        $this->action->revalidate($order_id);
    }

    /**
     * 更新 DCV
     */
    public function updateDCV(): void
    {
        $order_id = $this->orderIdFromOid();
        $method = (string) $this->request->input('method');

        $this->action->updateDCV($order_id, $method);
    }

    /**
     * 下载证书
     */
    public function download(): void
    {
        $order_id = $this->orderIdFromOid();
        $type = $this->request->input('type', 'all') ?? 'all';

        $this->action->download($order_id, $type);
    }

    private function orderIdFromOid(): int
    {
        $oid = $this->request->input('oid', '');
        $this->rejectLegacyOid($oid);

        return (int) $oid;
    }

    private function renameOidParam(array &$params): void
    {
        if (! isset($params['oid'])) {
            return;
        }

        $this->rejectLegacyOid($params['oid']);

        $params['order_id'] = $params['oid'];
        unset($params['oid']);
    }

    private function rejectLegacyOid(mixed $oid): void
    {
        if (is_string($oid) && strlen($oid) === 8 && ctype_alnum($oid) && ! ctype_digit($oid)) {
            $this->error('请使用数字订单号');
        }
    }

    /**
     * 根据 refer_id 做幂等推进。
     */
    private function resolveReferId(string $refer_id): void
    {
        if (! $refer_id) {
            return;
        }

        $order = $this->model
            ->whereHas('latestCert', function ($query) use ($refer_id) {
                $query->where('refer_id', $refer_id);
            })
            ->where('user_id', $this->user_id)
            ->with(['latestCert'])
            ->first();

        if (! $order) {
            return;
        }

        // manager 的卡单态是 pending 且 api_id=NULL（不是 gateway 的 processing）。
        // 仅 pending 才重提 commit，避免把 cancelled/revoked 等终态复活。
        if (! $order->latestCert->api_id && $order->latestCert->status === 'pending') {
            $this->getData('commit', [$order->id]);
            $order = $this->model->with(['latestCert'])->where('orders.id', $order->id)->first();
        }

        $this->success($this->buildApplyResponse($order));
    }

    private function buildApplyResponse(Order $order): array
    {
        return [
            'oid' => $order->id,
            'application_status' => $this->getProcessStatus($order->latestCert->cert_apply_status ?? 0),
            'dcv' => $order->latestCert->dcv ?? null,
            'validation' => $order->latestCert->validation ?? null,
        ];
    }

    /**
     * 获取数据
     *
     * @throws Exception
     */
    private function getData(string $action, array $params): array
    {
        // commit 段吞并守卫收敛至 OrderCommitResilience（V1/V2/Deploy 单一真相源）；
        // 吞并边界（仅 commit 吞 code=0 + MutationBusyException、扣费不回滚）是 P0 红线，勿在此另写分叉。
        return OrderCommitResilience::run(
            fn () => $this->action->$action(...$params),
            $action,
            fn (array $result) => $this->error($result['msg'], $result['errors'] ?? null),
        );
    }

    /**
     * 转换V1 API参数为新系统格式
     */
    private function convertV1Params(array $params): array
    {
        // auto_verify 转换
        if (isset($params['auto_verify'])) {
            $params['issue_verify'] = $params['auto_verify'];
            unset($params['auto_verify']);
        }

        // 加密算法转换
        if (isset($params['encryption']['digest_alg'])) {
            $params['encryption']['signature_digest_alg'] = $params['encryption']['digest_alg'];
            unset($params['encryption']['digest_alg']);
        }

        // 管理员信息转换
        if (isset($params['administrator'])) {
            $params['contact'] = $params['administrator'];
            unset($params['administrator']);
            $params['contact']['title'] = $params['contact']['job'];
            unset($params['contact']['job']);
        }

        // 组织信息转换
        if (isset($params['organization']['identification_number'])) {
            $params['organization']['registration_number'] = $params['organization']['identification_number'];
            unset($params['organization']['identification_number']);
        }

        return $params;
    }

    /**
     * 健康检查接口
     */
    public function health(): void
    {
        $this->success([
            'status' => 'ok',
            'version' => 'v1',
            'timestamp' => time(),
        ]);
    }

    /**
     * 获取处理状态 - 转换为V1 API格式
     */
    protected function getProcessStatus(int $status): string
    {
        $statusMap = [
            0 => 'notdone',
            1 => 'ongoing',
            2 => 'done',
        ];

        return $statusMap[$status] ?? 'notdone';
    }
}
