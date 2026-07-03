<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\NotificationTemplate\GetIdsRequest;
use App\Http\Requests\NotificationTemplate\StoreRequest;
use App\Http\Requests\NotificationTemplate\UpdateRequest;
use App\Models\NotificationTemplate;
use Illuminate\Http\Request;

class NotificationTemplateController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index(Request $request): void
    {
        $currentPage = (int) ($request->input('currentPage') ?? 1);
        $pageSize = (int) ($request->input('pageSize') ?? 10);

        $query = NotificationTemplate::query();

        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('status', (int) $request->input('status'));
        }
        if ($request->filled('code')) {
            $query->where('code', 'like', '%'.$request->input('code').'%');
        }
        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->input('name').'%');
        }

        $total = $query->count();
        $items = $query->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    public function store(StoreRequest $request): void
    {
        $template = NotificationTemplate::create($request->validated());
        $this->success(['id' => $template->id]);
    }

    public function show(int $id): void
    {
        $template = NotificationTemplate::find($id);
        if (! $template) {
            $this->error('通知模板不存在');
        }

        $this->success($template->toArray());
    }

    public function batchShow(GetIdsRequest $request): void
    {
        $items = NotificationTemplate::whereIn('id', $request->validated('ids'))->get();
        $this->success($items->toArray());
    }

    public function update(UpdateRequest $request, int $id): void
    {
        $template = NotificationTemplate::find($id);
        if (! $template) {
            $this->error('通知模板不存在');
        }

        $template->update($request->validated());
        $this->success();
    }

    public function destroy(int $id): void
    {
        $template = NotificationTemplate::find($id);
        if (! $template) {
            $this->error('通知模板不存在');
        }

        $template->delete();
        $this->success();
    }

    public function batchDestroy(GetIdsRequest $request): void
    {
        NotificationTemplate::whereIn('id', $request->validated('ids'))->delete();
        $this->success();
    }
}
