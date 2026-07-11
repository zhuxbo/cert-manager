<?php

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * pending 订单对账「到顶 / 产品缺失」判据的单一来源。
 *
 * 【包O 消费方契约 —— 改动须与 sweep-orphan-orders 同步】
 * 到顶判据(maxed-out)与产品缺失判据(product-missing)是 ReconcilePendingCommand 主扫描(可动作)
 * 与转人工扫描(告警)的分界；后续包O 的 sweep-orphan-orders 会直接消费同一「转人工」判据自动收尾
 * （cancelPending 退款）。两处必须共用本类的 SQL 片段，禁止手抄 whereRaw —— 否则「哪些单已到顶」
 * 会在 reconcile 与 sweep 之间漂移，造成「reconcile 判到顶告警、sweep 判未到顶不收尾」的裂缝。
 * 同理 $maxAttempts 参数：调用方（含 sweep-orphan-orders）必须传 config('reconcile.max_attempts') 同源值，
 * 不得硬编码或读别的键 —— 否则即便共用 SQL 片段，到顶阈值仍会在两处漂移（参数化本身留的口子）。
 *
 * 判据语义（与 ReconcilePendingCommand::shouldQueueCommit 的 C4 PHP 判据逐字同源）：
 *  - 锚点 = latestCert.created_at（JOIN certs as lc 引入），只统计「当前证书周期」内的失败 commit task
 *    （last_execute_at >= lc.created_at），不把上一张证书的旧账计入 → 二次卡单不被旧账误判到顶。
 *  - 到顶 = 本周期失败 commit task 行数 >= maxAttempts（每对账周期至多一个 commit task，行数=失败周期数）。
 *  - 产品缺失 = 本周期存在 result 命中 PRODUCT_NOT_FOUND_SIGNAL 的失败 commit task（上游产品下线/删除永久拒单）。
 *
 * actionable() 与 maxedOrProductMissing() 严格互补：共用同一批 SQL 片段常量，保证正反同源无漂移。
 */
final class PendingReconcileQuery
{
    /**
     * 上游（gateway V2 ApiController）产品缺失/下线时返回的英文信号串 'Product not found'，经
     * Api::handleResult 抛 ApiResponseException → TaskJob 落 task.result['msg']。辅助信号：上游措辞
     * 变更即失明，此时该单不再被排除、回到正常退避 → max_attempts 到顶转人工兜底（只慢不丢）。
     * 实现期已亲验当前 gateway V2/ApiController.php:169 措辞未变。
     *
     * 【包O 消费方契约】sweep-orphan-orders 若需分辨产品缺失，复用本常量与 matchesProductMissing()。
     */
    public const PRODUCT_NOT_FOUND_SIGNAL = 'Product not found';

    // 到顶 count 标量子查询（correlated，锚 lc.created_at）。绑定参数：action, status（比较值另附）。
    private const MAXED_COUNT_SUBQUERY =
        '(SELECT COUNT(*) FROM tasks t WHERE t.order_id = orders.id '
        .'AND t.action = ? AND t.status = ? AND t.last_execute_at >= lc.created_at)';

    // 产品缺失 EXISTS 子查询（correlated，锚 lc.created_at）。绑定参数：action, status, like。
    private const PRODUCT_MISSING_EXISTS =
        'EXISTS (SELECT 1 FROM tasks t2 WHERE t2.order_id = orders.id '
        .'AND t2.action = ? AND t2.status = ? AND t2.last_execute_at >= lc.created_at AND t2.result LIKE ?)';

    /**
     * 主可动作扫描叠加：未到顶 AND 无产品缺失（供 ReconcilePendingCommand 主扫描，占 limit）。
     *
     * @param  Builder<Order>  $query  调用方须已 whereHas('latestCert', pending+null api_id ...)
     * @return Builder<Order>
     */
    public static function actionable(Builder $query, int $maxAttempts): Builder
    {
        return self::withCertAnchor($query)
            ->whereRaw(self::MAXED_COUNT_SUBQUERY.' < ?', ['commit', 'failed', $maxAttempts])
            ->whereRaw('NOT '.self::PRODUCT_MISSING_EXISTS, ['commit', 'failed', self::likePattern()]);
    }

