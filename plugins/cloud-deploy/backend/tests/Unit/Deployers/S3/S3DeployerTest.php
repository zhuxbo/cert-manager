<?php

use App\Services\Binary\BinaryLocator;
use Aws\AwsClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Plugins\CloudDeploy\Deployers\S3\OpenSslPkcs12Capabilities;
use Plugins\CloudDeploy\Deployers\S3\S3Deployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（s3 kind）注入 mock S3Client。 */
function s3DeployerWith(callable $clientFactory): S3Deployer
{
    return new class($clientFactory) extends S3Deployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function s3CertRef(): array
{
    return ['cert' => 'LEAFPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function s3Creds(): array
{
    return ['access_key_id' => 'AKIDXXXX', 'secret_access_key' => 'SECRET', 'region' => 'us-east-1'];
}

/** @return array{cert:string,key:string} */
function s3GeneratedCertificate(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 's3.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);

    return ['cert' => $certPem, 'key' => $keyPem];
}

/** @return array{binary:string,log:string} */
function s3FakeOpenSsl(): array
{
    $binary = tempnam(sys_get_temp_dir(), 'clouddeploy_fake_openssl_');
    $log = tempnam(sys_get_temp_dir(), 'clouddeploy_fake_openssl_log_');
    if (! is_string($binary) || ! is_string($log)) {
        throw new RuntimeException('无法创建 fake OpenSSL');
    }
    $script = <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "__LOG__"
if [ "$1" = "version" ]; then
    printf '%s\n' 'OpenSSL 3.5.0 fixture'
    exit 0
fi
if [ "$1" = "pkcs12" ] && [ "$2" = "-help" ]; then
    printf '%s\n' '-pbmac1_pbkdf2 -pbmac1_pbkdf2_md -macsaltlen' >&2
    exit 0
fi
destination=''
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-out" ]; then
        shift
        destination="$1"
    fi
    shift
done
if [ -n "$destination" ]; then
    printf '\060\202fixture' > "$destination"
    exit 0
fi
exit 1
SH;
    file_put_contents($binary, str_replace('__LOG__', $log, $script));
    chmod($binary, 0700);

    return ['binary' => $binary, 'log' => $log];
}

/** @return array{binary:string,log:string} */
function s3FakeKeytool(): array
{
    $binary = tempnam(sys_get_temp_dir(), 'clouddeploy_fake_keytool_');
    $log = tempnam(sys_get_temp_dir(), 'clouddeploy_fake_keytool_log_');
    if (! is_string($binary) || ! is_string($log)) {
        throw new RuntimeException('无法创建 fake keytool');
    }
    $script = <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "__LOG__"
destination=''
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-destkeystore" ]; then
        shift
        destination="$1"
    fi
    shift
done
if [ -n "$destination" ]; then
    printf '\376\355\376\355fixture' > "$destination"
    exit 0
fi
exit 1
SH;
    file_put_contents($binary, str_replace('__LOG__', $log, $script));
    chmod($binary, 0700);

    return ['binary' => $binary, 'log' => $log];
}

function s3WithEmptyPath(Closure $callback): void
{
    $path = getenv('PATH');
    putenv('PATH=');
    try {
        $callback();
    } finally {
        putenv($path === false ? 'PATH' : "PATH=$path");
    }
}

test('S3 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new S3Deployer;
    expect($deployer->provider())->toBe('s3');
    expect($deployer->product())->toBe('s3');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('bucket')->toContain('file_format')->toContain('signature_version')->toContain('pfx_password')
        ->toContain('object_key_for_crt')->toContain('object_key_for_key');
});

test('PFX 格式转换后以二进制对象上传并透传签名版本', function () {
    $put = null;
    $clientCredentials = null;
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->once()->andReturnUsing(function (array $args) use (&$put) {
        $put = $args;
    });
    $deployer = new class(fn (array $credentials) => [$client, &$clientCredentials]) extends S3Deployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            $GLOBALS['s3_test_credentials'] = $credentials;

            return ($this->factory)($credentials)[0];
        }

        protected function buildPfx(string $cert, string $key, string $password, string $encoder): string
        {
            return "\x01PFXDATA";
        }
    };
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'b', 'file_format' => 'pfx', 'object_key_for_crt' => 'cert.pfx',
        'pfx_password' => 'secret', 'pfx_encoder' => '', 'signature_version' => 'v2',
    ]);
    expect($put['Body'])->toBe("\x01PFXDATA")->and($put['ContentType'])->toBe('application/x-pkcs12');
    expect($GLOBALS['s3_test_credentials']['__signature_version'])->toBe('s3');
    unset($GLOBALS['s3_test_credentials']);
});

