<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Models\CloudDeployRemoteCert;
use Plugins\CloudDeploy\Services\RemoteCertStore;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** 上传 test-double：实现 CertUploaderInterface（upload + storeKind）。 */
function fakeUploader(string $returnId, callable $onUpload, string $storeKind = 'tencent_ssl'): CertUploaderInterface
{
    return new class($returnId, $onUpload, $storeKind) implements CertUploaderInterface
    {
        public function __construct(private string $rid, private $cb, private string $kind) {}

        public function storeKind(): string
        {
            return $this->kind;
        }

        public function upload(string $c, string $k, string $ch, array $cred): string
        {
            ($this->cb)();

            return $this->rid;
        }
    };
}

test('未命中则上传一次并落库', function () {
    $calls = 0;
    $uploader = fakeUploader('cas-1', function () use (&$calls) {
        $calls++;
    });
    $store = new RemoteCertStore;

    $id = $store->ensure($uploader, accessId: 10, userId: 1, certId: 100, fingerprint: 'AA:BB', certPem: 'C', keyPem: 'K', chainPem: 'CH', credentials: []);

    expect($id)->toBe('cas-1');
    expect($calls)->toBe(1);
    expect(CloudDeployRemoteCert::where('access_id', 10)->where('store_kind', 'tencent_ssl')->where('fingerprint', 'AA:BB')->value('remote_cert_id'))->toBe('cas-1');
});

test('命中则复用、不再上传', function () {
    CloudDeployRemoteCert::create([
        'user_id' => 1, 'access_id' => 10, 'cert_id' => 100, 'fingerprint' => 'AA:BB', 'store_kind' => 'tencent_ssl', 'remote_cert_id' => 'cas-existing',
    ]);
    $calls = 0;
    $uploader = fakeUploader('cas-new', function () use (&$calls) {
        $calls++;
    });

    $id = (new RemoteCertStore)->ensure($uploader, 10, 1, 100, 'AA:BB', 'C', 'K', 'CH', []);

    expect($id)->toBe('cas-existing');
    expect($calls)->toBe(0);
});

test('store_kind 隔离：同 access+fingerprint 不同 store_kind 各自上传（标识空间不混用）', function () {
    $casUploader = fakeUploader('cas-id', fn () => null, 'cas');
    $slbUploader = fakeUploader('slb-id', fn () => null, 'slb');
    $store = new RemoteCertStore;

    $casId = $store->ensure($casUploader, 10, 1, 100, 'AA:BB', 'C', 'K', 'CH', []);
    $slbId = $store->ensure($slbUploader, 10, 1, 100, 'AA:BB', 'C', 'K', 'CH', []);

    expect($casId)->toBe('cas-id');
    expect($slbId)->toBe('slb-id'); // 不被 cas 行命中复用
    expect(CloudDeployRemoteCert::where('access_id', 10)->where('fingerprint', 'AA:BB')->count())->toBe(2);
});

test('1062 并发竞态：回查复用 winner 的 remote_cert_id', function () {
    // upload 回调内模拟“另一个 job 抢先落库”，使本次 create() 撞 (access_id,store_kind,fingerprint) 唯一索引→1062
    $uploader = fakeUploader('cas-loser', function () {
        CloudDeployRemoteCert::create([
            'user_id' => 1, 'access_id' => 10, 'cert_id' => 999,
            'fingerprint' => 'AA:BB', 'store_kind' => 'tencent_ssl', 'remote_cert_id' => 'cas-winner',
        ]);
    });

    $id = (new RemoteCertStore)->ensure($uploader, 10, 1, 100, 'AA:BB', 'C', 'K', 'CH', []);

    expect($id)->toBe('cas-winner'); // 回查返回 winner，而非自己的 loser
    expect(CloudDeployRemoteCert::where('access_id', 10)->where('store_kind', 'tencent_ssl')->where('fingerprint', 'AA:BB')->count())->toBe(1);
});
