<?php

namespace App\Http\Controllers\Deploy;

use App\Exceptions\ApiResponseException;
use App\Http\Controllers\Controller;
use App\Models\Cert;
use App\Models\Order;
use App\Services\Order\Action;
use App\Services\Order\AutoRenewService;
use Illuminate\Http\Request;
use Throwable;

class ApiController extends Controller
{
    /**
     * 查询订单列表
     * 统一使用 order 参数：纯数字为 ID，字符串为域名，含逗号为批量查询
     * 不传时返回最新 100 条 active 订单
     * field=certificate|private_key：order 为单个数字 ID 或域名时返回纯 PEM 文本（适配 certimate URL 拉取）
     * 域名模式按 common_name 精确匹配取最新已签发证书，续费后 URL 无需变更
     */
    public function query(Request $request): mixed
    {
        $request->validate([
            'order' => ['nullable', 'string'],
            'field' => ['nullable', 'in:certificate,private_key'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $order = trim($request->input('order', ''));
        $field = $request->input('field');

        if ($field !== null) {
            if ($order === '' || str_contains($order, ',')) {
                abort(400, 'field 参数要求 order 为单个订单 ID 或域名');
            }

            if (ctype_digit($order)) {
                $found = Order::with('latestCert')
                    ->whereHas('latestCert')
                    ->where('id', $order)
                    ->first();

                if (! $found) {
                    abort(404, '订单不存在');
                }

                $found = $this->resolveRenewedOrder($found);
                $cert = $found->latestCert;

                if ($cert->status !== 'active') {
                    abort(400, '证书状态非 active');
                }
            } else {
                // 域名模式：按 common_name 精确匹配取最新已签发的 active 证书
                // 通过 whereHas('order') 应用 Order 的 UserScope 保证隔离
                $cert = Cert::whereHas('order')
                    ->where('common_name', strtolower($order))
                    ->where('status', 'active')
                    ->orderByDesc('issued_at')
                    ->orderByDesc('id')
                    ->first();

                if (! $cert) {
                    abort(404, '未找到该域名的活跃证书');
                }
            }

            $pem = $field === 'certificate'
                ? rtrim((string) $cert->cert)."\n".(string) $cert->intermediate_cert
                : (string) $cert->private_key;

            return response($pem, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if ($order !== '') {
            // 含逗号：批量查询（支持 ID 和域名混合）
            if (str_contains($order, ',')) {
                $this->success($this->paginateResult($this->batchQuery($order), $request));
            }

            // 纯数字：按 ID 精确查询
            if (ctype_digit($order)) {
                $found = Order::with('latestCert')
                    ->whereHas('latestCert')
                    ->where('id', $order)
                    ->first();

                if (! $found) {
                    $this->error('未找到匹配的订单');
                }

                $found = $this->resolveRenewedOrder($found);

                $this->success($this->paginateResult(collect([$found])));
            }

            // 字符串：按域名查询
            $orders = $this->findOrdersByDomain(strtolower($order));

            if ($orders->isEmpty()) {
                $this->error('未找到匹配的订单');
            }

            $this->success($this->paginateResult($orders));
        }

        // 空参数：返回最新 active 订单（数据库级分页）
        $page = (int) $request->input('page', 1);
        $page_size = (int) ($request->input('page_size', 100) ?? 100);

        $query = Order::with('latestCert')
            ->whereHas('latestCert', fn ($q) => $q->where('status', 'active'))
            ->orderByDesc('created_at');

        $total = $query->count();
        $data = $query->offset(($page - 1) * $page_size)
            ->limit($page_size)
            ->get()
            ->map(fn ($o) => $this->getOrderData($o))
            ->toArray();

        $renew_before_days = (int) get_system_setting('site', 'renewBeforeDays', 14);
        $this->success(compact('total', 'page', 'page_size', 'data', 'renew_before_days'));
    }

    /**
     * 更新/续费证书
     *
     * @throws Throwable
     */
    public function update(Request $request): void
    {
        $params = $request->validate([
            'order_id' => ['required', 'integer'],
            'csr' => ['nullable', 'string'],
            'domains' => ['nullable', 'string'], // 多域名支持，逗号分割
            'validation_method' => ['nullable', 'in:delegation,file'],
        ]);

        // 通过 order_id 查找订单
        $order = Order::with(['latestCert', 'product'])
            ->whereHas('latestCert')
            ->where('id', $params['order_id'])
            ->first();

        if (! $order) {
            $this->error('订单不存在');
        }

        $cert = $order->latestCert;
        $orderId = $order->id;
        $reQuery = false;

        $action = new Action;

        // 证书状态如果是 unpaid 则支付
        if ($cert->status === 'unpaid') {
            $this->getData($action, 'pay', [$orderId]);

            // 重新查询状态
            $cert->refresh();
        }

        // 证书状态如果是 pending 则提交，直接进入验证状态
        if ($cert->status === 'pending') {
            $this->getData($action, 'commit', [$orderId]);
            $reQuery = true;
        }

        // 证书状态如果是 active 则发起重签或续费
        if ($cert->status === 'active') {
            $updateParams = [
                'order_id' => $orderId,
            ];

            if (empty($params['csr'])) {
                $updateParams['csr_generate'] = 1;
            } else {
                $updateParams['csr_generate'] = 0;
                $updateParams['csr'] = $params['csr'];
            }

            $updateParams['channel'] = 'deploy';
            // 优先使用客户端传入的 domains，否则使用当前证书的域名
            $updateParams['domains'] = ! empty($params['domains'])
                ? trim($params['domains'])
                : $cert->alternative_names;
            $validationMethod = $params['validation_method'] ?? 'delegation';
            $productMethods = $order->product->validation_methods ?? [];

            if ($validationMethod === 'delegation') {
                if (! in_array('delegation', $productMethods)) {
                    $this->error('该产品不支持委托验证');
                }
                $updateParams['validation_method'] = 'delegation';
            } else {
                // file: 按 file → https → http 顺序查找产品支持的方法
                $resolved = null;
                foreach (['file', 'https', 'http'] as $method) {
                    if (in_array($method, $productMethods)) {
                        $resolved = $method;
                        break;
                    }
                }
                if (! $resolved) {
                    $this->error('该产品不支持文件验证');
                }
                $updateParams['validation_method'] = $resolved;
            }

            // 如果订单到期时间小于 15 天则续费，否则重签
            if ($order->period_till?->lt(now()->addDays(15))) {
                // 续费需要检查 auto_renew 设置
                $autoRenewEnabled = app(AutoRenewService::class)->isAutoRenewEnabled($order, $order->user);
                if (! $autoRenewEnabled) {
                    $this->error('该订单未开启自动续费');
                }

                $updateParams['action'] = 'renew';
                $updateParams['period'] = $order->period;
                $result = $this->getData($action, 'renew', [$updateParams]);
                $orderId = $result['data']['order_id'] ?? $orderId;
            } else {
                $updateParams['action'] = 'reissue';
                $this->getData($action, 'reissue', [$updateParams]);
            }

            $this->getData($action, 'pay', [$orderId]);

            $reQuery = true;
        }

        if ($reQuery) {
            $order = Order::with('latestCert')->whereHas('latestCert')->where('id', $orderId)->first();

            if (! $order) {
                $this->error('订单不存在');
            }
        }

        $data = $this->getOrderData($order);
        $data['renew_before_days'] = (int) get_system_setting('site', 'renewBeforeDays', 14);

        $this->success($data);
    }

    /**
     * 切换订单自动重签开关
     */
    public function toggleAutoReissue(Request $request): void
    {
        $params = $request->validate([
            'order_id' => ['required', 'integer'],
            'auto_reissue' => ['required', 'boolean'],
        ]);

        $order = Order::find($params['order_id']);

        if (! $order) {
            $this->error('订单不存在');
        }

        $order->auto_reissue = $params['auto_reissue'];
        $order->save();

        $this->success([
            'order_id' => $order->id,
            'auto_reissue' => $order->auto_reissue,
        ]);
    }

    /**
     * 部署回调接口
     * 部署工具完成部署后调用此接口通知 Manager
     */
    public function callback(Request $request): void
    {
        $params = $request->validate([
            'order_id' => ['required', 'integer'],
            'status' => ['required', 'in:success,failure'],
            'deployed_at' => ['nullable', 'string'],
        ]);

        // 通过 Order 查询（Order 已被 UserScope 限制）
        $order = Order::with('latestCert')
            ->where('id', $params['order_id'])
            ->first();

        if (! $order) {
            $this->error('订单不存在');
        }

        $cert = $order->latestCert;

        // 只有部署成功才记录时间
        if ($params['status'] === 'success') {
            $deployTime = now();

            // 如果传了 deployed_at，尝试解析
            if (! empty($params['deployed_at'])) {
                try {
                    $deployTime = \Carbon\Carbon::parse($params['deployed_at']);
                } catch (\Exception) {
                    // 解析失败使用当前时间
                }
            }

            $cert->auto_deploy_at = $deployTime;
            $cert->save();
        }

        $this->success([
            'order_id' => $params['order_id'],
            'status' => $params['status'],
            'recorded' => $params['status'] === 'success',
            'renew_before_days' => (int) get_system_setting('site', 'renewBeforeDays', 14),
        ]);
    }

    /**
     * 统一分页返回格式
     */
    private function paginateResult(\Illuminate\Support\Collection $orders, ?Request $request = null): array
    {
        $page = $request ? (int) $request->input('page', 1) : 1;
        $page_size = $request ? (int) ($request->input('page_size', 100) ?? 100) : 100;
        $total = $orders->count();

        $data = $orders->slice(($page - 1) * $page_size, $page_size)->values()
            ->map(fn ($o) => $this->getOrderData($o))->toArray();

        $renew_before_days = (int) get_system_setting('site', 'renewBeforeDays', 14);

        return compact('total', 'page', 'page_size', 'data', 'renew_before_days');
    }

    /**
     * 批量查询：支持 id 和 domain 混合，英文逗号分割
     */
    private function batchQuery(string $queryStr): \Illuminate\Support\Collection
    {
        $items = array_filter(array_map('trim', explode(',', $queryStr)));

        if (empty($items)) {
            $this->error('查询参数不能为空');
        }

        if (count($items) > 100) {
            $this->error('单次最多查询 100 条');
        }

        $ids = [];
        $domains = [];

        foreach ($items as $item) {
            if (ctype_digit($item)) {
                $ids[] = (int) $item;
            } else {
                $domains[] = strtolower($item);
            }
        }

        $orders = collect();

        if ($ids) {
            $orders = Order::with('latestCert')
                ->whereHas('latestCert')
                ->whereIn('id', $ids)
                ->get()
                ->map(fn ($o) => $this->resolveRenewedOrder($o))
                ->unique('id')
                ->values();
        }

        // 按域名逐个查询并合并（去重）
        $existingIds = $orders->pluck('id')->all();
        foreach ($domains as $domain) {
            $found = $this->findOrdersByDomain($domain);
            /** @var Order $order */
            foreach ($found as $order) {
                if (! in_array($order->id, $existingIds)) {
                    $orders->push($order);
                    $existingIds[] = $order->id;
                }
            }
        }

        return $orders->sortByDesc('created_at')->values();
    }

    /**
     * 按域名精确查找订单
     * Order 已被 UserScope 限制
     */
    private function findOrdersByDomain(string $domain): \Illuminate\Database\Eloquent\Collection
    {
        return Order::with('latestCert')
            ->whereHas('latestCert', function ($query) use ($domain) {
                $query->where('alternative_names', 'like', "%$domain%")
                    ->where('status', 'active');
            })
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 执行 Action 并获取返回数据
     */
    private function getData(Action $action, string $method, array $params = []): array
    {
        try {
            $action->$method(...$params);
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            if ($result['code'] === 0) {
                $this->error($result['msg'], $result['errors'] ?? null);
            }
        }

        return $result ?? [];
    }

    /**
     * 已续费订单追踪到新订单
     * 通过 cert 的 last_cert_id 链找到续费后的新订单
     */
    private function resolveRenewedOrder(Order $order): Order
    {
        $cert = $order->latestCert;

        while ($cert->status === 'renewed') {
            $nextCert = Cert::where('last_cert_id', $cert->id)->first();

            if (! $nextCert || $nextCert->order_id === $order->id) {
                break;
            }

            $newOrder = Order::with('latestCert')
                ->whereHas('latestCert')
                ->where('id', $nextCert->order_id)
                ->first();

            if (! $newOrder) {
                break;
            }

            $order = $newOrder;
            $cert = $order->latestCert;
        }

        return $order;
    }

    /**
     * 统一返回数据
     */
    private function getOrderData(Order $order): array
    {
        $cert = $order->latestCert;

        $data = [
            'order_id' => $order->id,
            'domains' => $cert->alternative_names,
            'status' => $cert->status,
        ];

        if ($cert->status === 'active') {
            $data['certificate'] = $cert->cert;
            $data['private_key'] = $cert->private_key;
            $data['ca_certificate'] = $cert->intermediate_cert;
            $data['issued_at'] = $cert->issued_at?->toDateString();
            $data['expires_at'] = $cert->expires_at?->toDateString();
        }

        // 文件验证信息：processing 状态且 DCV 方式为文件类
        if ($cert->status === 'processing') {
            $dcvMethod = $cert->dcv['method'] ?? null;
            if (in_array($dcvMethod, ['file', 'http', 'https'])) {
                $data['file'] = [
                    'path' => $cert->dcv['file']['path'] ?? '',
                    'content' => $cert->dcv['file']['content'] ?? '',
                ];
            }
        }

        return $data;
    }
}
