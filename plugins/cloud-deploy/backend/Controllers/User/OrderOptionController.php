<?php

namespace Plugins\CloudDeploy\Controllers\User;

use App\Http\Controllers\User\BaseController;
use Illuminate\Http\Request;
use Plugins\CloudDeploy\Services\OrderOptionService;

class OrderOptionController extends BaseController
{
    public function index(Request $request, OrderOptionService $service): void
    {
        $this->success($service->paginate(
            (int) $this->guard->id(),
            (string) $request->input('quickSearch', ''),
            (int) $request->input('currentPage', 1),
            (int) $request->input('pageSize', 10),
            true,
        ));
    }

    public function show(int $id, OrderOptionService $service): void
    {
        $item = $service->show($id, (int) $this->guard->id(), true);
        if (! $item) {
            $this->error('订单不存在');
        }

        $this->success($item);
    }
}
