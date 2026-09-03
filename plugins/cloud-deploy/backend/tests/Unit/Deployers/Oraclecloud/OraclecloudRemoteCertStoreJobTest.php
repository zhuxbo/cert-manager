<?php

use App\Models\Cert;
use App\Models\Chain;
use App\Models\Order;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OciRequestSigner;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudAuthMaterial;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudCertificatesMgmtDeployer;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudClient;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudCredentialProvider;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleResourcePrincipalProvider;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployRemoteCert;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array{0:string,1:string} */
function oracleStoreKeypair(): array
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);

    return [$privateKey, $details['key']];
}

function oracleStoreJwt(int $expiresAt): string
{
    $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS256']).'.'.$encode(['exp' => $expiresAt]).'.signature';
}

/**
 * @param  Closure(array<string,mixed>):OraclecloudCredentialProvider  $providerFactory
 * @param  Closure(string):string  $upload
 */
function oracleStoreDeployer(Closure $providerFactory, Closure $upload): OraclecloudCertificatesMgmtDeployer
{
    return new class($providerFactory, $upload) extends OraclecloudCertificatesMgmtDeployer
    {
        public function __construct(private Closure $providerFactory, private Closure $upload) {}

        protected function makeClient(string $kind, array $credentials, ?OciRequestSigner $signer = null, string $region = ''): object
        {
            if ($kind === 'provider') {
                return ($this->providerFactory)($credentials);
            }

            $client = Mockery::mock(OraclecloudClient::class);
            $upload = $this->upload;
            $client->shouldReceive('createImportedCertificate')->once()->andReturnUsing(
                static fn (): string => $upload($region),
            );

            return $client;
        }
    };
}

/** @return array{CloudDeployTarget,Cert,CloudDeployAccess} */
function oracleStoreTarget(array $credentials): array
{
    $user = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $user->id,
        'name' => 'OCI principal',
        'provider' => 'oraclecloud',
        'credentials' => $credentials,
    ]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    Chain::create(['common_name' => 'OCI-TEST-CA', 'intermediate_cert' => 'CHAIN']);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'active',
        'issuer' => 'OCI-TEST-CA',
        'cert' => 'CERTPEM',
        'private_key' => oracleStoreKeypair()[0],
        'fingerprint' => 'OCI-FP-1',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);
    $target = CloudDeployTarget::create([
        'user_id' => $user->id,
        'access_id' => $access->id,
        'order_id' => $order->id,
        'product' => 'certificatesmgmt',
        'config' => ['compartment_ocid' => 'ocid1.compartment.oc1..test'],
    ]);

    return [$target, $cert, $access];
}

test('Oracle Job 在 RemoteCertStore 命中前仍解析凭证，region 或认证模式变化会隔离重传', function () {
    app()->instance(NotificationCenter::class, Mockery::mock(NotificationCenter::class)->shouldIgnoreMissing());
    [$resourcePrivateKey] = oracleStoreKeypair();
    $resourceToken = oracleStoreJwt(time() + 3600);
    $files = [
        '/runtime/region' => 'ap-tokyo-1',
        '/runtime/rpst' => $resourceToken,
        '/runtime/private.pem' => $resourcePrivateKey,
    ];
    $reads = [];
    $uploads = [];
    $resourceEnvironment = [
        'OCI_RESOURCE_PRINCIPAL_VERSION' => '2.2',
        'OCI_RESOURCE_PRINCIPAL_REGION' => '/runtime/region',
        'OCI_RESOURCE_PRINCIPAL_RPST' => '/runtime/rpst',
        'OCI_RESOURCE_PRINCIPAL_PRIVATE_PEM' => '/runtime/private.pem',
    ];
    $providerFactory = static function (array $credentials) use (&$files, &$reads, $resourceEnvironment): OraclecloudCredentialProvider {
        if (($credentials['auth_method'] ?? '') === 'instanceprincipal') {
            return new class implements OraclecloudCredentialProvider
            {
                public function resolve(): OraclecloudAuthMaterial
                {
                    return OraclecloudAuthMaterial::securityToken(
                        'instance-test-token',
                        'instance-test-key',
                        'us-ashburn-1',
                    );
                }
            };
        }

        return new OracleResourcePrincipalProvider(
            static fn (string $name): string|false => $resourceEnvironment[$name] ?? false,
            static function (string $path) use (&$files, &$reads): string|false {
                $reads[] = $path;

                return $files[$path] ?? false;
            },
        );
    };
    $deployer = oracleStoreDeployer(
        $providerFactory,
        static function (string $region) use (&$uploads): string {
            $uploads[] = $region;

            return 'remote-'.count($uploads);
        },
    );
    $registry = new Registry;
    $registry->registerDeployer('oraclecloud', 'certificatesmgmt', static fn () => $deployer);
    app()->instance(Registry::class, $registry);

    [$target, $cert, $access] = oracleStoreTarget(['auth_method' => 'resourceprincipal']);

    (new CloudDeployJob($target->id, $cert->id, 'manual', force: true))->handle();
    (new CloudDeployJob($target->id, $cert->id, 'manual', force: true))->handle();
    expect($uploads)->toBe(['ap-tokyo-1'])
        ->and($reads)->toHaveCount(6);

    $files['/runtime/region'] = 'us-ashburn-1';
    (new CloudDeployJob($target->id, $cert->id, 'manual', force: true))->handle();
    expect($uploads)->toBe(['ap-tokyo-1', 'us-ashburn-1'])
        ->and($reads)->toHaveCount(9);

    $access->update(['credentials' => ['auth_method' => 'instanceprincipal']]);
    (new CloudDeployJob($target->id, $cert->id, 'manual', force: true))->handle();
    expect($uploads)->toBe(['ap-tokyo-1', 'us-ashburn-1', 'us-ashburn-1'])
        ->and(CloudDeployRemoteCert::where('access_id', $access->id)->pluck('store_kind')->sort()->values()->all())
        ->toBe([
            'oci:i:'.substr(hash('sha256', "us-ashburn-1\0ocid1.compartment.oc1..test"), 0, 24),
            'oci:r:'.substr(hash('sha256', "ap-tokyo-1\0ocid1.compartment.oc1..test"), 0, 24),
            'oci:r:'.substr(hash('sha256', "us-ashburn-1\0ocid1.compartment.oc1..test"), 0, 24),
        ]);

    $rows = CloudDeployRemoteCert::where('access_id', $access->id)->get();
    $allPersisted = $rows->toJson();
    expect($rows->pluck('store_kind')->every(static fn (string $kind): bool => strlen($kind) <= 32))->toBeTrue()
        ->and($allPersisted)->not->toContain($resourceToken)
        ->not->toContain('PRIVATE KEY')
        ->not->toContain('instance-test-token');
});
