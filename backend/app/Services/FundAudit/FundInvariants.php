<?php

namespace App\Services\FundAudit;

use Illuminate\Support\Facades\DB;

/**
 * 资金审计（事后发现层）。
 *
 * 4 条 invariant 校验"账目-事件-状态-金额"一致性，供 Pest afterEach hook
 * 与 finance:audit 命令调用。物理阻断由 DB 唯一索引 + Fund.status CAS 承担。
 *
 * - L1 账目恒等：SUM(transactions.amount) == users.balance
 * - L2 事件唯一：(type, transaction_id) WHERE type!='order' 不允许重复
 * - L3 状态-事件配对：funds.status ∈ (1,2) 与资金类 transaction 双向配对
 * - L4 金额配对：addfunds/reverse 同号、deduct/refunds 异号
 */
class FundInvariants
{
    /**
     * 执行所有 invariant，返回违反项扁平数组。
     *
     * 空数组 → 全部通过；任一非空 → 至少 1 条不变式被破坏。
     *
     * @return array<int, array{layer: string, message: string, rows: array}>
     */
    public function all(): array
    {
        $violations = [];

        if ($l1 = $this->accountingIdentity()) {
            $violations[] = $l1;
        }
        if ($l2 = $this->eventUniqueness()) {
            $violations[] = $l2;
        }
        if ($l3 = $this->statePairing()) {
            $violations[] = $l3;
        }
        if ($l4 = $this->amountPairing()) {
            $violations[] = $l4;
        }

        return $violations;
    }

    /**
     * L1 账目恒等：每个 user 满足 SUM(transactions.amount) = balance。
     *
     * 前提：所有 balance 变更必须走 Transaction::create（钩子内同事务 bcadd
     * 改 balance + 写流水），禁止直接 SQL/Eloquent 改 balance。
     *
     * @return array{layer: string, message: string, rows: array}|null
     */
    public function accountingIdentity(): ?array
    {
        $rows = DB::select(
            'SELECT u.id, u.username, u.balance, '.
            'COALESCE(SUM(t.amount), 0) AS tx_sum, '.
            '(u.balance - COALESCE(SUM(t.amount), 0)) AS drift '.
            'FROM users u '.
            'LEFT JOIN transactions t ON t.user_id = u.id '.
            'GROUP BY u.id, u.username, u.balance '.
            'HAVING ABS(u.balance - COALESCE(SUM(t.amount), 0)) > 0.001'
        );

        if (empty($rows)) {
            return null;
        }

        return [
            'layer' => 'L1',
            'message' => 'L1 账目恒等破：'.count($rows).' 个用户的 balance 与 transactions 累计存在偏离',
            'rows' => array_map(fn ($r) => (array) $r, $rows),
        ];
    }

    /**
     * L2 事件唯一：(type, transaction_id) WHERE type != 'order' 不允许重复。
     *
     * 物理阻断由 transactions_dedup_unique 唯一索引承担，本查询用于
     * "索引被误删 / 三库索引不一致"的事后守门。
     *
     * @return array{layer: string, message: string, rows: array}|null
     */
    public function eventUniqueness(): ?array
    {
        $rows = DB::select(
            'SELECT type, transaction_id, COUNT(*) AS cnt '.
            'FROM transactions '.
            "WHERE type != 'order' ".
            'GROUP BY type, transaction_id '.
            'HAVING COUNT(*) > 1'
        );

        if (empty($rows)) {
            return null;
        }

        return [
            'layer' => 'L2',
            'message' => 'L2 事件唯一破：'.count($rows).' 组 (type, transaction_id) 出现重复',
            'rows' => array_map(fn ($r) => (array) $r, $rows),
        ];
    }

    /**
     * L3 状态-事件配对：funds.status ∈ (1, 2) 与对应 transaction 双向配对。
     *
     * 关联：transaction.transaction_id = fund.id, transaction.type = fund.type,
     * transaction.user_id = fund.user_id（来自 Fund::createRecord）。
     *
     * - 正向：fund 缺对应 transaction（漏写或被删）
     * - 反向：资金类 transaction (addfunds/deduct/refunds/reverse) 缺 fund 行
     *
     * @return array{layer: string, message: string, rows: array}|null
     */
    public function statePairing(): ?array
    {
        // 正向：fund 缺 tx
        $forward = DB::select(
            'SELECT f.id AS fund_id, f.user_id, f.type, f.status, f.amount, f.pay_method '.
            'FROM funds f '.
            'WHERE f.status IN (1, 2) AND NOT EXISTS ('.
            'SELECT 1 FROM transactions t '.
            'WHERE t.user_id = f.user_id '.
            'AND t.type = f.type '.
            'AND t.transaction_id = f.id'.
            ')'
        );

        // 反向：资金类 tx 缺对应已完成/已退 fund（孤儿，或 fund 仍停在处理中）
        $reverse = DB::select(
            'SELECT t.id AS tx_id, t.user_id, t.type, t.transaction_id, t.amount '.
            'FROM transactions t '.
            "WHERE t.type IN ('addfunds', 'deduct', 'refunds', 'reverse') ".
            'AND NOT EXISTS ('.
            'SELECT 1 FROM funds f WHERE f.id = t.transaction_id AND f.user_id = t.user_id '.
            'AND f.status IN (1, 2)'.
            ')'
        );

        if (empty($forward) && empty($reverse)) {
            return null;
        }

        $rows = array_merge(
            array_map(fn ($r) => ['direction' => 'forward'] + (array) $r, $forward),
            array_map(fn ($r) => ['direction' => 'reverse'] + (array) $r, $reverse),
        );

        $msgParts = [];
        if (! empty($forward)) {
            $msgParts[] = count($forward).' 条 fund 缺失对应 transaction';
        }
        if (! empty($reverse)) {
            $msgParts[] = count($reverse).' 条资金 transaction 缺失对应已完成/已退 fund（孤儿或状态未落地）';
        }

        return [
            'layer' => 'L3',
            'message' => 'L3 状态-事件配对破：'.implode('；', $msgParts),
            'rows' => $rows,
        ];
    }

    /**
     * L4 金额配对：fund 与对应 transaction.amount 严格符号匹配。
     *
     * Fund::getTypeAmount 写法：fund.amount 永远正数；transaction.amount 按
     * type 取符号 — addfunds/reverse → +fund.amount，deduct/refunds → -fund.amount。
     * 不能仅比绝对值（符号反向会让 L1 balance 错向更新但 ABS 校验通过）。
     *
     * @return array{layer: string, message: string, rows: array}|null
     */
    public function amountPairing(): ?array
    {
        $rows = DB::select(
            'SELECT f.id AS fund_id, f.user_id, f.type, f.status, '.
            'f.amount AS fund_amount, t.amount AS tx_amount '.
            'FROM funds f '.
            'JOIN transactions t ON t.user_id = f.user_id '.
            'AND t.type = f.type '.
            'AND t.transaction_id = f.id '.
            'WHERE f.status IN (1, 2) '.
            'AND ('.
            "(f.type IN ('addfunds', 'reverse') AND ABS(f.amount - t.amount) > 0.001) ".
            'OR '.
            "(f.type IN ('deduct', 'refunds') AND ABS(f.amount + t.amount) > 0.001)".
            ')'
        );

        if (empty($rows)) {
            return null;
        }

        return [
            'layer' => 'L4',
            'message' => 'L4 金额配对破：'.count($rows).' 条 fund 与对应 transaction 金额或符号不匹配',
            'rows' => array_map(fn ($r) => (array) $r, $rows),
        ];
    }
}
