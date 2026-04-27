<?php

namespace App\Http\Controllers\User;

use App\Http\Requests\Order\GetIdsRequest;
use App\Http\Requests\Order\IndexRequest;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Services\Order\Action;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class OrderController extends BaseController
{
    protected Action $action;

    public function __construct()
    {
        parent::__construct();

        $this->guard->id() || $this->error('用户不存在');
        $this->action = app(Action::class);
    }

    use \App\Http\Traits\OrderController;

    /**
     * 获取订单列表
     *
     * @throws Throwable
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Order::query();

        $statusSet = $validated['statusSet'] ?? 'activating';
        // 活动中的状态（含证书到期但订单未到期）
        if ($statusSet === 'activating') {
            $activeCertIds = Cert::whereIn('status', ['unpaid', 'pending', 'processing', 'active', 'approving', 'cancelling'])->select('id');
            $expiredCertIds = Cert::where('status', 'expired')->select('id');
            $query->where(function ($q) use ($activeCertIds, $expiredCertIds) {
                $q->whereIn('latest_cert_id', $activeCertIds)
                    ->orWhere(function ($q) use ($expiredCertIds) {
                        $q->whereIn('latest_cert_id', $expiredCertIds)
                            ->where('period_till', '>', now());
                    });
            });
        }
        // 已存档的状态（排除证书到期但订单未到期）
        if ($statusSet === 'archived') {
            $archivedCertIds = Cert::whereIn('status', ['cancelled', 'renewed', 'reissued', 'expired', 'revoked', 'failed'])->select('id');
            $expiredCertIds = Cert::where('status', 'expired')->select('id');
            $query->whereIn('latest_cert_id', $archivedCertIds)
                ->whereNot(function ($q) use ($expiredCertIds) {
                    $q->whereIn('latest_cert_id', $expiredCertIds)
                        ->where('period_till', '>', now());
                });
        }

        if (! empty($validated['quickSearch'])) {
            $keyword = $validated['quickSearch'];
            $query->where(function ($q) use ($keyword) {
                $q->where('id', 'like', "%$keyword%")
                    ->orWhere('remark', 'like', "%$keyword%")
                    ->orWhereIn('product_id', Product::where('name', 'like', "%$keyword%")->select('id'))
                    ->orWhereIn('latest_cert_id', Cert::where(function ($cq) use ($keyword) {
                        $cq->where('common_name', 'like', "%$keyword%")
                            ->orWhere('alternative_names', 'like', "%$keyword%");
                    })->select('id'));
            });
        }
        if (! empty($validated['id'])) {
            $query->where('id', $validated['id']);
        }
        if (! empty($validated['period'])) {
            $query->where('period', $validated['period']);
        }
        if (! empty($validated['amount'])) {
            if (isset($validated['amount'][0]) && isset($validated['amount'][1])) {
                $query->whereBetween('amount', $validated['amount']);
            } elseif (isset($validated['amount'][0])) {
                $query->where('amount', '>=', $validated['amount'][0]);
            } elseif (isset($validated['amount'][1])) {
                $query->where('amount', '<=', $validated['amount'][1]);
            }
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }
        if (! empty($validated['product_name'])) {
            $query->whereIn('product_id', Product::where('name', 'like', "%{$validated['product_name']}%")->select('id'));
        }
        if (! empty($validated['domain'])) {
            $query->whereIn('latest_cert_id', Cert::where(function ($cq) use ($validated) {
                $cq->where('common_name', 'like', "%{$validated['domain']}%")
                    ->orWhere('alternative_names', 'like', "%{$validated['domain']}%");
            })->select('id'));
        }
        if (! empty($validated['channel'])) {
            $query->whereIn('latest_cert_id', Cert::where('channel', $validated['channel'])->select('id'));
        }
        if (! empty($validated['action'])) {
            $query->whereIn('latest_cert_id', Cert::where('action', $validated['action'])->select('id'));
        }
        if (! empty($validated['expires_at'])) {
            $query->whereIn('latest_cert_id', Cert::whereBetween('expires_at', $validated['expires_at'])->select('id'));
        }
        if (! empty($validated['status'])) {
            $query->whereIn('latest_cert_id', Cert::where('status', $validated['status'])->select('id'));
        }

        $total = $query->count();
        $items = $query->with([
            'product' => function ($query) {
                $query->select(['id', 'name', 'product_type', 'refund_period']);
            }, 'latestCert' => function ($query) {
                $query->select(['id', 'common_name', 'channel', 'action', 'dcv', 'validation', 'status', 'amount', 'issuer', 'issued_at', 'expires_at']);
            },
        ])
            ->select(['id', 'product_id', 'latest_cert_id', 'period', 'amount', 'period_from', 'period_till', 'created_at'])
            ->when(
                ! empty($validated['sort_prop']),
                function ($q) use ($validated) {
                    $sortOrder = $validated['sort_order'] ?? 'desc';
                    if ($validated['sort_prop'] === 'expires_at') {
                        $sub = Cert::select('expires_at')->whereColumn('certs.id', 'orders.latest_cert_id')->limit(1);
                        $q->orderByRaw("({$sub->toRawSql()}) IS NULL")
                            ->orderBy($sub, $sortOrder);
                    } elseif ($validated['sort_prop'] === 'period_till') {
                        $q->orderByRaw('period_till IS NULL')
                            ->orderBy('period_till', $sortOrder);
                    } else {
                        $q->orderBy($validated['sort_prop'], $sortOrder);
                    }
                },
                fn ($q) => $q->orderBy('latest_cert_id', 'desc')
            )
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        // issuer 在 cert 模型中用于判断中级证书是否存在 查询后可以去除
        // 去除 issuer 和 intermediate_cert 字段
        $items->each(function ($item) {
            $item->latestCert->makeHidden(['issuer', 'intermediate_cert']);
        });

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
            'balance' => $this->guard->user()->balance,
        ]);
    }

    /**
     * 获取订单资料
     */
    public function show($id): void
    {
        $order = Order::with([
            'product' => function ($query) {
                $query->select(['id', 'name', 'product_type', 'ca', 'refund_period', 'validation_methods', 'validation_type', 'common_name_types', 'alternative_name_types']);
            }, 'latestCert',
        ])->find($id);

        if (! $order) {
            $this->error('订单不存在');
        }

        $order->makeHidden([
            'user_id',
            'plus',
            'admin_remark',
            'latestCert.last_cert_id',
            'latestCert.api_id',
            'latestCert.params',
            'latestCert.csr_md5',
        ]);

        $data = $order->toArray();
        $data['balance'] = $this->guard->user()->balance;

        $this->success($data);
    }

    /**
     * 批量获取订单资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $orders = Order::whereIn('id', $ids)
            ->with([
                'product' => function ($query) {
                    $query->select(['id', 'name', 'product_type', 'ca', 'refund_period', 'validation_methods', 'validation_type', 'common_name_types', 'alternative_name_types']);
                }, 'latestCert',
            ])
            ->get();

        foreach ($orders as $order) {
            $order->makeHidden([
                'user_id',
                'plus',
                'latestCert.last_cert_id',
                'latestCert.api_id',
                'latestCert.params',
                'latestCert.csr_md5',
            ]);
        }

        if ($orders->isEmpty()) {
            $this->error('订单不存在');
        }

        $result = $orders->map(function ($order) {
            return $order->toArray();
        });

        $this->success([
            'items' => $result->toArray(),
            'balance' => $this->guard->user()->balance,
        ]);
    }

    /**
     * 获取订单的颁发记录
     */
    public function certs(int $id): void
    {
        $order = Order::find($id);
        if (! $order) {
            $this->error('订单不存在');
        }

        $currentPage = (int) (request('currentPage', 1));
        $pageSize = min((int) (request('pageSize', 10)), 100);

        $query = Cert::where('order_id', $id);

        $total = $query->count();
        $items = $query->select(['id', 'order_id', 'action', 'channel', 'common_name', 'amount', 'status', 'issued_at', 'expires_at'])
            ->orderBy('id', 'desc')
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

    /**
     * 申请
     *
     * @throws Throwable
     */
    public function new(): void
    {
        $params = request()->post();
        $params['user_id'] = $this->guard->id();
        $params['action'] = 'new';
        $params['channel'] = 'web';
        $this->action->new($params);
    }

    /**
     * 批量申请
     *
     * @throws Throwable
     */
    public function batchNew(): void
    {
        $params = request()->post();
        $params['user_id'] = $this->guard->id();
        $params['action'] = 'new';
        $params['channel'] = 'web';
        $params['is_batch'] = true;
        $this->action->batchNew($params);
    }

    /**
     * 续费
     *
     * @throws Throwable
     */
    public function renew(): void
    {
        $params = request()->post();
        $params['action'] = 'renew';
        $params['channel'] = 'web';
        $this->action->renew($params);
    }

    /**
     * 重签
     *
     * @throws Throwable
     */
    public function reissue(): void
    {
        $params = request()->post();
        $params['action'] = 'reissue';
        $params['channel'] = 'web';
        $this->action->reissue($params);
    }

    /**
     * 上传验证文档
     */
    public function uploadDocument(Request $request, int $id): void
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,xades|max:5120',
            'type' => 'required|string',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $this->action->uploadDocument($id, $file, $request->input('type'), 'user');
    }

    /**
     * 预览文档
     */
    public function previewDocument(int $id): BinaryFileResponse
    {
        return $this->action->previewDocument($id);
    }

    /**
     * 获取文档列表
     */
    public function getDocuments(int $id): void
    {
        $this->action->getDocuments($id);
    }

    /**
     * 更新文档信息
     */
    public function updateDocument(Request $request, int $id): void
    {
        $request->validate([
            'file_name' => 'required|string|max:255',
            'type' => 'required|string|max:50',
        ]);
        $this->action->updateDocument($id, $request->input('file_name'), $request->input('type'));
    }

    /**
     * 删除文档
     */
    public function deleteDocument(int $id): void
    {
        $this->action->deleteDocument($id);
    }

    /**
     * 提交文档到上游
     */
    public function submitDocuments(int $id): void
    {
        $this->action->submitDocuments($id);
    }
}