test('JKS 格式转换后以二进制对象上传', function () {
    $put = null;
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->once()->andReturnUsing(function (array $args) use (&$put) {
        $put = $args;
    });
    $deployer = new class($client) extends S3Deployer
    {
        public function __construct(private object $client) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return $this->client;
        }

        protected function buildJks(string $cert, string $key, string $alias, string $keypass, string $storepass): string
        {
            return "\xFE\xEDJKS";
        }
    };
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'b', 'file_format' => 'jks', 'object_key_for_crt' => 'cert.jks',
        'jks_alias' => 'server', 'jks_keypass' => 'key-secret', 'jks_storepass' => 'store-secret',
    ]);
    expect($put['Body'])->toBe("\xFE\xEDJKS")->and($put['ContentType'])->toBe('application/x-java-keystore');
});

test('真实 keytool 转换产出可识别的 JKS', function () {
    $material = s3GeneratedCertificate();
    $deployer = new class extends S3Deployer
    {
        public function convert(string $cert, string $key): string
        {
            return $this->buildJks($cert, $key, 'server', 'key-secret', 'store-secret');
        }
    };

    $jks = $deployer->convert($material['cert'], $material['key']);
    expect(strlen($jks))->toBeGreaterThan(100)
        ->and(bin2hex(substr($jks, 0, 4)))->toBe('feedfeed');
});

test('Certimate PFX encoder 的 LegacyRC2 LegacyDES Modern2023 均可生成 PKCS12', function (string $encoder) {
    $material = s3GeneratedCertificate();
    $deployer = new class extends S3Deployer
    {
        public function convert(string $cert, string $key, string $encoder): string
        {
            return $this->buildPfx($cert, $key, 'pfx-secret', $encoder);
        }
    };

    $pfx = $deployer->convert($material['cert'], $material['key'], $encoder);
    expect(strlen($pfx))->toBeGreaterThan(100)
        ->and(bin2hex(substr($pfx, 0, 2)))->toBe('3082');
})->with(['LegacyRC2', 'LegacyDES', 'Modern2023']);

test('FPM 空 PATH 下旧 PFX encoder 使用 BinaryLocator 返回的绝对 OpenSSL 路径', function (string $encoder) {
    $material = s3GeneratedCertificate();
    $fake = s3FakeOpenSsl();
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('openssl')->once()->andReturn($fake['binary']);
    app()->instance(BinaryLocator::class, $locator);

    $deployer = new class extends S3Deployer
    {
        public function convert(string $cert, string $key, string $encoder): string
        {
            return $this->buildPfx($cert, $key, 'pfx-secret', $encoder);
        }
    };

    try {
        s3WithEmptyPath(function () use ($deployer, $material, $encoder) {
            expect($deployer->convert($material['cert'], $material['key'], $encoder))->toBe("\x30\x82fixture");
        });
        expect(file_get_contents($fake['log']))->toContain('pkcs12 -export');
    } finally {
        @unlink($fake['binary']);
        @unlink($fake['log']);
    }
})->with(['LegacyRC2', 'LegacyDES', 'Modern2023']);

test('FPM 空 PATH 下 Modern2026 能力探测与导出使用 BinaryLocator 返回的同一绝对 OpenSSL 路径', function () {
    $material = s3GeneratedCertificate();
    $fake = s3FakeOpenSsl();
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('openssl')->once()->andReturn($fake['binary']);
    app()->instance(BinaryLocator::class, $locator);

    $deployer = new class extends S3Deployer
    {
        public function convert(string $cert, string $key): string
        {
            return $this->buildPfx($cert, $key, 'pfx-secret', 'Modern2026');
        }
    };

    try {
        s3WithEmptyPath(function () use ($deployer, $material) {
            expect($deployer->convert($material['cert'], $material['key']))->toBe("\x30\x82fixture");
        });
        $commands = file_get_contents($fake['log']);
        expect($commands)->toContain('pkcs12 -help')
            ->toContain('version -v')
            ->toContain('pkcs12 -export');
    } finally {
        @unlink($fake['binary']);
        @unlink($fake['log']);
    }
});

