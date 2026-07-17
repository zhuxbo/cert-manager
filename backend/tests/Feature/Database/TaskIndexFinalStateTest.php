<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;

function task_index_final_state_rows(): Collection
{
    return collect(DB::select('SHOW INDEX FROM tasks'));
}

function assert_task_index_final_state(): void
{
    $target = 'tasks_order_action_status_index';
    $rows = task_index_final_state_rows();
    $indexes = $rows->groupBy('Key_name');

    expect($indexes->has($target))->toBeTrue("tasks 表必须保留 {$target}");

    $targetRows = $indexes->get($target)
        ->sortBy(fn ($row) => (int) $row->Seq_in_index)
        ->values();

    expect($targetRows->pluck('Column_name')->all())
        ->toBe(['order_id', 'action', 'status'], "{$target} 列序必须固定");
    expect($targetRows->pluck('Non_unique')->map(fn ($value) => (int) $value)->unique()->values()->all())
        ->toBe([1], "{$target} 必须是非 unique 索引");

    $forbidden = $rows
        ->filter(fn ($row) => (int) $row->Seq_in_index === 1
            && $row->Column_name === 'order_id'
            && $row->Key_name !== $target)
        ->map(fn ($row) => "{$row->Key_name}({$row->Column_name})")
        ->values()
        ->all();

    expect($forbidden)->toBe([], 'tasks 表不允许出现其它 order_id 首列索引');
}

test('tasks lock index remains the only order_id-leading index', function () {
    assert_task_index_final_state();
});

test('tasks index guard detects unexpected order_id-leading indexes', function () {
    try {
        DB::statement('DROP INDEX tasks_order_status_guard_test_index ON tasks');
    } catch (Throwable) {
        // ignore leftover index from an interrupted local run
    }

    DB::statement('CREATE INDEX tasks_order_status_guard_test_index ON tasks (order_id, status)');

    try {
        expect(fn () => assert_task_index_final_state())
            ->toThrow(ExpectationFailedException::class);
    } finally {
        try {
            DB::statement('DROP INDEX tasks_order_status_guard_test_index ON tasks');
        } catch (Throwable) {
            // ignore cleanup failure; the assertion above is the signal this test owns
        }
    }
});
