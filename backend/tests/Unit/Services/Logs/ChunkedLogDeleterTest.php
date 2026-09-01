<?php

use App\Services\Logs\ChunkedLogDeleter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Schema::create('test_chunk_logs', function (Blueprint $table) {
        $table->id();
        $table->timestamp('created_at')->nullable();
    });
});

afterEach(fn () => Schema::dropIfExists('test_chunk_logs'));

test('2501 行按 1000 一批删除并返回准确总数', function () {
    foreach (array_chunk(range(1, 2501), 500) as $ids) {
        DB::table('test_chunk_logs')->insert(array_map(
            fn (int $id) => ['id' => $id, 'created_at' => now()],
            $ids,
        ));
    }

    $deleteStatements = 0;
    DB::listen(function ($query) use (&$deleteStatements) {
        if (str_starts_with(strtolower($query->sql), 'delete from `test_chunk_logs`')) {
            $deleteStatements++;
        }
    });

    $deleted = (new ChunkedLogDeleter)->delete(
        fn () => DB::table('test_chunk_logs'),
        1000,
    );

    expect($deleted)->toBe(2501)
        ->and($deleteStatements)->toBe(3)
        ->and(DB::table('test_chunk_logs')->count())->toBe(0);
});

test('无效分批大小明确抛配置异常', function () {
    (new ChunkedLogDeleter)->delete(fn () => DB::table('test_chunk_logs'), 0);
})->throws(InvalidArgumentException::class, 'chunk size must be greater than zero');

test('dry run 只统计不删除', function () {
    DB::table('test_chunk_logs')->insert([
        ['created_at' => now()],
        ['created_at' => now()],
    ]);

    $count = (new ChunkedLogDeleter)->delete(fn () => DB::table('test_chunk_logs'), 1000, true);

    expect($count)->toBe(2)
        ->and(DB::table('test_chunk_logs')->count())->toBe(2);
});
