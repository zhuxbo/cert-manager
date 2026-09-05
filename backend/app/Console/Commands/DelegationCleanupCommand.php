<?php

namespace App\Console\Commands;

use App\Models\CnameDelegation;
use App\Models\Order;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Delegation\DelegationConfigService;
use App\Services\Delegation\DelegationDnsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class DelegationCleanupCommand extends Command
{
    private const int RETENTION_DAYS = 14;

    /** @var string */
    protected $signature = 'delegation:cleanup';

    /** @var string */
    protected $description = '清理本系统非处理中及超过 14 天的委托 DNS 记录';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dnsService = app(DelegationDnsService::class);
        $configService = app(DelegationConfigService::class);

        $this->info('开始清理委托DNS记录...');

        $failed = false;
        try {
            $configs = $configService->all();
            $invalidSettings = $configService->invalidSettings();
            foreach ($invalidSettings as $settingKey => $reason) {
                $failed = true;
                $this->error("[$settingKey] 委托域配置无效: $reason");
                Log::error('委托域配置无效', [
                    'setting_key' => $settingKey,
                    'reason' => $reason,
                ]);
            }

            if ($configs === []) {
                if ($invalidSettings !== []) {
                    return self::FAILURE;
                }

                $this->error('代理域名未设置，无法执行清理');
                $cleanedMarkCount = $this->cleanDatabaseMarks([], [], now()->subDays(self::RETENTION_DAYS)->timestamp);
                $this->info("清理了 $cleanedMarkCount 个数据库标记");

                return self::SUCCESS;
            }

            $keepLabelsByDomain = $this->loadKeepLabelsByDomain();
            $localLabels = CnameDelegation::pluck('label')->mapWithKeys(fn ($label) => [$this->normalizeLabel($label) => true])->all();
        } catch (Throwable $e) {
            $this->error('清理准备失败: '.$e->getMessage());
            Log::error('委托DNS记录清理准备失败', [
                'exception' => $e::class,
            ]);

            return self::FAILURE;
        }

        $deletedLabelsByDomain = [];
        $listedLabelsByDomain = [];
        $cutoffTimestamp = now()->subDays(self::RETENTION_DAYS)->timestamp;

        foreach (array_keys($configs) as $proxyDomain) {
            $deletedLabels = [];
            $deletedRecordCount = 0;
            try {
                $this->info("委托域名: $proxyDomain");
                $allTxtRecords = $dnsService->getAllTxtRecords($proxyDomain);
                $keepLabels = $keepLabelsByDomain[$proxyDomain] ?? [];
                $listedLabelsByDomain[$proxyDomain] = [];
                foreach ($allTxtRecords as $record) {
                    $listedLabelsByDomain[$proxyDomain][$this->normalizeLabel($record['name'] ?? '')] = true;
                }

                $recordsToDelete = collect($allTxtRecords)->filter(function ($record) use ($keepLabels, $localLabels, $cutoffTimestamp) {
                    $name = $this->normalizeLabel($record['name'] ?? '');
                    if (preg_match('/^([0-9a-f]{32}|[0-9a-f]{64})$/i', $name) !== 1) {
                        return false;
                    }

                    $changedAt = $record['changed_at'] ?? null;

                    return ! isset($keepLabels[$name])
                        && (isset($localLabels[$name])
                            || (is_int($changedAt) && $changedAt > 0 && $changedAt < $cutoffTimestamp));
                });

                $this->info('需要删除的记录数: '.$recordsToDelete->count());
                if ($recordsToDelete->isEmpty()) {
                    $this->info("[$proxyDomain] 没有需要清理的记录");

                    continue;
                }

                $recordIdsByLabel = [];
                foreach ($recordsToDelete as $record) {
                    $label = $this->normalizeLabel($record['name'] ?? '');
                    $recordId = $record['id'] ?? null;
                    if ((! is_int($recordId) && ! is_string($recordId))
                        || trim((string) $recordId) === '') {
                        throw new UnexpectedValueException('委托 DNS 记录 ID 无效');
                    }

                    $recordIdsByLabel[$label][] = $recordId;
                }

                foreach ($recordIdsByLabel as $label => $recordIds) {
                    $dnsService->deleteRecords($proxyDomain, $recordIds);
                    // 每个 label 的候选记录完整删除后立即记录；后续 label 失败不能抹掉已确认结果。
                    $deletedLabelsByDomain[$proxyDomain][$label] = true;
                    $deletedLabels[] = $label;
                    $deletedRecordCount += count($recordIds);
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("[$proxyDomain] 清理失败: ".$e->getMessage());
                Log::error('委托DNS记录清理失败', [
                    'proxy_domain' => $proxyDomain,
                    'exception' => $e::class,
                ]);
            } finally {
                if ($deletedLabels !== []) {
                    Log::info('批量清理委托DNS记录成功', [
                        'proxy_domain' => $proxyDomain,
                        'deleted_count' => $deletedRecordCount,
                        'deleted_labels' => $deletedLabels,
                    ]);
                }
            }
        }

        $cleanedMarkCount = 0;
        try {
            $this->info('正在清理数据库中的标记...');
            $cleanedMarkCount = $this->cleanDatabaseMarks(
                $deletedLabelsByDomain,
                $listedLabelsByDomain,
                $cutoffTimestamp,
            );
        } catch (Throwable $e) {
            $failed = true;
            $this->error('数据库标记清理失败: '.$e->getMessage());
            Log::error('委托DNS数据库标记清理失败', [
                'exception' => $e::class,
            ]);
        }

        $this->info("清理了 $cleanedMarkCount 个数据库标记");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 在途引用只加载一次，并按订单冻结的委托目标建立保留集。
     *
     * @return array<string, array<string, true>>
     */
    protected function loadKeepLabelsByDomain(): array
    {
        $orders = Order::with('latestCert')
            ->whereHas('latestCert', fn ($query) => $query->where('status', 'processing'))
            ->get();

        $delegationIds = [];
        $references = [];
        foreach ($orders as $order) {
            foreach ($order->latestCert->validation ?? [] as $validation) {
                if (! array_key_exists('delegation_id', $validation)
                    || $validation['delegation_id'] === null) {
                    continue;
                }

                $delegationId = $this->positiveDelegationId($validation['delegation_id']);
                if ($delegationId === null) {
                    throw new UnexpectedValueException('在途订单委托 ID 无效');
                }

                $delegationIds[$delegationId] = true;
                $references[] = [
                    'delegation_id' => $delegationId,
                    'validation' => $validation,
                ];
            }
        }

        if ($delegationIds === []) {
            return [];
        }

        $delegationsById = CnameDelegation::query()
            ->whereIn('id', array_keys($delegationIds))
            ->get(['id', 'label', 'proxy_domain'])
            ->keyBy('id');
        $keepLabelsByDomain = [];

        foreach ($references as $reference) {
            $delegation = $delegationsById->get($reference['delegation_id']);
            if (! $delegation) {
                continue;
            }

            $proxyDomain = $this->proxyDomainForValidation($delegation, $reference['validation']);
            if ($proxyDomain === null) {
                throw new UnexpectedValueException('在途订单委托目标无效');
            }

            $keepLabelsByDomain[$proxyDomain][$this->normalizeLabel($delegation->label)] = true;
        }

        return $keepLabelsByDomain;
    }

    /**
     * 有委托行时仅清理“精确 ID + 绑定代理域 + 已删除 label”命中的标记；
     * 正整数 ID 对应行已不存在时只清本地标记，不据此猜域或触碰远端 DNS。
     *
     * @param  array<string, array<string, true>>  $deletedLabelsByDomain
     * @param  array<string, array<string, true>>  $listedLabelsByDomain
     */
    protected function cleanDatabaseMarks(
        array $deletedLabelsByDomain,
        array $listedLabelsByDomain,
        int $cutoffTimestamp,
    ): int {
        $cleanedCount = 0;

        Order::with(['latestCert' => fn ($query) => $query->select('id', 'status', 'validation')])
            ->whereHas('latestCert', fn ($query) => $query->where('validation', 'like', '%auto_txt_written%'))
            ->chunkById(200, function ($orders) use (
                $deletedLabelsByDomain,
                $listedLabelsByDomain,
                $cutoffTimestamp,
                &$cleanedCount,
            ) {
                $delegationIds = [];
                foreach ($orders as $order) {
                    foreach ($order->latestCert->validation ?? [] as $validation) {
                        if (($validation['auto_txt_written'] ?? false) !== true) {
                            continue;
                        }

                        $delegationId = $this->positiveDelegationId($validation['delegation_id'] ?? null);
                        if ($delegationId !== null) {
                            $delegationIds[$delegationId] = true;
                        }
                    }
                }

                $delegationsById = $delegationIds === []
                    ? collect()
                    : CnameDelegation::query()
                        ->whereIn('id', array_keys($delegationIds))
                        ->get(['id', 'label', 'proxy_domain'])
                        ->keyBy('id');

                foreach ($orders as $order) {
                    $cert = $order->latestCert;
                    $validations = $cert->validation;
                    if (empty($validations)) {
                        continue;
                    }

                    $hasChanges = false;
                    $updatedValidations = [];

                    foreach ($validations as $index => $validation) {
                        $delegationId = $this->positiveDelegationId($validation['delegation_id'] ?? null);
                        if (($validation['auto_txt_written'] ?? false) !== true
                            || $delegationId === null
                            || ($cert->status === 'processing' && ! $this->isExpiredMark($validation, $cutoffTimestamp))) {
                            $updatedValidations[$index] = $validation;

                            continue;
                        }

                        $delegation = $delegationsById->get($delegationId);
                        if (! $delegation && ! $this->isExpiredMark($validation, $cutoffTimestamp)) {
                            $updatedValidations[$index] = $validation;

                            continue;
                        }
                        $proxyDomain = $delegation !== null
                            ? $this->proxyDomainForValidation($delegation, $validation)
                            : null;
                        $label = $delegation !== null
                            ? $this->normalizeLabel($delegation->label)
                            : null;
                        $deletedLabels = $proxyDomain !== null
                            ? ($deletedLabelsByDomain[$proxyDomain] ?? null)
                            : null;
                        $listedLabels = $proxyDomain !== null
                            ? ($listedLabelsByDomain[$proxyDomain] ?? null)
                            : null;
                        $wasDeleted = $deletedLabels !== null && isset($deletedLabels[$label]);
                        $wasConfirmedAbsent = $listedLabels !== null && ! isset($listedLabels[$label]);

                        if ($delegation !== null
                            && ! $wasDeleted
                            && ! $wasConfirmedAbsent) {
                            $updatedValidations[$index] = $validation;

                            continue;
                        }

                        if (! $delegation) {
                            unset($validation['delegation_id']);
                        }
                        unset(
                            $validation['auto_txt_written'],
                            $validation['auto_txt_written_at'],
                        );
                        $updatedValidations[$index] = $validation;
                        $hasChanges = true;
                        $cleanedCount++;
                    }

                    if ($hasChanges) {
                        $cert->validation = $updatedValidations;
                        $cert->save();
                    }
                }
            });

        return $cleanedCount;
    }

    private function positiveDelegationId(mixed $delegationId): ?int
    {
        return is_int($delegationId) && $delegationId > 0 ? $delegationId : null;
    }

    private function normalizeLabel(mixed $label): string
    {
        return strtolower((string) $label);
    }

    private function isExpiredMark(array $validation, int $cutoffTimestamp): bool
    {
        $writtenAt = $validation['auto_txt_written_at'] ?? null;
        if (! is_string($writtenAt) || trim($writtenAt) === '') {
            return false;
        }

        try {
            return Carbon::parse($writtenAt)->timestamp < $cutoffTimestamp;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 新数据以 validation 快照为准；旧数据没有快照时回落共享委托记录。
     * 快照存在但无法解析时返回 null，由在途保留集失败关闭，历史标记则保持不动。
     */
    private function proxyDomainForValidation(CnameDelegation $delegation, array $validation): ?string
    {
        $target = $validation['delegation_target'] ?? null;
        if ($target === null || $target === '') {
            return $delegation->proxy_domain ?: null;
        }
        if (! is_string($target)) {
            return null;
        }

        return app(CnameDelegationService::class)->proxyDomainFromTarget($delegation, $target);
    }
}
