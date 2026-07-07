<?php

namespace Plugins\CloudDeploy\Controllers\Admin;

use App\Http\Controllers\Admin\BaseController;
use Illuminate\Http\Request;
use Plugins\CloudDeploy\Services\OrderOptionService;

class OrderOptionController extends BaseController
{
    public function index(Request $request, OrderOptionService $service): void
    {
        $this->success($service->paginate(
            $this->requestedUserId($request),
            (string) $request->input('quickSearch', ''),
            (int) $request->input('currentPage', 1),
            (int) $request->input('pageSize', 10),
            false,
        ));
    }

    public function show(Request $request, int $order, OrderOptionService $service): void
    {
        $item = $service->show($order, $this->requestedUserId($request), false);
        if (! $item) {
            $this->error('订单不存在');
        }

        $this->success($item);
    }

    private function requestedUserId(Request $request): int
    {
        $userId = (int) $request->input('user_id');
        if ($userId <= 0) {
            $this->error('请选择用户');
        }

        return $userId;
    }
}
