<?php

namespace App\Console\Commands;

use App\Models\CnameDelegation;
use App\Models\DomainValidationRecord;
use App\Models\Order;
use App\Services\Delegation\AutoDcvTxtService;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Order\Action;
use App\Services\Order\Utils\VerifyUtil;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 定时验证证书
 * 每1分钟执行一次
 */
class ValidateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedule:validate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto verify processing certificate';

    /**
     * 时间节点（分钟）
     * 表示从创建时间开始的累积时间点：创建后3分钟、6分钟、10分钟、20分钟...
     */
    protected array $time_nodes = [
        3, 6, 10, 20, 30, 45, 60, 120, 180, 240, 360, 540, 360 * 2, 360 * 3, 360 * 4, 360 * 5, 360 * 6, 360 * 7, 360 * 8,
    ];

    /**
     * 互斥锁 cache key 与 TTL（秒）。
     * Cache::add 实现原子获取；每个订单处理前 Cache::put 刷新 TTL 模拟心跳。
     * 健康任务无论跑多久都不会被锁过期；死进程停止续期后 TTL 到期自动释放。
     */
    private const LOCK_KEY = 'cmd:schedule:validate';

    private const LOCK_TTL = 120;

    /**
     * Execute the console command.
     *
     * sub-minute 调度可能在 30 秒就再次触发，靠 Cache::add 返回 false 跳过。
     */
    public function handle(): void
    {
        if (! Cache::add(self::LOCK_KEY, 1, self::LOCK_TTL)) {
            return; // 已有实例在跑
        }

        // 让锁的 TTL 来管生命周期，避免 PHP 默认 max_execution_time 提前杀进程
        @set_time_limit(0);

        try {
            $this->runValidation();
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    private function runValidation(): void
    {
        // 查询所有待验证的订单：状态为processing或approving且有DCV配置的证书
        $orders = Order::with(['latestCert'])
            ->whereHas('latestCert', function ($query) {
                $query->whereIn('status', ['processing', 'approving'])
                    ->where('dcv', '!=', null)
                    ->where('validation', '!=', null);
            })
            ->get();

        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');
        $this->info("[$siteName] 证书验证命令开始执行");
        $this->info("待验证订单数量: {$orders->count()}");

        foreach ($orders as $order) {
            // 心跳：刷新锁 TTL，让健康长任务不被误杀
            Cache::put(self::LOCK_KEY, 1, self::LOCK_TTL);

            try {
                // 查找或创建域名验证记录
                $record = DomainValidationRecord::where('order_id', $order->id)->first();

                if (! $record) {
                    // 首次创建验证记录：1分钟后开始首次验证
                    $record = new DomainValidationRecord([
                        'order_id' => $order->id,
                        'last_check_at' => now(),
                        'next_check_at' => now()->addMinutes(), // 首次验证在1分钟后
                    ]);
                    $record->save();
                }

                // 检查是否到了验证时间
                if ($record->next_check_at->timestamp <= time()) {
                    $cert = $order->latestCert;
                    $action = app(Action::class);

                    // 对于需要验证内容的方法，优先检查 validation 是否就绪
                    $method = $cert->dcv['method'] ?? '';
                    if (in_array($method, ['txt', 'cname', 'file', 'http', 'https'], true)) {
                        if (! $action->isValidationReady($cert->validation ?? null, $method)) {
                            $this->info("订单 #$order->id: validation 未就绪，先执行同步");
                            try {
                                $action->sync($order->id, true);
                                $cert->refresh();
                            } catch (Throwable $e) {
                                $this->warn("订单 #$order->id: 同步失败 - ".$e->getMessage());
                            }

                            // 同步后再次检查
                            if (! $action->isValidationReady($cert->validation ?? null, $method)) {
                                $this->warn("订单 #$order->id: 同步后 validation 仍未就绪，跳过本次验证");
                                $this->setNextCheckAt($record);

                                continue;
                            }
                        }
                    }

                    // 创建 delegation 任务处理 TXT 记录写入
                    if ($cert->dcv['method'] === 'txt' && ($cert->dcv['is_delegate'] ?? false)) {
                        // 检测 validation 是否为空
                        if (! empty($cert->validation)) {
                            // 检测是需要处理委托
                            $autoDcvService = new AutoDcvTxtService;
                            $shouldProcessDelegation = $autoDcvService->shouldProcessDelegation($order);

                            // 创建委托任务
                            $shouldProcessDelegation && $action->createTask($order->id, 'delegation');
                        }
                    }

                    // 根据证书状态和验证方法决定验证方式
                    if ($cert->status === 'processing' && in_array($cert->dcv['method'] ?? '',
                        ['txt', 'cname', 'file', 'http', 'https'])) {

                        // 委托验证：执行即时检测
                        $this->checkDelegationValidity($cert->validation);

                        // 执行域名验证（DNS/HTTP/HTTPS验证）
                        $verified = VerifyUtil::verifyValidation($cert->validation);

                        if ($verified['code'] == 1) {
                            // 验证成功：创建重新验证任务
                            $action->createTask($order->id, 'revalidate');
                            $this->info("订单 #$order->id: 验证成功，已创建提交CA验证的任务");
                        } else {
                            // 验证失败
                            $errorMsg = $verified['msg'] ?: '验证失败';
                            $this->warn("订单 #$order->id: $errorMsg");
                        }
                    } else {
                        // 其他状态：直接创建同步任务（如approving状态等待CA处理）
                        $action = app(Action::class);
                        $action->createTask($order->id, 'sync');
                        $this->info("订单 #$order->id: 已创建同步任务");
                    }

                    // 无论验证成功失败，都基于创建时间设置下次检测时间
                    $this->setNextCheckAt($record);
                }

                $nextCheckTime = $record->next_check_at->format('Y-m-d H:i:s');
                $this->info("订单 #$order->id: 下次检测时间 $nextCheckTime");
            } catch (Throwable $e) {
                $this->error("订单 #$order->id: 验证异常 - {$e->getMessage()}");
            }
        }
    }

    /**
     * 设置下次验证时间
     *
     * 基于创建时间和时间节点数组，设置下次验证的绝对时间
     *
     * @param  DomainValidationRecord  $record  域名验证记录
     */
    protected function setNextCheckAt(DomainValidationRecord $record): void
    {
        // 计算从创建时间到现在的分钟数
        $elapsed_minutes = $record->created_at->diffInMinutes(now());

        // 找到下一个时间节点
        $next_time_node = $this->getNextTimeNode((int) $elapsed_minutes);

        if ($next_time_node > 0) {
            // 更新验证记录
            $record->last_check_at = now();

            // 基于创建时间计算下次验证的绝对时间
            $record->next_check_at = $record->created_at->addMinutes($next_time_node);

            $record->save();

            $interval_minutes = intval($next_time_node - $elapsed_minutes);
            $this->info("订单 #$record->order_id: 将在 $interval_minutes 分钟后再次检测（距创建 $next_time_node 分钟）");
        }
    }

    /**
     * 根据已过去的时间获取下一个时间节点
     *
     * @param  int  $elapsed_minutes  从创建时间已过去的分钟数
     * @return int 下一个时间节点（分钟）
     */
    protected function getNextTimeNode(int $elapsed_minutes): int
    {
        // 找到第一个大于已过去时间的时间节点
        foreach ($this->time_nodes as $time_node) {
            if ($time_node > $elapsed_minutes) {
                return $time_node;
            }
        }

        // 所有时间节点用完后，每12小时检测一次
        $lastNode = end($this->time_nodes);
        $intervals = (int) floor(($elapsed_minutes - $lastNode) / 720) + 1;

        return $lastNode + $intervals * 720;
    }

    /**
     * 检测委托验证的有效性
     * 在执行验证前即时检测委托记录状态
     *
     * @param  array|null  $validation  验证信息数组
     */
    protected function checkDelegationValidity(?array $validation): void
    {
        if (empty($validation)) {
            return;
        }

        // 提取并去重 delegation_id，避免同一委托被多次检测
        $delegationIds = collect($validation)
            ->pluck('delegation_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($delegationIds)) {
            return;
        }

        $delegationService = app(CnameDelegationService::class);

        foreach ($delegationIds as $delegationId) {
            $delegation = CnameDelegation::find($delegationId);
            if (! $delegation) {
                $this->warn("委托记录 #$delegationId 不存在");

                continue;
            }

            // 即时检测委托状态
            $valid = $delegationService->checkAndUpdateValidity($delegation);
            if ($valid) {
                $this->info("委托 #{$delegation->id} ({$delegation->zone}) 检测有效");
            } else {
                $this->warn("委托 #{$delegation->id} ({$delegation->zone}) 检测无效: {$delegation->last_error}");
            }
        }
    }
}
