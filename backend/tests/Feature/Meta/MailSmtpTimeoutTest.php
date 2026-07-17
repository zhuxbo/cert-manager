<?php

declare(strict_types=1);

it('smtp mailer 设了显式 timeout（不留 null 无界分支）', function () {
    expect(config('mail.mailers.smtp.timeout'))->toBe(15);
});
