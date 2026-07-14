<?php

use App\Models\User;

/**
 * 余额预检口径单一真相源——available = balance + |credit_limit|。
 * AutoRenewCommand / BalanceForecastCommand / Deploy update 三处收敛复用此方法，锁定口径等价。
 */
test('availableBalance = balance + |credit_limit|（credit_limit 负数存储取绝对值）', function () {
    $u = new User;
    $u->balance = '100.00';
    $u->credit_limit = '50.00'; // setter 归一为 -50.00

    expect($u->availableBalance())->toBe('150.00');
});

test('credit_limit 为 0 时 availableBalance = balance', function () {
    $u = new User;
    $u->balance = '88.30';
    $u->credit_limit = '0.00';

    expect($u->availableBalance())->toBe('88.30');
});

test('balance 为负（欠费）时口径仍为代数和', function () {
    $u = new User;
    $u->balance = '-20.00';
    $u->credit_limit = '100.00'; // -100.00

    expect($u->availableBalance())->toBe('80.00');
});
