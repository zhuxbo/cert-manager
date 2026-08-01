<?php

use App\Exceptions\ApiResponseException;
use App\Models\Cert;
use App\Models\Chain;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\Action;
use Illuminate\Support\Facades\File;

uses()->group('database');

/**
 * @return array{0:string,1:string}
 */
function makeSmimeCertificatePair(string $commonName): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privateKey);
    $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certificatePem);

    return [$certificatePem, $privateKey];
}

function createActiveDeliveryOrder(string $productType, string $commonName): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['product_type' => $productType]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    [$certificate, $privateKey] = makeSmimeCertificatePair($commonName);
    [$intermediate] = makeSmimeCertificatePair('CA '.substr(hash('sha256', $commonName), 0, 20));
    $issuer = 'Issuer '.substr(hash('sha256', $commonName), 0, 20);
    Chain::create([
        'common_name' => $issuer,
        'intermediate_cert' => $intermediate,
    ]);
    app()->forgetInstance('cert.chainMap');

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'common_name' => $commonName,
        'issuer' => $issuer,
        'cert' => $certificate,
        'private_key' => $privateKey,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    return $order->refresh();
}

/**
 * @return list<string>
 */
function archiveEntryNames(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }
    $zip->close();
    sort($names);

    return $names;
}

function expectDownloadBuildError(Closure $callback, string $message): void
{
    try {
        $callback();
    } catch (ApiResponseException $exception) {
        expect($exception->getApiResponse()['msg'] ?? '')->toContain($message);

        return;
    }

    throw new RuntimeException('预期下载构建失败，但未抛异常');
}

test('S/MIME 默认 all 归档只包含 PEM、PFX 和密码说明', function () {
    $order = createActiveDeliveryOrder(Product::TYPE_SMIME, 'alice@example.test');
    $action = app(Action::class);
    expect(method_exists($action, 'buildDownloadArchive'))->toBeTrue();

    $archive = $action->buildDownloadArchive([$order->id]);
    try {
        expect(archiveEntryNames($archive['zipPath']))->toBe([
            'alice@example.test/pem/alice@example.test.key',
            'alice@example.test/pem/alice@example.test.pem',
            'alice@example.test/pfx/alice@example.test.pfx',
            'alice@example.test/pfx/password.txt',
        ]);
    } finally {
        File::deleteDirectory($archive['tempDir']);
    }
});

test('两张 S/MIME 真实归档使用各自 stdin 密码且 PFX 与同目录 PEM 私钥逐张匹配', function () {
    $first = createActiveDeliveryOrder(Product::TYPE_SMIME, 'first@example.test');
    $second = createActiveDeliveryOrder(Product::TYPE_SMIME, 'second@example.test');
    $passwords = [
        'first@example.test' => 'A2b3C4',
        'second@example.test' => 'D5e6F7',
    ];
    $action = new class($passwords) extends Action
    {
        /** @var array<string, string> */
        public array $passwords;

        /** @var list<list<string>> */
        public array $commands = [];

        /** @param array<string, string> $passwords */
        public function __construct(array $passwords)
        {
            parent::__construct();
            $this->passwords = $passwords;
        }

        protected function addSmimeCertToZip(
            Order $order,
            ZipArchive $zip,
            string $tempDir,
            string $certPath,
            string $certName,
            string $type,
            ?string $password = null
        ): ?string {
            return parent::addSmimeCertToZip(
                $order,
                $zip,
                $tempDir,
                $certPath,
                $certName,
                $type,
                $this->passwords[$certName]
            );
        }

        /**
         * @param  list<string>  $command
         * @return array{exitCode:int,stdout:string,stderr:string}
         */
        protected function runProcess(array $command, string $stdin): array
        {
            $this->commands[] = $command;

            return parent::runProcess($command, $stdin);
        }
    };

    $archive = $action->buildDownloadArchive([$first->id, $second->id]);
    try {
        expect($action->commands)->toHaveCount(2);
        foreach ($action->commands as $command) {
            $passoutIndex = array_search('-passout', $command, true);
            expect($passoutIndex)->not->toBeFalse()
                ->and($command[$passoutIndex + 1] ?? null)->toBe('stdin')
                ->and($command)->not->toContain('-password');

            $arguments = implode("\0", $command);
            foreach ($passwords as $password) {
                expect($arguments)->not->toContain($password);
            }
        }

        $zip = new ZipArchive;
        expect($zip->open($archive['zipPath']))->toBeTrue();
        foreach ($passwords as $commonName => $password) {
            $pem = $zip->getFromName("$commonName/pem/$commonName.pem");
            $key = $zip->getFromName("$commonName/pem/$commonName.key");
            $pfx = $zip->getFromName("$commonName/pfx/$commonName.pfx");
            expect($zip->getFromName("$commonName/pfx/password.txt"))->toBe($password.PHP_EOL)
                ->and($pem)->not->toBeFalse()
                ->and($key)->not->toBeFalse()
                ->and($pfx)->not->toBeFalse();

            $parsed = [];
            expect(openssl_pkcs12_read($pfx, $parsed, $password))->toBeTrue()
                ->and(openssl_x509_fingerprint($parsed['cert'], 'sha256'))
                ->toBe(openssl_x509_fingerprint($pem, 'sha256'))
                ->and(openssl_x509_check_private_key($pem, $key))->toBeTrue()
                ->and(openssl_x509_check_private_key($parsed['cert'], $parsed['pkey']))->toBeTrue();

            $otherPassword = $password === 'A2b3C4' ? 'D5e6F7' : 'A2b3C4';
            $wrongPasswordResult = [];
            expect(@openssl_pkcs12_read($pfx, $wrongPasswordResult, $otherPassword))->toBeFalse();
        }
        $zip->close();
    } finally {
        File::deleteDirectory($archive['tempDir']);
    }

    expect(is_dir($archive['tempDir']))->toBeFalse();
});

