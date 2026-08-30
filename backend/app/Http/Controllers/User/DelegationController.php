<?php

namespace App\Http\Controllers\User;

use App\Http\Requests\Delegation\GetIdsRequest;
use App\Http\Requests\Delegation\IndexRequest;
use App\Http\Requests\Delegation\StoreRequest;
use App\Http\Requests\Delegation\UpdateRequest;
use App\Models\CnameDelegation;
use App\Services\Delegation\CnameDelegationService;
use Throwable;

/**
 * 用户端 CNAME 委托管理控制器
 */
class DelegationController extends BaseController
{
    protected CnameDelegationService $delegationService;

    public function __construct()
    {
        parent::__construct();
        $this->delegationService = app(CnameDelegationService::class);
    }

    /**
     * 获取委托列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();

        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 20);

        $query = CnameDelegation::where('user_id', $this->guard->id());

        // 添加快速搜索条件
        if (! empty($validated['quickSearch'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('zone', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('label', 'like', "%{$validated['quickSearch']}%");
            });
        }

        // 添加筛选条件
        if (isset($validated['zone'])) {
            $query->where('zone', 'like', "%{$validated['zone']}%");
        }
        if (isset($validated['prefix'])) {
            $query->where('prefix', $validated['prefix']);
        }
        if (isset($validated['valid'])) {
            $query->where('valid', $validated['valid']);
        }

        $total = $query->count();
        $items = $query
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        // 为每个项目添加 CNAME 配置指引
        $items = $items->map(function ($item) {
            return $this->delegationService->withCnameGuide($item);
        });

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    /**
     * 创建委托
     *
     * 入参按 CA 选择，内部经 ca_map 派生 prefix + zone（exact 精确域名 / 非 exact 根域）
     */
    public function store(StoreRequest $request): void
    {
        $validated = $request->validated();
        $ca = $validated['ca'];

        try {
            $prefix = CnameDelegationService::getDelegationPrefixForCa($ca);
            $zone = $this->delegationService->resolveZone($validated['zone'], $ca);

            $delegation = $this->delegationService->createOrGet(
                $this->guard->id(),
                $zone,
                $prefix,
            );

            $data = $this->delegationService->withCnameGuide($delegation);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        $this->success($data);
    }

    /**
     * 批量创建委托
     */
    public function batchStore(): void
    {
        $validated = request()->validate([
            'zones' => 'required|string',
            // 按 CA 选择，内部经 ca_map 派生 prefix + zone；未知 ca 走 default(_dnsauth)
            'ca' => 'required|string|max:50',
        ]);

        $ca = $validated['ca'];
        $prefix = CnameDelegationService::getDelegationPrefixForCa($ca);

        // 解析域名列表（支持逗号、换行、空格分隔）
        $zones = preg_split('/[\s,\n]+/', $validated['zones'], -1, PREG_SPLIT_NO_EMPTY);
        $zones = array_map('trim', $zones);
        $zones = array_filter($zones);
        $zones = array_unique($zones);

        if (empty($zones)) {
            $this->error('请提供至少一个域名');
        }

        if (count($zones) > 100) {
            $this->error('单次最多创建100个委托记录');
        }

        $created = [];
        $failed = [];

        foreach ($zones as $zone) {
            try {
                $resolvedZone = $this->delegationService->resolveZone($zone, $ca);
                $delegation = $this->delegationService->createOrGet(
                    $this->guard->id(),
                    $resolvedZone,
                    $prefix,
                );
                $created[] = $this->delegationService->withCnameGuide($delegation);
            } catch (Throwable $e) {
                $failed[] = [
                    'zone' => $zone,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->success([
            'created' => $created,
            'failed' => $failed,
            'total' => count($zones),
            'success_count' => count($created),
            'fail_count' => count($failed),
        ]);
    }

    /**
     * 获取委托详情
     */
    public function show($id): void
    {
        $delegation = CnameDelegation::where('user_id', $this->guard->id())
            ->where('id', (int) $id)
            ->first();

        if (! $delegation) {
            $this->error('委托记录不存在');
        }

        $data = $this->delegationService->withCnameGuide($delegation);

        $this->success($data);
    }

    /**
     * 批量获取委托详情
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $delegations = CnameDelegation::where('user_id', $this->guard->id())
            ->whereIn('id', $ids)
            ->get();

        if ($delegations->isEmpty()) {
            $this->error('委托记录不存在');
        }

        $data = $delegations->map(function ($item) {
            return $this->delegationService->withCnameGuide($item);
        });

        $this->success($data->toArray());
    }

    /**
     * 更新委托
     */
    public function update(UpdateRequest $request, $id): void
    {
        $validated = $request->validated();

        try {
            $delegation = $this->delegationService->update(
                $this->guard->id(),
                (int) $id,
                $validated
            );

            $data = $this->delegationService->withCnameGuide($delegation);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        $this->success($data);
    }

    /**
     * 删除委托
     */
    public function destroy($id): void
    {
        $delegation = CnameDelegation::where('user_id', $this->guard->id())
            ->where('id', (int) $id)
            ->first();

        if (! $delegation) {
            $this->error('委托记录不存在');
        }

        $delegation->delete();

        $this->success();
    }

    /**
     * 批量删除委托
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $delegations = CnameDelegation::where('user_id', $this->guard->id())
            ->whereIn('id', $ids)
            ->get();

        if ($delegations->isEmpty()) {
            $this->error('委托记录不存在');
        }

        $delegations->each->delete();

        $this->success();
    }

    /**
     * 手动触发健康检查
     */
    public function check($id): void
    {
        $delegation = CnameDelegation::where('user_id', $this->guard->id())
            ->where('id', (int) $id)
            ->first();

        if (! $delegation) {
            $this->error('委托记录不存在');
        }

        $valid = $this->delegationService->checkAndUpdateValidity($delegation);
        $warning = $this->delegationService->checkTxtConflict($delegation);

        if ($valid) {
            $data = $this->delegationService->withCnameGuide($delegation->fresh());
            if ($warning) {
                $data['warning'] = $warning;
            }
            $this->success($data);
        } else {
            $errorMsg = '检查失败：'.$delegation->last_error;
            if ($warning) {
                $errorMsg .= '；'.$warning;
            }
            $this->error($errorMsg);
        }
    }
}
