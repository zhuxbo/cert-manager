<?php

use App\Services\Payment\PaymentGateway;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Yansongda\Artful\Contract\HttpClientInterface;
use Yansongda\Pay\Pay;

afterEach(function () {
    Pay::clear();
    Mockery::close();
});

/**
 * 用自签 RSA 密钥对 + 自签证书配置一套能通过「请求相位」（本地签名 + 组装 Radar）的
 * 微信 v3 配置，并绑定一个假 PSR-18 client 捕获真实出站 Request。
 *
 * 这样才能在 HTTP 层断言 Wechatpay-Serial 头是否真的发出——而非 mock 掉整个 SDK
 * 只验「参数到达 wrapper」（那样即便 vendor 管线把 _serial_no 抹掉也测不出来）。
 */
function captureWechatOutgoingRequest(): object
{
    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($pkey, $privatePem);
    $csr = openssl_csr_new(['commonName' => 'test-mch'], $pkey, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $pkey, 365, ['digest_alg' => 'sha256'], 20260710);
    openssl_x509_export($cert, $certPem);

    Pay::clear();
    Pay::config(['wechat' => ['default' => [
        'mch_id' => '1600000000',
        'mch_secret_key' => str_repeat('a', 32),
        'mch_secret_cert' => $privatePem,
        'mch_public_cert_path' => $certPem,
        'notify_url' => 'https://example.com/callback/wechat',
        'wechat_public_cert_path' => [],
        'mode' => Pay::MODE_NORMAL,
    ]]]);

    $captured = new stdClass;
    $captured->request = null;

    $fake = new class($captured) implements HttpClientInterface
    {
        public function __construct(private object $captured) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->captured->request = $request;

            // 占位响应；下游验签相位会因无有效签名抛错，调用方 catch 后断言已捕获的出站请求
            return new Response(200, [], json_encode(['code' => 'SUCCESS']));
        }
    };
    app()->instance(HttpClientInterface::class, $fake);

    return $captured;
}

test('微信查单-经 wechatQuery 走真实管线时出站请求带 Wechatpay-Serial 公钥头', function () {
    $captured = captureWechatOutgoingRequest();

    try {
        app(PaymentGateway::class)->wechatQuery([
            'out_trade_no' => '123456',
            '_serial_no' => 'PUB_KEY_ID_TEST_0001',
        ]);
    } catch (Throwable) {
        // 下游验签相位对占位响应报错，忽略——只关心出站请求头
    }

    expect($captured->request)->not->toBeNull();
    expect($captured->request->getHeaderLine('Wechatpay-Serial'))->toBe('PUB_KEY_ID_TEST_0001');
});

test('微信查单-未配公钥（order 无 _serial_no）时 wechatQuery 不发头（回退不破坏现状）', function () {
    $captured = captureWechatOutgoingRequest();

    try {
        app(PaymentGateway::class)->wechatQuery(['out_trade_no' => '123456']);
    } catch (Throwable) {
    }

    expect($captured->request)->not->toBeNull();
    expect($captured->request->getHeaderLine('Wechatpay-Serial'))->toBe('');
});

test('微信查单-vanilla query（vendor）会抹掉 _serial_no 不发头（记录 vendor 缺陷、证明 wechatQuery 修复必要）', function () {
    $captured = captureWechatOutgoingRequest();

    try {
        Pay::wechat()->query([
            'out_trade_no' => '123456',
            '_serial_no' => 'PUB_KEY_ID_TEST_0001',
        ]);
    } catch (Throwable) {
    }

    expect($captured->request)->not->toBeNull();
    // vendor QueryPlugin 的 setPayload 整体重建 payload、抹掉 _serial_no → AddRadarPlugin 不发头
    expect($captured->request->getHeaderLine('Wechatpay-Serial'))->toBe('');
});
