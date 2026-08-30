<?php

declare(strict_types=1);

use App\Services\Delegation\Sdk\TencentCloud\TencentCloudTc3Signer;

test('Tencent TC3 签名与官方固定向量一致', function () {
    $payload = '{"Limit": 1, "Filters": [{"Values": ["\u672a\u547d\u540d"], "Name": "instance-name"}]}';

    expect(TencentCloudTc3Signer::authorization(
        secretId: 'test-secret-id',
        secretKey: 'Gu5t9xGARNpq86cd98joQYCN3EXAMPLE',
        host: 'cvm.tencentcloudapi.com',
        service: 'cvm',
        timestamp: 1551113065,
        payload: $payload,
    ))->toBe(
        'TC3-HMAC-SHA256 Credential=test-secret-id/2019-02-25/cvm/tc3_request, '
        .'SignedHeaders=content-type;host, '
        .'Signature=72e494ea809ad7a8c8f7a4507b9bddcbaa8e581f516e8da2f66e2c5a96525168'
    );
});
