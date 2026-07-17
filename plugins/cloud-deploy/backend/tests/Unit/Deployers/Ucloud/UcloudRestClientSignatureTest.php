<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudApiException;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * UCloud 公钥/私钥签名固定输入 → 固定输出（逐字节对齐 ucloud-sdk-go ucloud/auth/signature.go）。
 *
 * 用官方文档（docs.ucloud.cn/api/summary/signature）的权威测试向量钉死签名实现：
 *   参数 {Action:DescribeUHostInstance, Region:cn-bj2, Limit:10, PublicKey:ucloudsomeone@example.com1296235120854146120}
 *   PrivateKey:46f09bb9fab4f12dfc160dae12273d5332b5debe
 *   被签名串 ActionDescribeUHostInstanceLimit10PublicKey...Regioncn-bj2{PrivateKey}
 *   → SHA1 = cba5cf5ec4d4233d206b1b54951e3787350a642f
 *
 * 签名是私有方法，经一次真实 invoke（注入 mock HTTP）从外发的 form body 里抓 Signature 校验。
 */

/** 抓取 UcloudRestClient invoke 外发的 form 参数（含 Signature）的 mock HTTP client。 */
function ucloudCaptureHttp(array &$capture, array $responseBody = ['RetCode' => 0]): ClientInterface
{
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')
        ->andReturnUsing(function (string $method, string $uri, array $options) use (&$capture, $responseBody) {
            $capture = [
                'method' => $method,
                'uri' => $uri,
                'form_params' => $options['form_params'] ?? [],
            ];

            return new Response(200, [], (string) json_encode($responseBody));
        });

    return $http;
}

test('签名对齐官方测试向量（DescribeUHostInstance → cba5cf5e...）', function () {
    // 用官方向量的 PublicKey/PrivateKey 构造 client；Region 经构造参数注入，Limit 经业务参数。
    $capture = [];
    $http = ucloudCaptureHttp($capture);

    $client = new class('ucloudsomeone@example.com1296235120854146120', '46f09bb9fab4f12dfc160dae12273d5332b5debe', '', 'cn-bj2', $http) extends UcloudRestClient
    {
        /** 暴露一个调 invoke 的入口，喂官方向量的 Action + Limit=10。 */
        public function probeSign(): void
        {
            // 经 reflection 调私有 invoke，模拟 Action=DescribeUHostInstance + Limit=10
            $ref = new ReflectionMethod(UcloudRestClient::class, 'invoke');
            $ref->invoke($this, 'DescribeUHostInstance', ['Limit' => 10]);
        }
    };

    $client->probeSign();

    $form = $capture['form_params'];
    // 外发参数齐全：Action / Region / PublicKey / Limit / Signature
    expect($form['Action'])->toBe('DescribeUHostInstance');
    expect($form['Region'])->toBe('cn-bj2');
    expect($form['PublicKey'])->toBe('ucloudsomeone@example.com1296235120854146120');
    expect($form['Limit'])->toBe('10');
    // 权威签名向量
    expect($form['Signature'])->toBe('cba5cf5ec4d4233d206b1b54951e3787350a642f');
    // 私钥绝不外发
    expect($form)->not->toHaveKey('PrivateKey');
    expect(implode('', array_map('strval', $form)))->not->toContain('46f09bb9fab4f12dfc160dae12273d5332b5debe');
});

test('POST 到固定 endpoint，body 为 form-urlencoded', function () {
    $capture = [];
    $http = ucloudCaptureHttp($capture);
    $client = new UcloudRestClient('PUB', 'PRIV', '', '', $http);

    // 经公开方法触发一次请求
    $client->getProjectList();

    expect($capture['method'])->toBe('POST');
    expect($capture['uri'])->toBe('https://api.ucloud.cn');
    expect($capture['form_params']['Action'])->toBe('GetProjectList');
    expect($capture['form_params'])->toHaveKey('Signature');
});

test('数组参数按 Key.N 展开后参与签名（Port.0 / DomainId.0）', function () {
    $capture = [];
    $http = ucloudCaptureHttp($capture);
    $client = new UcloudRestClient('PUB', 'PRIV', 'proj-1', 'cn-bj2', $http);

    $client->bindPathXSSL('uga-1', [443, 8443], 'ssl-1');

    $form = $capture['form_params'];
    // 数组展开为 .0/.1 下标（与官方 FormEncoder 一致）
    expect($form['Port.0'])->toBe('443');
    expect($form['Port.1'])->toBe('8443');
    expect($form)->not->toHaveKey('Port'); // 原数组键不外发
    expect($form['UGAId'])->toBe('uga-1');
    expect($form['SSLId'])->toBe('ssl-1');
    expect($form['ProjectId'])->toBe('proj-1');
    expect($form)->toHaveKey('Signature');
});

test('project_id / region 为空时不外发对应公共参数', function () {
    $capture = [];
    $http = ucloudCaptureHttp($capture);
    $client = new UcloudRestClient('PUB', 'PRIV', '', '', $http);

    $client->getProjectList();

    $form = $capture['form_params'];
    expect($form)->not->toHaveKey('Region');
    expect($form)->not->toHaveKey('ProjectId');
    expect($form['PublicKey'])->toBe('PUB');
});

test('RetCode≠0 抛 UcloudApiException（携 RetCode + Message）', function () {
    $capture = [];
    $http = ucloudCaptureHttp($capture, ['RetCode' => 171, 'Message' => 'invalid signature']);
    $client = new UcloudRestClient('PUB', 'PRIV', '', '', $http);

    try {
        $client->getProjectList();
        expect(false)->toBeTrue('应抛异常');
    } catch (UcloudApiException $e) {
        expect($e->getErrorCode())->toBe('171');
        expect($e->getErrorMessage())->toBe('invalid signature');
    }
});

test('HTTP 2xx 但缺 RetCode 当作错误（不静默成功）', function () {
    $capture = [];
    $http = ucloudCaptureHttp($capture, ['Action' => 'X']); // 无 RetCode
    $client = new UcloudRestClient('PUB', 'PRIV', '', '', $http);

    expect(fn () => $client->getProjectList())->toThrow(UcloudApiException::class);
});
