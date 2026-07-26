<?php

namespace App\Http\Controllers\Deploy;

use App\Exceptions\ApiResponseException;
use App\Http\Controllers\Controller;
use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Services\Order\Action;
use App\Services\Order\AutoDeployReportService;
use App\Services\Order\AutoRenewService;
use App\Services\Order\OrderCommitResilience;
use App\Services\Order\Utils\OrderUtil;
use App\Support\ApiErrorCode;
use App\Support\MutexLock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiController extends Controller
{
    use MutexLock;

    /**
     * 批量查询单次可传的最大订单 ID 数
     *
     * 每个 ID 至多对应一个订单，故它同时就是返回条数上限——响应无需分页。
     */
    private const MAX_BATCH_ITEMS = 100;

    /**
     * 续费链追踪的硬上限跳数
     *
     * 链长等于该证书的历史续费次数，一年一次意味着 60 年，正常数据远够用；
     * 触顶只可能是脏数据（异常长链 / visited 判环兜不住的形态）。
     */
    private const RENEW_CHAIN_MAX_HOPS = 60;

    /**
     * 查询订单
     *
     * order 必填：单个订单 ID，或英文逗号分隔的多个订单 ID（上限 MAX_BATCH_ITEMS）。
     * 自动部署链路的发起方（客户端 daemon、管理端「部署命令」复制）永远持有准确订单号，
     * 故不再支持域名查询与空参数列全量——两者都只有手工场景、前端从未展示，且域名走的是
     * `alternative_names LIKE %domain%` 子串匹配，会把 notexample.com 这类跨域证书混进结果。
     *
     * field=certificate|private_key：返回纯 PEM 文本（适配 certimate 等 URL 拉取），此模式下
     * order 可以是单个订单 ID **或单个域名**（精确匹配 common_name 取最新 active 证书）。
     * 域名形态只服务 URL 拉取：certimate 配置里 URL 是填死的，而订单号会变（到期后重新下单
     * 不产生 last_cert_id 关联，resolveRenewedOrder 追不回来），域名 URL 则始终有效。
     */
    public function query(Request $request): mixed
    {
        $request->validate([
            'order' => ['nullable', 'string'],
            'field' => ['nullable', 'in:certificate,private_key'],
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

        // 不带 field 的 JSON 查询：order 必填且只接受订单 ID（单个或英文逗号分隔）。
        // 空串同样落这里报错——空参数列全量已取消。
        if (! preg_match('/^\d+(,\d+)*$/', $order)) {
            $this->error(
                'order 参数必填，仅支持订单 ID，多个用英文逗号分隔',
                ['error_code' => ApiErrorCode::INVALID_ORDER]
            );
        }

        // 含逗号：批量查询
        if (str_contains($order, ',')) {
            $this->success($this->queryResult($this->batchQuery($order)));
        }

        // 单个 ID
        $found = Order::with('latestCert')
            ->whereHas('latestCert')
            ->where('id', $order)
            ->first();

        if (! $found) {
            $this->error('未找到匹配的订单', ['error_code' => ApiErrorCode::ORDER_NOT_FOUND]);
        }

        $this->success($this->queryResult(collect([$this->resolveRenewedOrder($found)])));
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
            $this->error('订单不存在', ['error_code' => ApiErrorCode::ORDER_NOT_FOUND]);
        }

        $cert = $order->latestCert;
        $orderId = $order->id;
        $reQuery = false;

        // 在途订单（unpaid/pending，签发进行中）CSR/域名已在建单时定型、无法再变更：
        // 客户端携带非空 csr 或 domains 时显式报错，不静默丢弃新 CSR 后签发出与新私钥错配的证书。
        // 仅传 order_id 的推进（pay/commit 自愈）不带 csr/domains，不触发此守卫。
        if (in_array($cert->status, ['unpaid', 'pending'], true)
            && (! empty($params['csr']) || ! empty($params['domains']))) {
            $this->error(
                "订单处于{$cert->status}状态（签发进行中），无法变更 CSR 或域名；请等待当前签发完成后再重签，或联系管理员处理卡单",
                ['error_code' => ApiErrorCode::ORDER_IN_PROGRESS]
            );
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
                    $this->error(
                        '该产品不支持委托验证',
                        ['error_code' => ApiErrorCode::VALIDATION_METHOD_UNSUPPORTED]
                    );
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
                    $this->error(
                        '该产品不支持文件验证',
                        ['error_code' => ApiErrorCode::VALIDATION_METHOD_UNSUPPORTED]
                    );
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
                    $this->error(
                        '该订单未开启自动续费',
                        ['error_code' => ApiErrorCode::AUTO_RENEW_DISABLED]
                    );
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
                bccomp($availableBalance, $estimatedAmount, 2) < 0 && $this->error(
                    '余额不足，请充值后再续费',
                    ['error_code' => ApiErrorCode::INSUFFICIENT_BALANCE]
                );

                $updateParams['action'] = 'renew';
                $updateParams['period'] = $order->period;
            } else {
                $updateParams['action'] = 'reissue';
            }

            try {
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
            } catch (ApiResponseException $e) {
                // local CSR 提交（renew_mode=local，携带 CSR）后的服务端签发处理失败：服务端自写一行签发
                // 失败记录（客户端零参与——签发失败不由客户端上报）。非本地路径（服务端生成 CSR）的同步错误
                // 由调用方自行感知、不重复留痕。message 以「本地签发失败：」开头，与客户端部署失败天然可辨。
                if (! empty($params['csr'])) {
                    app(AutoDeployReportService::class)->recordServerFailure(
                        $order,
                        '本地签发失败：'.($e->getApiResponse()['msg'] ?? '未知错误')
                    );
                }

                throw $e;
            }
        }

        if ($reQuery) {
            $order = Order::with('latestCert')->whereHas('latestCert')->where('id', $orderId)->first();

            if (! $order) {
                $this->error('订单不存在', ['error_code' => ApiErrorCode::ORDER_NOT_FOUND]);
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
            $this->error('订单不存在', ['error_code' => ApiErrorCode::ORDER_NOT_FOUND]);
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
            // message 为前向兼容可选字段，清理后随自动部署记录保存。
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        // 通过 Order 查询（Order 已被 UserScope 限制）
        $order = Order::with('latestCert')
            ->where('id', $params['order_id'])
            ->first();

        if (! $order) {
            $this->error('订单不存在', ['error_code' => ApiErrorCode::ORDER_NOT_FOUND]);
        }

        $cert = $order->latestCert;
        if (! $cert) {
            $this->error('证书不存在', ['error_code' => ApiErrorCode::CERT_NOT_FOUND]);
        }

        $deployTime = null;

        // 成功回调未传或无法解析 deployed_at 时，以服务端接收时间作为部署时间。
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

        } elseif (! empty($params['deployed_at'])) {
            try {
                $deployTime = Carbon::parse($params['deployed_at']);
            } catch (\Exception) {
                // 失败上报的无效部署时间不影响留痕，记录为 null。
            }
        }

        $message = isset($params['message']) && $params['message'] !== ''
            ? mb_substr(strip_tags($params['message']), 0, 500)
            : null;

        AutoDeployReport::create([
            'order_id' => $order->id,
            'cert_id' => $cert->id,
            'status' => $params['status'],
            'deployed_at' => $deployTime,
            'ip' => $request->ip(),
            'message' => $message,
        ]);

        // 记录全量之上做通知消噪：失败触发按订单去重的 SystemAlert；成功即清去重键，
        // 复发时立即再告警（healthy 分支清键，与服务端自写签发失败共用同一 per-order 去重）。
        $reportService = app(AutoDeployReportService::class);
        if ($params['status'] === 'failure') {
            $reportService->notifyFailure($order, $message);
        } else {
            $reportService->clearFailureAlert($order);
        }

        $this->success([
            'order_id' => $params['order_id'],
            'status' => $params['status'],
            'recorded' => true,
            'renew_before_days' => (int) get_system_setting('site', 'renewBeforeDays', 14),
        ]);
    }

    /**
     * 统一返回格式
     *
     * 不分页：单 ID 恒 1 条，批量受 MAX_BATCH_ITEMS 约束且每个 ID 至多一个订单，
     * 故返回条数恒 ≤ MAX_BATCH_ITEMS，total / page / page_size 三个字段没有信息量，已移除。
     */
    private function queryResult(Collection $orders): array
    {
        return [
            'data' => $orders->map(fn ($o) => $this->getOrderData($o))->toArray(),
            'renew_before_days' => (int) get_system_setting('site', 'renewBeforeDays', 14),
        ];
    }

    /**
     * 批量查询：英文逗号分隔的订单 ID
     *
     * 调用前 query() 已用 /^\d+(,\d+)*$/ 校验过形态，此处只做条数上限与查库。
     */
    private function batchQuery(string $queryStr): Collection
    {
        $ids = explode(',', $queryStr);

        if (count($ids) > self::MAX_BATCH_ITEMS) {
            // 与形态非法同归 invalid_order：都是"order 参数本身不合法"，且同样是确定性失败
            $this->error(
                '单次最多查询 '.self::MAX_BATCH_ITEMS.' 条',
                ['error_code' => ApiErrorCode::INVALID_ORDER]
            );
        }

        return Order::with('latestCert')
            ->whereHas('latestCert')
            ->whereIn('id', $ids)
            ->get()
            ->map(fn ($o) => $this->resolveRenewedOrder($o))
            ->unique('id')
            ->sortByDesc('created_at')
            ->values();
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
     *
     * 边界（本方法跑在 HTTP 请求线程里，每跳一次 DB 查询；Linux 下 max_execution_time 不计 I/O
     * 等待，无界循环会长时间占住 PHP-FPM 进程）：
     *  1) visited 精确判环——每跳的下一站完全由「当前 order → latestCert」决定，故 order id 重复
     *     即必然死循环，第一次重复就退出（原先只比对 `$nextCert->order_id === $order->id`，仅能防
     *     直接自环，A→B→C→A 这类三跳以上的环每步都不相等，永远退不出）；
     *  2) MAX_HOPS 硬上限兜底链虽不成环但异常长的脏数据。
     *
     * 触顶/判环都**返回当前 order（status 仍是 renewed）而不报错**：客户端收到 renewed 走终态分支
     * 停止并等人工，正是数据成环这类事故需要的语义；若改返 code=0，客户端会当网络错误每天重试，
     * 反把需人工介入的数据事故降级成静默重试。
     */
    private function resolveRenewedOrder(Order $order): Order
    {
        $cert = $order->latestCert;
        $chain = [$order->id];
        $visited = [$order->id => true];

        while ($cert->status === 'renewed') {
            if (count($chain) > self::RENEW_CHAIN_MAX_HOPS) {
                Log::error('[deploy.renew_chain] 续费链超过硬上限，停止追踪', [
                    'entry_order_id' => $chain[0],
                    'stopped_at_order_id' => $order->id,
                    'max_hops' => self::RENEW_CHAIN_MAX_HOPS,
                    'chain' => $chain,
                ]);
                break;
            }

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

            if (isset($visited[$newOrder->id])) {
                Log::error('[deploy.renew_chain] 续费链成环，停止追踪', [
                    'entry_order_id' => $chain[0],
                    'stopped_at_order_id' => $order->id,
                    'repeated_order_id' => $newOrder->id,
                    'chain' => $chain,
                ]);
                break;
            }

            $visited[$newOrder->id] = true;
            $chain[] = $newOrder->id;
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
