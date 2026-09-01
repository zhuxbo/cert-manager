<?php

use App\Services\LogBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\Easy\EasyLogHandler;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => LogBuffer::clear());

test('Easy log handler 只持久化 action 不持久化 module', function () {
    (new EasyLogHandler)->handle([
        'module' => 'Easy',
        'action' => 'revalidate',
        'method' => 'POST',
        'url' => '/api/easy/revalidate',
        'params' => [],
        'response' => ['code' => 0],
        'ip' => '127.0.0.1',
        'status' => 0,
    ]);
    LogBuffer::flush();

    $log = DB::table('easy_logs')->first();
    expect($log->action)->toBe('revalidate')
        ->and(property_exists($log, 'module'))->toBeFalse();
});
