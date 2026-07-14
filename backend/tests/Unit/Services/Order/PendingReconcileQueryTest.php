<?php

use App\Services\Order\PendingReconcileQuery;

/**
 * 到顶 count 子查询单一真相源——锁定收敛后生成的 SQL 片段与收敛前 Order/ACME 两侧常量逐字节等价。
 * 任一字符漂移都会改变对账「到顶」判据结果集；此断言是行为等价的护栏。
 */
test('maxedCountSubquery(orders, lc.created_at) 与收敛前 Order 侧常量逐字节一致', function () {
    expect(PendingReconcileQuery::maxedCountSubquery('orders', 'lc.created_at'))
        ->toBe('(SELECT COUNT(*) FROM tasks t WHERE t.order_id = orders.id '
            .'AND t.action = ? AND t.status = ? AND t.last_execute_at >= lc.created_at)');
});

test('maxedCountSubquery(acmes, acmes.created_at) 与收敛前 ACME 侧常量逐字节一致', function () {
    expect(PendingReconcileQuery::maxedCountSubquery('acmes', 'acmes.created_at'))
        ->toBe('(SELECT COUNT(*) FROM tasks t WHERE t.order_id = acmes.id '
            .'AND t.action = ? AND t.status = ? AND t.last_execute_at >= acmes.created_at)');
});
