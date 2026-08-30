<?php

declare(strict_types=1);

use App\Services\Delegation\Sdk\Aliyun\AliyunRpcSigner;

test('Aliyun RPC 签名按参数名排序并执行双层 RFC3986 编码', function () {
    $parameters = [
        'Version' => '2015-05-12',
        'Timestamp' => '2015-05-26T09:23:06Z',
        'sn' => '2015-05-12',
        'SignatureVersion' => '1.0',
        'SignatureNonce' => '1432632186688',
        'SignatureMethod' => 'HMAC-SHA1',
        'RegionId' => 'cn-beijing',
        'Format' => 'XML',
        'Action' => 'GetBsnBySn',
        'AccessKeyId' => 'testKey',
    ];

    expect(AliyunRpcSigner::sign($parameters, 'testsecret'))
        ->toBe('n6D5K/HDEaVSPm+GMgBWMRPfrac=');
});

test('Aliyun RPC 签名区分空格加号星号和波浪号', function () {
    expect(AliyunRpcSigner::sign([
        'Z' => ':/',
        'A' => 'a b+c*~',
    ], 's'))->toBe('5Ltw7N7oIf22WTKd2biyuCdTlAY=');
});
