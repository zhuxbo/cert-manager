<?php

use App\Services\Backup\BackupArtifactInspector;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->testDir = storage_path('framework/testing/backup-artifact-inspector');
    if (is_dir($this->testDir)) {
        array_map('unlink', glob($this->testDir.'/*') ?: []);
    } else {
        mkdir($this->testDir, 0755, true);
    }
    $this->inspector = new BackupArtifactInspector($this->testDir);
});

afterEach(function () {
    array_map('unlink', glob($this->testDir.'/*') ?: []);
    rmdir($this->testDir);
});

function writeArtifact(string $directory, string $id, string $sql = 'SELECT 1;', array $meta = []): void
{
    $sqlPath = "$directory/$id.sql.gz";
    $gzip = gzopen($sqlPath, 'wb');
    gzwrite($gzip, $sql);
    gzclose($gzip);

    $meta += [
        'compressed_bytes' => filesize($sqlPath),
        'uncompressed_bytes' => strlen($sql),
        'sha256' => hash_file('sha256', $sqlPath),
    ];
    file_put_contents("$directory/$id.schema.json", json_encode([
        'tables' => [],
        'backup_meta' => ['format_version' => 2, 'stream' => $meta],
    ]));
}

test('inspect accepts complete new-format artifact', function () {
    writeArtifact($this->testDir, 'backup_20260830_120000');

    $artifact = $this->inspector->inspect('backup_20260830_120000');

    expect($artifact['legacy'])->toBeFalse()
        ->and($artifact['schema'])->toHaveKey('backup_meta')
        ->and($artifact['integrity'])->toMatchArray([
            'verified' => true,
            'sha256' => hash_file('sha256', "$this->testDir/backup_20260830_120000.sql.gz"),
        ])
        ->and($artifact['integrity'])->not->toHaveKeys(['gzip_eof', 'uncompressed_bytes']);
});

test('inspect verifies legacy high-compression artifacts in bounded decoded chunks', function () {
    $id = 'backup_20260830_120008';
    $sql = str_repeat('INSERT INTO `t` VALUES (1);'."\n", 400_000);
    $sqlPath = "$this->testDir/$id.sql.gz";
    $gzip = gzopen($sqlPath, 'wb');
    gzwrite($gzip, $sql);
    gzclose($gzip);
    file_put_contents("$this->testDir/$id.schema.json", json_encode(['tables' => []]));

    $artifact = $this->inspector->inspect($id);

    expect($artifact['integrity']['uncompressed_bytes'])->toBe(strlen($sql))
        ->and($artifact['integrity']['gzip_eof'])->toBeTrue();
});

test('inspect accepts legacy high-compression gzip whose decoded size exactly fills chunks', function () {
    $id = 'backup_20260830_120009';
    $sqlPath = "$this->testDir/$id.sql.gz";
    $gzip = gzopen($sqlPath, 'wb');
    $chunk = str_repeat('A', 1_048_576);
    for ($i = 0; $i < 128; $i++) {
        gzwrite($gzip, $chunk);
    }
    gzclose($gzip);
    file_put_contents("$this->testDir/$id.schema.json", json_encode(['tables' => []]));

    $artifact = $this->inspector->inspect($id);

    expect($artifact['integrity']['uncompressed_bytes'])->toBe(134_217_728)
        ->and($artifact['integrity']['gzip_eof'])->toBeTrue();
});

test('inspect rejects new-format sha256 or compressed byte mismatch', function () {
    writeArtifact($this->testDir, 'backup_20260830_120001', meta: ['sha256' => str_repeat('0', 64)]);
    expect(fn () => $this->inspector->inspect('backup_20260830_120001'))
        ->toThrow(RuntimeException::class, 'SHA-256');

    writeArtifact($this->testDir, 'backup_20260830_120002', meta: ['compressed_bytes' => 1]);
    expect(fn () => $this->inspector->inspect('backup_20260830_120002'))
        ->toThrow(RuntimeException::class, '压缩大小');
});

test('inspect new format trusts matching compressed hash without decoding gzip', function () {
    writeArtifact($this->testDir, 'backup_20260830_120003');
    $path = "$this->testDir/backup_20260830_120003.sql.gz";
    file_put_contents($path, substr((string) file_get_contents($path), 0, -4));
    $schemaPath = "$this->testDir/backup_20260830_120003.schema.json";
    $schema = json_decode((string) file_get_contents($schemaPath), true);
    $schema['backup_meta']['stream']['compressed_bytes'] = filesize($path);
    $schema['backup_meta']['stream']['sha256'] = hash_file('sha256', $path);
    file_put_contents($schemaPath, json_encode($schema));

    $artifact = $this->inspector->inspect('backup_20260830_120003');

    expect($artifact['integrity'])->toMatchArray([
        'verified' => true,
        'compressed_bytes' => filesize($path),
        'sha256' => hash_file('sha256', $path),
    ])->not->toHaveKeys(['gzip_eof', 'uncompressed_bytes']);
});

test('inspect rejects new-format schema without final sql', function () {
    file_put_contents("$this->testDir/backup_20260830_120004.schema.json", json_encode(['backup_meta' => ['format_version' => 2]]));

    expect(fn () => $this->inspector->inspect('backup_20260830_120004'))
        ->toThrow(RuntimeException::class, 'SQL');
});

test('inspect rejects a new-format schema with unsupported metadata version', function () {
    $id = 'backup_20260830_120007';
    writeArtifact($this->testDir, $id);
    $schemaPath = "$this->testDir/$id.schema.json";
    $schema = json_decode((string) file_get_contents($schemaPath), true);
    $schema['backup_meta']['format_version'] = 1;
    file_put_contents($schemaPath, json_encode($schema));

    expect(fn () => $this->inspector->inspect($id))
        ->toThrow(RuntimeException::class, '不支持');
});

test('inspect keeps legacy schema and schema-less sql visible with warnings', function () {
    $idWithSchema = 'backup_20260830_120005';
    $gzip = gzopen("$this->testDir/$idWithSchema.sql.gz", 'wb');
    gzwrite($gzip, 'legacy');
    gzclose($gzip);
    file_put_contents("$this->testDir/$idWithSchema.schema.json", json_encode(['tables' => []]));

    $withSchema = $this->inspector->inspect($idWithSchema);
    expect($withSchema['legacy'])->toBeTrue()
        ->and($withSchema['metadata']['warning'])->toContain('遗留');

    $idWithoutSchema = 'backup_20260830_120006';
    $gzip = gzopen("$this->testDir/$idWithoutSchema.sql.gz", 'wb');
    gzwrite($gzip, 'legacy');
    gzclose($gzip);
    $withoutSchema = $this->inspector->inspect($idWithoutSchema);

    expect($withoutSchema['legacy'])->toBeTrue()
        ->and($withoutSchema['schema'])->toBeNull()
        ->and($withoutSchema['metadata']['warning'])->toContain('遗留');
});