test('FPM 空 PATH 下 JKS 转换使用 BinaryLocator 返回的绝对 keytool 路径', function () {
    $material = s3GeneratedCertificate();
    $fake = s3FakeKeytool();
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('keytool')->once()->andReturn($fake['binary']);
    app()->instance(BinaryLocator::class, $locator);

    $deployer = new class extends S3Deployer
    {
        public function convert(string $cert, string $key): string
        {
            return $this->buildJks($cert, $key, 'server', 'key-secret', 'store-secret');
        }
    };

    try {
        s3WithEmptyPath(function () use ($deployer, $material) {
            expect($deployer->convert($material['cert'], $material['key']))->toBe("\xFE\xED\xFE\xEDfixture");
        });
        expect(file_get_contents($fake['log']))->toContain('-importkeystore');
    } finally {
        @unlink($fake['binary']);
        @unlink($fake['log']);
    }
});

test('Modern2026 在当前不具备 PBMAC1 能力的 OpenSSL CLI 上明确拒绝并提示替代方案', function () {
    $material = s3GeneratedCertificate();
    $deployer = new class extends S3Deployer
    {
        public function convert(string $cert, string $key): string
        {
            return $this->buildPfx($cert, $key, 'pfx-secret', 'Modern2026');
        }
    };

    expect(fn () => $deployer->convert($material['cert'], $material['key']))
        ->toThrow(RuntimeException::class, '请使用 Modern2023或升级 OpenSSL CLI');
});

test('OpenSSL 能力探测合并 help 的 stdout/stderr，并以 flags 而非版本号判定 Modern2026', function () {
    $calls = 0;
    $capabilities = OpenSslPkcs12Capabilities::probe('fixture-openssl-supported', function (array $command) use (&$calls): array {
        $calls++;

        return $command[1] === 'pkcs12'
            ? ['exitCode' => 0, 'output' => "-pbmac1_pbkdf2\n", 'errorOutput' => "-pbmac1_pbkdf2_md\n-macsaltlen\n"]
            : ['exitCode' => 0, 'output' => 'OpenSSL 3.5.0 fixture', 'errorOutput' => ''];
    });

    expect($capabilities->supportsModern2026())->toBeTrue()
        ->and($capabilities->diagnosticVersion())->toBe('OpenSSL 3.5.0')
        ->and($calls)->toBe(2);

    expect(OpenSslPkcs12Capabilities::probe('fixture-openssl-supported', static fn (): array => throw new RuntimeException('不得重复探测')))
        ->toBe($capabilities)
        ->and($calls)->toBe(2);
});

test('即使伪造 OpenSSL 3.5 版本，缺少任一独立 PBMAC1 flag 仍拒绝 Modern2026', function (string $missingFlag, string $help) {
    $capabilities = OpenSslPkcs12Capabilities::probe("fixture-openssl-missing-$missingFlag", static fn (array $command): array => $command[1] === 'pkcs12'
        ? ['exitCode' => 0, 'output' => $help, 'errorOutput' => '']
        : ['exitCode' => 0, 'output' => 'OpenSSL 3.5.0 fixture /private/tmp/pfx-secret.pem', 'errorOutput' => '']);

    expect($capabilities->supportsModern2026())->toBeFalse()
        ->and($capabilities->diagnosticVersion())->toBe('OpenSSL 3.5.0')
        ->not->toContain('/private', 'pfx-secret');
})->with([
    '缺 base flag（不能被 _md 子串替代）' => ['base', '-pbmac1_pbkdf2_md -macsaltlen'],
    '缺 md flag' => ['md', '-pbmac1_pbkdf2 -macsaltlen'],
    '缺 macsaltlen flag' => ['macsaltlen', '-pbmac1_pbkdf2 -pbmac1_pbkdf2_md'],
]);

test('OpenSSL capability runner 抛异常时 fail-closed 且不泄漏异常内容', function () {
    $capabilities = OpenSslPkcs12Capabilities::probe('fixture-openssl-runner-throws', static fn (): array => throw new RuntimeException('/private/tmp/pfx-secret.pem'));

    expect($capabilities->supportsModern2026())->toBeFalse()
        ->and($capabilities->diagnosticVersion())->toBe('未知 OpenSSL CLI 版本');
});