    /**
     * 转人工扫描叠加：到顶 OR 产品缺失（供 ReconcilePendingCommand 转人工扫描 + 包O sweep-orphan-orders）。
     * 与 actionable() 严格互补（同 MAXED_COUNT_SUBQUERY / PRODUCT_MISSING_EXISTS 片段，正反同源）。
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function maxedOrProductMissing(Builder $query, int $maxAttempts): Builder
    {
        return self::withCertAnchor($query)
            ->where(function (Builder $q) use ($maxAttempts) {
                $q->whereRaw(self::MAXED_COUNT_SUBQUERY.' >= ?', ['commit', 'failed', $maxAttempts])
                    ->orWhereRaw(self::PRODUCT_MISSING_EXISTS, ['commit', 'failed', self::likePattern()]);
            });
    }

    /**
     * O4 sweep-orphan-orders 收尾叠加：到顶 AND 无产品缺失（供 sweep-orphan-orders 自动 cancelPending 退款）。
     *
     * 与 actionable() / maxedOrProductMissing() 共用同一批 SQL 片段常量（MAXED_COUNT_SUBQUERY /
     * PRODUCT_MISSING_EXISTS），三者同源无漂移。语义 = maxedOrProductMissing() ∩ 非产品缺失
     * = (到顶 OR 缺失) ∩ 非缺失 = 到顶 ∩ 非缺失：reconcile 已停止 re-queue（到顶）且非 T8 产品缺失单
     * （那类留 admin 判断重购/取消，O4 不接手）。sweep-orphan-orders 必经本方法消费，禁止手抄 SQL/字面量。
     *
     * @param  Builder<Order>  $query  调用方须已 whereHas('latestCert', pending+null api_id+channel ...)
     * @return Builder<Order>
     */
    public static function maxedAndNotProductMissing(Builder $query, int $maxAttempts): Builder
    {
        return self::withCertAnchor($query)
            ->whereRaw(self::MAXED_COUNT_SUBQUERY.' >= ?', ['commit', 'failed', $maxAttempts])
            ->whereRaw('NOT '.self::PRODUCT_MISSING_EXISTS, ['commit', 'failed', self::likePattern()]);
    }

    /**
     * PHP 侧产品缺失精判（转人工循环分文案用）。
     *
     * 口径统一：SQL LIKE 在 utf8mb4_*_ci collation 下大小写不敏感，故此处 PHP 侧也用大小写不敏感匹配
     * （stripos），两侧口径一致 —— 避免「SQL 粗筛排除了、PHP 精判漏判归错文案」的分叉。分歧方向本就
     * fail-safe（误配后果仅提前转人工），此处进一步消除分叉。
     */
    public static function matchesProductMissing(?string $message): bool
    {
        return $message !== null && $message !== ''
            && stripos($message, self::PRODUCT_NOT_FOUND_SIGNAL) !== false;
    }

    /**
     * JOIN certs 锚点 + select orders.*（防 lc 列污染 Order 水合）。
     *
     * INNER JOIN：调用方必已 whereHas('latestCert' ...) 保证 latest_cert_id 有效，INNER 语义与之一致
     * （NULL latest_cert_id 双双排除）。**勿改 LEFT JOIN** —— 否则锚点 NULL 时 `>= lc.created_at` 恒 NULL、
     * 语义静默漂移（到顶判据失效）。
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function withCertAnchor(Builder $query): Builder
    {
        return $query
            ->join('certs as lc', 'lc.id', '=', 'orders.latest_cert_id')
            ->select('orders.*');
    }

    private static function likePattern(): string
    {
        return '%'.self::PRODUCT_NOT_FOUND_SIGNAL.'%';
    }
}
