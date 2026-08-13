<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Axisnow\AxisnowApiException;
use Plugins\CloudDeploy\Deployers\Axisnow\AxisnowCertUploader;
use Plugins\CloudDeploy\Deployers\Axisnow\AxisnowClient;
use Plugins\CloudDeploy\Deployers\Axisnow\AxisnowProvider;
use Plugins\CloudDeploy\Deployers\Axisnow\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** @return array{cert:string,key:string} */
function axisnowCertificateMaterial(string $commonName = 'axisnow.example.com'): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certPem);
    openssl_pkey_export($key, $keyPem);

    return ['cert' => $certPem, 'key' => $keyPem];
}

function axisnowClientWithMock(array $responses, ArrayObject $history): AxisnowClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new AxisnowClient(new Client([
        'handler' => $stack,
        'http_errors' => false,
        'base_uri' => 'https://api.axisnow.io/client/v1/',
        'headers' => ['Authorization' => 'Bearer token'],
    ]));
}

test('AxisNow provider 与纯上传部署器元信息对齐', function () {
    $provider = new AxisnowProvider;
    $deployer = new CertificateDeployer;

    expect($provider->key())->toBe('axisnow')
        ->and(array_column($provider->credentialSchema(), 'key'))->toBe(['api_token'])
        ->and($provider->credentialSchema()[0]['secret'])->toBeTrue()
        ->and($deployer->provider())->toBe('axisnow')
        ->and($deployer->product())->toBe('certificate')
        ->and($deployer->configSchema())->toBe([])
        ->and($deployer)->toBeInstanceOf(UploadOnlyDeployerInterface::class)
        ->and($deployer->usesRemoteCertStore())->toBeFalse()
        ->and($deployer->certUploader())->toBeNull();
});

test('deployer bind 每次都执行 AxisNow 云端列表核验', function () {
    $client = Mockery::mock(AxisnowClient::class);
    $client->shouldNotReceive('addCertificate');
    $deployer = new class($client) extends CertificateDeployer
    {
        public function __construct(private object $client) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return $this->client;
        }
    };

    $material = axisnowCertificateMaterial();
    $certRef = ['cert' => $material['cert'], 'key' => $material['key'], 'chain' => ''];
    $client->shouldReceive('listCertificates')->twice()->with(1, 100)->andReturn([
        'result' => [['uuid' => 'same-uuid', 'certificate' => $material['cert']]],
        'result_info' => ['total_count' => 1],
    ]);

    $deployer->bind($certRef, ['api_token' => 'token'], []);
    $deployer->bind($certRef, ['api_token' => 'token'], []);
});

test('client 逐字对齐 AxisNow GET 与 POST 证书协议', function () {
    $history = new ArrayObject;
    $client = axisnowClientWithMock([
        new Response(200, [], json_encode(['success' => true, 'result' => [], 'result_info' => ['total_count' => 0]])),
        new Response(200, [], json_encode(['success' => true, 'result' => ['uuid' => 'uuid-1', 'name' => 'certimate-1']])),
    ], $history);

    $client->listCertificates(2, 100);
    $created = $client->addCertificate([
        'type' => 'upload',
        'name' => 'certimate-1',
        'certificate' => 'CERT',
        'private_key' => 'KEY',
    ]);

    /** @var RequestInterface $list */
    $list = $history[0]['request'];
    /** @var RequestInterface $add */
    $add = $history[1]['request'];
    expect($list->getMethod())->toBe('GET')
        ->and($list->getUri()->getPath())->toBe('/client/v1/certificates')
        ->and($list->getUri()->getQuery())->toContain('page=2')->toContain('per_page=100')
        ->and($list->getHeaderLine('Authorization'))->toBe('Bearer token')
        ->and($add->getMethod())->toBe('POST')
        ->and($add->getUri()->getPath())->toBe('/client/v1/certificates')
        ->and(json_decode((string) $add->getBody(), true))->toMatchArray([
            'type' => 'upload', 'name' => 'certimate-1', 'certificate' => 'CERT', 'private_key' => 'KEY',
        ])
        ->and($created['uuid'])->toBe('uuid-1');
});

test('uploader 跨页复用相同 X509 证书且不重复上传', function () {
    $material = axisnowCertificateMaterial();
    $client = Mockery::mock(AxisnowClient::class);
    $client->shouldReceive('listCertificates')->once()->with(1, 100)->andReturn([
        'result' => array_fill(0, 100, ['uuid' => 'other', 'name' => 'other', 'certificate' => axisnowCertificateMaterial('other.example.com')['cert']]),
        'result_info' => ['total_count' => 101],
    ]);
    $client->shouldReceive('listCertificates')->once()->with(2, 100)->andReturn([
        'result' => [['uuid' => 'same-uuid', 'name' => 'same', 'certificate' => $material['cert']]],
        'result_info' => ['total_count' => 101],
    ]);
    $client->shouldNotReceive('addCertificate');

    $uploader = new AxisnowCertUploader(fn () => $client);
    expect($uploader->upload($material['cert'], $material['key'], '', ['api_token' => 'token']))->toBe('same-uuid')
        ->and($uploader->storeKind())->toBe('axisnow_certificate');
});

test('uploader 首个满页缺少 result_info 时仍继续下一页', function () {
    $material = axisnowCertificateMaterial();
    $other = axisnowCertificateMaterial('other-page.example.com');
    $client = Mockery::mock(AxisnowClient::class);
    $client->shouldReceive('listCertificates')->once()->with(1, 100)->andReturn([
        'result' => array_fill(0, 100, ['uuid' => 'other', 'certificate' => $other['cert']]),
    ]);
    $client->shouldReceive('listCertificates')->once()->with(2, 100)->andReturn([
        'result' => [['uuid' => 'same-uuid', 'certificate' => $material['cert']]],
    ]);
    $client->shouldNotReceive('addCertificate');

    $uploader = new AxisnowCertUploader(fn () => $client);
    expect($uploader->upload($material['cert'], $material['key'], '', ['api_token' => 'token']))->toBe('same-uuid');
});

test('uploader 未命中时上传完整链并返回 uuid', function () {
    $client = Mockery::mock(AxisnowClient::class);
    $client->shouldReceive('listCertificates')->once()->andReturn(['result' => [], 'result_info' => ['total_count' => 0]]);
    $client->shouldReceive('addCertificate')->once()->with(Mockery::on(function (array $body): bool {
        return $body['type'] === 'upload'
            && str_starts_with($body['name'], 'certimate-')
            && $body['certificate'] === "LEAF\nCHAIN"
            && $body['private_key'] === 'KEY';
    }))->andReturn(['uuid' => 'new-uuid', 'name' => 'certimate-1']);

    $uploader = new AxisnowCertUploader(fn () => $client);
    expect($uploader->upload('LEAF', 'KEY', 'CHAIN', ['api_token' => 'token']))->toBe('new-uuid');
});

test('client 不采用 AxisNow 远端错误 code 且不回显敏感正文', function () {
    $client = axisnowClientWithMock([
        new Response(400, [], json_encode(['success' => false, 'errors' => [['code' => 1001, 'message' => 'KEY-LEAK']]])),
    ], new ArrayObject);

    try {
        $client->listCertificates(1, 100);
        expect(false)->toBeTrue('应抛异常');
    } catch (AxisnowApiException $e) {
        expect($e->getErrorCode())->toBe('AxisnowRequestFailed')
            ->and($e->getMessage())->not->toContain('KEY-LEAK');
    }
});