test('三张清洗及后缀碰撞的 S/MIME 证书使用唯一目录且密码与 PFX 逐张对应', function () {
    $first = createActiveDeliveryOrder(Product::TYPE_SMIME, 'unsafe/name');
    $second = createActiveDeliveryOrder(Product::TYPE_SMIME, 'unsafe\\name');
    $secondArchiveName = 'unsafe-name-'.$second->latestCert->id;
    $third = createActiveDeliveryOrder(Product::TYPE_SMIME, $secondArchiveName);
    $passwords = [
        $first->id => 'A2b3C4',
        $second->id => 'D5e6F7',
        $third->id => 'G8h9J2',
    ];
    $action = new class($passwords) extends Action
    {
        /** @param array<int, string> $passwords */
        public function __construct(private readonly array $passwords)
        {
            parent::__construct();
        }

        protected function addSmimeCertToZip(
            Order $order,
            ZipArchive $zip,
            string $tempDir,
            string $certPath,
            string $certName,
            string $type,
            ?string $password = null
        ): ?string {
            return parent::addSmimeCertToZip(
                $order,
                $zip,
                $tempDir,
                $certPath,
                $certName,
                $type,
                $this->passwords[$order->id]
            );
        }
    };

    $archive = $action->buildDownloadArchive([$first->id, $second->id, $third->id]);
    try {
        $zip = new ZipArchive;
        expect($zip->open($archive['zipPath']))->toBeTrue();

        $entryNames = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if ($entryName !== false) {
                $entryNames[] = $entryName;
            }
        }

        $passwordEntries = array_values(array_filter(
            $entryNames,
            static fn (string $entryName): bool => str_ends_with($entryName, '/pfx/password.txt')
        ));
        $archiveDirectories = array_map(
            static fn (string $entryName): string => explode('/', $entryName, 2)[0],
            $passwordEntries
        );
        $thirdArchiveName = $secondArchiveName.'-'.$third->latestCert->id;

        expect($passwordEntries)->toHaveCount(3)
            ->and(array_values(array_unique($archiveDirectories)))->toHaveCount(3)
            ->and($archiveDirectories)->toContain('unsafe-name', $secondArchiveName, $thirdArchiveName);

        $deliveries = [
            [$first, 'unsafe-name', 'unsafe-name', $passwords[$first->id]],
            [$second, $secondArchiveName, 'unsafe-name', $passwords[$second->id]],
            [$third, $thirdArchiveName, $secondArchiveName, $passwords[$third->id]],
        ];
        foreach ($deliveries as [$order, $archiveName, $certName, $password]) {
            $passwordFile = $zip->getFromName("$archiveName/pfx/password.txt");
            $pfx = $zip->getFromName("$archiveName/pfx/$certName.pfx");
            expect($passwordFile)->toBe($password.PHP_EOL)
                ->and($pfx)->not->toBeFalse();

            $parsed = [];
            expect(openssl_pkcs12_read($pfx, $parsed, $password))->toBeTrue()
                ->and(openssl_x509_fingerprint($parsed['cert'], 'sha256'))
                ->toBe(openssl_x509_fingerprint((string) $order->latestCert->cert, 'sha256'));

            foreach ($passwords as $otherPassword) {
                if ($otherPassword === $password) {
                    continue;
                }

                $wrongPasswordResult = [];
                expect(@openssl_pkcs12_read($pfx, $wrongPasswordResult, $otherPassword))->toBeFalse();
            }
        }
        $zip->close();
    } finally {
        File::deleteDirectory($archive['tempDir']);
    }

    expect(is_dir($archive['tempDir']))->toBeFalse();
});

test('S/MIME 显式 pem 和 pfx 分别只输出所选格式', function (string $type, array $expectedSuffixes) {
    $order = createActiveDeliveryOrder(Product::TYPE_SMIME, "$type@example.test");
    $archive = app(Action::class)->buildDownloadArchive([$order->id], $type);

    try {
        $names = archiveEntryNames($archive['zipPath']);
        expect($names)->toHaveCount(count($expectedSuffixes));
        foreach ($expectedSuffixes as $suffix) {
            expect(collect($names)->contains(fn (string $name) => str_ends_with($name, $suffix)))->toBeTrue();
        }
    } finally {
        File::deleteDirectory($archive['tempDir']);
    }
})->with([
    'pem' => ['pem', ['.pem', '.key']],
    'pfx' => ['pfx', ['.pfx', 'password.txt']],
]);

