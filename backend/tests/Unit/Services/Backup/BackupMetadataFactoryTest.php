<?php

use App\Services\Backup\BackupMetadataFactory;
use Tests\TestCase;

uses(TestCase::class);

test('make writes version, toolchain, stream, table selection and capacity metadata', function () {
    config([
        'version.version' => '2.3.4',
        'version.channel' => 'dev',
        'version.build_commit' => 'abc1234',
    ]);

    $metadata = (new BackupMetadataFactory)->make(
        ['tables' => [
            'orders' => ['data_length' => 4096, 'index_length' => 1024],
            'jobs' => ['data_length' => 8192, 'index_length' => 2048],
        ]],
        ['server_version' => '8.4.0', 'client_version' => '8.4.0'],
        ['compressed_bytes' => 800, 'uncompressed_bytes' => 3200, 'sha256' => str_repeat('a', 64)],
        ['orders'],
        ['admin_logs', 'jobs'],
    );

    expect($metadata['backup_meta'])->toMatchArray([
        'format_version' => 2,
        'application' => [
            'version' => '2.3.4',
            'channel' => 'dev',
            'build_commit' => 'abc1234',
        ],
        'toolchain' => ['server_version' => '8.4.0', 'client_version' => '8.4.0'],
        'stream' => [
            'compressed_bytes' => 800,
            'uncompressed_bytes' => 3200,
            'sha256' => str_repeat('a', 64),
        ],
        'included_tables' => ['orders'],
        'excluded_tables' => ['admin_logs', 'jobs'],
        'table_capacities' => ['orders' => ['data_length' => 4096, 'index_length' => 1024]],
    ])->and($metadata['backup_meta'])->not->toHaveKeys(['retained_tables', 'runtime_reset_tables']);
});

test('make does not fabricate missing application version fields', function () {
    config([
        'version.version' => null,
        'version.channel' => null,
        'version.build_commit' => null,
    ]);

    $metadata = (new BackupMetadataFactory)->make([], [], [], [], []);

    expect($metadata['backup_meta']['application'])->toBe([
        'version' => null,
        'channel' => null,
        'build_commit' => null,
    ]);
});
