<?php

namespace Plugins\CloudDeploy\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrderOptionService
{
    private const SELECTABLE_CERT_STATUSES = ['unpaid', 'pending', 'processing', 'approving', 'active'];

    /**
     * @return array{items:Collection<int,array{id:int,label:string,user_id:int,common_name:string,status:string}>,total:int,pageSize:int,currentPage:int}
     */
    public function paginate(?int $userId, string $quickSearch, int $currentPage, int $pageSize, bool $withGlobalScopes): array
    {
        $query = $this->selectableQuery($userId, $withGlobalScopes);

        if ($quickSearch !== '') {
            $query->where(function (Builder $q) use ($quickSearch) {
                $q->where('orders.id', 'like', "%{$quickSearch}%")
                    ->orWhereHas('latestCert', function (Builder $cq) use ($quickSearch) {
                        $cq->where('common_name', 'like', "%{$quickSearch}%")
                            ->orWhere('alternative_names', 'like', "%{$quickSearch}%");
                    });
            });
        }

        $total = $query->count();
        $items = $query->with('latestCert:id,common_name,alternative_names,status')
            ->select(['id', 'user_id', 'latest_cert_id', 'period_till'])
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->map(fn (Order $order) => $this->toOption($order))
            ->values();

        return [
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ];
    }

    /**
     * 回显允许返回同用户下的存量非候选订单；新增和改绑时由 isSelectable() 收紧。
     *
     * @return array{id:int,label:string,user_id:int,common_name:string,status:string}|null
     */
    public function show(int $orderId, ?int $userId, bool $withGlobalScopes): ?array
    {
        $query = $withGlobalScopes ? Order::query() : Order::withoutGlobalScopes();
        if ($userId !== null) {
            $query->where('orders.user_id', $userId);
        }

        $order = $query->with('latestCert:id,common_name,alternative_names,status')
            ->select(['id', 'user_id', 'latest_cert_id', 'period_till'])
            ->find($orderId);

        return $order instanceof Order ? $this->toOption($order) : null;
    }

    public function isSelectable(int $orderId, int $userId): bool
    {
        return $this->selectableQuery($userId, false)->whereKey($orderId)->exists();
    }

    /** @return Builder<Order> */
    private function selectableQuery(?int $userId, bool $withGlobalScopes): Builder
    {
        $query = $withGlobalScopes ? Order::query() : Order::withoutGlobalScopes();
        if ($userId !== null) {
            $query->where('orders.user_id', $userId);
        }

        return $query
            ->whereNull('orders.cancelled_at')
            ->whereHas('latestCert', fn (Builder $q) => $q->whereIn('status', self::SELECTABLE_CERT_STATUSES));
    }

    /**
     * @return array{id:int,label:string,user_id:int,common_name:string,status:string}
     */
    private function toOption(Order $order): array
    {
        $cert = $order->latestCert;
        $domain = $cert?->common_name ?: '-';
        $status = $cert?->status ?: '-';

        return [
            'id' => (int) $order->id,
            'label' => "#{$order->id} · {$domain} · {$status}",
            'user_id' => (int) $order->user_id,
            'common_name' => $domain,
            'status' => $status,
        ];
    }
}
