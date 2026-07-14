<?php

namespace App\Http\Controllers\Deploy;

use App\Http\Controllers\Controller;
use App\Models\Cert;
use App\Models\ErrorLog;
use App\Models\Order;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Action;
use App\Services\Order\AutoRenewService;
use App\Services\Order\OrderCommitResilience;
use App\Services\Order\Utils\OrderUtil;
use App\Support\MutexLock;
use App\Utils\LogScrubber;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApiController extends Controller
{
    use MutexLock;

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

        $order = trim((string) $request->input('order'));
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

            // 国密(SM2)双证书不支持单证书字段拉取（certimate 等自动部署会拿到残缺的签名证书）。
            // 前端已隐藏自动部署入口，此处后端兜底防 deploy token 直调绕过（反模式 2/17 防绕过）。
            if (strtolower((string) $cert->encryption_alg) === 'sm2') {
                abort(400, '国密证书为签名+加密双证书，不支持自动部署字段拉取，请下载完整国密包手动部署');
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
        $page = (int) ($request->input('page') ?? 1);
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

        // 在途订单（unpaid/pending，签发进行中）CSR/域名已在建单时定型、无法再变更：
        // 客户端携带非空 csr 或 domains 时显式报错，不静默丢弃新 CSR 后签发出与新私钥错配的证书。
        // 仅传 order_id 的推进（pay/commit 自愈）不带 csr/domains，不触发此守卫。
        if (in_array($cert->status, ['unpaid', 'pending'], true)
            && (! empty($params['csr']) || ! empty($params['domains']))) {
            $this->error("订单处于{$cert->status}状态（签发进行中），无法变更 CSR 或域名；请等待当前签发完成后再重签，或联系管理员处理卡单");
        }

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
            // 产品校验 / auto_renew 校验放互斥锁之前（行为不变，早失败不进临界区）
            $isRenew = $order->period_till?->lt(now()->addDays(15));
            if ($isRenew) {
                // 续费需要检查 auto_renew 设置
                $autoRenewEnabled = app(AutoRenewService::class)->isAutoRenewEnabled($order, $order->user);
                if (! $autoRenewEnabled) {
                    $this->error('该订单未开启自动续费');
                }

                // O3-D：续费余额预检（fail-fast + 友好文案；孤儿主防线是下方 atomicity，非预检——预检通过后
                // 并发耗尽余额由 charge 锁内二次校验兜住，事务回滚撤销 renew）。Deploy $order->user 懒加载现取
                // 现读，单请求内 balance 新鲜，无需 refresh（区别于 AutoRenew O2）。口径与 AutoRenew 预检逐字一致。
                $payer = $order->user;
                $estimatedAmount = OrderUtil::getLatestCertAmount(
                    ['user_id' => $payer->id, 'product_id' => $order->product_id, 'period' => $order->period,
                        'purchased_standard_count' => 0, 'purchased_wildcard_count' => 0],
                    ['standard_count' => $cert->standard_count, 'wildcard_count' => $cert->wildcard_count, 'action' => 'renew'],
                    $order->product->toArray()
                );
                $availableBalance = $payer->availableBalance();
                bccomp($availableBalance, $estimatedAmount, 2) < 0 && $this->error('余额不足，请充值后再续费');

                $updateParams['action'] = 'renew';
                $updateParams['period'] = $order->period;
            } else {
                $updateParams['action'] = 'reissue';
            }

            // order 级互斥 + 外层事务：把本地 renew/reissue（终态化旧证书 + 建新单）+ pay(false)（扣费落 pending）
            // 串行且原子，根治多下游服务器共用订单时并发双开续费单 + 双扣费（被审计的 Deploy×Deploy）。
            // 关键设计约束：
            //  1) 与 Action::commit/cancel 共用 order_mutate_{id} 键（下划线格式），撞进行中
            //     commit/cancel 抢不到抛 MutationBusyException→503（与 V1/V2 同步入口语义一致）；
            //  2) O3-A：pay(false) 进事务与 renew/reissue 原子（charge 纯本地扣费、无上游、无 mutex → 安全嵌套），
            //     charge 失败即整体回滚，杜绝「旧证书终态 + 新单卡 unpaid」孤儿（P0-1 路径 2）；
            //  3) commit 移到互斥锁「外」——reissue 复用同一 orderId，commit 自带同键互斥锁，
            //     若在锁内则二次抢锁必失败自死锁；且 commit 含上游 HTTP，锁内不做上游调用（红线）。
            //  4) 【锁纪律】不在此处对订单行做「先于 renew/reissue 的显式 FOR UPDATE 预锁」：
            //     renew/reissue 的 initParams（CSR keygen + 委托 TXT 逐 token 上游 DNS 写，ProxyDNS 单 token 15s）
            //     在其内部【源订单行锁之前】执行；并发双开的串行主体是 renew(persistOrder)/reissue 内的
            //     「源订单行锁 + 前驱翻转 affected-rows CAS」（CAS 是锁定写 current read，不受 initParams 前置
            //     一致读建立的 RR view 影响，无需外层再叠一把预锁）。此前的预锁会把 keygen + 委托 DNS HTTP 全
            //     罩进订单行锁内——DNSPod 劣化时 ≥4 token 即超 innodb_lock_wait_timeout=50，同订单 sync/renew
            //     抢锁 1205，违反锁内不做上游 HTTP 红线。移除后对齐 V2/AutoRenew「CSR/委托生成先于行锁」范式。
            $orderId = $this->withMutex("order_mutate_$orderId", function () use ($orderId, $action, $updateParams, $isRenew) {
                $resolved = $orderId;

                DB::transaction(function () use (&$resolved, $orderId, $action, $updateParams, $isRenew) {
                    if ($isRenew) {
                        // renew→new：initParams（CSR+委托，锁前）→ persistOrder 锁源订单行 + 前驱 active→renewed CAS。
                        // code=1 成功由 getData 吸收返回 data；并发抢先则内部 CAS affected=0 抛「订单已续费」回滚。
                        $result = $this->getData($action, 'renew', [$updateParams]);
                        $resolved = $result['data']['order_id'] ?? $orderId;
                    } else {
                        // reissue：initParams（CSR+委托，锁前）→ 事务内锁源订单行 + latest_cert_id 基线比对 + 前驱 CAS 翻 reissued。
                        $this->getData($action, 'reissue', [$updateParams]);
                    }

                    // O3-A：pay(false) 纯本地扣费落 pending，与 renew/reissue 同事务原子（charge 失败 → 整体回滚）
                    $this->getData($action, 'pay', [$resolved, false]);
                });

                return $resolved;
            });

            // O3-B：commit 移出互斥锁（commit 自取 order_mutate_{resolved} 锁，此处 mutex 已释放、无自死锁）。
            // 超时/失败/抢锁忙被 getData('commit') 吞 → 订单停 pending、已扣费保留；权威自愈 = ReconcilePendingCommand
            // 主扫描（无 channel 过滤），下游 pull（query 跟 last_cert 链 + 以新 id update）仅为条件式加速。
            $this->getData($action, 'commit', [$orderId]);

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
            // message 为前向兼容可选字段：当前下游四仓均不上送，供后续版本携失败原因说明；
            // 服务端转义 + 截断后记录（见 recordCallbackFailure）。
            'message' => ['nullable', 'string', 'max:500'],
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
                    $deployTime = Carbon::parse($params['deployed_at']);
                } catch (\Exception) {
                    // 解析失败使用当前时间
                }
            }

            $cert->auto_deploy_at = $deployTime;
            $cert->save();
        } elseif ($params['status'] === 'failure') {
            // 部署失败：服务端留痕（error_logs）+ 按 order 7 天滑窗聚合告警（旁路，不改响应封套）
            $this->recordCallbackFailure($request, $order, $params['message'] ?? null);
        }

        $this->success([
            'order_id' => $params['order_id'],
            'status' => $params['status'],
            'recorded' => $params['status'] === 'success',
            'renew_before_days' => (int) get_system_setting('site', 'renewBeforeDays', 14),
        ]);
    }

    /**
     * 记录下游部署失败回调 + 7 天滑窗聚合告警
     *
     * 下游 spec「每天执行一次续签检查」→ 单证书每天至多 1 次 failure 回调、200 ack 不重试，
     * 故用「7 天滑窗计数 ≥ threshold」而非 24h tumbling（后者日频节奏下恒不可达）。
     */
    private function recordCallbackFailure(Request $request, Order $order, ?string $message): void
    {
        // message 是任意 deploy-token 持有者可控自由文本，且告警走 SystemAlert 模板渲染：
        // 调用侧 strip_tags + 截断 ≤256 是第一道（Builder denylist/转义为第二道），两道都要在。
        $safeMsg = '';
        if ($message !== null && $message !== '') {
            $safeMsg = mb_substr(strip_tags($message), 0, 256);
        }

        // 结构化前缀（; 分隔）：滑窗计数用前缀 LIKE "order_id={id};%"，杜绝 order_id=5 误匹配 50/51
        $prefix = "order_id={$order->id};user_id={$order->user_id};deploy_callback_failure";
        $logMessage = $safeMsg !== '' ? $prefix.';reason='.$safeMsg : $prefix;

        // 直写 ErrorLog（不走 LogBuffer——buffer 到请求 terminating 才 flush，写后立即计数会漏当前次）
        ErrorLog::create([
            'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
            'method' => 'POST',
            'url' => LogScrubber::scrubUrl($request->fullUrl()),
            'exception' => 'DeployCallbackFailure',
            'message' => $logMessage,
            'status_code' => 200,
            'ip' => $request->ip(),
        ]);

        $windowDays = (int) config('deploy.callback_failure.window_days', 7);
        $threshold = (int) config('deploy.callback_failure.threshold', 2);
        $ttlHours = (int) config('deploy.callback_failure.dedupe_ttl_hours', 168);

        // 7 天滑窗计数（含当前次，因已直写）
        $count = ErrorLog::where('exception', 'DeployCallbackFailure')
            ->where('message', 'like', "order_id={$order->id};%")
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->count();

        if ($count >= $threshold) {
            // 固定指纹（非默认内容指纹）：计数逐次变化会击穿 per-order 去重致每日刷屏，
            // 传固定指纹使同一订单持续失败在 dedupe TTL 内只发一封（对齐 AutoRenewCommand 固定指纹范式，防计数 churn）。
            app(SystemAlert::class)->send(
                'deploy_callback',
                "订单 #{$order->id} 部署回调持续失败",
                "{$windowDays} 天内 {$count} 次部署失败回调",
                [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'failure_count' => $count,
                    'latest_message' => $safeMsg,
                ],
                dedupeKey: "deploy_callback_fail_{$order->id}",
                dedupeTtlHours: $ttlHours,
                fingerprint: 'deploy_callback_failure',
            );
        }
    }

    /**
     * 统一分页返回格式
     */
    private function paginateResult(Collection $orders, ?Request $request = null): array
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
    private function batchQuery(string $queryStr): Collection
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
        // commit 段吞并守卫收敛至 OrderCommitResilience（V1/V2/Deploy 单一真相源）；镜像 V2 getData。
        // 注（M-2 知情不对称）：unpaid resume 分支走 pay(autoCommit=true)，其 MutationBusyException 经此
        // method='pay'≠'commit' 仍上抛 503——与 active 分支 commit 段吞不对称；既有行为、有意不改（O3 范围
        // 仅 active 分支 + commit 段），下游重试即收敛。该不对称由传入 $method 值天然保留，收敛未触碰。
        return OrderCommitResilience::run(
            fn () => $action->$method(...$params),
            $method,
            fn (array $result) => $this->error($result['msg'], $result['errors'] ?? null),
        );
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

            // 国密(SM2)：附加密证书 + 加密私钥（双证书）并标记算法，供国密客户端/下游代理拿完整数据；
            // certimate 等单证书自动部署不支持国密，已在 query field 拉取处拒绝。
            if (strtolower((string) $cert->encryption_alg) === 'sm2') {
                $data['encryption_alg'] = 'sm2';
                // 加密证书 + 加密私钥成对才下发（与下载包 addSm2CertToZip 成对守卫同口径）：
                // 缺任一（gateway 未就绪）则不附 enc，避免下游拿到"有证书无私钥"的残缺数据
                if ($cert->enc_cert && $cert->enc_key) {
                    $data['enc_certificate'] = $cert->enc_cert;
                    $data['enc_private_key'] = $cert->enc_key;
                    if ($cert->enc_key2) {
                        $data['enc_private_key_gmt0009'] = $cert->enc_key2;
                    }
                }
            }
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