test('Modern2026 临时文件创建写入或设权失败均清理已创建文件并报固定本地错误', function (string $failureStage) {
    $material = s3GeneratedCertificate();
    $created = [];
    $deployer = new class($failureStage, $created) extends S3Deployer
    {
        public function __construct(private readonly string $failureStage, private array &$created) {}

        public function convert(string $cert, string $key): string
        {
            return $this->buildPfx($cert, $key, 'pfx-secret', 'Modern2026');
        }

        protected function openSslPkcs12Capabilities(string $binary): OpenSslPkcs12Capabilities
        {
            return OpenSslPkcs12Capabilities::probe("fixture-openssl-temp-{$this->failureStage}", static fn (array $command): array => $command[1] === 'pkcs12'
                ? ['exitCode' => 0, 'output' => '-pbmac1_pbkdf2 -pbmac1_pbkdf2_md -macsaltlen', 'errorOutput' => '']
                : ['exitCode' => 0, 'output' => 'OpenSSL 3.5.0', 'errorOutput' => '']);
        }

        protected function createPfxTemporaryFile(string $prefix): string|false
        {
            if ($this->failureStage === 'tempnam' && $this->created !== []) {
                return false;
            }
            $path = tempnam(sys_get_temp_dir(), $prefix);
            if (is_string($path)) {
                $this->created[] = $path;
            }

            return $path;
        }

        protected function writePfxTemporaryFile(string $path, string $contents): bool
        {
            return $this->failureStage !== 'write' && file_put_contents($path, $contents) !== false;
        }

        protected function securePfxTemporaryFile(string $path): bool
        {
            return $this->failureStage !== 'chmod' && chmod($path, 0600);
        }

        protected function executeOpenSslPkcs12Export(array $command, array $environment): bool
        {
            throw new RuntimeException('临时文件失败时不得运行 OpenSSL');
        }
    };

    try {
        $deployer->convert($material['cert'], $material['key']);
        expect(false)->toBeTrue('应抛固定本地错误');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('证书转换为 PFX 失败：临时文件不可用')
            ->not->toContain('pfx-secret', '/tmp');
        expect($e->getPrevious())->toBeNull();
    }
    foreach ($created as $path) {
        expect(is_file($path))->toBeFalse();
    }
})->with(['tempnam', 'write', 'chmod']);

test('PFX 临时文件原生失败的 warning 不外逸', function (string $operation) {
    $directory = sys_get_temp_dir().'/clouddeploy-missing-'.bin2hex(random_bytes(6));
    $deployer = new class($directory) extends S3Deployer
    {
        public function __construct(private readonly string $directory) {}

        public function probe(string $operation): string|bool
        {
            return match ($operation) {
                'tempnam' => $this->createPfxTemporaryFile('clouddeploy_pfx_test_'),
                'write' => $this->writePfxTemporaryFile($this->directory.'/missing.pem', 'fixture'),
                'chmod' => $this->securePfxTemporaryFile($this->directory.'/missing.pem'),
            };
        }

        protected function pfxTemporaryDirectory(): string
        {
            return $this->directory;
        }
    };

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if ((error_reporting() & $severity) !== 0) {
            $warnings[] = [$severity, $message];
        }

        return true;
    });
    try {
        $result = $deployer->probe($operation);
    } finally {
        restore_error_handler();
        if (isset($result) && is_string($result)) {
            @unlink($result);
        }
    }

    if ($operation === 'tempnam') {
        expect(is_string($result))->toBeTrue();
    } else {
        expect($result)->toBeFalse();
    }
    expect($warnings)->toBe([]);
})->with(['tempnam', 'write', 'chmod']);

