<?php

declare(strict_types=1);

namespace Plugins\Invoice\Controllers\External;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Invoice\Models\Invoice;

class InvoiceExternalController extends Controller
{
    /**
     * 单次 pending 拉取返回的最大条数。
     * 防止待开票记录堆积时全量返回拖垮内存/响应；
     * 对接方通过 since 游标分批增量拉取。
     */
    public const MAX_PENDING_ITEMS = 500;

    public function pending(Request $request): void
    {
        $since = (int) $request->query('since', 0);

        $query = Invoice::query()
            ->where('status', 0)
            ->orderBy('id', 'asc');

        if ($since > 0) {
            $query->where('id', '>', $since);
        }

        $items = $query->select([
            'id', 'amount', 'organization', 'taxation', 'email', 'remark', 'created_at',
        ])->limit(self::MAX_PENDING_ITEMS)->get();

        $this->success(['items' => $items]);
    }

    public function complete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $invoice = Invoice::where('id', $id)->lockForUpdate()->first();
            if (! $invoice) {
                $this->error('发票不存在');
            }
            if ($invoice->status === 1) {
                return; // 幂等：已是已开票，直接 success
            }
            if ($invoice->status === 2) {
                $this->error('发票已作废，无法标记完成');
            }
            $invoice->status = 1;
            $invoice->save();
        });

        $this->success();
    }
}
