<?php

namespace Plugins\CloudDeploy\Services;

use App\Exceptions\ApiResponseException;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Support\TenantConsistency;

class TargetMutationService
{
    private const STRUCTURAL_KEYS = ['access_id', 'order_id', 'product', 'config'];

    public function __construct(private readonly OrderOptionService $orders) {}

    /**
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    public function prepareForCreate(array $validated, int $userId): array
    {
        $accessId = (int) $validated['access_id'];
        $orderId = (int) $validated['order_id'];

        if (! TenantConsistency::check($userId, $accessId, $orderId)) {
            throw new ApiResponseException('凭证或订单不属于当前用户', null, null, 0);
        }

        if (! $this->orders->isSelectable($orderId, $userId)) {
            throw new ApiResponseException('订单当前不可绑定部署目标', null, null, 0);
        }

        $validated['user_id'] = $userId;
        $this->assertUniqueTarget(
            $userId,
            $accessId,
            (string) $validated['product'],
            (array) $validated['config'],
        );

        return $validated;
    }

    /**
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    public function prepareForUpdate(CloudDeployTarget $target, array $validated, ?int $lockedUserId): array
    {
        $finalAccessId = (int) ($validated['access_id'] ?? $target->access_id);
        $finalOrderId = (int) ($validated['order_id'] ?? $target->order_id);
        $finalUserId = Order::withoutGlobalScopes()
            ->whereKey($finalOrderId)
            ->value('user_id');

        if ($finalUserId === null) {
            throw new ApiResponseException('订单不存在', null, null, 0);
        }
        $finalUserId = (int) $finalUserId;

        if ($lockedUserId !== null && $finalUserId !== $lockedUserId) {
            throw new ApiResponseException('凭证或订单不属于当前用户', null, null, 0);
        }

        if (! TenantConsistency::check($finalUserId, $finalAccessId, $finalOrderId)) {
            throw new ApiResponseException('凭证与订单不属于同一用户', null, null, 0);
        }

        $orderChanged = array_key_exists('order_id', $validated)
            && (int) $validated['order_id'] !== (int) $target->order_id;
        if ($orderChanged && ! $this->orders->isSelectable($finalOrderId, $finalUserId)) {
            throw new ApiResponseException('订单当前不可绑定部署目标', null, null, 0);
        }

        $validated['user_id'] = $finalUserId;
        $structuralChanged = $this->hasStructuralChange($target, $validated);

        if ($structuralChanged) {
            $this->assertUniqueTarget(
                $finalUserId,
                $finalAccessId,
                (string) ($validated['product'] ?? $target->product),
                array_key_exists('config', $validated) ? (array) $validated['config'] : (array) $target->config,
                (int) $target->id,
            );

            $validated['last_cert_id'] = null;
            $validated['last_status'] = null;
            $validated['last_error'] = null;
            $validated['last_deployed_at'] = null;
            $validated['pending_job'] = null;
        }

        return $validated;
    }

    /** @param array<string,mixed> $validated */
    public function create(array $validated): CloudDeployTarget
    {
        try {
            return CloudDeployTarget::create($validated);
        } catch (QueryException $e) {
            $this->throwDuplicateTargetIfNeeded($e);
            throw $e;
        }
    }

    /** @param array<string,mixed> $validated */
    public function update(CloudDeployTarget $target, array $validated): void
    {
        $target->fill($validated);

        try {
            $target->save();
        } catch (QueryException $e) {
            $this->throwDuplicateTargetIfNeeded($e);
            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function assertUniqueTarget(
        int $userId,
        int $accessId,
        string $product,
        array $config,
        ?int $ignoreId = null,
    ): void {
        $query = CloudDeployTarget::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('access_id', $accessId)
            ->where('product', $product)
            ->where('config_hash', CloudDeployTarget::configHash($config));

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw new ApiResponseException('部署目标已存在，不能多个订单绑定同一推送目标', null, null, 0);
        }
    }

    private function throwDuplicateTargetIfNeeded(QueryException $e): void
    {
        $message = $e->getMessage();
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if ($sqlState === '23000' && str_contains($message, 'cloud_deploy_targets_unique_target')) {
            throw new ApiResponseException('部署目标已存在，不能多个订单绑定同一推送目标', null, null, 0);
        }
    }

    /** @param array<string,mixed> $validated */
    private function hasStructuralChange(CloudDeployTarget $target, array $validated): bool
    {
        foreach (self::STRUCTURAL_KEYS as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $current = $target->getAttribute($key);
            if ($key === 'config') {
                if ((array) $current !== (array) $validated[$key]) {
                    return true;
                }

                continue;
            }

            if ($current != $validated[$key]) {
                return true;
            }
        }

        return false;
    }
}