test('Modern2026 捕获 OpenSSL 执行异常或超时并使用固定本地错误', function (Throwable $failure) {
    $material = s3GeneratedCertificate();
    $executed = false;
    $deployer = new class($failure, $executed) extends S3Deployer
    {
        public function __construct(private readonly Throwable $failure, private bool &$executed) {}

        public function convert(string $cert, string $key): string
        {
            return $this->buildPfx($cert, $key, 'pfx-secret', 'Modern2026');
        }

        protected function openSslPkcs12Capabilities(string $binary): OpenSslPkcs12Capabilities
        {
            return OpenSslPkcs12Capabilities::probe('fixture-openssl-execute-throws', static fn (array $command): array => $command[1] === 'pkcs12'
                ? ['exitCode' => 0, 'output' => '-pbmac1_pbkdf2 -pbmac1_pbkdf2_md -macsaltlen', 'errorOutput' => '']
                : ['exitCode' => 0, 'output' => 'OpenSSL 3.5.0', 'errorOutput' => '']);
        }

        protected function executeOpenSslPkcs12Export(array $command, array $environment): bool
        {
            $this->executed = true;
            throw $this->failure;
        }
    };

    try {
        $deployer->convert($material['cert'], $material['key']);
        expect(false)->toBeTrue('应抛固定本地错误');
    } catch (RuntimeException $e) {
        expect($executed)->toBeTrue();
        expect($e->getMessage())->toBe('证书转换为 PFX 失败：Modern2026 编码不可用')
            ->not->toContain('pfx-secret', '/private', 'env:');
        expect($e->getPrevious())->toBeNull();
    }
})->with([
    'runner 异常' => [new RuntimeException('/private/tmp/pfx-secret.pem env:CLOUDDEPLOY_PFX_PASSWORD')],
    'runner 超时' => [new ProcessTimedOutException(new Process(['openssl', 'pkcs12', '-passout', 'env:CLOUDDEPLOY_PFX_PASSWORD']), ProcessTimedOutException::TYPE_GENERAL)],
]);

test('Modern2026 有完整能力时精确传递 Certimate 对齐的 OpenSSL flags', function () {
    $material = s3GeneratedCertificate();
    $command = null;
    $permissions = [];
    $temporaryPaths = [];
    $deployer = new class($command, $permissions, $temporaryPaths) extends S3Deployer
    {
        public function __construct(private mixed &$command, private array &$permissions, private array &$temporaryPaths) {}

        public function convert(string $cert, string $key): string
        {
            return $this->buildPfx($cert, $key, 'pfx-secret', 'Modern2026');
        }

        protected function openSslPkcs12Capabilities(string $binary): OpenSslPkcs12Capabilities
        {
            return OpenSslPkcs12Capabilities::probe('fixture-openssl-full-flags', static fn (array $command): array => $command[1] === 'pkcs12'
                ? ['exitCode' => 0, 'output' => '-pbmac1_pbkdf2 -pbmac1_pbkdf2_md -macsaltlen', 'errorOutput' => '']
                : ['exitCode' => 0, 'output' => 'OpenSSL 3.5.0 fixture', 'errorOutput' => '']);
        }

        protected function runOpenSslPkcs12Export(array $command, array $environment): bool
        {
            $this->command = $command;
            foreach (['-in', '-inkey', '-certfile', '-out'] as $flag) {
                $index = array_search($flag, $command, true);
                if ($index !== false) {
                    $path = $command[$index + 1];
                    $this->permissions[$flag] = fileperms($path) & 0777;
                    $this->temporaryPaths[] = $path;
                }
            }
            $outputIndex = array_search('-out', $command, true);
            file_put_contents($command[$outputIndex + 1], "\x30\x82fixture");

            return $environment['CLOUDDEPLOY_PFX_PASSWORD'] === 'pfx-secret';
        }
    };

    expect($deployer->convert($material['cert']."\n".$material['cert'], $material['key']))->toBe("\x30\x82fixture");
    expect($command)->toContain('-keypbe', 'AES-256-CBC')
        ->toContain('-certpbe', 'AES-256-CBC')
        ->toContain('-pbmac1_pbkdf2')
        ->toContain('-pbmac1_pbkdf2_md', 'SHA256')
        ->toContain('-iter', '2048')
        ->toContain('-macsaltlen', '16')
        ->toContain('-passout', 'env:CLOUDDEPLOY_PFX_PASSWORD');
    expect($permissions)->each->toBe(0600);
    foreach ($temporaryPaths as $path) {
        expect(is_file($path))->toBeFalse();
    }
});

test('PutObject 写完整链 + 私钥到指定对象键', function () {
    $puts = [];
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->andReturnUsing(function (array $args) use (&$puts) {
        $puts[$args['Key']] = $args;
    });

    $deployer = s3DeployerWith(fn () => $client);
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'my-bucket',
        'object_key_for_crt' => 'ssl/full.pem',
        'object_key_for_key' => 'ssl/key.pem',
    ]);

    expect($puts)->toHaveKey('ssl/full.pem')->toHaveKey('ssl/key.pem');
    expect($puts['ssl/full.pem']['Bucket'])->toBe('my-bucket');
    expect($puts['ssl/full.pem']['Body'])->toContain('LEAFPEM')->toContain('CHAINPEM');
    expect($puts['ssl/key.pem']['Body'])->toBe('KEYPEM');
});