test('S/MIME 拒绝旧服务器格式且 SSL 和混合批次拒绝 pfx', function () {
    $smime = createActiveDeliveryOrder(Product::TYPE_SMIME, 'smime@example.test');
    $ssl = createActiveDeliveryOrder(Product::TYPE_SSL, 'ssl.example.test');
    $action = app(Action::class);

    expectDownloadBuildError(
        fn () => $action->buildDownloadArchive([$smime->id], 'iis'),
        'S/MIME'
    );
    expectDownloadBuildError(
        fn () => $action->buildDownloadArchive([$ssl->id], 'pfx'),
        '下载格式'
    );
    expectDownloadBuildError(
        fn () => $action->buildDownloadArchive([$ssl->id, $smime->id], 'pfx'),
        '下载格式'
    );
    expectDownloadBuildError(
        fn () => $action->buildDownloadArchive([$smime->id], 'unknown'),
        '下载格式'
    );
});

test('S/MIME 缺失或不匹配私钥时所有格式均失败并清理实际根临时目录', function (
    string $type,
    string $keyState
) {
    $order = createActiveDeliveryOrder(Product::TYPE_SMIME, "{$keyState}-key-{$type}@example.test");
    $privateKey = null;
    if ($keyState === 'mismatched') {
        [, $privateKey] = makeSmimeCertificatePair('other-key@example.test');
    }
    $order->latestCert->update(['private_key' => $privateKey]);
    $action = new class extends Action
    {
        public ?string $capturedRoot = null;

        protected function makeArchiveRootDir(): string
        {
            $this->capturedRoot = parent::makeArchiveRootDir();

            return $this->capturedRoot;
        }
    };

    expectDownloadBuildError(
        fn () => $action->buildDownloadArchive([$order->id], $type),
        'S/MIME 证书私钥不存在或不匹配'
    );
    expect($action->capturedRoot)->not->toBeNull()
        ->and(is_dir($action->capturedRoot))->toBeFalse();
})->with(['pem', 'pfx', 'all'])->with(['missing', 'mismatched']);

test('净化后同名的两张 S/MIME 使用证书 ID 区分归档目录', function () {
    $first = createActiveDeliveryOrder(Product::TYPE_SMIME, 'first@example.test');
    $second = createActiveDeliveryOrder(Product::TYPE_SMIME, 'second@example.test');
    $first->latestCert->update(['common_name' => 'unsafe/name']);
    $second->latestCert->update(['common_name' => 'unsafe\\name']);

    $archive = app(Action::class)->buildDownloadArchive([$first->id, $second->id], 'pem');
    try {
        $names = archiveEntryNames($archive['zipPath']);
        expect($names)->toContain(
            'unsafe-name/pem/unsafe-name.pem',
            'unsafe-name/pem/unsafe-name.key',
            'unsafe-name-'.$second->latestCert->id.'/pem/unsafe-name.pem',
            'unsafe-name-'.$second->latestCert->id.'/pem/unsafe-name.key',
        );
        foreach ($names as $name) {
            expect($name)->not->toContain('../')
                ->not->toContain('\\');
        }
    } finally {
        File::deleteDirectory($archive['tempDir']);
    }
});

test('mixed batch 默认 all 按各产品规则输出且显式 pem 为共同格式', function () {
    $ssl = createActiveDeliveryOrder(Product::TYPE_SSL, 'ssl.example.test');
    $smime = createActiveDeliveryOrder(Product::TYPE_SMIME, 'mixed@example.test');

    foreach (['all', 'pem'] as $type) {
        $archive = app(Action::class)->buildDownloadArchive([$ssl->id, $smime->id], $type);
        try {
            $names = archiveEntryNames($archive['zipPath']);
            expect($names)->toContain(
                'ssl.example.test/pem/ssl.example.test.pem',
                'mixed@example.test/pem/mixed@example.test.pem',
            );
            if ($type === 'all') {
                expect($names)->toContain(
                    'ssl.example.test/apache/ssl.example.test.crt',
                    'mixed@example.test/pfx/mixed@example.test.pfx',
                )->not->toContain('mixed@example.test/apache/mixed@example.test.crt');
            } else {
                expect($names)->toHaveCount(4);
            }
        } finally {
            File::deleteDirectory($archive['tempDir']);
        }
    }
});

test('SSL 显式 pem 继续输出既有 PEM 证书链和匹配私钥', function () {
    $ssl = createActiveDeliveryOrder(Product::TYPE_SSL, 'ssl-pem.example.test');
    $archive = app(Action::class)->buildDownloadArchive([$ssl->id], 'pem');

    try {
        expect(archiveEntryNames($archive['zipPath']))->toBe([
            'ssl-pem.example.test/pem/ssl-pem.example.test.key',
            'ssl-pem.example.test/pem/ssl-pem.example.test.pem',
        ]);
    } finally {
        File::deleteDirectory($archive['tempDir']);
    }
});