test('服务器证书 / 中间证书对象键各写单独内容', function () {
    $puts = [];
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->andReturnUsing(function (array $args) use (&$puts) {
        $puts[$args['Key']] = $args['Body'];
    });

    $deployer = s3DeployerWith(fn () => $client);
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'b',
        'object_key_for_crt_server' => 'leaf.pem',
        'object_key_for_crt_inter' => 'inter.pem',
    ]);

    expect($puts['leaf.pem'])->toContain('LEAFPEM')->not->toContain('CHAINPEM');
    expect($puts['inter.pem'])->toContain('CHAINPEM')->not->toContain('LEAFPEM');
});

test('空对象键跳过、不调 putObject', function () {
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->once()->andReturnUsing(function (array $args) {
        expect($args['Key'])->toBe('ssl/key.pem');
    });

    $deployer = s3DeployerWith(fn () => $client);
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'b',
        'object_key_for_key' => 'ssl/key.pem',
    ]);
});

test('缺 bucket 抛业务错误', function () {
    $deployer = s3DeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(s3CertRef(), s3Creds(), ['object_key_for_crt' => 'x']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
});

test('全部对象键为空抛业务错误', function () {
    $deployer = s3DeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(s3CertRef(), s3Creds(), ['bucket' => 'b']))
        ->toThrow(RuntimeException::class, '至少需配置一个对象键');
});

test('bind 遇 AwsException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $cmd = Mockery::mock(CommandInterface::class);
    $awsEx = new AwsException('upload failed', $cmd, [
        'code' => 'AccessDenied',
        'message' => 'Access Denied for key',
    ]);
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->andThrow($awsEx);

    $deployer = s3DeployerWith(fn () => $client);
    try {
        $deployer->bind(s3CertRef(), ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SECRET-LEAK-9', 'region' => 'us-east-1'], [
            'bucket' => 'b', 'object_key_for_crt' => 'x.pem',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AccessDenied');
        expect($e->getMessage())->not->toContain('SECRET-LEAK-9')->not->toContain('AKIA-LEAK-1234567890');
        expect($e->getPrevious())->toBeNull();
    }
});

test('运行时 Endpoint 指向回环地址被出站策略拦截（不构造 S3Client）', function () {
    app()->instance(
        OutboundDestinationPolicy::class,
        new OutboundDestinationPolicy(
            resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
        ),
    );

    $deployer = new S3Deployer;
    $method = (new ReflectionClass(S3Deployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 's3', [
        'access_key_id' => 'AKIDXXXX', 'secret_access_key' => 'SECRET', 'region' => 'us-east-1',
        'endpoint' => 'http://127.0.0.1:9000',
    ]))->toThrow(OutboundDestinationException::class);
});

test('自定义 S3 Endpoint 的 SDK 传输层钉在策略已审 IP', function () {
    app()->instance(
        OutboundDestinationPolicy::class,
        new OutboundDestinationPolicy(
            resolver: static fn (string $host): array => $host === 's3.example.com'
                ? ['93.184.216.34']
                : [],
        ),
    );

    $deployer = new S3Deployer;
    $method = (new ReflectionClass(S3Deployer::class))->getMethod('makeClient');
    $method->setAccessible(true);
    /** @var S3Client $client */
    $client = $method->invoke($deployer, 's3', [
        'access_key_id' => 'AKIDXXXX',
        'secret_access_key' => 'SECRET',
        'region' => 'us-east-1',
        'endpoint' => 'https://S3.EXAMPLE.COM./api',
    ]);

    $requestOptions = (new ReflectionClass(AwsClient::class))->getProperty('defaultRequestOptions');
    $requestOptions->setAccessible(true);
    /** @var array<string,mixed> $http */
    $http = $requestOptions->getValue($client);
    expect((string) $client->getEndpoint())->toBe('https://s3.example.com/api')
        ->and($http['curl'][CURLOPT_RESOLVE] ?? null)
        ->toBe(['s3.example.com:443:93.184.216.34']);
});
